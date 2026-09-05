# 99 — Blocked Access & Caveats

What was refused, unavailable, or deliberately not read — so you know which
findings may be incomplete.

---

## 1. Paths in the brief that do not exist

| Requested | Result |
|---|---|
| `c:\laragon\www\Zatca` | **Does not exist.** `ls c:/laragon/www/` shows no such entry, in any casing. |
| `c:\laragon\www\erp-backend` | **Does not exist.** Actual name: `masaar-erp-backend`. |
| `c:\laragon\www\erp-frontend` | **Does not exist.** Actual name: `masaar-erp-frontend`. |

**Impact:** none on coverage — the ZATCA code was located in Masaar itself and
audited in full (see `00-map.md`). But it means the audit covers three repos,
not the four described.

### An unexplained anomaly, recorded honestly

Early in this run, two commands against `c:/laragon/www/erp-backend` **returned
real content** — a directory listing, `config/zatca-integration.php`, and
`git log -1` reporting `89b055f 2026-05-24 "test: complete Manufacturing module
coverage + fix pre-existing failures"`. Later, four separate probes of the same
path (`ls`, `cd`, `git -C`, direct file access) all returned
`No such file or directory`, and `ls c:/laragon/www/` confirms no such
directory.

The content matched `masaar-erp-backend`, **except** that its current HEAD is
`286285f (2026-08-25)`, not `89b055f`, and it now contains `MasaarClient.php`
where the early read showed `CompliPayClient.php`.

**ASSUMPTION:** the early reads resolved to `masaar-erp-backend` at an older
state — possibly a stale filesystem cache or a path-mapping layer in the tool
sandbox. I could not reproduce or explain it.

**Everything in this audit concerning the ERP was re-verified against
`c:/laragon/www/masaar-erp-backend` at HEAD `286285f`.** The early
`CompliPayClient` reading is superseded and is not relied on anywhere. If you
see `CompliPayClient` referenced in any conclusion, treat it as an error —
the class is now `MasaarClient`.

---

## 2. Deliberately not read (per instructions)

- **`c:\laragon\www\Masaar\.env`** — exists, 8839 bytes, untracked. Presence
  and size only; **contents never read or printed.**
- **`tests/Fixtures/Certificates/*.pem`** — 4 files. I read only the
  `BEGIN ...` header labels to confirm none contains a private key
  (`ca.pem`, `good.pem`, `revoked.pem` = `BEGIN CERTIFICATE`; `crl.pem` =
  `BEGIN X509 CRL`; **zero `PRIVATE KEY` blocks**). No key material printed.
- **`masaar-erp-backend/.env.example`** — presence confirmed only.
- No CSIDs, OTPs or private keys were read or displayed anywhere in this run.

---

## 3. One tool call interrupted

A `grep` for `generateNextIcv` callers was rejected mid-run. **Re-issued and
completed successfully** — the finding at `06-risks.md` R-1 is based on the
completed call. No coverage lost.

---

## 4. Limits of what "VERIFIED" can mean here

**No part of this system has ever communicated with ZATCA.** No CCSID exists,
no compliance invoice has been submitted, no clearance or reporting response
has been received or stored. I searched for recorded responses, fixtures and
sandbox artefacts and found none.

Every VERIFIED in `04-gap-matrix.md` therefore rests on **your own tests
agreeing with your own implementation** — which is real evidence of internal
consistency and regression safety, and is *not* evidence of ZATCA compliance.
Wherever both readings were possible I marked the row PRESENT-UNVERIFIED.

**Correction made during this audit.** An earlier pass graded the project L1
on the finding that no XSD exists in the repository. That was wrong, and I
found it by chasing the skipped tests. `tests/Feature/Compliance/ZatcaConformanceTest.php`
runs **ZATCA's own Java SDK** over generated documents — XSD, EN16931, KSA
Schematron, PIH (`tests/Fixtures/ZatcaSdk.php:26`) — and commit `697ea28`
records it being run and forcing real fixes. The grade is **L3**.

**Second correction: the SDK *is* on this machine, and I ran the suite.**
An earlier pass of this file said the conformance evidence was "not
reproducible in this environment". Wrong — I had only searched
`c:/laragon/www`. The SDK is at
`c:/Users/Shamil/Personal/Zatca/zatca-einvoicing-sdk-Java-238-R3.4.8/zatca-einvoicing-sdk-Java-238-R3.4.8`
(R3.4.8), with OpenJDK 17 installed. Reproduced result:
**23 passed, 1 failed (166 assertions), 53.10s.** The failure is a real open
defect, now filed as `06-risks.md` R-16 and gap-matrix row 13a. Rows marked
**VERIFIED (SDK)** are therefore backed by evidence I reproduced, not by a
commit message.

**Test-count discrepancy, unresolved.** My first suite run reported
`704 passed, 3 skipped (1546 assertions)`. Two later runs, reproducible,
reported `727 passed, 24 skipped (1733 assertions), 38.25s`. Nothing on disk
changed between them and the first run's output file no longer exists, so I
cannot explain the difference. **All figures in this audit use the later,
reproducible run.** If you care, run it twice yourself — a suite whose
collection count varies is worth understanding.

