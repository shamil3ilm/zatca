# 07 — Extractability & Consolidation Verdict

Covers Step 8 (extractability) and Step 9 (consolidation).

---

## STEP 8 — Extractability

### Verdict

> **Much closer than you probably think. The hard part is already decoupled.**
> Roughly **60–90 hours** to a standalone package that takes a plain invoice
> DTO and returns signed XML plus a submission result.

### The actual coupling

Of the 24 services in `app/Domains/Compliance/Fatoora/Services/`, exactly
**10 import an Eloquent model**:

| Depends on Eloquent | Free of Eloquent |
|---|---|
| `ChainRecorder` | `XmlBuilder` |
| `DocumentBuilder` | `XadesSigner` |
| `DuplicateDetector` | `EcdsaSigner` |
| `InvoiceValidator` | `TlvEncoder` |
| `OfflineFallback` | `QrCodeGenerator` |
| `OfflineQueue` | `InvoiceHasher` |
| `SubmissionGuard` | `CertificateService` |
| `SubmissionTracker` | `CsidOnboarding` |
| `Submitter` | `ClearanceState` |
| `VatPeriodTracker` | `TimestampValidator` |
| | `CircuitBreaker`, `KillSwitch`, `Connectivity`, `CredentialStore` |

**The entire cryptographic and document-generation core is already
model-free.** `XmlBuilder::build()` takes an `InvoiceXmlData` DTO
(`XmlBuilder.php:41`), not an `Invoice`. There are six DTOs already —
`InvoiceXmlData`, `AddressData`, `QrCodeData`, `CsrData`, `CsidResponse`,
`FatooraResponse`. That is not an accident; someone designed for this.

This is the part that is expensive to write and expensive to get right — a
1109-line XAdES signer, a 1047-line UBL builder, a TLV encoder with correct
multibyte length handling. It is **already** a library sitting inside an
application.

### What actually blocks extraction

1. **`DocumentBuilder` and `Submitter` are the seam.** Both take
   `Invoice` + `Organization` and orchestrate the pure services. They need to
   become DTO-in / DTO-out, with the Eloquent→DTO mapping moving to the
   application. ~20-30h; this is the bulk of the work.
2. **`ChainRecorder` needs an interface.** It writes `ChainEntry` and
   `ChainState` directly (`:63`, `:75`). Extract a `ChainStore` contract with
   an Eloquent implementation left behind in the app. ~8h.
3. **`CredentialStore` binds to Laravel's `Storage` and `Encrypter`**
   (`:40`, `:65`). Needs a `CredentialRepository` interface. ~6h.
4. **Laravel facades throughout.** 13 files use `Log`, 5 use `Cache`, 3 use
   `DB`. `Log` → PSR-3 (trivial). `Cache` → PSR-16 (easy). `DB` is only in the
   three services that also hold models, so it leaves with them. ~10h.
5. **`config('fatoora.*')`.** `FatooraConfig` already centralises most of it;
   turn it into a constructor-injected config object. ~6h.
6. **Tests move with it.** The compliance tests are `Tests\Feature` with
   `RefreshDatabase`; the pure ones can become unit tests with no database.
   ~10-15h, and this is where the payoff is — a package test suite that runs in
   two seconds instead of thirty.

### Recommended target shape

```
masaar/zatca  (standalone, no Laravel)
  Document\  XmlBuilder, DocumentBuilder, InvoiceHasher, InvoiceValidator
  Crypto\    EcdsaSigner, XadesSigner, CertificateService, CsrBuilder
  Qr\        TlvEncoder, QrCodeGenerator
  Client\    FatooraClient, CsidOnboarding, ClearanceState
  Contracts\ ChainStore, CredentialRepository, Clock, LoggerInterface
  Dto\       Invoice, Line, Party, Address, SigningCredentials, Result
```

Left in the application: `ChainRecorder` (Eloquent `ChainStore`),
`SubmissionTracker`, `OfflineQueue`, `DuplicateDetector`, `VatPeriodTracker`,
`SubmissionGuard` — all of which are about *your* persistence and workflow, not
about ZATCA.

### Should you do it now?

**No — not before the sandbox run.** Extraction is a refactor, and refactoring
ahead of the first real ZATCA response means doing it twice: the compliance
suite will change the shape of `DocumentBuilder`'s inputs. Do it *after* L4,
when the DTO fields have been validated by the authority rather than by you.

