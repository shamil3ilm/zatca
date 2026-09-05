> ## ⚠ This audit describes a moving tree
>
> **The repository was being actively edited while this audit ran.** At session
> start `git status` showed 7 modified files (docs + one command). It now shows
> **27**, including `XmlBuilder.php` (+140 lines), `DocumentBuilder.php`,
> `Invoice.php`, `CreateInvoiceRequest.php`, `0080_invoices.php`,
> `docs/openapi.yaml`, and `ZatcaConformanceTest.php` (**+305 lines**) —
> the very files several findings below cite by line number.
>
> **Consequence: line numbers in this audit may not resolve, and at least one
> finding (R-16) was fixed while I was writing it up.** Re-verify before acting
> on any specific `path:line`.
>
> **Final measurement, taken on the settled tree at the end of the session:**
>
> ```
> ZATCA_SDK_PATH=<sdk>  php artisan test
> Tests: 752 passed, 3 skipped, 0 failed (1817 assertions) - 123.68s
>   including ZatcaConformanceTest: 25 passed, 0 failed
> ```
>
> The 3 remaining skips are POSIX file-mode tests that cannot pass on Windows.

---

# 01 — Summary: where this actually stands

> **Correction notice.** An earlier draft placed this at **L1**, on the finding
> that no XSD exists in the repository. That was wrong twice over. Schema
> validation is real — it runs ZATCA's own Java SDK through an optional test
> harness — and the SDK turned out to be **on this machine**, so I ran it rather
> than inferring. **23 of 24 conformance tests pass; one fails.** The verdict is
> **L3**, and the failure is now blocker 2.

## Ladder verdict

> ### **L3 fully satisfied. L4 is the next rung and it is not started.**

The distinguishing fact, which took two passes to find:

**`tests/Feature/Compliance/ZatcaConformanceTest.php` (378 lines) runs the
authority's own validator over documents this platform generates** — UBL 2.1
schema, CEN EN16931 rules, ZATCA Schematron, and the PIH chain check, via
`tests/Fixtures/ZatcaSdk.php:26` (`STAGES = ['XSD', 'EN', 'KSA', 'PIH']`).

**I ran it during this audit.** The SDK (R3.4.8) and OpenJDK 17 are both on
this machine; the harness had simply never been pointed at them here:

```
ZATCA_SDK_PATH=<SDK>  php artisan test --filter=ZatcaConformanceTest
Tests: 1 failed, 23 passed (166 assertions) - 53.10s
```

**23 of 24 pass against ZATCA's own validator**, including all six invoice
subtype-flag combinations, exempt/zero-rated/out-of-scope lines, foreign
currency, and — notably — `test_the_authority_own_sample_passes`. The one
failure is real and open: see blocker 2 below.

It had also been run before, and found real defects. Commit `697ea28`
(2026-08-24):

> "BT-23 read `clearance:1.0` on standard invoices, ZATCA rejects that as
> BR-KSA-EN16931-01, and 715 passing tests had nothing to say. […] Carries the
> fixes that running it surfaced, across XAdES signing, the document builder
> and the XML builder."

That is an external oracle citing specific ZATCA rule codes and driving fixes
across three services. It is qualitatively different from self-written tests,
and it is what carries L2 and L3.

### Level by level

