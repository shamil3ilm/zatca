# 06 — Risks

Includes Step 6 (chain integrity under concurrency) and Step 7 (failure paths).

| ID | Risk | Severity | Evidence | Fix | Effort |
|---|---|---|---|---|---|
| ~~R-1~~ | ~~Two issuances can select the same PIH~~ **FIXED** `0bcd7bd` | — | Was proven by `ChainForkTest`; counter now allocated at issuance under the org-row lock that reads the predecessor. Restoring the old hook fails 3 of 5 tests | Done | — |
| R-2 | The submitted document can differ from the archived one | **CRITICAL** | `Submitter.php` re-signs in `submit()`; `ProcessFatooraSubmission` builds a third document of its own | Submit the stored `signed_xml` | 3–6h |
| R-17 | **Three separate paths build the document** — `Submitter::generate()`, `Submitter::submit()`, `ProcessFatooraSubmission::handle()` | **HIGH** | Found while fixing R-1: the queue path submitted ICV 0 and the genesis PIH because it never issued. Now guarded, but three builders remain | Collapse to one | 6–10h |
| R-3 | One encryption key covers every tenant's signing key | **HIGH** | `CredentialStore.php:57-72`, `config/fatoora.php:90` | Per-tenant DEKs | 16–24h |
| R-4 | No reconciliation — a stalled B2C queue is invisible | **HIGH** | absence; `VerifyHashChain.php:124` | Reconciliation command + alert | 8–12h |
| R-5 | Schema validation is test-time only, opt-in, and undocumented | **MEDIUM** | `ZatcaSdk.php:31-50`; `ZATCA_SDK_PATH` absent from `.env.example`/CI/docs; `InvoiceValidator.php:518-531` has 0 callers | Document + wire the SDK; later add a runtime check | 2–3h |
| R-6 | Offline queue detects chain advance and does nothing | **HIGH** | `OfflineQueue.php:421-430` | Act on the check | 6–10h |
| R-7 | Operator is never alerted to submission failure | **MEDIUM** | `ProcessFatooraSubmission.php:432-436` | Operator channel | 3–4h |
| R-8 | 402 uncommitted migration deletions in erp-backend | **MEDIUM** | `git status` | Commit to a branch | 15min |
| R-9 | Suite cannot run on the default PHP | **MEDIUM** | php 8.2.28 vs `composer.json:11` | Fix PATH / document | 15min |
| R-10 | Seller VAT nullable, non-unique, unvalidated | **MEDIUM** | `0050_organizations.php:17` | Constraints (`05` M-3) | 2–3h |
| R-11 | No retention policy for signed XML | **MEDIUM** | absence | Policy + archive job | 4–8h |
| R-12 | Arabic party names not modelled | **MEDIUM** | `0050:16`, `0080:28` | Migration `05` M-1 | 3–5h |
| R-13 | Buyer address is free text | **MEDIUM** | `0080_invoices.php:30` | Migration `05` M-2 | 4–6h |
| R-14 | Invoice-type bit mapping asserted only by comment | **LOW** | `0080:50-54` | One table-driven test | 2–3h |
| R-15 | No written ZATCA-downtime runbook | **LOW** | absence | Write it | 2–4h |
| ~~R-16~~ | ~~Conformance suite red on document-level discount~~ **CLOSED during this audit** | — | Was reproduced (23/1); fixed by `XmlBuilder::breakdown()` `:598` while this was being written. **25/25 conformance tests now pass** | Done | — |

---

## STEP 6 — Chain integrity under concurrency

### The verdict

> **ICV allocation is safe. PIH linkage is not.**

Two concurrent invoice creations **cannot** produce a duplicate ICV. Two
concurrent *issuances* **can** produce two invoices claiming the same
predecessor, which is a broken chain and a ZATCA rejection.

### Why ICV is safe (and it is genuinely well done)

`app/Domains/Invoice/Models/Invoice.php:216-234`:

```php
return DB::transaction(function () use ($organizationId) {
    DB::table('organizations')->where('id', $organizationId)
        ->lockForUpdate()->first();          // :219-222
    $highest = static::withoutTenantScope(
        fn () => static::query()->where('org_id', $organizationId)->max('icv')
    );
    return ((int) $highest) + 1;
});
```

Three things are right, and one of them is subtle:

1. **The lock is on the `organizations` row, not on invoice rows.** The
   docblock at `:200-206` explains why: `SELECT MAX(icv) ... FOR UPDATE` locks
   nothing for an organization's *first* invoice, so two concurrent requests
   both read no rows and both allocate 1. The organization row always exists.
   This is the exact bug most implementations ship with.
