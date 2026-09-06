# Masaar — GCC E-Invoicing Compliance Platform

A multi-jurisdiction e-invoicing compliance API platform for GCC businesses.

## Supported Jurisdictions

| Country | Authority | System | Status |
|---------|-----------|--------|--------|
| 🇸🇦 Saudi Arabia | ZATCA | Fatoora Phase 2 | 🟡 Feature complete — not yet validated against ZATCA |
| 🇦🇪 UAE | FTA | Peppol PINT AE | 🚧 In development (mandate: 2027-01-01) |
| 🇶🇦 Qatar | GTA | — | 📋 Planned |

> **Production readiness.** The Saudi pipeline — UBL generation, ICV/PIH hash
> chaining, XAdES signing, TLV QR, CSID onboarding and submission — is built,
> and the parts of it that can be checked without ZATCA's own fixtures are:
> signatures verify against the certificate in the document, the QR's tags match
> the document beside them, and the UBL totals satisfy their own arithmetic.
>
> That wording used to be "built and covered by tests", which was true and
> misleading. Tests existed; they did not check these things, and until recently
> the signature was computed over an empty string, certificate requests could not
> be generated, and every tax subtotal declared a base that included its own tax.
>
> Documents are now checked against ZATCA's own validator rather than against
> our reading of it — see [Conformance](#conformance) below. That check found
> nine defects the internal suite could not, because none of those tests knew
> what the authority requires.
>
> Still outstanding: signing keys are not yet held in a managed KMS, and a live
> submission has not been made — the conformance run uses a self-signed
> certificate, so the certificate, QR and PIH checks it performs are not
> exercised. See [`docs/audit/09-WORK-MAP.md`](docs/audit/09-WORK-MAP.md) for
> the current gap list before deploying to production.

## Repository Structure

```
Masaar/
├── platform/        ← This directory: Compliance API (Laravel 12, PHP 8.4)
├── erp/             ← ERP backend (separate repo, future git submodule)
├── sdks/            ← Client SDKs (PHP, TypeScript, Python, Java, Go, ...)
└── docs/
    ├── sa/          ← Saudi Arabia (Fatoora) documentation
    ├── ae/          ← UAE (FTA) documentation
    ├── qa/          ← Qatar (GTA) documentation — planned
    └── architecture/ ← Platform design docs
```

> **Note:** The `platform/` directory is the root of this repository.  
> The monorepo parent (`Masaar/`) is `C:/laragon/www/Masaar` on the development machine.

## Quick Start

**Requires PHP 8.4+** — the dependency tree (Symfony 8, Pest 4) will not install
on 8.3 or below.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate
php artisan db:seed
php artisan serve
```

Run the test suite:

```bash
php artisan test
```

## Conformance

The test suite checks that the code does what the code intends. That is worth
having and it is not compliance: it cannot tell you that BT-23 must read
`reporting:1.0`, because nothing in this repository knew. ZATCA's own Java SDK
knows, and `ZatcaConformanceTest` runs it over documents this platform
generates — the UBL 2.1 schema, the CEN EN 16931 rules, ZATCA's Schematron, and
the PIH check.

The SDK is a licensed download that cannot be committed, so these tests skip
unless you point at one:

```bash
ZATCA_SDK_PATH=/path/to/zatca-einvoicing-sdk-Java-238-R3.4.8 php artisan test
```

Point it at the directory holding `Apps/` and `Data/`. Java 17+ must be on the
PATH. Without it the suite still runs; the conformance tests report as skipped,
which is why CI is unaffected.

What it covers: the six documents ZATCA's compliance check requires (standard
and simplified — invoice, credit note, debit note), all five BT-3 sub-type
flags, zero-rated, exempt and out-of-scope lines, a healthcare supply billed to
a citizen, foreign currency, a document-level discount, and a discount spread
across two tax categories. One test validates ZATCA's own sample invoice, so a
broken harness reports itself as broken rather than as a broken invoice.

Two things about the SDK itself, both of which cost an afternoon:

- It reads `SDK_CONFIG` and `FATOORA_HOME`. Without `SDK_CONFIG` it dies in
  `Config.readResourcesPaths` with a NullPointerException that reads like a
  malformed invoice and is not. The test sets both per-process.
- **Do not run its `install.bat`.** It executes `SETX PATH ""` and then rebuilds
  PATH from `%PATH%`, which truncates at 1024 characters and can destroy a
  Windows PATH. Nothing needs it; the two variables above are the whole setup.
- Moving the SDK after installing it leaves absolute paths in
  `Configuration/config.json` pointing at the old location. Repoint them or the
  validator fails on every document.

What the conformance run does **not** establish: it signs with a self-signed
certificate, so the SDK's certificate, QR-signature and PIH-chain checks cannot
pass and are excluded. Those need a real CSID from the Fatoora portal.

## Scheduled Tasks

One cron entry drives all of them:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` prints the live schedule, which is the only thing
that cannot drift. What it currently registers:

| When | Task |
|------|------|
| Every 5 min | `fatoora:process-offline --limit=50` — drain the offline queue |
| Every 15 min | `compliance:index-health --alert` |
| Hourly | `license:cleanup-rate-limits` |
| Hourly | `license:report-usage` |
| Daily 00:00 | `license:check-expiration` |
| Daily 04:00 | `compliance:cleanup-offline-queue` |
| Daily 08:00 | `fatoora:check-certificate --notify` |
| Weekly Sun 02:00 | `fatoora:verify-hash-chain` |
| Monthly 1st 03:00 | `compliance:partition-maintenance --create-future --months-ahead=2` |

Run once when connecting a taxpayer, not scheduled:

```bash
php artisan fatoora:onboard --step=full --otp=<otp> --target=simulation
```

The remaining commands are operator tools rather than scheduled work:
`fatoora:generate-csr`, `fatoora:validate`, `fatoora:sandbox-test`,
`license:generate`, `license:status`, `masaar:openapi`, `masaar:sdk-types` and
`masaar:rotate-credential-key`.

## Documentation

- [Saudi Arabia (Fatoora)](docs/sa/README.md)
- [UAE (FTA)](docs/ae/README.md)
- [Qatar (GTA)](docs/qa/README.md)
- [Adding a Jurisdiction](docs/architecture/ADDING-A-JURISDICTION.md)
- [Design Spec](docs/superpowers/specs/2026-04-02-masaar-multi-jurisdiction-design.md)

## SDKs

Client libraries live in [`sdks/`](sdks/). **None are published to a package
registry yet** — use them by vendoring the source. They are hand-written against
the API rather than generated, so treat the HTTP API and
[`docs/openapi.yaml`](docs/openapi.yaml) as authoritative where they disagree.

| SDK | Status | Notes |
|-----|--------|-------|
| [Java](sdks/java/) | 🟢 Most complete | Typed models, resource classes, exception hierarchy |
| [PHP](sdks/php/) | 🟡 Single-file client | Covers a subset of the API surface |
| [TypeScript](sdks/typescript/) | 🟡 Single-file client | Intended Tier-1 target |
| [Python](sdks/python/) | 🟡 Single-file client | |
| [Rust](sdks/rust/) · [Go](sdks/go/) · [Kotlin](sdks/kotlin/) · [Swift](sdks/swift/) · [.NET](sdks/dotnet/) · [Ruby](sdks/ruby/) · [Dart](sdks/dart/) | 🟠 Skeleton | One client file each; no tests, unverified against a live API |
| JavaScript | 🔴 Not implemented | Use the TypeScript SDK — it compiles to JavaScript |

Known gaps, tracked in [`docs/audit/`](docs/audit/): the SDKs cover a subset of
the API, carry no automated tests, and several still use the platform's former
name in class names. The intended direction is to generate them from the OpenAPI
specification rather than maintain eleven by hand.

## License

Commercial use requires registration. See [LICENSE](LICENSE) and [TERMS](TERMS.md).
