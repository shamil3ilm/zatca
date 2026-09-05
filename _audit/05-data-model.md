# 05 — Data Model

32 tables across 17 migrations. The compliance-relevant ones are
`organizations`, `branches`, `compliance_profiles`, `invoices`,
`invoice_lines`, `invoice_submissions`, `submission_state_logs`,
`submission_idempotency`, `offline_queue`, `hash_chain_state`,
`hash_chain_history`.

---

## Required-field audit

| Field | Present? | Where | Assessment |
|---|---|---|---|
| Seller VAT number | Yes | `organizations.vat_number` varchar(15) nullable — `0050_organizations.php:17` | **Wrongly nullable.** A ZATCA-onboarded org cannot lack one. Indexed (`:37`) — good |
| Seller TIN / other ID | Partial | `organizations.cr_number` varchar(20) nullable — `:24` | ZATCA's "other seller ID" accepts CRN/MOM/MLS/700/SAG. Only CRN is modelled; **the scheme identifier is not stored**, so the emitted `PartyIdentification/@schemeID` can only ever be `CRN` |
| Seller address | Yes | `street`, `building_number`, `additional_street`, `district`, `city`, `postal_code`, `country` — `:18-23` | Matches ZATCA's required address parts. All nullable (see below) |
| Buyer name | Yes | `invoices.buyer_name` varchar(255) **not null** — `0080_invoices.php:28` | Correct |
| Buyer VAT | Yes | `invoices.buyer_vat_number` nullable — `:29` | Correctly nullable (B2C has none) |
| Buyer address | Yes | `invoices.buyer_address` text nullable — `:30` | **Free text, not structured.** ZATCA wants discrete address components for standard invoices. This is a real gap for B2B |
| Invoice UUID | Yes | `invoices.id` uuid PK — `:14`; `invoice_submissions.zatca_uuid` — `0140_submissions.php:10` | Correct |
| ICV | Yes | `invoices.icv` unsignedBigInteger **nullable** — `:47`; unique `(org_id, icv)` — `:67` | Nullable is deliberate (drafts). Unique constraint present |
| PIH | **No column** | Derived by accessor `Invoice.php:383-391`; persisted only in `hash_chain_history.previous_hash` char(64) — `0160_hash_chain.php:30` | Deliberate and documented (`Invoice.php:362-366`). Defensible, but see R-1 |
| Invoice hash | Yes | `invoices.hash` varchar(255) nullable — `:39`; `hash_chain_history.invoice_hash` char(64) — `:29` | Inconsistent width: 255 vs 64. Harmless, untidy |
| QR payload | Yes | `invoices.qr_code` text nullable — `:40` | Correct |
| Submission status | Yes | `invoices.status`; `invoice_submissions.state` enum of 10 — `0140_submissions.php:7`; `clearance_state` enum of 7 — `:14` | Excellent. Distinct `clearance_status` and `reporting_status` strings too (`:12-13`) |
| ZATCA response payload | Yes | `invoices.zatca_response` json — `:48`; plus `zatca_warnings` / `zatca_errors` json — `0140:17-18` | Correct |
| Clearance/reporting timestamp | Yes | `invoice_submissions.cleared_at`, `submitted_at`, `signed_at`, `queued_at`, `completed_at` — `0140:15,27-30` | Thorough |
| Warning/error arrays | Yes | Separate `zatca_warnings` and `zatca_errors` json — `0140:17-18` | Correctly separated, not one blob |
| Arabic fields | **Partial** | Present on `branches` (`0070_branches.php`); **absent on `organizations`** (only `name`, `:16`) and **absent on `invoices`** (only `buyer_name`, `:28`) | **Gap.** See M-1/M-2 below |
| Invoice-type flags | Yes | `is_third_party`, `is_nominal`, `is_export`, `is_summary`, `is_self_billed` — all boolean default false with per-bit ZATCA comments — `:50-54` | Complete and well documented |
| `payment_means_code` | Yes | varchar(10) nullable — `:32` | Correct |
| Billing reference | Yes | `billing_ref` varchar(255), `adjustment_reason` varchar(255) — `:33-34` | Required for credit/debit notes (BR-KSA-17). Correctly nullable |
| Exchange rate | Yes | `decimal(16,6)` nullable — `:27`, with a comment citing BR-KSA-CU-01 | Well handled |
| Tax category | Yes | `invoice_lines.tax_category` char(1) default `S`, `exempt_code`, `exempt_reason` — `0080:85-87` | Correct |

---

## Problems, ranked

### P-1 — `organizations.vat_number` is nullable and unconstrained

`0050_organizations.php:17` — `string('vat_number', 15)->nullable()`.

A Saudi VAT number is exactly 15 digits, starts and ends with `3`, and is the
seller identity in every signed document. Nothing enforces length, format, or
presence at the database level. `tests/Feature/Organization/VatNumberTest.php`
exists and passes, so validation happens in the application — but an org can
still be persisted without one and then used to sign.

There is also **no unique constraint**. Two organizations can share a VAT
number, which would merge two taxpayers' hash chains in every report keyed on
VAT.

