# 04 — Gap Matrix

**Status vocabulary.** VERIFIED requires a passing test or a real ZATCA
response. **No ZATCA API response has ever been received by this codebase** —
no CCSID, no clearance, no reporting. So no row is VERIFIED on that basis.

There is, however, a second and stronger-than-usual class of evidence:
`tests/Feature/Compliance/ZatcaConformanceTest.php` (378 lines) runs **ZATCA's
own Java SDK** over generated documents through four validators —
`STAGES = ['XSD', 'EN', 'KSA', 'PIH']` (`tests/Fixtures/ZatcaSdk.php:26`).
**It was skipped by default, and I ran it.** The SDK is on this machine
(`c:/Users/Shamil/Personal/Zatca/zatca-einvoicing-sdk-Java-238-R3.4.8/zatca-einvoicing-sdk-Java-238-R3.4.8`,
R3.4.8) along with OpenJDK 17. Result, reproduced during this audit:

```
ZATCA_SDK_PATH=<above>  php artisan test --filter=ZatcaConformanceTest
Tests: 1 failed, 23 passed (166 assertions) · 53.10s
```

**23 of 24 conformance tests pass against ZATCA's own validator.** Rows resting
on this are marked **VERIFIED (SDK)**. The one failure is a genuine, currently
open defect — see #13a below and `06-risks.md` R-16.

Test-suite baseline for every VERIFIED below, reproduced during this audit:
`PHP 8.4.12 - 727 passed, 24 skipped, 0 failed (1733 assertions), 38.25s`.
The 24 skips are the conformance suite (`ZATCA_SDK_PATH` unset) and POSIX
file-mode tests that cannot pass on Windows.

---

## GENERATION

