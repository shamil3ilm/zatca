# 02 — Inventory

Three repositories, not four. `c:\laragon\www\Zatca` does not exist.

---

## 1. Masaar — the compliance platform

**This is the repo that matters.**

| | |
|---|---|
| Framework | Laravel 12 (`laravel/framework ^12.0`), PHP `^8.4` — `composer.json:11-12` |
| Key deps | `phpseclib/phpseclib ^3.0` (EC crypto), `tymon/jwt-auth ^2.2` |
| Dev deps | Pest 4 (`pestphp/pest ^4.3`), Pint, Collision, Mockery, Faker |
| Frontend | Vite (`vite.config.js`), minimal — this is an API |
| Git | `main`, HEAD `60c2fc9` 2026-08-24, **235 commits total, 130 in last 90 days** |
| Remote | `github.com/shamil3ilm/masaar.git`, **0 unpushed**, **0 stashes** |
| Uncommitted | 7 files, all modified, no untracked: `README.md`, `app/Console/Commands/FatooraGenerateCsr.php`, and 5 files under `docs/` |
| App LOC | 35,195 PHP |
| Compliance LOC | **16,549** under `app/Domains/Compliance/` |
| Test LOC | 17,092 across **117 files** |
| Migrations | 17, sequentially numbered `0010`–`0200` |
| Tests | **727 passed, 24 skipped, 0 failed** (1733 assertions, 38.25s) on PHP 8.4.12. Skips = the ZATCA-SDK conformance suite (`ZATCA_SDK_PATH` unset) + POSIX file-mode tests that cannot pass on Windows |
| CI | `.github/workflows/ci.yml` + `build-and-push.yml` |
| Docker | `Dockerfile`, `docker-compose.yml`, `docker-compose.prod.yml`, `docker/` with nginx, supervisor, monitoring, entrypoint |
| `.env.example` | Present, 8434 bytes, enforced by `tests/Feature/Architecture/EnvExampleTest.php` |
| Files >1000 lines | 2: `Fatoora/Services/XadesSigner.php` (1109), `Fatoora/Services/XmlBuilder.php` (1047) |
| TODO/FIXME/HACK/XXX | **0** in `app/` |

### Layout, two levels

```
app/
  Console/Commands/     17 commands (FatooraGenerateCsr, FatooraOnboarding,
                        FatooraSandboxTest, FatooraValidate, VerifyHashChain,
                        CheckCertificateExpiry, RotateCredentialKey, ...)
  Domains/
    Audit/  Auth/  Compliance/  Invoice/  Licensing/  Logging/
    Organization/  Pipeline/  Platform/  Webhook/
  Http/  Models/  Providers/  Support/
database/migrations/    17 files, 0010_users -> 0200_hash_chain_unique_icv
routes/
  api.php (splitter), api/{public,tenant,partner,platform,deprecated}.php,
  console.php (schedule), web.php
tests/
  E2e/  Feature/{Api,Architecture,Compliance,Invoice,Licensing,
                 Organization,Pipeline,Platform,Security,Webhook}
docs/
  audit/ (10 prior audit docs)  sa/  ae/  qa/  architecture/  superpowers/
sdks/    11 language directories
```

### README vs reality

**Broadly honest, with one overstatement.** `README.md:9` claims Saudi is
"🟢 Feature complete — conformance suite pending", and the production-readiness
note at `:13-20` explicitly says it has "**not** yet been validated against
ZATCA's official conformance fixtures, and signing keys are not yet held in a
managed KMS". Both true, both verified above.

Where it undersells itself: the README never mentions
`tests/Feature/Compliance/ZatcaConformanceTest.php`, which runs **ZATCA's own
Java SDK** (XSD, EN16931, KSA Schematron, PIH) over generated documents and is
the strongest evidence in the repository. Neither `ZATCA_SDK_PATH` nor the
conformance suite appears in the README, `.env.example`, or any CI workflow —
so the one thing that most justifies the "feature complete" claim is invisible
to a reader and off by default.

