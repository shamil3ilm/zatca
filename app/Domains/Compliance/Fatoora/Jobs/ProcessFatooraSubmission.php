<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Jobs;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Events\BaseInvoiceEvent;
use App\Domains\Compliance\Fatoora\Events\InvoiceFailed;
use App\Domains\Compliance\Fatoora\Events\InvoiceSubmitted;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\SubmissionIdempotency;
use App\Domains\Compliance\Fatoora\Services\ClearanceState;
use App\Domains\Compliance\Fatoora\Services\KillSwitch;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Licensing\Services\UsageMeteringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Async ZATCA Submission Job.
 *
 * Processes invoice submissions asynchronously with:
 * - Automatic retries with exponential backoff
 * - State machine transitions
 * - Idempotency record updates
 * - Full audit logging
 */
class ProcessFatooraSubmission implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries;

    /**
     * Maximum processing time in seconds.
     */
    public int $timeout;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly InvoiceSubmission $submission
    ) {
        $this->tries = (int) config('fatoora.queue.tries', 3);
        $this->timeout = (int) config('fatoora.queue.timeout', 120);
        // From config rather than hardcoded. fatoora.queue.name and .connection
        // existed and were read nowhere, so renaming the queue or pointing it at
        // another connection moved nothing — and an operator running a worker on
        // the name they had configured would watch an empty queue.
        $this->onQueue((string) config('fatoora.queue.name', 'zatca-submissions'));

        // Unset — null or empty — leaves the application's own connection in
        // place. Empty matters because that is what an unset .env value reads
        // as, and it is how .env.example documents "use the default".
        if (($connection = (string) config('fatoora.queue.connection')) !== '') {
            $this->onConnection($connection);
        }
    }

    /**
     * Get unique job ID.
     */
    public function uniqueId(): string
    {
        return 'zatca-submission-'.$this->submission->id;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('fatoora.queue.backoff', [10, 60, 300]);
    }

    /**
     * How long until the next attempt, for the column that reports it.
     *
     * The queue applies backoff() itself. next_retry_at exists so an operator
     * reading invoice_submissions sees the same answer, which means deriving
     * it from the same list rather than a second constant.
     *
     * Past the end of the list the queue repeats its final value, so this does
     * too.
     */
    private function retryDelay(): int
    {
        $backoff = $this->backoff();
        $index = max(0, $this->attempts() - 1);

        return (int) ($backoff[$index] ?? end($backoff) ?: 300);
    }

    /**
     * Execute the job.
     */
    public function handle(
        FatooraClient $zatcaClient,
        KillSwitch $killSwitch,
        Submitter $submitter
    ): void {
        $submission = $this->submission->fresh();

        // Checked here as well as before queueing, because the gap between the
        // two is exactly when an operator throws the switch. A job queued
        // before an incident would otherwise submit during it.
        $killSwitch->assertNotEnabled(KillSwitch::SWITCH_SUBMISSION, (string) $submission->org_id);

        if ($submission->isTerminal()) {
            Log::info('Submission already in terminal state, skipping', [
                'submission_id' => $submission->id,
                'state' => $submission->state,
            ]);

            return;
        }

        Log::info('Processing ZATCA submission', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Transition to pending
            $this->transitionState($submission, 'pending_submission', 'queue_job');

            // Load invoice and organization
            $invoice = $submission->invoice;
            $organization = $submission->org;

            // Issue the document first if it has not been issued.
            //
            // This path builds its own document and never went through
            // Submitter, so nothing here allocated a counter or fixed a
            // predecessor: queued documents reached the authority carrying
            // ICV 0 and the genesis PIH, each claiming to be first in its
            // chain. Submitter::generate() is the one place that allocates,
            // under the organization-row lock that also reads the
            // predecessor, and it leaves an already-issued document alone.
            if ($invoice->icv === null || $invoice->hash === null) {
                $submitter->generate($invoice, $organization);
                $invoice->refresh();
            }
            // The document that was issued, not a new one.
            //
            // This built its own with DocumentBuilder, which made it the third
            // place a document was produced and the only one that produced a
            // different one each time it ran: signing again moves the XAdES
            // SigningTime, so a retry sent bytes the archive had never held.
            // Issuance is where a document is made; this is transport.
            $invoiceXml = (string) $invoice->signed_xml;
            $invoiceHash = (string) $invoice->hash;
            $invoiceUuid = $invoice->id;

            // Submit to ZATCA
            $this->transitionState($submission, 'submitted', 'queue_job');

            // Fire submitted event for real-time tracking
            event(new InvoiceSubmitted($submission->fresh()));

            $result = $submission->isClearance()
                ? $zatcaClient->clearInvoice($invoiceXml, $invoiceHash, $invoiceUuid)
                : $zatcaClient->reportInvoice($invoiceXml, $invoiceHash, $invoiceUuid);

            // Handle response
            $this->handleZatcaResponse($submission, $result);

            Log::info('ZATCA submission processed successfully', [
                'submission_id' => $submission->id,
                'state' => $submission->fresh()->state,
            ]);
        } catch (Throwable $e) {
            $this->handleError($submission, $e);
            throw $e; // Re-throw for queue retry mechanism
        }
    }

    /**
     * Handle ZATCA API response.
     */
    private function handleZatcaResponse(InvoiceSubmission $submission, FatooraResponse $response): void
    {
        $success = $response->success;
        $hasWarnings = $response->hasWarnings();

        // A 200 from ZATCA does not mean the invoice is cleared. For a B2B
        // document "REPORTED" means received and not yet cleared, and only
        // "CLEARED" is terminal. This read `$success && isClearance()`, so any
        // successful call marked the document cleared and fired the
        // invoice.cleared webhook — telling the integrator, and the taxpayer's
        // own records, that a document had cleared when the authority had only
        // acknowledged it.
        //
        // SubmissionTracker was corrected for the synchronous path. This is the
        // asynchronous one, which is the path the pipeline actually uses, and
        // it kept the original behaviour.
        $clearance = app(ClearanceState::class)->parseResponse([
            'clearanceStatus' => $response->clearanceStatus,
            'reportingStatus' => $response->reportingStatus,
            'validationResults' => $response->validationResults,
        ], isSimplified: ! $submission->isClearance());

        $newState = match (true) {
            ! $success => 'rejected',
            $hasWarnings => 'warning',
            default => ClearanceState::submissionState($clearance['state']),
        };

        app(UsageMeteringService::class)->recordSubmissionOutcome(
            (string) $submission->org_id,
            $newState,
            (float) ($submission->invoice?->total ?? 0)
        );

        // Update submission
        $submission->update([
            'state' => $newState,
            'previous_state' => 'submitted',
            'state_changed_at' => now(),
            'clearance_status' => $response->clearanceStatus,
            // What ZATCA said, kept beside where the submission is in this
            // platform's workflow. The job recorded only the latter, so a
            // document awaiting a decision was indistinguishable from one that
            // never got that far.
            'clearance_state' => $clearance['state'],
            'cleared_at' => $clearance['is_terminal'] ? now() : null,
            'reporting_status' => $response->reportingStatus,
            'zatca_warnings' => $response->warningMessages ?: null,
            'zatca_errors' => $response->errorMessages ?: null,
            // Only once ZATCA has actually decided. This was set on every
            // response, marking a document still awaiting clearance complete.
            'completed_at' => $clearance['is_terminal'] ? now() : null,
        ]);

        // Update idempotency
        $this->updateIdempotency($submission, $success, $response);

        // Log transition
        $this->logStateTransition($submission, 'submitted', $newState, 'zatca', [
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
        ]);

        // Fire appropriate event for real-time notifications
        $this->fireStateEvent($submission->fresh(), $newState, [
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
        ]);
    }

    /**
     * Handle submission error.
     */
    private function handleError(InvoiceSubmission $submission, Throwable $e): void
    {
        $errorCode = $e instanceof FatooraException
            ? $e->getErrorCode()
            : ErrorCode::SYS_INTERNAL_ERROR;

        $isRetryable = $errorCode->isRetryable();
        $maxRetries = $errorCode->getMaxRetries();

        // Update submission
        $submission->update([
            'state' => 'failed',
            'previous_state' => $submission->state,
            'state_changed_at' => now(),
            'last_error_code' => $errorCode->value,
            'last_error' => $e->getMessage(),
            'retry_count' => $submission->retry_count + 1,
            // backoff() is a method, not a property. Reading $this->backoff
            // yields null, null[$i] yields null, and the coalesce then pins
            // every retry at 300s — so the column reports a delay the queue is
            // not using.
            'next_retry_at' => $isRetryable && $this->attempts() < $this->tries
                ? now()->addSeconds($this->retryDelay())
                : null,
        ]);

        // Update idempotency
        $idempotency = SubmissionIdempotency::find($submission->idempotency_id);
        if ($idempotency) {
            $idempotency->update([
                'status' => $isRetryable && $this->attempts() < $this->tries
                    ? 'processing'
                    : 'failed',
                'attempt_count' => $idempotency->attempt_count + 1,
                'last_attempt_at' => now(),
            ]);
        }

        // Log
        $this->logStateTransition($submission, $submission->previous_state, 'failed', 'error', [
            'error_code' => $errorCode->value,
            'error_message' => $e->getMessage(),
            'attempt' => $this->attempts(),
            'will_retry' => $isRetryable && $this->attempts() < $this->tries,
        ]);

        Log::error('ZATCA submission failed', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'error_code' => $errorCode->value,
            'error' => $e->getMessage(),
            'attempt' => $this->attempts(),
            'will_retry' => $isRetryable && $this->attempts() < $this->tries,
        ]);

        // Fire failed event for real-time notifications
        event(new InvoiceFailed($submission->fresh(), [
            'error_code' => $errorCode->value,
            'error_message' => $e->getMessage(),
            'attempt' => $this->attempts(),
            'will_retry' => $isRetryable && $this->attempts() < $this->tries,
        ]));
    }

    /**
     * Transition state with logging.
     */
    private function transitionState(
        InvoiceSubmission $submission,
        string $newState,
        string $trigger,
        array $context = []
    ): void {
        $oldState = $submission->state;

        $submission->update([
            'state' => $newState,
            'previous_state' => $oldState,
            'state_changed_at' => now(),
            'submitted_at' => $newState === 'submitted' ? now() : $submission->submitted_at,
        ]);

        $this->logStateTransition($submission, $oldState, $newState, $trigger, $context);
    }

    /**
     * Log state transition for audit.
     */
    private function logStateTransition(
        InvoiceSubmission $submission,
        ?string $fromState,
        string $toState,
        string $trigger,
        array $context = []
    ): void {
        DB::table('submission_state_logs')->insert([
            'id' => Str::uuid()->toString(),
            'submission_id' => $submission->id,
            'from_state' => $fromState,
            'to_state' => $toState,
            'trigger' => $trigger,
            'context' => ! empty($context) ? json_encode($context) : null,
            'actor_type' => 'system',
            'actor_id' => null,
            'ip_address' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Fire the appropriate event based on state.
     */
    private function fireStateEvent(InvoiceSubmission $submission, string $state, array $context = []): void
    {
        BaseInvoiceEvent::raise($submission, $state, $context);
    }

    /**
     * Update idempotency record with response.
     */
    private function updateIdempotency(InvoiceSubmission $submission, bool $success, FatooraResponse $response): void
    {
        SubmissionIdempotency::where('id', $submission->idempotency_id)->update([
            'status' => $success ? 'completed' : 'failed',
            'http_status_code' => $success ? 200 : 422,
            'response_body' => $response->rawResponse,
            'clearance_status' => $response->clearanceStatus,
            'completed_at' => now(),
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        $submission = $this->submission->fresh();

        // Final failure - mark as permanently failed
        $submission->update([
            'state' => 'failed',
            'state_changed_at' => now(),
            'last_error' => 'Max retries exceeded: '.$exception->getMessage(),
            'next_retry_at' => null,
        ]);

        // Update idempotency
        SubmissionIdempotency::where('id', $submission->idempotency_id)
            ->update(['status' => 'failed']);

        $this->logStateTransition($submission, $submission->state, 'failed', 'error', [
            'reason' => 'max_retries_exceeded',
            'final_error' => $exception->getMessage(),
        ]);

        Log::error('ZATCA submission permanently failed', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'error' => $exception->getMessage(),
        ]);

        // Fire permanent failure event
        event(new InvoiceFailed($submission->fresh(), [
            'reason' => 'max_retries_exceeded',
            'error_message' => $exception->getMessage(),
            'permanent' => true,
        ]));
    }
}