| # | Requirement | Status | Evidence | What's missing |
|---|---|---|---|---|
| 1 | UBL 2.1 XML generated natively | **VERIFIED** | `Fatoora/Services/XmlBuilder.php:20-31` declares the five UBL/CAC/CBC/EXT/SIG namespaces; built with `DOMDocument`/`DOMElement` (`:33-35`), 1047 lines. Not string concatenation, not PDF-first. Exercised by `tests/Feature/Compliance/UblTotalsTest.php`, `XadesPropertiesTest.php` | — |
| 2 | XML validates against ZATCA XSD | **VERIFIED (SDK)** | `ZatcaConformanceTest::test_standard_invoice_matches_the_schema` and `test_the_authority_own_sample_passes` run the real UBL 2.1 XSD via `ZatcaSdk` (`tests/Fixtures/ZatcaSdk.php:26,31-50`). Commit `697ea28` records the run and the fixes it forced | **Two real gaps remain.** (a) No schema check exists **at runtime** — `InvoiceValidator::validateXml()` (`:518-531`) has `schemaValidate` commented out at `:526` and **zero callers**, so nothing validates on the issuance path. (b) `ZATCA_SDK_PATH` is in no `.env.example`, no CI workflow, no doc — the oracle is opt-in and currently silent |
| 3 | Standard (B2B) invoice | PRESENT-UNVERIFIED | Type code `388` + subtype `01`, `Fatoora/Http/Controllers/OnboardingController.php:244`; `tests/Feature/Compliance/InvoiceTypeCodeTest.php` covers the mapping | Never accepted by ZATCA |
| 4 | Simplified (B2C) invoice | PRESENT-UNVERIFIED | `388`/`02`, `OnboardingController.php:247` | Never accepted by ZATCA |
| 5 | Credit note — standard + simplified | PRESENT-UNVERIFIED | `381`/`01` `:245`, `381`/`02` `:248`; billing reference + reason enforced for BR-KSA-17 at `:256-262`; `tests/Feature/Compliance/CreditNoteTest.php` | Never accepted by ZATCA |
| 6 | Debit note — standard + simplified | PRESENT-UNVERIFIED | `383`/`01` `:246`, `383`/`02` `:249` | Never accepted by ZATCA |
| 7 | UUID per document | **VERIFIED** | `OnboardingController.php:265` `Str::uuid()`; `invoices.id` is `uuid` (`database/migrations/0080_invoices.php:14`); `invoice_submissions.zatca_uuid` (`0140_submissions.php`) | — |
| 8 | ICV — monotonic, gapless | **VERIFIED** | `Invoice::generateNextIcv()` `app/Domains/Invoice/Models/Invoice.php:216-234` — `DB::transaction` + `lockForUpdate()` on the **organizations** row `:219-222`, deliberately not on invoice rows (reasoning at `:196-214`). Unique index `invoices_org_icv_unique` (`0080_invoices.php:67`). 5 passing tests in `tests/Feature/Invoice/IcvAllocationTest.php` incl. `test_duplicate_icv_rejected`, `test_counter_is_per_org` | Gaplessness is not enforced by a constraint — a rolled-back transaction burns a number. ASSUMPTION: acceptable, since ZATCA checks monotonic sequence, not absence of gaps |
| 9 | PIH chained correctly | **PARTIAL** | Accessor `Invoice.php:383-391`, ordered by ICV, skipping unhashed. `ChainRecorder::record()` writes entry + head in one transaction (`:58-90`). 6 passing tests in `PreviousHashTest.php`. `hash_chain_history` unique on `(org_id, icv)` **and** on `invoice_id` (`0200_hash_chain_unique_icv.php:27-28`) | **The read at `Submitter.php:68` is outside the transaction opened at `:84`.** Two interleaved issuances can select the same predecessor. See `06-risks.md` R-1 |
| 10 | Invoice hash per spec | PRESENT-UNVERIFIED | `Fatoora/Services/InvoiceHasher.php`; `hash_algorithm` column `0080_invoices.php:46` | No ZATCA confirmation the canonicalisation matches |
| 11 | Arabic fields where mandated | **PARTIAL** | Arabic present on branches (`database/migrations/0070_branches.php`, `Organization/Models/Branch.php`) and referenced in `Fatoora/Services/DocumentBuilder.php` | **`organizations` has only `name`** (`0050_organizations.php:16`) — no `name_ar`. **`invoices` has only `buyer_name`** (`0080_invoices.php:28`) — no `buyer_name_ar`. Seller and buyer Arabic names are the ones ZATCA cares about |
| 12 | Zero-rated / exempt / out-of-scope | **VERIFIED** | `invoice_lines.tax_category` char(1) default `S`, `exempt_code`, `exempt_reason` (`0080_invoices.php:85-87`); VATEX reason codes in `config/fatoora.php` (e.g. `VATEX-SA-35` at `:330`); `tests/Feature/Invoice/ExemptLineTest.php` passes | — |
| 13 | Line + document rounding | **PARTIAL** | `InvoiceValidator::isValidDecimalPrecision()` `:511-516` enforces 2dp; amounts stored `decimal(12,2)`; `tests/Feature/Compliance/UblTotalsTest.php` passes | The validator method exists but I found no evidence it is invoked on the document-total path. ASSUMPTION: rounding is correct by column type rather than by explicit ZATCA half-up rule |
| 13a | Document-level discount (`cac:AllowanceCharge`) | **FAILING** | `ZatcaConformanceTest::test_document_level_discount_validates` **fails against SDK R3.4.8**: two advisories, `BR-KSA-EN16931-05` (percentage BT-94 must be provided when base amount BT-93 is) and `BR-KSA-EN16931-03` (amount must equal base x percentage / 100). Errors are clean — ZATCA *accepts* the document; these are warnings the test asserts must be empty | `XmlBuilder.php:460-472` deliberately emits **neither** `BaseAmount` nor `MultiplierFactorNumeric` (`grep -rn BaseAmount app/` confirms it is never emitted), reasoning that a flat discount has no percentage to state. The rules fire anyway. The pair appears to be required together rather than optional together. `invoices.discount_amount` (`0080_invoices.php:36`) means this affects a supported feature |
| 14 | Invoice-type flags map to UBL | **VERIFIED (SDK)** | All five columns with per-bit comments naming the ZATCA bit — `0080_invoices.php:50-54`. Consumed in `Fatoora/Config/FatooraConfig.php`, `Services/DocumentBuilder.php`, `Invoice/Http/Requests/CreateInvoiceRequest.php`, `Pipeline/Services/InvoiceDrafter.php`. **`ZatcaConformanceTest::test_subtype_flags_match_the_authority`** puts the emitted bitstring through ZATCA's Schematron | Only checked when the SDK is present. The `ZatcaSdk` docblock notes a missing conformance test is "what let BT-3's business process stay wrong" — so this row was a real defect once |

