# 03 — The Invoice Pipeline

A real pipeline exists, is coherent, and crosses one repo boundary.

## Full path

```
+-- masaar-erp-frontend --------------------------------------------+
|  apps/staff . apps/admin . apps/portal   (React/TS, pnpm+turbo)   |
+-------------------------------+-----------------------------------+
                                | HTTP
+-- masaar-erp-backend ---------v-----------------------------------+
|  Sales invoice saved                                              |
|    -> Orchestrators/Sales/PostInvoiceOrchestrator                 |
|         :94   handleZatcaSubmission($invoice)                     |
|         :196  guard - $invoice->requiresCompliance()              |
|         :201  MasaarClient::submitInvoice($invoice)               |
|                 +- Services/Compliance/ZatcaInvoiceTransformer    |
|                      ::transform()  model -> payload array        |
|         :204  write back compliance_status / _uuid / _hash /      |
|               _qr_code / _response / _submitted_at                |
|         :235  ConnectionException -> RetryComplianceSubmission    |
|               ::dispatch()->delay(5 min)                          |
+-------------------------------+-----------------------------------+
                                | HTTPS  config/zatca-integration.php:5
                                | ZATCA_INTEGRATION_URL (:8001/api/v1)
                                | guard: 'license' + 'rate.api'
+-- Masaar ---------------------v-----------------------------------+
|                                                                   |
|  routes/api/partner.php:68                                        |
|    POST /v1/pipeline/submit   scope:invoice.submit, license.quota |
|      -> Pipeline/Http/Requests/PipelineSubmitRequest (validation) |
|      -> Pipeline/Http/Controllers/PipelineController::submit      |
|      -> Pipeline/Services/PipelineService::submitInvoice  :48     |
|                                                                   |
|    +--------------------------------------------------------+     |
|    | 1. InvoiceDrafter::draft()        :46 DB::transaction   |     |
|    |      Invoice::create()                                  |     |
|    |        +- boot() creating hook  Invoice.php:155         |     |
|    |             generateNextIcv()   Invoice.php:216         |     |
|    |               DB::transaction + organizations           |     |
|    |               row lockForUpdate  :219-222               |     |
|    |      + invoice_lines                                    |     |
|    +--------------------------------------------------------+     |
|                          |                                        |
|    +---------------------v----------------------------------+     |
|    | 2. Submitter::generate()   :58     <- ISSUANCE          |     |
|    |      KillSwitch::assertNotEnabled(ISSUANCE, SIGNING)    |     |
|    |      $previousHash = $invoice->previous_invoice_hash    |     |
|    |            + accessor, Invoice.php:383   (!) unlocked   |     |
|    |      CredentialStore::get()  -> privateKey, cert        |     |
|    |      DocumentBuilder::generateComplianceData()          |     |
|    |        |- XmlBuilder::build()        UBL 2.1 DOM        |     |
|    |        |- InvoiceHasher              SHA-256            |     |
|    |        |- XadesSigner::sign()        ECDSA secp256k1    |     |
|    |        |- TlvEncoder + QrCodeGenerator  9-tag Phase 2   |     |
|    |      DB::transaction  :84                               |     |
|    |        |- invoice->update(hash, qr_code, signed_xml,    |     |
|    |        |                  status=Issued)                |     |
|    |        +- ChainRecorder::record()  :48                  |     |
|    |             ChainEntry::updateOrCreate  (history)       |     |
|    |             ChainState::updateOrCreate  (head)          |     |
|    +--------------------------------------------------------+     |
|                          |                                        |
|    +---------------------v----------------------------------+     |
|    | 3. SubmissionTracker::submit()                          |     |
|    |      SubmissionIdempotency  (dedupe on key)             |     |
|    |      InvoiceSubmission row -> state machine             |     |
|    |      SubmissionGuard / CircuitBreaker / Connectivity    |     |
|    |      DuplicateDetector                                  |     |
|    +--------------------------------------------------------+     |
|                 |                        |                        |
|         sync    |                        |  async                 |
|                 v                        v                        |
|   Submitter::submit()  :141    Jobs/ProcessFatooraSubmission      |
|     :183 branch on type          queue 'zatca-submissions'        |
|       B2B -> CLEARANCE           tries=3, backoff [10,60,300]     |
|             (blocking)           failed() :407 -> state 'failed'  |
|       B2C -> REPORTING                    -> event InvoiceFailed  |
|             validateReportingDeadline :274                        |
|             24h, config fatoora.reporting.deadline_hours          |
|                 |                                                 |
|                 v  offline / unreachable                          |
|   OfflineFallback -> OfflineQueue (table offline_queue)           |
|     Schedule: fatoora:process-offline every 5 min                 |
|               routes/console.php:73                               |
|                 |                                                 |
|                 v                                                 |
|   ClearanceState::                                                |
|     CLEARED | REPORTED | WARNING | REJECTED | TIMEOUT             |
|     :119  reporting -> REPORTED / NOT_REPORTED                    |
|           clearance -> CLEARED  / NOT_CLEARED                     |
|                 |                                                 |
|                 v                                                 |
|   Submitter::updateInvoiceStatus() :392                           |
|     clearance_status, reporting_status, cleared XML :431          |
|   Events -> Listeners/DispatchInvoiceWebhook                      |
|     -> webhooks table, HMAC-signed delivery, webhook_logs         |
+-------------------------------+-----------------------------------+
                                | HTTPS
                                v
                 ZATCA Fatoora  (sandbox | simulation | production)
                 config/fatoora.php:29-31 - all three real URLs
```