Where it overstates: "conformance suite pending" reads as a formality. What is
actually pending is **every interaction with ZATCA's API** — no CCSID, no
clearance, no reporting response has ever been obtained (gap matrix #22-24).

The SDK table (`:65-73`) is refreshingly blunt — "🟠 Skeleton", "no tests,
unverified against a live API", "🔴 Not implemented". No complaint there.

### Notable

- **Architecture tests are a real asset.** 17 files in
  `tests/Feature/Architecture/` fail the build on structural drift:
  `NoShellOutTest`, `RouteAuthPostureTest` (every route must declare a guard),
  `OpenapiDriftTest` (spec must match the router in both directions),
  `ModelColumnTest`, `ConfigKeyTest`, `ScheduledCommandTest`, `DocLinkTest`.
  This is why the codebase has not rotted.
- **The environment trap.** Default `php` on this machine is **8.2.28**;
  `composer.json:11` requires `^8.4`. `php artisan test` fails the platform
  check. The suite only runs via
  `c:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe`.

**Verdict: actively developed, disciplined, not abandoned.**

---

## 2. masaar-erp-backend — the ERP

| | |
|---|---|
| Framework | Laravel 12, PHP `^8.2` (note: **lower than Masaar's `^8.4`**) |
| Key deps | `bacon/bacon-qr-code ^3.0`, `barryvdh/laravel-dompdf ^3.1`, `guzzlehttp/guzzle ^7.10`, `php-open-source-saver/jwt-auth ^2.8`, `pragmarx/google2fa-laravel ^2.3` |
| Git | `main`, HEAD `286285f` 2026-08-25, **48 commits total, 7 in last 90 days** |
| Remote | `github.com/shamil3ilm/masaar-erp-backend.git` |
| Uncommitted | **453 files** — see below |
| App LOC | ~272,000 PHP (very large) |
| Tests | 189 files |
| Migrations | 49 tracked |
| CI | `.github/workflows/ci.yml` |
| `.env.example` | Present |
| TODO/FIXME | 2 |
| Files >1000 lines | None |

### The uncommitted state is the headline

```
402  deleted    all in database/migrations/
 49  untracked  all in database/migrations/  (0010_accounting, 0020_admin,
                0030_core, 0040_core_2, 0050_core_3, 0060_accounting_2, ...)
  5  modified   .github/workflows/ci.yml, CLAUDE.md, README.md,
                tests/Feature/Accounting/AccountStatementTest.php,
                tests/Feature/Tax/Tds194QTest.php
```

**ASSUMPTION:** this is a migration squash — 402 dated migrations replaced by 49
consolidated, sequentially-numbered ones, mirroring the `0010`–`0200` scheme
Masaar already uses. It is **half-finished and entirely uncommitted.**

That is the single most fragile thing found in this audit. A stray
`git checkout .` destroys 49 hand-written files; a stray `git stash` hides the
deletion of 402. Neither state is recoverable from the remote. This should be
committed on a branch **today**, working or not.

### ZATCA surface

Correctly thin. `app/Services/Compliance/` holds 14 files, of which three are
ZATCA-related:

- `MasaarClient.php` — HTTP client to the Masaar platform
- `ZatcaClientV1.php` (46 lines) — adapter to the `ExternalApiClient` contract
- `ZatcaInvoiceTransformer.php` (159 lines) — Eloquent model → payload array

Plus `CircuitBreaker.php`, `app/Jobs/RetryComplianceSubmission.php`,
`app/Http/Controllers/Api/V1/Compliance/{OnboardingController,ZatcaWebhookController}.php`,
`app/Http/Middleware/VerifyZatcaWebhook.php`, and
`config/zatca-integration.php`.

**No cryptography. No UBL. No hash chain.** The split is correct — there is no
duplicated compliance logic to delete.

**Verdict: alive but slow (7 commits/90 days), and in a dangerous uncommitted
state.**

---

## 3. masaar-erp-frontend — the ERP UI

| | |
|---|---|
| Stack | TypeScript ^5.4, pnpm 9.15.4, Turborepo ^2.0, Node >=20 |
| Layout | `apps/{admin,portal,staff}` + `packages/{api-client,types,ui}` |
| Git | `main`, HEAD `407d740` 2026-08-24 (`ci: build and typecheck on every push`), **36 commits total, 4 in last 90 days** |
| Remote | `github.com/shamil3ilm/masaar-erp-frontend.git` |
| Uncommitted | 18 files |
| CI | `.github/workflows/ci.yml` — build + typecheck |
| Scripts | `dev`, `build`, `test`, `typecheck`, `e2e` (all via turbo) |

**Verdict: the least active of the three (4 commits in 90 days), but recently
touched and structurally sound. Not abandoned — dormant.**

---

## Cross-repo notes

- **PHP version mismatch.** Masaar needs `^8.4`; erp-backend declares `^8.2`.
  They cannot share a runtime image as declared. Fine while they are separate
  services; a problem the day you try to merge them.
- **No repo requires another as a dependency.** The coupling is HTTP only, via
  `ZATCA_INTEGRATION_URL`. That is a good boundary and worth keeping.
- **Masaar's README describes `erp/` as a "future git submodule"**
  (`README.md:24`). That has not happened, and `07-consolidation.md` argues it
  should not.
- **Nothing is abandoned.** All three have commits within the last 12 days.
  The activity is lopsided (130 / 7 / 4) but that reflects where the hard
  problem is, which is correct.