## CRYPTOGRAPHY

| # | Requirement | Status | Evidence | What's missing |
|---|---|---|---|---|
| 15 | ECDSA secp256k1 keygen | PRESENT-UNVERIFIED | `app/Console/Commands/FatooraGenerateCsr.php:326` `EC::createKey('secp256k1')` via phpseclib3; `Fatoora/Services/EcdsaSigner.php`; `tests/Feature/Compliance/CsrGenerationTest.php` passes | Curve choice is right; no ZATCA acceptance |
| 16 | CSR with correct OIDs + template | PRESENT-UNVERIFIED | `Fatoora/Services/CertificateService.php:26` `OID_INVOICE_TYPE = 1.3.6.1.4.1.311.20.2`; `:158` `1.3.6.1.4.1.311.20.2 = ASN1:PRINTABLESTRING:ZATCA-Code-Signing`; `:133` `organizationIdentifier = 2.5.4.97`; `:68` passes `getOrganizationIdentifier()`. Comment at `:61` records that an explicit `$dn` previously stopped this reaching the request | Never submitted to ZATCA's CA |
| 17 | XAdES signature embedded | **VERIFIED (SDK)** | `Fatoora/Services/XadesSigner.php` (1109 lines); scaffold built in `UBLExtensions` via `XmlBuilder::addSignatureExtension()` called at `XmlBuilder.php:49` — the comment at `:43-51` records it previously had no callers, so the signature landed under the root where no verifier looks. `XadesPropertiesTest.php` + `Phase2SigningTest.php` pass; commit `697ea28` lists XAdES signing among the areas the SDK run forced fixes in | Signature placement and structure are checked by the SDK. The signature is still not verified by ZATCA's *service*, only its offline validator |
| 18 | Cryptographic stamp applied | PRESENT-UNVERIFIED | `signature_algorithm` / `cert_id` columns `0080_invoices.php:44,42`; stamp produced in `DocumentBuilder` | — |
| 19 | TLV base64 QR, Phase-2 tag set | **VERIFIED** | `Fatoora/Services/QrCodeGenerator.php:50-70` — all 9 tags: 1 seller, 2 VAT, 3 timestamp, 4 total, 5 VAT total, 6 hash, 7 signature, 8 public key, 9 cert signature. Phase 1 (5 tags) at `:33-48`. `TlvEncoder.php:30-51` is correct `chr(tag).chr(len).value` with bounds checks. `tests/Feature/Compliance/SellerNameBytesTest.php` covers multibyte length | — |
| 20 | Keys outside repo, encrypted at rest | **PARTIAL** | `Fatoora/Services/CredentialStore.php:88-95` — `encryptString` before `disk()->put()`; disk configurable `:40`; rotation via `masaar:rotate-credential-key` (`app/Console/Commands/RotateCredentialKey.php`); `tests/Feature/Security/CredentialStoreTest.php` + `CredentialKeyTest.php` pass | **One key for every tenant**, falling back to `APP_KEY` (`config/fatoora.php:90`). No KMS. See `06-risks.md` R-2 |
| 21 | No keys/OTPs/CSIDs in git | **VERIFIED** | `git ls-files` finds 4 `.pem`, all in `tests/Fixtures/Certificates/`: `ca.pem`, `good.pem`, `revoked.pem` are `BEGIN CERTIFICATE` only, `crl.pem` is a CRL — **zero `PRIVATE KEY` blocks in any of them**. `git log --all --diff-filter=A` shows only `.env.example` and `docker/.env.template` ever added. `.gitignore:10-11` covers `.env` and `.env.*`. A local `.env` exists, untracked (contents not read) | — |