## Named components

| Kind | Name | Location |
|---|---|---|
| Controller | `PipelineController` | `app/Domains/Pipeline/Http/Controllers/` |
| Controller | `ComplianceController`, `InvoiceController` | `app/Domains/.../Http/Controllers/` |
| Controller | `OnboardingController`, `BranchOnboardingController` | `Fatoora/Http/Controllers/` |
| Request | `PipelineSubmitRequest`, `CreateInvoiceRequest` | `.../Http/Requests/` |
| Service | `PipelineService`, `InvoiceDrafter`, `PipelineNotifier` | `app/Domains/Pipeline/Services/` |
| Service | 23 Fatoora services (listed in `00-map.md`) | `Fatoora/Services/` |
| Model | `Invoice`, `InvoiceSubmission`, `ChainState`, `ChainEntry`, `SubmissionIdempotency` | various |
| Job | `ProcessFatooraSubmission` | `Fatoora/Jobs/` |
| Queue | `zatca-submissions` (configurable, `fatoora.queue.name`) | `ProcessFatooraSubmission.php:69` |
| Events | `InvoiceFailed`, `BaseInvoiceEvent` subclasses | `Fatoora/Events/` |
| Listener | `DispatchInvoiceWebhook` | `Fatoora/Listeners/` |
| Scheduled | `fatoora:process-offline` (5 min), `fatoora:check-certificate` (daily 08:00), `fatoora:verify-hash-chain` (weekly Sun 02:00), `compliance:cleanup-offline-queue` (daily 04:00) | `routes/console.php:73-105` |

## Two entry paths, one engine

There is also a **direct** path that does not go through `Pipeline`:
`POST /v1/compliance/generate/{id}` then `submit/{id}`
(`routes/api/partner.php:54-61`), plus the tenant-facing
`InvoiceController::store` (`app/Domains/Invoice/Http/Controllers/InvoiceController.php:53`).
Both converge on the same `Submitter`, so there is one issuance
implementation, not two. That is the right shape.

## The one structural weakness

**Issuance is a separate step from creation, and the PIH is read outside any
lock.** `Submitter::generate()` reads `$previousHash` at
`app/Domains/Compliance/Fatoora/Services/Submitter.php:68` — *before* the
transaction it opens at `:84`. The accessor
(`app/Domains/Invoice/Models/Invoice.php:383-391`) deliberately skips unhashed
drafts, behaviour that is tested as correct
(`tests/Feature/Compliance/PreviousHashTest.php::test_unhashed_drafts_are_skipped`).

That is correct for a single sequential caller. Under two interleaved callers,
or whenever signing is deferred (the offline queue), two invoices can read the
same predecessor. See `06-risks.md` R-1.