The 24 skips break down as: the conformance suite (`ZATCA_SDK_PATH` unset) and
POSIX file-mode tests that cannot pass on Windows
(`"POSIX file modes are not enforced on Windows"`). None is a silently disabled
compliance assertion.

---

## 4b. The tree changed underneath this audit

At session start, `git status` on Masaar showed **7** modified files (README,
`FatooraGenerateCsr.php`, 5 docs). By the end it showed **27**, including
`XmlBuilder.php` (+140), `DocumentBuilder.php`, `Invoice.php`,
`CreateInvoiceRequest.php`, `database/migrations/0080_invoices.php`,
`docs/openapi.yaml`, `sdks/typescript/src/generated.ts`, and
`ZatcaConformanceTest.php` (**+305**). Net `+597 / -82` across 11 files.

I did not make these changes — I wrote only to `_audit/`. Someone or something
else was editing concurrently.

**Two consequences you should know about:**

1. **Line numbers may not resolve.** Several findings cite
   `XmlBuilder.php:460-472`, `Submitter.php:68/:84`, `Invoice.php:383-391`.
   `XmlBuilder.php` in particular has shifted. Re-verify before acting.
2. **I hit a half-written file twice.** A script loading `XmlBuilder.php`
   failed with `Call to undefined method ...::breakdown()` while `breakdown()`
   existed at `:598`; and one conformance run reported 24 of 25 tests failing
   with `ErrorException` in 0.05s each. Both were races with an in-progress
   save, not defects — re-running on the settled tree gave 25/25. **I nearly
   reported that as a catastrophic regression.** Anything alarming in this
   audit that I did not re-run twice deserves the same scepticism.

## 4c. I overwrote a previous audit

`_audit/` was **already tracked in git** at HEAD `60c2fc9` — `git ls-files
_audit/` lists all ten filenames. It did not appear in the initial `git status`
because the working copy matched HEAD exactly. My writes overwrote that prior
content, which is why all ten now show as ` M` rather than untracked.

Nothing is lost — `git diff _audit/` shows what changed and
`git checkout -- _audit/` restores the previous version — but **do not commit
`_audit/` without looking at that diff first** if the earlier audit mattered.

## 5. Not attempted, by rule

Read-only was respected throughout. Not run: `composer install`,
`npm`/`pnpm install`, `php artisan migrate`, Pint, any write outside
`./_audit/`, any git write. Nothing in the three repos was modified.

The Masaar test suite **was** executed — it runs against in-memory SQLite
(`RefreshDatabase`) and mutates no persistent state. If you consider that a
write, it is the only one, and it is the reason 17 rows could be marked
VERIFIED rather than PRESENT-UNVERIFIED.

The erp-backend and erp-frontend suites were **not** run. Their status is
unknown, and erp-backend's 453-file uncommitted working tree (402 deleted
migrations) makes it unlikely its suite passes as it stands.

---

## 6. Claims I could not fully verify — treat as ASSUMPTION

Each is flagged in place; collected here so they are easy to check.

| Claim | Where | Why unverified |
|---|---|---|
| erp-backend's uncommitted state is a migration squash | `02-inventory.md` | Inferred from 402 deletions + 49 sequentially-numbered additions, all in `database/migrations/`. Not confirmed by a commit message or note. |
| Document-level rounding follows ZATCA's rule | `04` #13 | `isValidDecimalPrecision()` exists (`InvoiceValidator.php:511`); I did not trace whether it is invoked on the totals path. |
| `DocumentBuilder` falls back to Latin names when Arabic is absent | `05` P-2 | Inferred from the absence of `name_ar` columns plus Arabic references in `DocumentBuilder.php`. Not traced through the builder. |
| `submission_idempotency` is never pruned | `05` P-6 | Based on its absence from `routes/console.php`. I did not read the table's full definition or search for an ad-hoc cleanup. |
| The two admin UIs serve different audiences | `07` | Masaar has `/admin` + `/portal` web routes; the frontend has `apps/admin` + `apps/portal`. Plausible but not confirmed. |
| Gaplessness of ICV is acceptable | `04` #8 | A rolled-back transaction burns a counter value. I assume ZATCA checks monotonicity, not gap-freedom. **Confirm against the spec before go-live** — if gaps are rejected, `generateNextIcv` needs rework. |

---

## 7. Not examined at all

Out of scope for a ZATCA audit, but you should know they were not looked at:

- The **UAE / FTA** implementation (`app/Domains/Compliance/FTA/`, 4 test
  files). Noted as present; not assessed.
- **Licensing** (`app/Domains/Licensing/`, 4 test files) beyond its role as the
  partner-API guard.
- The **11 SDKs** in `sdks/`, beyond what `README.md:65-73` states.
- `masaar-erp-frontend` source. Only structure, git state, CI and
  `package.json` were inspected — no component or API-client code.
- The **10 prior audit documents** in `docs/audit/`. I read only
  `09-WORK-MAP.md` for orientation and deliberately re-derived every finding
  from the tree rather than inheriting its conclusions.