| Level | Verdict | Evidence |
|---|---|---|
| **L0** | Passed | Structured invoices — `database/migrations/0080_invoices.php` |
| **L1** | Passed | Mandatory fields + 5-tag Phase-1 QR — `Fatoora/Services/QrCodeGenerator.php:33-48` |
| **L2** | **SATISFIED** | UBL 2.1 built natively in DOM (`XmlBuilder.php`, 1047 lines, 5 namespaces at `:20-31`). Validated against the real UBL 2.1 XSD by `ZatcaConformanceTest::test_standard_invoice_matches_the_schema`, plus `test_the_authority_own_sample_passes` |
| **L3** | **SATISFIED** | secp256k1 (`FatooraGenerateCsr.php:326`), CSR OIDs (`CertificateService.php:26,133,158`), XAdES embedded in `UBLExtensions` (`XmlBuilder.php:49`, `XadesSigner.php`), 9-tag TLV Phase-2 QR (`QrCodeGenerator.php:50-70`), invoice hash + PIH chain (`ChainRecorder.php`, `Invoice.php:383`). The SDK's `KSA` and `PIH` stages cover the last two. **Caveat: R-1 below** |
| **L4** | **NOT STARTED** | The six-document suite is *constructed* (`OnboardingController.php:243-250`) and `CsidOnboarding` is fully written — but **no CCSID has ever been requested and nothing here has ever called ZATCA's API.** The SDK validates documents offline; it does not onboard |
| **L5** | Partly present, out of order | PCSID flow, clearance/reporting split, retry queue, EGS/branch modelling, cert-expiry alerting — all built ahead of the L4 gate they depend on |

**Why not L4:** L4 requires a CCSID and a passing compliance suite *against
ZATCA*. Offline conformance is a strong proxy, not the thing itself. Erring
downward as instructed.

---

## The honest verdict, in plain words

This is a **serious, disciplined implementation** — well past the point where
"half-built" or "abandoned" apply.

- 235 commits, **130 in the last 90 days**, last commit 12 days ago.
- 35,195 LOC of app code; **16,549 of it ZATCA-specific**; 17,092 LOC of tests
  across 117 files.
- **727 tests pass, 24 skipped, 0 fail** (1733 assertions) on PHP 8.4.12.
- Zero TODO/FIXME/HACK markers in `app/`. Two files over 1000 lines, both
  legitimately large.
- No secrets in git history. Four `.pem` files, all public certs or a CRL,
  zero private-key blocks.
- 17 architecture tests that fail the build on structural drift — route guards,
  OpenAPI drift, shell-outs, config keys, scheduled commands.

The thing that most distinguishes it from typical work at this stage is that
someone **went looking for an external oracle instead of trusting a green
suite**, found one, ran it, and fixed what it said. The `ZatcaSdk` docblock
puts it exactly right: *"A skipped conformance test is honest. A missing one is
what let BT-3's business process stay wrong."*

**What is genuinely not done:** nothing here has ever spoken to ZATCA. No
CCSID, no PCSID, no clearance response, no reporting response. Every
integration path — `CsidOnboarding`, `Submitter::submit`, `ClearanceState`
response mapping, the retry queue — is written, reachable, unit-tested, and
**never once exercised against the authority's API**. That is the entire
distance between here and L4, and it is where unknown-unknowns live.

The second thing not done is **operating** it: no reconciliation between issued
and acknowledged, no operator alert on submission failure, no written downtime
runbook. Those matter the day there is a client, not before.

---

## Top blockers

**1. No CCSID has ever been obtained. (The L4 blocker — everything else is
secondary.)**
`Fatoora/Services/CsidOnboarding.php:37` `requestComplianceCsid()`, `:82`
`runComplianceChecks()`, `:127` `requestProductionCsid()`, orchestrated by
`completeOnboarding()` `:169-185` which refuses to proceed unless compliance
passed (`:181`). All six document types are built with correct type codes
(`OnboardingController.php:243-250`). **None of it has run.**
*Estimate: 4–8h to run, plus unknown remediation.*

**2. ~~R-16 — the conformance suite is red on document-level discounts.~~
CLOSED during this audit.**
I reported this after reproducing it (23 passed / 1 failed). It was fixed while
this document was being written: `XmlBuilder::breakdown()`
(`XmlBuilder.php:598`) now apportions a document-level discount across tax
categories in proportion to each category's net contribution, and recomputes
tax on the reduced base. `ZatcaConformanceTest` grew from 24 to 25 tests
(new: `test_mixed_categories_with_a_discount_validate`,
`test_healthcare_to_a_citizen_validates`) and **all 25 now pass**. My suggested
fix — emit `BaseAmount` and `MultiplierFactorNumeric` together — was **wrong**:
ZATCA's own `Standard Invoice with Document Level Charge.xml` sample carries
neither element, exactly as the code comment claimed. The real defect was in
the apportionment arithmetic, not the element set.