2. **The tenant scope is lifted** (`:229-231`), so a request without tenant
   context cannot read zero rows and restart the counter at 1.
3. **`invoices_org_icv_unique`** (`0080_invoices.php:67`) is the backstop — a
   collision fails the INSERT rather than corrupting the chain.

Both real creation paths wrap this in an outer transaction, which is what the
docblock at `:207-211` requires so the savepoint holds the lock until after the
INSERT:

- `app/Domains/Pipeline/Services/InvoiceDrafter.php:46` — `DB::transaction`
- `app/Domains/Invoice/Http/Controllers/InvoiceController.php:53` — `DB::transaction`

Verified by 5 passing tests including `test_duplicate_icv_rejected` and
`test_counter_is_per_org`.

### Why PIH is not safe — R-1

The PIH is **not** stored on the invoice. It is derived
(`Invoice.php:383-391`):

```php
return static::withoutTenantScope(fn (): ?string => static::query()
    ->where('org_id', $this->org_id)
    ->where('icv', '<', $this->icv)
    ->whereNotNull('hash')          // <-- skips unhashed drafts
    ->orderByDesc('icv')
    ->value('hash'));
```

`whereNotNull('hash')` is deliberate and tested
(`PreviousHashTest::test_unhashed_drafts_are_skipped`). It is also the hazard.

In `Submitter::generate()` the read happens at **`:68`** — *before* the
transaction that writes the hash and the chain entry opens at **`:84`**. There
is no lock between them.

**Failure sequence** (single tenant, two concurrent requests):

```
t0  A creates invoice icv=5   (ICV lock held and released)
t1  B creates invoice icv=6   (ICV lock held and released)
t2  B reaches Submitter::generate()  :68
      previous_invoice_hash -> highest icv < 6 with a hash
      icv=5 is still unhashed, so it is SKIPPED
      B gets H4  (the hash of icv=4)
t3  A reaches Submitter::generate()  :68
      previous_invoice_hash -> highest icv < 5 with a hash
      A gets H4
t4  Both transactions commit.

Result: icv=5 and icv=6 both declare PIH = H4.
        The chain forks. icv=6 does not reference icv=5.
```

**Nothing prevents this.** The unique constraints added in
`0200_hash_chain_unique_icv.php` are on `(org_id, icv)` and `invoice_id` —
they guarantee one entry per position and per document, but say nothing about
`previous_hash`. Two rows with different `icv` and the same `previous_hash`
satisfy both constraints.

`fatoora:verify-hash-chain` (weekly, `routes/console.php:89`) would eventually
detect it — a week later, after the documents have been submitted.

**How likely?** Low at one invoice at a time; **near-certain under POS load or
any batch import**, which is precisely the "POS/retail scenarios with
intermittent connectivity" the offline queue was built for
(`routes/console.php:66-69`).

**Fix.** Take the same organization-row lock for issuance that allocation
already takes, and move the PIH read inside it:

```php
DB::transaction(function () use ($invoice, ...) {
    DB::table('organizations')->where('id', $invoice->org_id)
        ->lockForUpdate()->first();
    $previousHash = $invoice->previous_invoice_hash;   // now serialised
    $complianceData = $this->compliance->generateComplianceData(...);
    $invoice->update([...]);
    $this->chain->record(...);
});
```

Signing inside a transaction lengthens it; measure before accepting. The
alternative — a dedicated advisory lock keyed on `org_id` — avoids holding a
row lock across a CPU-bound signature.

**Test to add first (it should fail):** two invoices at icv N and N+1, sign
N+1 before N, assert N+1's PIH equals N's hash rather than N-1's.

### R-6 — the offline queue knows about this and does nothing

`OfflineQueue.php:421-430`:

```php
if ($currentState && $invoice && $invoice->hash) {
    // The PIH in the queued invoice should match what was current at queue time
    // If chain has advanced, we may need to re-sign
    Log::debug('Validating queued item hash chain', [...]);
}
```

The comment states the exact condition. The body **only writes a debug log**.
Nothing compares the queued PIH to `$currentState->last_hash`; nothing
re-signs; the item proceeds regardless.

The ICV-conflict check immediately above it (`:384-398`) is real — it detects
the conflict and returns `action => 'regenerate_icv'`. The chain check was
written to the same shape and left unfinished.

---

## STEP 7 — Failure paths

### Does the system distinguish blocking clearance from non-blocking reporting?

**Yes, and better than most.** `Submitter.php:183-199` branches on
`$invoice->requiresClearance()`. `ClearanceState.php:119-120` maps responses
*per document type*:

```php
? ['REPORTED' => STATE_REPORTED, 'NOT_REPORTED' => STATE_REJECTED]
: ['CLEARED'  => STATE_CLEARED,  'NOT_CLEARED'  => STATE_REJECTED]
```

