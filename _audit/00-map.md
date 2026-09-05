# 00 — Compliance Surface Map

**Audit date:** 2026-09-05 · **Auditor:** independent read-only pass
**Masaar HEAD:** `60c2fc9` (2026-08-24) on `main`, 7 modified files uncommitted (docs + one command)

---

## Correction to the brief, up front

Three of the premises in the audit request are wrong. Correcting them changes
where the rest of this audit points.

| Premise | Reality |
|---|---|
| "ZATCA logic lives in `./Zatca`" | **There is no `Zatca` directory.** `ls c:/laragon/www/` returns exactly three relevant entries: `Masaar`, `masaar-erp-backend`, `masaar-erp-frontend`. |
| "`c:\laragon\www\erp-backend` / `erp-frontend`" | Actual names are **`masaar-erp-backend`** and **`masaar-erp-frontend`**. The unprefixed paths do not resolve. |
| "four repos" | **Three.** |

The migrations you remembered — `payment_means_code`, `is_third_party`,
`is_nominal`, `is_export`, `is_summary`, `is_self_billed` — are real, and they
are **in Masaar itself**, not in a separate package:
`database/migrations/0080_invoices.php:32` (payment_means_code) and
`:50-54` (all five BT-3 flags, each carrying a comment naming its ZATCA bit).

So: **Masaar is the ZATCA implementation.** It is not a consumer of one.

---

## What Masaar is

A **standalone Laravel 12 / PHP 8.4 multi-jurisdiction e-invoicing compliance
API**, self-described in `README.md:1-3` as "a multi-jurisdiction e-invoicing
compliance API platform for GCC businesses".

- Not a package. It is a full Laravel application with `artisan`, `bootstrap/`,
  `routes/`, 17 migrations, and its own HTTP API.
- Not abandoned. **235 commits total, 130 in the last 90 days**, last commit
  12 days before this audit. This is the actively developed repo.
- `composer.json:11-17` — `php ^8.4`, `laravel/framework ^12.0`,
  `phpseclib/phpseclib ^3.0`, `tymon/jwt-auth ^2.2`. Pest 4 for tests.

Jurisdictions are first-class: `app/Domains/Compliance/` holds
`ComplianceRouter.php`, `Fatoora/` (Saudi, 16.5k LOC) and `FTA/` (UAE, early).

## Repo relationship

```
masaar-erp-frontend  (TypeScript, pnpm/turbo monorepo — apps/staff, apps/admin)
        │  HTTP
        ▼
masaar-erp-backend   (Laravel ERP: Sales, Manufacturing, Accounting, Inventory)
        │  HTTP — config/zatca-integration.php:5
        │  ZATCA_INTEGRATION_URL, default http://localhost:8001/api/v1
        │  client: app/Services/Compliance/MasaarClient.php
        ▼
Masaar               (compliance platform — owns ALL ZATCA logic)
        │  HTTPS
        ▼
   ZATCA Fatoora
```

**This is one system split across three repos, plus one product.** They are not
four separate products. The ERP is the invoice *source*; Masaar is the
compliance *engine*; the frontend is the ERP's UI.

### Who owns invoice issuance

**Both, at different layers — and this is the one genuine architectural
overlap.**

- `masaar-erp-backend` owns the *business* invoice.
  `app/Orchestrators/Sales/PostInvoiceOrchestrator.php:94` calls
  `handleZatcaSubmission()`, which at `:201` calls
  `MasaarClient::submitInvoice($invoice)` and writes back
  `compliance_status`, `compliance_uuid`, `compliance_hash`,
  `compliance_qr_code`, `compliance_response` (`:204-209`).
- **Masaar owns the compliance invoice** and everything ZATCA cares about:
  UBL XML, ICV, PIH, hashing, XAdES, TLV QR, CSID, submission.

The ERP holds **no** cryptography and **no** UBL. `masaar-erp-backend`'s
`app/Services/Compliance/ZatcaInvoiceTransformer.php` is a 159-line
model→array mapper, and `ZatcaClientV1.php` is a 46-line adapter. That is the
correct split. There is no duplicated crypto to delete.

## Tenancy

**Multi-tenant, structurally enforced.** `org_id` is on every compliance table.

- `database/migrations/0080_invoices.php:15` — `uuid('org_id')`, FK to
  `organizations` with `cascadeOnDelete` (`:71`).
- `app/Domains/Organization/Concerns/BelongsToTenant.php` + `TenantScope.php`
  apply a global scope; models opt in via the trait
  (e.g. `Fatoora/Models/ChainState.php:19`).
- Verified, not just present: `tests/Feature/Security/TenantIsolationTest.php`
  passes 7 cases including "missing tenant context yields no rows" and
  "created records inherit the active tenant".
- The hash chain is **per-organization**: `hash_chain_state` is keyed on
  `org_id` as its primary key (`database/migrations/0160_hash_chain.php:21`),
  with a comment at `Models/ChainState.php:14-16` explaining that a second row
  would mean two competing chain heads.

There is also a `branch_id` dimension (`0080_invoices.php:17`) for EGS-unit /
per-branch modelling.

## Where the compliance surface actually is

Everything ZATCA touches lives under **`app/Domains/Compliance/Fatoora/`**
(16,549 LOC). 23 services:

```
CertificateService   ChainRecorder      CircuitBreaker    ClearanceState
Connectivity         CredentialStore    CsidOnboarding    DocumentBuilder
DuplicateDetector    EcdsaSigner        InvoiceHasher     InvoiceValidator
KillSwitch           OfflineFallback    OfflineQueue      QrCodeGenerator
SubmissionGuard      SubmissionTracker  Submitter         TimestampValidator
TlvEncoder           VatPeriodTracker   XadesSigner       XmlBuilder
```

Plus `app/Domains/Invoice/` (the invoice model + ICV allocation) and
`app/Domains/Pipeline/` (the ERP-facing intake path).

## Verification status of this codebase

**The test suite runs and is green**, which is the single most important fact
for grading everything downstream:

```
PHP 8.4.12 · Tests: 727 passed, 24 skipped, 0 failed (1733 assertions) · 38.25s
```

Of the 24 skips, the important ones are the **conformance suite**:
`tests/Feature/Compliance/ZatcaConformanceTest.php` runs ZATCA's own Java SDK
over generated documents through four validators — XSD, EN16931, KSA
Schematron and the PIH chain (`tests/Fixtures/ZatcaSdk.php:26`). It skips
because `ZATCA_SDK_PATH` is unset. Commit `697ea28` records it being run and
forcing fixes across XAdES signing, `DocumentBuilder` and `XmlBuilder`. This is
an **external oracle**, and it is what carries the L2/L3 grading in
`01-summary.md`.

Caveat that matters: **the default `php` on this machine is 8.2.28**, which
fails `composer`'s platform check (`^8.4`). The suite only runs when PHP 8.4.12
is invoked explicitly from `c:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64`.
Anyone running `php artisan test` on this box gets a hard failure, not a
test run. That is an environment trap, not a code defect.

## Prior art in the repo

`docs/audit/` contains a substantial earlier audit (10 documents, e.g.
`09-WORK-MAP.md`, 369 lines, dated 2026-08-18). **This audit does not inherit
its conclusions** — every finding below was re-derived from the tree. Where I
agree with it I say so; where the tree has moved on, the tree wins.
