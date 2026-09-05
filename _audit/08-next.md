# 08 — The Next Rung Only

> **Correction notice.** An earlier draft of this file targeted L2 and led with
> "vendor the XSDs". That was based on a wrong reading — schema validation
> already exists via ZATCA's own SDK (`tests/Feature/Compliance/ZatcaConformanceTest.php`).
> The correct next rung is **L4**.

**Current: L3.** UBL 2.1 generation, ECDSA secp256k1, CSR with correct OIDs,
XAdES embedded in `UBLExtensions`, 9-tag TLV Phase-2 QR, invoice hash and PIH
chain — all built, and checked against ZATCA's own validator (XSD, EN16931,
KSA Schematron, PIH), which I ran during this audit: **23 passed, 1 failed**.

**Next: L4 — "Sandbox: CCSID obtained, compliance suite passes for all six
document types."**

Nothing here has ever spoken to ZATCA. That single fact is the whole of the
gap, and no amount of further local work closes it.

---

## Why this is the right next rung

You are at the point where **more local development has sharply diminishing
returns**. The conformance SDK is the strongest offline oracle available and it
is 23/24 satisfied — close the last one (R-16) and the offline evidence is
exhausted. Everything still unknown after that is unknown *because* it requires
the authority: whether your CSR is accepted by their CA, whether their API
agrees with your `ClearanceState` mapping, what their error codes actually look
like in `zatca_errors`, whether a WARNING response arrives in the shape
`ProcessFatooraSubmission` expects.

There is also a **deadline-free window that will not come again**: no client,
no production data, no chain to reconstruct. R-1 and R-2 below are trivial to
fix now and expensive to fix after go-live.

---

## Dependency order

```
  T1  Make the oracle and the suite runnable by default
       |            (nothing depends on it; everything is verified through it)
       |
  T2  Fix R-1 -- PIH read inside a lock
       |
  T3  Fix R-2 -- submit the stored XML, not a re-signed one
       |
       +--> the chain is trustworthy under load
       |
  T4  Obtain a CCSID and run the six-document compliance suite
       |
       +--> L4 REACHED
```

T2 and T3 come **before** T4 deliberately. Both produce documents ZATCA rejects
for reasons that look like structural problems. Debugging three classes of
failure simultaneously during a first sandbox run is how people conclude their
UBL is broken when their chain is.

---

## The first three tasks

### T1 — Make verification reproducible · **~2-3 hours**

Two separate problems, both of which make the project look worse than it is.

**(a) The suite does not run on the default PHP. ~15 min.**
Default `php` here is **8.2.28**; `composer.json:11` requires `^8.4`.
`php artisan test` fails composer's platform check — a hard stop, not a test
failure. It runs only via
`c:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe`, which is how the
`727 passed, 24 skipped` baseline in this audit was produced. Point Laragon's
PHP selection (or PATH) at 8.4.12 and add the required version to
`README.md`'s Quick Start (`:37` currently says only "Requires PHP 8.4+").

**(b) The conformance oracle is invisible — and it is currently red. ~2h.**

The SDK is already on this machine at
`c:/Users/Shamil/Personal/Zatca/zatca-einvoicing-sdk-Java-238-R3.4.8/zatca-einvoicing-sdk-Java-238-R3.4.8`
with OpenJDK 17. Running it yields **23 passed, 1 failed**. Fixing that failure
(R-16, document-level discount advisories) belongs here, before T2, because it
is a two-hour fix with a five-minute feedback loop and it gets the suite green
before you start changing the chain.

`ZATCA_SDK_PATH` appears in **no `.env.example`, no CI workflow, and no
document.** I found it only by reading skip messages. The consequence is that
the single most valuable test in the repository is off by default and nobody is
reminded it exists.

- Add `ZATCA_SDK_PATH=` to `.env.example` with a comment explaining what it
  points at and that the SDK is a licensed download. `tests/Feature/Architecture/EnvExampleTest.php`
  already polices that file, so this is the natural home.
- Write `docs/sa/CONFORMANCE.md`: where to get the SDK, where to put it, the
  Java requirement, and the one command to run
  `--filter=ZatcaConformanceTest`.
- Print a **visible warning** — not a silent skip — when the suite runs without
  it. `ZatcaSdk::requireSdk()` (`tests/Fixtures/ZatcaSdk.php:31-50`) already has
  three distinct skip reasons; surface the count in the run summary or in CI.

*Done when:* `php artisan test` runs green from a fresh shell, and a developer
who has never seen the repo can enable conformance from the docs alone.