with a docblock at `:11-13` recording that only CLEARED is terminal for one and
REPORTED for the other, and that "treating a successful call as a terminal
state" is the mistake being avoided. `invoice_submissions.submission_type` is an
enum of exactly `clearance|reporting` (`0140_submissions.php:19`).

### Trace of each failure

| Failure | Handling | Verdict |
|---|---|---|
| **ZATCA unreachable** | `Connectivity` → `CircuitBreaker` → `OfflineFallback` → `offline_queue` table; `fatoora:process-offline` every 5 min (`routes/console.php:73`). Covered by `OfflineFallbackTest`, `OfflineQueueTest`, `CircuitBreakerTest` — all passing | **Good** — but see R-6, items resume with a possibly stale PIH |
| **400 rejection** | `ClearanceState` → `STATE_REJECTED`; `zatca_errors` json populated (`0140:18`); `last_error_code` + `last_error` (`:25-26`); `ErrorCode::getMaxRetries()` (`ProcessFatooraSubmission.php:279`) distinguishes retryable from terminal so a 400 is not retried into the ground | **Good** |
| **WARNING response** | Distinct `warning` state in the enum (`0140:7`), separate `zatca_warnings` column, `conditionally_accepted` in `clearance_state` (`:14`) | **Good** — genuinely distinguished, not folded into success |
| **Mid-request timeout** | `fatoora.timeout` / `connect_timeout` (`config/fatoora.php:100-101`); `timeout` state in `clearance_state`; `SubmissionIdempotency` prevents a retry double-submitting; `DuplicateDetector` as second defence; job `timeout` 120s (`ProcessFatooraSubmission.php:64`) | **Good** — this is the case most implementations get wrong |
| **Max retries exhausted** | `failed()` `:407-440` — state `failed`, idempotency updated, state transition logged, `Log::error`, `InvoiceFailed` event | **Adequate but see R-7** |

### Is anything silently swallowed?

I found **no empty catch blocks** and no swallowed exceptions on the submission
path. Errors are logged with context and re-thrown for queue retry
(`ProcessFatooraSubmission.php:192` — `throw $e; // Re-throw for queue retry`).

Three things are *quiet* rather than swallowed:

1. **R-6** — the offline chain check logs at `debug` and continues. On a
   production log level of `info` or above, it is invisible.
2. **R-7** — final failure raises `InvoiceFailed`, which
   `Listeners/DispatchInvoiceWebhook` turns into a tenant webhook. **There is
   no operator-facing alert.** Compare `CheckCertificateExpiry.php`, which
   emails (`:227`) *and* posts to Slack (`:247-252`). A tenant with no webhook
   configured, or a webhook endpoint that is itself down, means a permanently
   failed legal document that nobody is told about.
3. **R-4** — nothing reconciles. `VerifyHashChain` checks the chain against
   itself (`:124-125`), never against ZATCA.

### R-2 — the archived document may not be the submitted one

This is separate from R-1 and just as serious.

`Submitter::generate()` signs the document and stores it
(`:84-98` — `signed_xml`, `hash`, `qr_code`, `status = Issued`).

`Submitter::submit()` then **signs it again** (`:172-181`):

```php
$complianceData = $this->compliance->generateComplianceData(
    invoice: $invoice,
    organization: $organization,
    previousInvoiceHash: $invoice->previous_invoice_hash,   // re-read
    ...
);
```

and submits `$complianceData['xml']` — **not** the `signed_xml` it stored at
issuance. If any input changed between the two calls, the submitted document
differs from the archived one.

The PIH is exactly such an input. Given R-1's interleaving, an invoice can be
issued with PIH = H4 and submitted with PIH = H5. Then:

- `invoices.signed_xml` holds one document,
- ZATCA received a different one,
- `invoices.hash` and the `hash_chain_history` entry describe the first,
- and gap-matrix item #34 ("the ZATCA-cleared XML is what gets stored and sent
  to the buyer") is violated in the worst way — the buyer's copy and the
  authority's copy disagree.

Even without R-1 this is fragile: re-signing produces a new timestamp in the
XAdES `SigningTime`, so the two documents are unlikely to be byte-identical
regardless.

**Fix.** `submit()` should send the stored `signed_xml` and its stored `hash`,
not re-derive them. Issuance is the signing event; submission is transport.
Add a test asserting `submit()` transmits exactly the bytes in
`invoices.signed_xml`.

---

## Severity rationale

R-1 and R-2 are CRITICAL not because they will fire tomorrow — with one
invoice at a time they never fire — but because they are **silent, produce
legally invalid documents, and are discovered by ZATCA rather than by you**.
They are also the two cheapest to fix *now*, while there is no production data
and no client. After go-live, R-1 requires reconstructing a chain.