## FATOORA INTEGRATION

| # | Requirement | Status | Evidence | What's missing |
|---|---|---|---|---|
| 22 | CCSID flow | PRESENT-UNVERIFIED | `Fatoora/Services/CsidOnboarding.php:37` `requestComplianceCsid()`; wired to `OnboardingController` and `BranchOnboardingController`; `tests/Feature/Compliance/OnboardingFlowTest.php`, `OnboardingTlsTest.php` pass | **Never executed against ZATCA. No CCSID exists.** This is the L4 blocker |
| 23 | Compliance suite, all six types | **PARTIAL** | `OnboardingController.php:226-250` builds all six with correct codes; `CsidOnboarding::runComplianceChecks()` `:82-114` iterates and aggregates. Offline, `ZatcaConformanceTest::test_every_compliance_document_validates` and `test_subtype_flags_match_the_authority` put generated documents through the SDK | **Never submitted to ZATCA's compliance endpoint.** Offline conformance is a strong proxy for document correctness; it does not obtain a CCSID or exercise the API |
| 24 | PCSID onboarding | PRESENT-UNVERIFIED | `CsidOnboarding::requestProductionCsid()` `:127`; orchestrated by `completeOnboarding()` `:169-185`, which refuses to proceed unless `$complianceResult['passed']` (`:181`) | Never run |
| 25 | PCSID renewal | **PARTIAL** | `app/Console/Commands/CheckCertificateExpiry.php` + daily schedule (`routes/console.php:87`); `hash_chain_state.cert_transition` JSON column (`0160_hash_chain.php:20`) and `ChainState` cast (`Models/ChainState.php:44`) exist for cert rollover; `tests/Feature/Compliance/CertificateHealthTest.php` passes | Monitoring and the data model for transition exist; **no renewal call path** — nothing invokes a PCSID renewal endpoint |
| 26 | Clearance API (B2B), blocking | **VERIFIED** | `Fatoora/Services/Submitter.php:183-190` branches on type; comment at `:185` "B2B: Submit for clearance"; `invoice_submissions.submission_type` enum `clearance|reporting` (`0140_submissions.php:19`); `tests/Feature/Compliance/SubmissionPathTest.php` passes | — |
| 27 | Reporting API (B2C), 24h | **VERIFIED** | `Submitter.php:192` reporting branch; `validateReportingDeadline()` `:274-315` reads `fatoora.reporting.deadline_hours` (default 24) and `enforce_deadline`, warns when approaching `:311`, throws when exceeded `:295` | — |
| 28 | sandbox / simulation / production | **VERIFIED** | `config/fatoora.php:21` `ZATCA_ENVIRONMENT`; `:29-31` all three real gateway URLs; `Submitter::validateEnvironment()` `:351`; `tests/Feature/Compliance/SmokeTest.php` passes | — |
| 29 | CLEARED/REPORTED/WARNING/ERROR distinct | **VERIFIED** | `Fatoora/Services/ClearanceState.php:25-36` states; `:119-120` maps **per document type** — reporting to `REPORTED`/`NOT_REPORTED`, clearance to `CLEARED`/`NOT_CLEARED`; the comment at `:11-13` states that only CLEARED is terminal for one and REPORTED for the other. `invoice_submissions.state` enum carries all ten states incl. `warning` (`0140_submissions.php:7`); separate `zatca_warnings` and `zatca_errors` JSON columns (`:17-18`); `tests/Feature/Compliance/ClearanceTimestampTest.php` passes | — |
| 30 | Retry + dead-letter queue | **PARTIAL** | `Jobs/ProcessFatooraSubmission.php:50,63` `tries` from config; `backoff()` `:92-95` `[10,60,300]`; `failed()` `:407-440` sets state `failed`, updates idempotency, logs, fires `InvoiceFailed`. Laravel `failed_jobs` table exists (`database/migrations/0030_jobs.php`). `tests/Feature/Compliance/SubmissionJobTest.php`, `QueueRoutingTest.php` pass | No dedicated DLQ table or replay command. `grep -rni "dead.letter\|dlq"` returns nothing. Recovery from `failed_jobs` is manual |
| 31 | Full request/response audit log | **VERIFIED** | `audit_logs` table (`0090_audit_logs.php`); `submission_state_logs` table records every transition (`0140_submissions.php`); `logStateTransition()` in the job; raw payload retained in `invoices.zatca_response` JSON (`0080_invoices.php:48`) and `zatca_warnings`/`zatca_errors`; `tests/Feature/Security/SecurityAuditTest.php` passes incl. "audit entries never carry the secret" | — |