### P-2 — Arabic seller and buyer names are not modelled

`organizations` has `name` only (`:16`); `invoices` has `buyer_name` only
(`:28`). ZATCA requires Arabic for the seller name on all invoices and for the
buyer on standard invoices. `branches` got Arabic columns; the two tables that
feed the UBL `PartyName` did not.

`DocumentBuilder.php` references Arabic, so **ASSUMPTION:** it currently falls
back to the Latin name or to a branch value. Either way the emitted document
carries a Latin string in a field ZATCA expects Arabic in.

### P-3 — `invoices.buyer_address` is unstructured text

`:30` — `text('buyer_address')`. For a standard (B2B) invoice ZATCA requires
discrete `StreetName`, `BuildingNumber`, `CitySubdivisionName`, `CityName`,
`PostalZone`, `CountrySubentity`, `IdentificationCode`. A single text blob
cannot populate them. The seller side got this right
(`0050_organizations.php:18-23`); the buyer side did not.

### P-4 — Seller address parts are all nullable

`0050_organizations.php:18-23`. Each is mandatory for a ZATCA-onboarded seller.
Same class of problem as P-1: enforced in application code, not in schema.

### P-5 — Hash-width inconsistency

`invoices.hash` is `varchar(255)` (`:39`) while `hash_chain_history.invoice_hash`
and `previous_hash` are `char(64)` (`0160:29-30`). A base64 SHA-256 is 44
characters; hex is 64. The 255 column will silently accept a truncated or
padded value that the chain columns would reject. Cosmetic today, a debugging
trap later.

### P-6 — `submission_idempotency` retention is unbounded

**ASSUMPTION** (I did not read the table's full definition): nothing in
`routes/console.php` prunes it, while `compliance:cleanup-offline-queue` prunes
the offline queue daily. An idempotency ledger that grows forever eventually
slows every submission.

---

## What is right, and worth not breaking

- **`invoices_org_icv_unique`** (`0080:67`) — the backstop that makes a
  duplicate ICV an insert failure rather than a corrupted chain.
- **`hash_chain_history` unique on `(org_id, icv)` *and* on `invoice_id`**
  (`0200_hash_chain_unique_icv.php:27-28`). The migration's docblock is the
  best piece of reasoning in the repository: without the first, "the record of
  the chain can hold two entries at a position the chain itself cannot — the
  one shape a comparison between them could never detect".
- **`hash_chain_state` keyed on `org_id` as its primary key** (`0160:21`) —
  one chain head per tenant, structurally.
- **Legal-hold columns** on `organizations`: `hold_ref`, `legal_hold_at`,
  `hold_expires_at` (`0050:26-28`).
- **Index coverage is genuinely good.** `invoices` carries 9 indexes including
  `(org_id, status)`, `(org_id, created_at)`, `issue_date`;
  `invoice_submissions` carries `(state, next_retry_at)` — exactly the index a
  retry sweeper needs.
- **Every compliance table cascades from `organizations`**, so tenant deletion
  is complete.

---

## Migrations needed

Ordered by value. All are additive except M-4.

**M-1 · Arabic party names** — *required before sandbox*
```
0210_arabic_party_names
  organizations : + name_ar        varchar(255) nullable
  invoices      : + buyer_name_ar  varchar(255) nullable
```
Nullable at first so existing rows survive; tighten once backfilled.

**M-2 · Structured buyer address** — *required for standard invoices*
```
0220_buyer_address_parts
  invoices : + buyer_street, buyer_building_number, buyer_additional_street,
              buyer_district, buyer_city  varchar(255) nullable
            + buyer_postal_code  varchar(5)  nullable
            + buyer_country      char(2)     nullable default 'SA'
  (retain buyer_address for one release, then drop)
```

**M-3 · Seller identity integrity** — *required before a second tenant*
```
0230_seller_identity
  organizations : + unique(vat_number)            [partial/filtered on NOT NULL]
                  + seller_id_scheme  varchar(10) nullable   -- CRN|MOM|MLS|700|SAG
  + CHECK vat_number IS NULL OR vat_number ~ '^3[0-9]{13}3$'   (Postgres)
```
Keep `vat_number` nullable — an org exists before onboarding — but block
duplicates and malformed values.

**M-4 · Hash width alignment** — *cheap, do it while the table is small*
```
0240_align_hash_widths
  invoices : hash  varchar(255) -> varchar(64)
```
Verify no existing row exceeds 64 first.

**M-5 · Idempotency retention**
```
0250_idempotency_pruning
  submission_idempotency : + index(created_at)
  + scheduled command to prune older than N days
```

**M-6 · Reconciliation support** — *pairs with gap-matrix #40*
```
0260_reconciliation
  invoice_submissions : + index(org_id, submission_type, state)
                        + last_reconciled_at  timestamp nullable
```

Not proposed: a `previous_invoice_hash` column on `invoices`. The accessor is
deliberate and reasoned (`Invoice.php:359-381`), and denormalising it would
create a second source of truth for the chain. The fix for R-1 is a lock, not a
column.