R-5 was downgraded from HIGH after finding
`tests/Feature/Compliance/ZatcaConformanceTest.php`, which runs ZATCA's own
validator (XSD, EN16931, KSA Schematron, PIH) over generated documents. Schema
validation is real and has been run — commit `697ea28` records it forcing fixes
across XAdES signing, `DocumentBuilder` and `XmlBuilder`. What remains is that
the oracle is **opt-in and invisible**: `ZATCA_SDK_PATH` appears in no
`.env.example`, no CI workflow and no document, so it is off by default and
nothing reminds anyone it exists. Separately, there is still no schema check on
the **runtime** issuance path — `InvoiceValidator::validateXml()` has zero
callers and its `schemaValidate` line is commented out at `:526`.


---

## R-16 — the conformance suite was red, and is now green

> **CLOSED.** Fixed during this audit by concurrent work on the tree. The
> apportionment fix landed in `XmlBuilder::breakdown()` (`:598`), which spreads
> a document-level discount across tax categories in proportion to each
> category's net and recomputes tax on the reduced base. All 25 conformance
> tests pass. **My proposed fix below was wrong** — ZATCA's own
> `Standard Invoice with Document Level Charge.xml` sample carries neither
> `BaseAmount` nor `MultiplierFactorNumeric`, so the original code comment was
> correct and the defect was arithmetic, not structural. Kept for the record.

### Original finding

Found by running it. The SDK is on this machine and the harness had simply
never been pointed at it in this environment.

```
ZATCA_SDK_PATH=c:/Users/Shamil/Personal/Zatca/zatca-einvoicing-sdk-Java-238-R3.4.8/zatca-einvoicing-sdk-Java-238-R3.4.8
php artisan test --filter=ZatcaConformanceTest
  -> Tests: 1 failed, 23 passed (166 assertions) - 53.10s
```

The failure, at `tests/Feature/Compliance/ZatcaConformanceTest.php:255`:

```
an advisory fired on a discounted invoice.
  BR-KSA-EN16931-05: Allowance/Charge percentage (BT-94, BT-101, BT-138, BT-143)
    must be provided when the allowance/Charge base amount (BT-93, BT-100,
    BT-137, BT-142) is provided.
  BR-KSA-EN16931-03: Allowance/Charge amount (BT-92, BT-99, BT-136, BT-141)
    must equal base amount * percentage / 100 if base amount and percentage exists.
```

**These are warnings, not errors.** `businessRules($result['errors'])` asserted
clean first (`:250-253`), so ZATCA's validator *accepts* the document. The test
then asserts no advisories fired, and two did.

### Why the existing fix did not take

`XmlBuilder.php:460-472` carries a deliberate decision to emit **neither**
element, with reasoning:

> "BT-93 and BT-94 travel together: BR-KSA-EN16931-05 requires the percentage
> whenever the base amount is given […] This emitted the base and […]
> deliberately skipped the percentage as 'optional' — which is true of the pair
> and not of one without the other, so every discounted invoice drew both
> rules. ZATCA's own document-level charge sample carries neither."

The reasoning is sound and the code does what it says — `grep -rn "BaseAmount"
app/` returns **only that comment**; the element is never emitted, at document
level (`addAllowanceCharge`, `:427-480`) or line level
(`buildLineAllowanceCharge`, `:860-890`).

**The rules fire regardless.** So dropping both did not silence them, which
means the SDK's Schematron is not gated on `BaseAmount` being present the way
the rule text reads. **ASSUMPTION:** the implementation asserts the presence of
`MultiplierFactorNumeric` on any `AllowanceCharge`, and the rule prose
describes the intent rather than the check.

### Recommended direction

Try the opposite of the current fix: emit **both** elements together —
`cbc:BaseAmount` equal to the pre-discount taxable amount and
`cbc:MultiplierFactorNumeric` equal to `amount / base * 100`, rounded so that
BR-KSA-EN16931-03's arithmetic (`amount = base x pct / 100`) holds exactly at
2dp. Then re-run the suite; it is a five-minute feedback loop now that the SDK
path is known.

If that also fails, the next step is to dump the generated `cac:AllowanceCharge`
and compare it element-by-element against ZATCA's own sample in
`<SDK>/Data/` — the harness already validates that sample
(`test_the_authority_own_sample_passes`, which **passes**), so a working
reference is available locally.

**Severity HIGH, not CRITICAL:** ZATCA accepts these documents today. But the
suite is red, and a red suite that nobody runs is how the BT-23 defect survived
715 passing tests in the first place.