Two things worth doing **now**, because they cost little and make the later
extraction nearly mechanical:

- Stop adding Eloquent imports to the 14 currently-clean services. An
  architecture test would enforce this in ~1h — and you already have 17 such
  tests, so the pattern exists (`tests/Feature/Architecture/`).
- When fixing R-1 and R-2, put the lock in the *caller*, not inside
  `DocumentBuilder`. That keeps the seam clean.

---

## STEP 9 — Consolidation verdict

### Keep all three. Merge nothing. Delete nothing.

That is not a hedge — it is the opposite of what the framing expected, so here
is the reasoning.

**There is no overlapping work to consolidate.** The premise of the question
was four repos with duplicated effort. There are three, and the boundaries are
already correct:

| Repo | Owns | ZATCA code |
|---|---|---|
| `Masaar` | Compliance engine, all jurisdictions | 16,549 LOC |
| `masaar-erp-backend` | Business domain: Sales, Manufacturing, Accounting, Inventory | ~250 LOC of HTTP client + mapper |
| `masaar-erp-frontend` | UI for the ERP | none |

`masaar-erp-backend` holds **no cryptography, no UBL, no hash chain**. Its
entire compliance surface is `MasaarClient.php` (HTTP), `ZatcaClientV1.php`
(46-line adapter) and `ZatcaInvoiceTransformer.php` (159-line mapper). There is
nothing to delete because nothing is duplicated.

### Why merging would be actively harmful

1. **Masaar is the product; the ERP is a customer of it.** `README.md:1` calls
   Masaar a "compliance API platform for GCC businesses" and the SDK directory
   lists eleven client languages. That only makes sense if the ERP is *one*
   consumer among many. Merging destroys the thing you are building.
2. **The PHP versions already diverge.** Masaar requires `^8.4`
   (`composer.json:11`); erp-backend declares `^8.2`. They cannot share a
   runtime image as declared.
3. **Sizes differ by an order of magnitude.** erp-backend is ~272,000 LOC of
   app code against Masaar's 35,195. Merging buries the compliance engine
   inside an ERP.
4. **The HTTP boundary is the compliance boundary.** A signed, licensed,
   rate-limited, audited API surface between the invoice source and the signing
   engine is exactly what you want when an auditor asks who could have issued a
   document.

### What Masaar's README gets wrong

`README.md:24` describes `erp/` as a "**future git submodule**". **Do not do
this.** A submodule couples the release cycles of a product and its customer,
and gives you nothing the HTTP client does not already provide. Delete that
line.

### What to actually change

**1. Commit erp-backend's working tree today. (15 minutes, highest urgency
in this whole audit.)**
453 uncommitted files: 402 deleted migrations, 49 new consolidated ones, 5
modified. An unfinished migration squash existing *only* in a working
directory. Not on the remote, not stashed, not on a branch. One careless
command destroys it. Commit it to `chore/migration-squash` even half-finished.

**2. Decide what `masaar-erp-frontend` is for.**
4 commits in 90 days, three apps (`admin`, `portal`, `staff`). Masaar *also*
has its own `/admin` and `/portal` web routes (`routes/web.php`) with
`platform.admin` and `portal.tenant` guards, and a `DashboardController`. You
have two admin UIs. Not urgent — no client — but decide before building more of
either. **ASSUMPTION:** the frontend's `admin` is the ERP's, and Masaar's is
the platform's. If so they are genuinely different; say so in a README so you
do not re-litigate this in six months.

**3. Thin the SDKs from eleven to two.**
Not a repo change, but the same instinct. `README.md:65-73` already admits nine
are skeletons with no tests, unverified against a live API. Keep TypeScript and
PHP; delete the other nine. They cost maintenance and imply a support surface
you cannot honour. When you need Go, generate it from
`docs/openapi.yaml` — which is already drift-tested
(`tests/Feature/Architecture/OpenapiDriftTest.php`).

### The smallest structure that could still ship

Exactly what exists, minus the SDK sprawl:

```
Masaar                (compliance engine + API)        <- all effort here
masaar-erp-backend    (the first customer)             <- commit the tree
masaar-erp-frontend   (the first customer's UI)        <- park it
sdks/{typescript,php} (drop the other nine)
```

For a solo developer with no client and no deadline, the correct move is not to
restructure anything. It is to **stop widening and go deep on one path**: get
Masaar from L1 to L4. The repo structure is not what is holding that back — the
missing XSD and the never-run sandbox are.
