# Masaar Compliance Policies

This document defines the compliance policies for Masaar. These are **decision boundaries**, not code - they define how the system handles edge cases that require human judgment.

## 1. Retroactive Regulatory Changes

### Policy Statement

**An invoice is considered compliant based on the rules in effect at the time of issuance, not retroactively.**

### Rationale

ZATCA may issue clarifications or rule changes that affect interpretation of previous submissions. Without a clear policy, customers face legal uncertainty.

### Implementation

Each invoice stores:
- `rule_version`: ZATCA business rules version at issuance
- `schema_version`: UBL/ZATCA schema version at issuance
- `signature_algorithm`: Cryptographic algorithm used
- `hash_algorithm`: Hash algorithm used

### Decision Matrix

| Scenario | Action | Rationale |
|----------|--------|-----------|
| ZATCA clarifies a rule interpretation | No reprocessing | Compliant at time of issuance |
| ZATCA mandates new field for future invoices | Apply only to new invoices | Non-retroactive |
| ZATCA discovers security vulnerability | Mark affected invoices, do not resubmit | Audit trail preserved |
| Customer requests voluntary resubmission | Allowed with new ICV | Customer choice, tracked |

### Legal Statement (Generated per Invoice)

> This invoice (ID: {id}) was issued on {issue_date} and determined compliant under ZATCA rules version {rule_version} in effect at that time. Subsequent rule changes do not retroactively affect this determination.

---

## 2. Canonical Invoice Identity

### Policy Statement

**The canonical identity of an invoice is `(organization_id + issue_date + internal_uuid)`. External invoice numbers from ERPs are metadata, not identity.**

### Rationale

When organizations switch ERPs mid-year, invoice number collisions can occur (e.g., both ERPs generate `INV-1001`). Using internal UUIDs prevents disputes.

### Implementation

| Field | Purpose | Uniqueness |
|-------|---------|------------|
| `id` (UUID) | Canonical identity | Globally unique |
| `organization_id` | Tenant scope | Per-tenant |
| `invoice_number` | ERP reference | Metadata only |
| `icv` | ZATCA sequence | Unique per organization |
| `hash` | Content identity | Unique per content |

### Collision Handling

```
Scenario: ERP A issues INV-1001, ERP B (new system) issues INV-1001

Resolution:
- Internal IDs are different (UUID)
- ICVs are different (sequential)
- Hashes are different (content differs)
- Both are valid, distinct invoices
- ERP invoice_number treated as display label only
```

### Guidance for Integrators

1. Never assume `invoice_number` is unique across systems
2. Always use `id` (UUID) for references
3. Store ERP-specific IDs in your system, not ours
4. Use `icv` for ZATCA sequencing verification

---

## 3. Non-Compliant Export Policy

### Policy Statement

**Non-compliant invoices can be exported for audit purposes but MUST be clearly watermarked to prevent misuse.**

### Rationale

CFOs and auditors may need to review rejected invoices. Without proper watermarking, these could be misused for fraudulent tax deductions.

### Export Modes

| Mode | Watermark | Use Case |
|------|-----------|----------|
| `compliant` | None | Normal cleared/reported invoices |
| `draft` | `*** DRAFT - NOT SUBMITTED ***` | Pre-submission review |
| `audit` | `*** NON-COMPLIANT - NOT CLEARED BY ZATCA ***` | Audit of rejected invoices |

### Requirements for Non-Compliant Export

1. **Reason required**: Must provide justification
2. **Audit logged**: Who, when, why
3. **Watermark prominent**: Header and footer
4. **Disclaimer included**: Legal warning text

### Disclaimer Text (Audit Mode)

> This invoice export is for AUDIT PURPOSES ONLY. It has NOT been cleared by ZATCA and MUST NOT be used for tax deduction, reimbursement, or any official purpose. Using this document for tax purposes may constitute fraud.

---

## 4. Organization Lifecycle

### Policy Statement

**Organizations have defined lifecycle states that control what operations are permitted.**

### States

| State | Can Issue? | Can Submit? | Hash Chain | Certificates |
|-------|------------|-------------|------------|--------------|
| `active` | Yes | Yes | Active | Valid |
| `suspended` | No | No | Frozen | Valid but unused |
| `legally_replaced` | No | No | Closed | Revoked |
| `archived` | No | No | Read-only | Expired |
| `legal_hold` | No | No | Preserved | Preserved |

### Transitions

```
active → suspended (temporary halt)
suspended → active (resume operations)
active → legally_replaced (merger, VAT change)
legally_replaced → archived (after transition period)
any → legal_hold (government request)
```

### Legal Entity Change Handling

When a company merges or changes VAT number:

1. Mark old organization as `legally_replaced`
2. Create new organization with new VAT
3. Transfer users (optional)
4. Old invoices remain under old organization
5. New invoices under new organization
6. Hash chain is NOT continued (new entity = new chain)