## STORAGE & AUDIT

| # | Requirement | Status | Evidence | What's missing |
|---|---|---|---|---|
| 32 | Signed XML archived, retention | **PARTIAL** | `invoices.signed_xml` `longText` (`0080_invoices.php:41`) | **No retention policy in code.** `grep -n "retention" config/fatoora.php` finds only a VATEX description string. ZATCA requires e-invoices be retained **6 years** from the end of the tax period (and 11 years for capital-asset-related records). Nothing enforces, records, or documents this |
| 33 | Tamper-evident, no hard deletes | **VERIFIED** | `Invoice::boot()` `app/Domains/Invoice/Models/Invoice.php:162-171` — `deleting` throws unless status is Draft; `:173-192` — `updating` throws on any change to a finalized field, naming the fields. Legal-hold columns on `organizations`: `hold_ref`, `legal_hold_at`, `hold_expires_at` (`0050_organizations.php:26-28`). Hash chain is the tamper evidence; `fatoora:verify-hash-chain` runs weekly | `invoices` has **no `deleted_at`** — deletion of drafts is hard. Acceptable, since drafts are not issued documents |
| 34 | ZATCA-cleared XML is what is stored/sent | PRESENT-UNVERIFIED | `Submitter::clearedXml()` `:431` extracts the returned document; `updateInvoiceStatus()` `:392-430` writes it back; comment at `:409` — "Only clearance returns a document — reporting acknowledges one" | Correct in principle; unproven because no clearance response has ever been received. **Nothing verifies the buyer-facing copy is the cleared one rather than the locally signed one** |
| 35 | Retrievable by date, VAT, UUID | **VERIFIED** | `invoices_issue_date_idx`, `invoices_org_created_idx`, `invoices_invoice_number_index`, PK on `id` (uuid) — `0080_invoices.php:60-69`; `organizations_vat_number_index` (`0050_organizations.php:37`); `invoice_submissions` indexes incl. `zatca_uuid` | VAT-number retrieval is one join away (invoices carry `org_id`, not seller VAT). Adequate |

## OPERATIONS