---

### T2 — Fix R-1: read the PIH under the same lock that allocates the ICV · **~4-8 hours**

The chain can fork under concurrency. `Submitter::generate()` reads
`$invoice->previous_invoice_hash` at
`app/Domains/Compliance/Fatoora/Services/Submitter.php:68` — **outside** the
transaction it opens at `:84`. The accessor skips unhashed drafts
(`app/Domains/Invoice/Models/Invoice.php:387`), which is correct for one
caller and is exactly why two interleaved issuances can select the same
predecessor. Full trace in `06-risks.md` R-1.

**Write the failing test first.** Create invoices at ICV *N* and *N+1*, sign
*N+1* before *N*, and assert *N+1*'s PIH is *N*'s hash. It should fail today.

Then move the read inside a lock. `Invoice::generateNextIcv()`
(`:216-234`) already establishes the pattern — and its docblock at `:196-214`
explains why the lock belongs on the `organizations` row rather than on invoice
rows. Use the same lock:

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

**Measure before accepting.** This holds a row lock across a CPU-bound ECDSA
signature. If the signing time is material, use a dedicated advisory lock keyed
on `org_id` instead, so the organization row stays free.

While here, close **R-6**: `OfflineQueue.php:421-430` detects that the chain may
have advanced and only writes `Log::debug`. The ICV-conflict check immediately
above it (`:384-398`) shows the intended shape — return an action
(`resign`) and act on it.

*Done when:* the concurrency test passes, reverting the lock fails it, and the
suite is green.

---

### T3 — Fix R-2: submit the document you archived · **~3-6 hours**

`Submitter::generate()` signs and stores the document (`:84-98` — `signed_xml`,
`hash`, `qr_code`, `status = Issued`). `Submitter::submit()` then calls
`generateComplianceData()` **again** (`:172-181`) and transmits *that* XML —
not the stored one.

Two documents, two `SigningTime` values, and — if R-1 has fired — two different
PIH values. Your archive and ZATCA's copy disagree, which is gap-matrix #34
violated in the worst way: the buyer's copy and the authority's copy are not
the same document.

- Change `submit()` to transmit `$invoice->signed_xml` and `$invoice->hash`.
  Issuance is the signing event; submission is transport.
- If the invoice has no `signed_xml`, that is a programming error — throw
  rather than silently re-signing.
- Add a test asserting the bytes sent to the client are byte-identical to
  `invoices.signed_xml`.
- Then confirm the cleared document returned by ZATCA replaces the stored one
  (`clearedXml()` `:431`, `updateInvoiceStatus()` `:392-430`), so the archive
  ends up holding the *cleared* XML — which is what #34 actually requires.

*Done when:* a test proves submitted bytes equal archived bytes, and the suite
is green.

---

## After these three

**T4 — Sandbox onboarding · ~4-8h plus unknown remediation.**

Everything is written and reachable: `CsidOnboarding::requestComplianceCsid()`
(`:37`), `runComplianceChecks()` (`:82-114`), `requestProductionCsid()`
(`:127`), orchestrated by `completeOnboarding()` (`:169-185`) which refuses to
continue unless compliance passed (`:181`). All six document types are built
with correct type codes at `OnboardingController.php:243-250`.
`config/fatoora.php:29-31` already holds all three real gateway URLs.

Budget generously for remediation. Even with the conformance SDK satisfied, a
first CCSID run typically surfaces CSR-subject issues and API-contract
surprises that no offline validator models. **That remediation is the point of
the exercise, not a sign something went wrong.**

Do it in `sandbox`, then `simulation`, then stop. PCSID is L5.

## What not to do next

- **Do not extract the package yet.** `07-consolidation.md` explains the
  reasoning: the compliance run will reshape `DocumentBuilder`'s inputs and you
  would do the work twice. Post-L4 it is 60-90h and largely mechanical.
- **Do not start UAE / Peppol PINT AE.** Mandate is 2027-01-01. Saudi is one
  rung short.
- **Do not fix the `05-data-model.md` gaps yet — except M-1 (Arabic party
  names).** ZATCA will reject you for a missing Arabic seller name; the rest
  can wait until the sandbox tells you which fields it actually enforces.
- **Do not add runtime XSD validation yet.** It is worth doing eventually
  (gap-matrix #2b — `validateXml()` has zero callers), but the SDK covers
  document correctness today, and adding a second validation path before the
  sandbox run just gives you two things to debug.