---

## 5. Cryptographic Obsolescence

### Policy Statement

**All cryptographic parameters are versioned and stored with the invoice for future audit verification.**

### Stored Parameters

| Parameter | Current Value | Stored With Invoice |
|-----------|---------------|---------------------|
| Signature Algorithm | ECDSA-secp256k1 | Yes |
| Hash Algorithm | SHA256 | Yes |
| Canonicalization | C14N | Yes |
| Key Size | 256-bit | Yes (via certificate) |

### Migration Path

When ZATCA deprecates an algorithm:

1. New invoices use new algorithm
2. Old invoices retain old algorithm metadata
3. Verification uses stored algorithm, not current
4. Audit reports show algorithm distribution

### Future-Proofing Checklist

- [ ] Monitor ZATCA announcements for algorithm changes
- [ ] Maintain algorithm version registry
- [ ] Test verification with historical algorithms
- [ ] Plan 6-month migration windows

---

## 6. Legal Hold

### Policy Statement

**When under legal hold, all data for the specified scope is preserved indefinitely with no deletions or modifications.**

### Triggers

- Government investigation request
- Court order
- Internal legal review
- Regulatory audit notice

### Effects

| Normal Operation | Under Legal Hold |
|------------------|------------------|
| Soft delete allowed | No deletions |
| Certificate rotation | Certificates preserved |
| Retention policy applies | Indefinite retention |
| Data can be modified | Read-only (audit additions only) |

### Implementation

```
legal_hold_scope:
  - organization_id: specific tenant
  - date_range: optional time bounds
  - invoice_ids: optional specific invoices

legal_hold_metadata:
  - hold_id: unique identifier
  - requested_by: legal/authority name
  - requested_at: timestamp
  - reason: documented reason
  - expires_at: null (indefinite) or date
```

### Release Process

1. Written authorization required
2. Legal review of release request
3. Audit log of release
4. Normal retention resumes

---

## 7. Timestamp Authority

### Policy Statement

**Invoice issuance time is determined by system UTC time at signing. If XAdES-T is enabled, the TSA (Timestamp Authority) timestamp supersedes local issuance time for audit disputes.**

### Rationale

ZATCA may later dispute invoice timestamp correctness. System clocks can drift. Having a clear policy on timestamp authority prevents legal ambiguity during audits.

### Timestamp Hierarchy

| Source | Priority | Use Case |
|--------|----------|----------|
| TSA (XAdES-T) | 1 (highest) | Legally authoritative if enabled |
| System UTC | 2 | Default issuance time |
| ERP timestamp | 3 (lowest) | Informational only |

### Implementation

Each invoice stores:
- `issue_timestamp`: System UTC at signing
- `timestamp_authority`: `local` or `tsa`
- `tsa_timestamp`: TSA response timestamp (if XAdES-T enabled)

### Dispute Resolution

```
Scenario: ZATCA questions invoice timestamp

Resolution Path:
1. If timestamp_authority = tsa → TSA timestamp is authoritative
2. If timestamp_authority = local → System UTC is authoritative
3. Compare against ZATCA's received timestamp (clearance response)
4. Clock drift tolerance: ±30 seconds
```

### Configuration

```env
# Enable Timestamp Authority for XAdES-T
ZATCA_TSA_ENABLED=true
ZATCA_TSA_URL=https://tsa.zatca.gov.sa/timestamp
ZATCA_TSA_USERNAME=
ZATCA_TSA_PASSWORD=
ZATCA_TSA_TIMEOUT=30
```

---

## 8. Certificate Overlap Resolution

### Policy Statement

**When multiple certificates are valid simultaneously (overlap period), always prefer the newest active certificate once issued, unless explicitly overridden for reconciliation.**

### Rationale

Certificate rotation creates overlap windows where both old and new certificates are valid. Without clear policy, different invoices might be signed with different certificates, causing confusion.

### Overlap Scenarios

| Scenario | Resolution |
|----------|------------|
| New cert issued, old still valid | Use new cert immediately |
| Old cert compromised | Halt all signing, emergency rotation |
| Reconciliation needed with old cert | Explicit override with audit log |
| Both certs valid, unclear which to use | Newest cert wins |

### Implementation

```php
// Certificate selection logic
public function getActiveCertificate(string $organizationId): Certificate
{
    return CertificateLineage::where('organization_id', $organizationId)
        ->whereNull('revoked_at')
        ->where('valid_from', '<=', now())
        ->where('valid_until', '>=', now())
        ->orderByDesc('activated_at')  // Newest first
        ->firstOrFail();
}
```

### Override Process

For reconciliation scenarios requiring old certificate:

1. Document reason for override
2. Log override in audit trail
3. Set explicit `certificate_override_id`
4. Review and approve by compliance officer
5. Automatic revert after reconciliation period

### Stored Metadata

| Field | Purpose |
|-------|---------|
| `signing_certificate_hash` | Which cert signed the invoice |
| `certificate_override_reason` | Why override was used (if any) |
| `certificate_overlap_window` | Boolean: was signed during overlap? |

---

## 9. Sandbox vs Production Variance Tracking

### Policy Statement

**All behavioral differences between ZATCA sandbox and production environments must be tracked and documented to support customer disputes and debugging.**

### Rationale

Invoices frequently pass sandbox validation but fail in production due to undocumented enforcement differences. Customers say "But it worked in sandbox." We need evidence.

### Tracked Variances

| Variance Type | Example |
|---------------|---------|
| `sandbox_only_pass` | Invoice accepted in sandbox, rejected in production |
| `production_only_fail` | Same payload, different error in production |
| `validation_difference` | Different error codes for same issue |
| `timing_difference` | Timeouts differ between environments |

### Implementation

Database table: `environment_variance_log`

```sql
CREATE TABLE environment_variance_log (
    id UUID PRIMARY KEY,
    organization_id UUID NOT NULL,
    invoice_id UUID,
    variance_type VARCHAR(50) NOT NULL,
    sandbox_result JSONB,
    production_result JSONB,
    payload_hash VARCHAR(64),
    rule_code VARCHAR(50),
    notes TEXT,
    reported_to_zatca BOOLEAN DEFAULT FALSE,
    zatca_ticket_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT NOW()
);
```

### Workflow

```
1. Invoice fails in production
2. Check if identical payload passed sandbox
3. If yes → Log as variance
4. Generate variance report for customer
5. Optionally report to ZATCA
```

### Customer Communication Template

```
Your invoice {id} was rejected in production with error {code}.
This same payload was accepted in sandbox on {date}.

Variance ID: {variance_id}
This has been logged for ZATCA review.

Please contact support with this variance ID for further assistance.
```

---

## 10. Webhook Replay Protection

### Policy Statement

**Webhook payloads include replay protection fields. Consumers MUST implement duplicate detection. Masaar does NOT enforce this server-side but provides the tools.**

### Rationale

If a customer's webhook endpoint is compromised, attackers could replay old webhook payloads internally. We provide protection mechanisms; consumers must use them.

### Payload Security Fields

Every webhook payload includes:

```json
{
  "event_id": "evt_unique_uuid",
  "event_type": "invoice.cleared",
  "occurred_at": "2026-01-31T12:00:00Z",
  "delivered_at": "2026-01-31T12:00:01Z",
  "signature": "sha256=abc123...",
  "idempotency_key": "idem_xyz789"
}
```

### Consumer Requirements

| Requirement | Implementation |
|-------------|----------------|
| Verify signature | HMAC-SHA256 with webhook secret |
| Check event_id uniqueness | Store processed event_ids |
| Validate timestamp freshness | Reject if `occurred_at` > 5 minutes old |
| Idempotent processing | Use `idempotency_key` for deduplication |

### SDK Guidance

```typescript
// TypeScript SDK - Webhook verification
import { verifyWebhook } from '@masaar/sdk';

app.post('/webhooks', (req, res) => {
  const event = verifyWebhook(
    req.body,
    req.headers['x-masaar-signature'],
    process.env.WEBHOOK_SECRET
  );

  // Check for replay
  if (await isEventProcessed(event.event_id)) {
    return res.status(200).send('Already processed');
  }

  // Check timestamp freshness
  const age = Date.now() - new Date(event.occurred_at).getTime();
  if (age > 5 * 60 * 1000) { // 5 minutes
    return res.status(400).send('Event too old');
  }

  // Process event...
  await markEventProcessed(event.event_id);
  res.status(200).send('OK');
});
```

### Documentation Requirements

All SDK READMEs must include:
1. Signature verification example
2. Replay protection example
3. Timestamp validation example
4. Idempotency handling example

---

## 11. What We Do NOT Handle

### Explicitly Out of Scope

| Topic | Reason |
|-------|--------|
| Tax interpretation correctness | Customer/accountant responsibility |
| ERP data field correctness | Source system responsibility |
| Legal advice | Requires licensed professionals |
| Government policy prediction | Impossible to predict |
| Business accounting decisions | Customer domain |

### Customer Responsibilities

1. Ensure invoice data accuracy before submission
2. Understand applicable tax categories
3. Maintain proper exemption documentation
4. Comply with retention requirements
5. Respond to ZATCA inquiries

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-01-31 | Masaar Team | Initial release |
| 1.1 | 2026-01-31 | Masaar Team | Added timestamp authority, certificate overlap, sandbox variance, webhook replay protection policies |

**Last Updated**: January 31, 2026