*Original text follows for the record.*

**~~2. R-16 — the conformance suite is red on document-level discounts.~~**
`ZatcaConformanceTest::test_document_level_discount_validates` fails with two
advisories: `BR-KSA-EN16931-05` (percentage BT-94 required when base amount
BT-93 is given) and `BR-KSA-EN16931-03` (amount must equal base x pct / 100).
`XmlBuilder.php:460-472` deliberately emits **neither** element — and
`grep -rn "BaseAmount" app/` confirms it never does — yet the rules fire
anyway, so that fix did not take. Errors are clean, so ZATCA *accepts* these
documents; but `invoices.discount_amount` is a supported feature producing
advisory-generating XML, and a red suite nobody runs is how the BT-23 defect
survived 715 passing tests. Try emitting **both** elements instead of neither.
*Estimate: 2–4h, with a five-minute feedback loop now the SDK path is known.*

**3. ~~R-1 — the chain can fork.~~ FIXED — commit `0bcd7bd`.**
Proven with `ChainForkTest`, then fixed: the counter is allocated in
`Submitter::generate()` inside the transaction, under the same
organization-row lock that reads the predecessor. A draft now holds no
counter and no position. Fixing it surfaced **R-17**: three separate paths
build documents, and the queue path (`ProcessFatooraSubmission`) never issued
at all — queued documents reached the authority carrying **ICV 0 and the
genesis PIH**, each claiming to be first in its chain. All three now issue
first. Suite: 758 passed, 3 skipped, 25/25 conformance.

*Original text follows for the record.*

**~~3. R-1 — the PIH is read outside any lock.~~**
`Submitter.php:68` reads `$invoice->previous_invoice_hash` *before* the
transaction opened at `:84`. The accessor skips unhashed drafts
(`Invoice.php:387`) — correct sequentially, and the reason two interleaved
issuances can select the same predecessor. ICV allocation is safe; this is not.
Full trace in `06-risks.md`.
*Estimate: 4–8h.*

**4. R-2 — the submitted document is re-signed and may differ from the archived
one.**
`Submitter::submit()` calls `generateComplianceData()` again at `:172-181` and
transmits that XML rather than the `signed_xml` stored at issuance (`:87`).
Different `SigningTime` at minimum; a different PIH if R-1 fired. Your archive
and ZATCA's copy then disagree — which is gap-matrix #34 violated in the worst
possible way.
*Estimate: 3–6h.*

**5. Conformance validation is opt-in and unwired, so it silently does not
run — which is why nobody saw R-16.**
`ZATCA_SDK_PATH` appears in **no** `.env.example`, **no** CI workflow, and
**no** document. I found the harness only by reading skip messages, and found
the SDK by searching the filesystem. Both were sitting here the whole time.
*Estimate: 1–2h to document and wire.* The exact value that works on this
machine is:
`c:/Users/Shamil/Personal/Zatca/zatca-einvoicing-sdk-Java-238-R3.4.8/zatca-einvoicing-sdk-Java-238-R3.4.8`

**6. One encryption key covers every tenant's signing key.**
`CredentialStore::cipher()` `:57-72` builds one `Encrypter` from
`fatoora.signing.key`, falling back to `APP_KEY` (`config/fatoora.php:90`).
Storage, rotation and previous-key decryption are all correctly built — but a
single secret compromise exposes every taxpayer's private key, and the fallback
also protects sessions and sits in every container.
*Estimate: 16–24h for per-tenant DEKs.*

---

## Two smaller things worth fixing this week

- **The suite cannot run on this machine's default PHP.** Default is 8.2.28;
  `composer.json:11` requires `^8.4`. `php artisan test` fails composer's
  platform check outright. Runs only via
  `c:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64`. *15 minutes.*
- **`masaar-erp-backend` has 453 uncommitted files** — 402 deleted migrations,
  49 new consolidated ones, nothing on the remote. An unfinished squash living
  only in a working directory. *15 minutes, and the highest-urgency item in
  this audit by risk-per-minute.*