| # | Requirement | Status | Evidence | What's missing |
|---|---|---|---|---|
| 36 | EGS units as first-class entities | **VERIFIED** | `branches` table (`0070_branches.php`) with Arabic fields; `invoices.branch_id` FK + index (`0080_invoices.php:17,61,69`); `compliance_profiles` table (`0060_compliance_profiles.php`) with `invoices.profile_id`; per-branch credentials in `CredentialStore` (branch arg on `get`/`put`/`certificate`); `Submitter::incrementBranchInvoiceCount()` `:570`. `tests/Feature/Pipeline/BranchRoutingTest.php`, `Compliance/BranchReadinessTest.php` pass | — |
| 37 | Repeatable per-unit onboarding | **VERIFIED (as code path)** | `Fatoora/Http/Controllers/BranchOnboardingController.php:120-126` runs the full compliance-check flow per branch; `tests/Feature/Compliance/BranchOnboardingTest.php` passes | Repeatable by construction; never executed for real |
| 38 | Certificate expiry monitoring + alerting | **VERIFIED** | `app/Console/Commands/CheckCertificateExpiry.php` — email at `:227` (`Mail::raw` to admin list), Slack at `:189,247-252`; scheduled daily 08:00 with `--notify` (`routes/console.php:86-89`); `tests/Feature/Compliance/CertificateHealthTest.php` and `Architecture/ScheduledCommandTest.php` pass | — |
| 39 | Submission failure alerting | **PARTIAL** | `ProcessFatooraSubmission::failed()` `:432` `Log::error` + `InvoiceFailed` event `:436`; `Listeners/DispatchInvoiceWebhook` delivers HMAC-signed webhooks; `tests/Feature/Webhook/WebhookDeliveryTest.php` passes | Alerting is **outbound to the tenant's webhook**. Nothing alerts **the operator** — no email/Slack on submission failure the way there is for certificate expiry. A tenant with no webhook configured fails silently to everyone |
| 40 | Reconciliation issued vs cleared vs reported | **ABSENT** | `app/Console/Commands/VerifyHashChain.php` checks the chain's **internal** consistency only (`:124-125` compares recorded `previous_hash` values) | No command, job or report compares issued invoices against ZATCA-acknowledged ones. For B2C — non-blocking, 24h window — a stalled queue is invisible. See `06-risks.md` R-4 |
| 41 | Runbook for ZATCA downtime | **PARTIAL** | The **mechanism** is strong: `Fatoora/Services/CircuitBreaker.php`, `Connectivity.php`, `OfflineFallback.php`, `OfflineQueue.php`, `KillSwitch.php`; `fatoora:process-offline` every 5 min; `tests/Feature/Compliance/OfflineFallbackTest.php`, `OfflineQueueTest.php`, `CircuitBreakerTest.php`, `KillSwitchTest.php` all pass. `routes/console.php:17` points at `docs/PRODUCTION-READINESS.md` | A **document** telling a human what to do — who to tell, when to throw the kill switch, how to drain the queue afterwards, what to do at hour 23 of a B2C window. The code handles it; the operator has no written procedure |

---

## Tally

| Status | Count | Items |
|---|---|---|
| **VERIFIED** | 17 | 1, 7, 8, 12, 17, 19, 21, 26, 27, 28, 29, 31, 33, 35, 36, 37, 38 |
| **VERIFIED (SDK)** | 3 | **2**, 14, and the schema half of 10 |
| **PRESENT-UNVERIFIED** | 10 | 3, 4, 5, 6, 10, 15, 16, 18, 22, 24, 34 |
| **PARTIAL** | 10 | 9, 11, 13, 20, 23, 25, 30, 32, 39, 41 |
| **ABSENT** | 1 | **40 (reconciliation)** |
| **FAILING** | 1 | **13a (document-level discount)** — reproduced against ZATCA's validator |

The corrected picture: **document generation is in good shape and externally
checked**. What is untouched is the **API relationship with ZATCA** (#22, #23,
#24, #34) and **operating the thing** (#39, #40, #41).

Two items deserve emphasis over the rest:

- **#40 (reconciliation) is the only outright ABSENT row**, and it is the one
  that will hide a production failure. Nothing compares what you issued against
  what ZATCA acknowledged.
- **#2 is VERIFIED at test time and ABSENT at runtime.** The distinction
  matters: your documents are known-good today because someone ran the SDK by
  hand, not because the system checks them. Nothing stops a future change from
  emitting an invalid document and signing it.
