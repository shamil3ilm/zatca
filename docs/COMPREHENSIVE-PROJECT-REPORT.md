# Masaar - ZATCA E-Invoicing Platform
## Comprehensive Project Report

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [System Architecture](#2-system-architecture)
3. [Complete Project Flow](#3-complete-project-flow)
4. [Use Cases & Scenarios](#4-use-cases--scenarios)
5. [Edge Cases & Error Handling](#5-edge-cases--error-handling)
6. [API Reference](#6-api-reference)
7. [Configuration Guide](#7-configuration-guide)
8. [Operational Workflows](#8-operational-workflows)
9. [Security Considerations](#9-security-considerations)
10. [Monitoring & Maintenance](#10-monitoring--maintenance)
11. [Compliance Policies](#11-compliance-policies)
12. [Explicit Non-Goals](#12-explicit-non-goals)

---

## 1. Executive Summary

### 1.1 Project Overview

**Masaar** is a production-ready Laravel-based e-invoicing compliance platform designed for Saudi Arabian ZATCA (Zakat, Tax and Customs Authority) Phase 2 requirements. The platform enables businesses to:

- Generate UBL 2.1 compliant invoices
- Apply XAdES-BES/XAdES-T digital signatures using ECDSA secp256k1
- Submit invoices for clearance (B2B) or reporting (B2C)
- Manage ZATCA onboarding and certificate lifecycle
- Track submission states with full audit trails

### 1.2 Key Capabilities

| Feature | Description |
|---------|-------------|
| **Multi-tenant Architecture** | Organization-scoped data isolation |
| **Dual Authentication** | JWT tokens + API keys for flexibility |
| **Offline Mode** | Queue invoices when ZATCA is unavailable |
| **Circuit Breaker** | Automatic protection against cascading failures |
| **Idempotent Submissions** | 24-hour deduplication window |
| **Certificate Management** | CSR generation, OCSP/CRL revocation checking |
| **Webhook Notifications** | Real-time event delivery with replay protection |

### 1.3 ZATCA Compliance Standards

- **UBL 2.1** - Universal Business Language invoice format
- **XAdES-BES** - XML Advanced Electronic Signatures (Basic)
- **XAdES-T** - Optional timestamped signatures via TSA
- **ECDSA secp256k1** - Elliptic curve digital signature algorithm
- **TLV Encoding** - Tag-Length-Value for QR code data
- **SHA-256** - Cryptographic hashing with C14N canonicalization

---

## 2. System Architecture

### 2.1 Domain-Driven Design Structure

```
app/
├── Domains/
│   ├── Auth/                      # Authentication domain
│   │   ├── Contracts/
│   │   │   └── AuthenticatesUsers.php
│   │   └── Services/
│   │       └── JwtAuthenticatesUsers.php
│   │
│   ├── Compliance/
│   │   └── Zatca/                 # ZATCA compliance domain
│   │       ├── Client/
│   │       │   └── ZatcaClient.php
│   │       ├── DTOs/
│   │       │   ├── InvoiceXmlData.php
│   │       │   ├── QrCodeData.php
│   │       │   ├── CsrData.php
│   │       │   └── ZatcaResponse.php
│   │       ├── Enums/
│   │       │   ├── ErrorCode.php
│   │       │   ├── ClearanceStatus.php
│   │       │   └── SubmissionState.php
│   │       ├── Exceptions/
│   │       │   ├── ZatcaException.php
│   │       │   ├── CertificateException.php
│   │       │   └── SigningException.php
│   │       ├── Models/
│   │       │   ├── InvoiceSubmission.php
│   │       │   └── SubmissionIdempotency.php
│   │       └── Services/
│   │           ├── ZatcaComplianceService.php
│   │           ├── ZatcaSubmissionService.php
│   │           ├── SubmissionService.php
│   │           ├── CsidOnboardingService.php
│   │           ├── XmlBuilder.php
│   │           ├── InvoiceHasher.php
│   │           ├── XadesSigner.php
│   │           ├── QrCodeGenerator.php
│   │           ├── CertificateService.php
│   │           ├── ZatcaValidator.php
│   │           ├── AtomicIcvManager.php
│   │           ├── ClusterCircuitBreaker.php
│   │           ├── TimestampValidator.php
│   │           ├── WebhookPayloadBuilder.php
│   │           ├── EnvironmentVarianceTracker.php
│   │           ├── CertificateLineageService.php
│   │           ├── KeyCompromiseHandler.php
│   │           ├── VatPeriodTracker.php        # Cross-period VAT adjustments
│   │           └── DuplicateInvoiceDetector.php # Duplicate invoice detection
│   │
│   ├── Invoice/
│   │   ├── Models/
│   │   │   ├── Invoice.php
│   │   │   └── InvoiceLine.php
│   │   └── Enums/
│   │       ├── InvoiceStatus.php
│   │       ├── InvoiceType.php
│   │       └── DocumentType.php
│   │
│   ├── Organization/
│   │   ├── Models/
│   │   │   └── Organization.php
│   │   └── Services/
│   │       └── TenantResolver.php
│   │
│   └── Webhook/
│       ├── Models/
│       │   └── WebhookEndpoint.php
│       └── Services/
│           └── WebhookDeliveryService.php
│
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php
│   │   ├── InvoiceController.php
│   │   ├── ComplianceController.php
│   │   ├── OnboardingController.php
│   │   ├── OrganizationController.php
│   │   ├── DashboardController.php
│   │   ├── AdminDashboardController.php
│   │   ├── VarianceController.php
│   │   ├── WebhookController.php
│   │   └── ApiKeyController.php
│   │
│   ├── Middleware/
│   │   ├── JwtAuthenticate.php
│   │   ├── ApiKeyAuthenticate.php
│   │   └── RateLimitApi.php
│   │
│   └── Responses/
│       └── ApiResponse.php
│
└── Console/Commands/
    ├── ComplianceIndexHealth.php
    └── CompliancePartitionMaintenance.php
```

### 2.2 Database Schema Overview

```
┌─────────────────────┐       ┌─────────────────────┐
│   organizations     │       │      invoices       │
├─────────────────────┤       ├─────────────────────┤
│ id (UUID)           │◄──────│ organization_id     │
│ name                │       │ id (UUID)           │
│ vat_number          │       │ invoice_number      │
│ cr_number           │       │ type                │
│ street              │       │ status              │
│ building_number     │       │ icv                 │
│ city                │       │ hash                │
│ postal_code         │       │ qr_code             │
│ country             │       │ signed_xml          │
│ compliance_profile  │       │ subtotal            │
│ status              │       │ tax_amount          │
└─────────────────────┘       │ total               │
                              │ buyer_*             │
                              └─────────┬───────────┘
                                        │
                    ┌───────────────────┴───────────────────┐
                    │                                       │
          ┌─────────▼─────────┐               ┌─────────────▼─────────────┐
          │   invoice_lines   │               │   invoice_submissions     │
          ├───────────────────┤               ├───────────────────────────┤
          │ invoice_id        │               │ invoice_id                │
          │ description       │               │ organization_id           │
          │ quantity          │               │ state                     │
          │ unit_price        │               │ submission_type           │
          │ tax_rate          │               │ zatca_uuid                │
          │ tax_category      │               │ clearance_status          │
          │ tax_exemption_*   │               │ reporting_status          │
          │ line_total        │               │ retry_count               │
          └───────────────────┘               │ zatca_warnings            │
                                              │ zatca_errors              │
                                              └───────────────────────────┘
```

---

## 3. Complete Project Flow

### 3.1 Onboarding Flow (One-time Setup)

```
PHASE 1: REQUEST COMPLIANCE CSID
════════════════════════════════
POST /api/compliance/onboarding/ccsid
Body: { otp: "123456", common_name: "ABC Co", serial_number: "1-TST|2-TST|..." }

1. Generate CSR (Certificate Signing Request)
   • Subject: C=SA, O=ABC Co, OU=IT, CN=ABC Co
   • Extensions: VAT number, serial number, invoice type code
   • Key: ECDSA secp256k1

2. Submit to ZATCA /compliance endpoint
   • Header: OTP=123456 (valid 1 hour from portal)
   • Body: Base64-encoded CSR

3. Receive CCSID Response
   • binarySecurityToken (Certificate)
   • secret (API password)
   • requestID (for PCSID request)

4. Store Encrypted Credentials
   • Path: storage/zatca/{org_id}/ccsid.json
   • Encrypted with Laravel's encryption

PHASE 2: COMPLIANCE CHECK
═════════════════════════
POST /api/compliance/onboarding/compliance-check

1. Generate Test Invoices (B2B standard, B2C simplified)
2. Sign Each Invoice with CCSID
3. Submit to ZATCA /compliance/invoices
4. Verify All Pass

PHASE 3: REQUEST PRODUCTION CSID
════════════════════════════════
POST /api/compliance/onboarding/pcsid

1. Submit PCSID Request using CCSID + requestID
2. Receive Production certificate and secret
3. Store & Update Organization (zatca_onboarded = true)
```

### 3.2 Invoice Lifecycle Flow

```
STEP 1: CREATE INVOICE
══════════════════════
POST /api/invoices

• Validate request, generate ICV
• Calculate totals (subtotal, tax, grand total)
• Create Invoice (status: Draft) and InvoiceLine records

STEP 2: GENERATE COMPLIANCE DATA
════════════════════════════════
POST /api/compliance/zatca/generate/{invoiceId}

1. Build UBL 2.1 XML
2. Get Previous Invoice Hash (PIH)
   • First invoice: base64(0x00 × 32)
   • Subsequent: SHA-256 of previous signed XML
3. Calculate Invoice Hash (C14N + SHA-256)
4. Sign with XAdES-BES
5. Generate QR Code (Phase 1 or Phase 2)
6. Update Invoice (hash, qr_code, signed_xml, status: issued)

STEP 3: SUBMIT TO ZATCA
═══════════════════════
POST /api/compliance/zatca/submit/{invoiceId}

PRE-SUBMISSION CHECKS:
✓ Organization not suspended
✓ Certificate valid (not expired, not revoked)
✓ Rate limits OK (60/min, 10,000/day, 10 concurrent)
✓ Idempotency check (24h window)
✓ Timestamp drift within ±30 seconds

SUBMISSION:
• B2B → /invoices/clearance/single → CLEARED
• B2C → /invoices/reporting/single → REPORTED

RESPONSE HANDLING:
• CLEARED/REPORTED → status: accepted
• REJECTED → status: rejected (no retry for validation errors)
• Network error → retry with exponential backoff
```

### 3.3 State Machine

```
                              ┌─────────┐
                              │  DRAFT  │
                              └────┬────┘
                                   │
            ┌──────────────────────┼──────────────────────┐
            │                      │                      │
            ▼                      ▼                      ▼
     ┌───────────┐          ┌───────────────┐      ┌───────────┐
     │  QUEUED   │          │   PENDING     │      │ CANCELLED │
     │ (offline) │          │  SUBMISSION   │      │           │
     └─────┬─────┘          └───────┬───────┘      └───────────┘
           │                        │
           └────────────┬───────────┘
                        │
                        ▼
                 ┌───────────┐
                 │ SUBMITTED │
                 └─────┬─────┘
                       │
       ┌───────────────┼───────────────┬───────────────┐
       │               │               │               │
       ▼               ▼               ▼               ▼
┌───────────┐   ┌───────────┐   ┌───────────┐   ┌───────────┐
│  CLEARED  │   │ REPORTED  │   │  WARNING  │   │ REJECTED  │
└───────────┘   └───────────┘   └───────────┘   └─────┬─────┘
                                                      │
                                                      ▼
                                               ┌───────────┐
                                               │  FAILED   │ ──► retry
                                               └───────────┘
```

---

## 4. Use Cases & Scenarios

### 4.1 B2B Standard Invoice (Clearance Required)
- Create invoice with type="standard"
- Buyer VAT number required
- Phase 2 QR code (9 tags with cryptographic data)
- Submit for clearance → CLEARED

### 4.2 B2C Simplified Invoice (Reporting Only)
- Create invoice with type="simplified"
- Buyer VAT optional
- Phase 1 QR code (5 tags)
- Submit for reporting → REPORTED

### 4.3 Credit/Debit Notes
- Set document_type="credit_note" or "debit_note"
- Required: billing_reference_id (original invoice)
- Same submission flow as invoices

### 4.4 Tax Exemptions
- **Zero-rated (Z)**: tax_rate=0, tax_exemption_code="VATEX-SA-32" (exports)
- **Exempt (E)**: tax_rate=0, tax_exemption_code="VATEX-SA-EDU" (education)
- **Out of scope (O)**: Services outside KSA

### 4.5 Offline Mode
- Circuit breaker opens after 5 consecutive failures
- Invoices queued in offline_queue table
- Background job retries when API available
- Max queue size: 10,000 invoices

---

## 5. Edge Cases & Error Handling

### 5.1 Error Categories

| Category | Codes | Retryable | Example |
|----------|-------|-----------|---------|
| AUTH_* | 401/403 | No | Invalid API key, expired token |
| VAL_* | 400 | No | Invalid VAT format, calculation mismatch |
| ZATCA_* | 422/503 | Some | Clearance rejected (no), timeout (yes) |
| CERT_* | 400 | No | Certificate expired, revoked |
| NET_* | 503 | Yes | Connection failed, DNS error |
| RATE_* | 429 | Yes | Rate limit exceeded |

### 5.2 Retry Logic

```
RETRYABLE ERRORS:
• ZATCA_SERVICE_UNAVAILABLE  │ 30s delay │ 3 attempts
• ZATCA_TIMEOUT              │ 10s delay │ 3 attempts
• ZATCA_RATE_LIMITED         │ 60s delay │ 5 attempts
• NET_CONNECTION_FAILED      │ 5s delay  │ 3 attempts

EXPONENTIAL BACKOFF:
• Attempt 1: base_delay
• Attempt 2: base_delay × 2
• Attempt 3: base_delay × 4
• Max delay: 300 seconds
```

### 5.3 Edge Case Handling

1. **Duplicate Submission**: Idempotency key = SHA256(invoice_id + hash + signed_xml)
2. **Clock Drift**: ±30 second tolerance, configurable enforcement
3. **First Invoice**: PIH = base64(32 zero bytes)
4. **ICV Gaps**: Allowed by ZATCA, tracked in audit log
5. **Certificate Revocation**: OCSP check before submission, CRL fallback
6. **Partial Network Failure**: Retry with idempotency key
7. **Arabic Normalization**: NFC form, strip invisible characters

### 5.4 Cross-Period VAT Adjustments (VatPeriodTracker)

**Scenario**: Credit/debit notes issued after the original invoice's VAT period has closed.

**Per ZATCA Rules**: If the original period's filing deadline has passed, the adjustment must be reported in the current VAT period, not retroactively applied to the closed period.

| Scenario | Reporting Period | Notes |
|----------|-----------------|-------|
| CN issued, original period OPEN | Original period | Normal adjustment |
| CN issued, original period CLOSED | CN issue period | Cross-period adjustment |

**Implementation**: `VatPeriodTracker::determineReportingPeriod()`

```php
$tracker = new VatPeriodTracker();
$reporting = $tracker->determineReportingPeriod($creditNote, $originalInvoice);

if ($reporting['is_cross_period']) {
    // Log cross-period adjustment for VAT return reconciliation
    // Report in $reporting['report_in_period']
}
```

### 5.5 Duplicate Invoice Detection (DuplicateInvoiceDetector)

**Multi-layer duplicate detection**:

| Check | Severity | Description |
|-------|----------|-------------|
| Invoice Number | Critical | Unique per organization |
| UUID | Critical | Globally unique |
| Hash | Warning | Content-based (same data = same hash) |
| Fuzzy Match | Warning | Same buyer + amount ± 1 SAR within 24h |

**Implementation**: `DuplicateInvoiceDetector::check()`

```php
$detector = new DuplicateInvoiceDetector();
$result = $detector->check(
    organizationId: $orgId,
    invoiceNumber: 'INV-001',
    uuid: $uuid,
    hash: $hash,
    fuzzyMatchData: ['buyer_vat' => '300...', 'total' => 1150.00]
);

if ($result['is_duplicate']) {
    // Block submission - critical duplicate found
}
```

**Sync Conflict Detection**: Detects multi-ERP/POS sync issues by identifying ICV gaps and near-simultaneous invoice creation.

### 5.6 Non-VAT Buyer Identification

**Per ZATCA**: When buyer is not VAT-registered, alternative identification schemes are accepted.

| Scheme | Description | Use Case |
|--------|-------------|----------|
| TIN | Tax Identification Number | Primary |
| CRN | Commercial Registration | Business |
| NAT | National ID | Saudi individuals |
| IQA | Iqama Number | Residents |
| PAS | Passport Number | Visitors |
| GCC | GCC ID | GCC nationals |
| MOM | MOMRA License | Real estate |
| MLS | MLSD License | Labor services |
| SAG | SAGIA License | Foreign investment |
| OTH | Other ID | Fallback |

### 5.7 Free Goods / Promotional Items

**Per ZATCA**: Free goods must use **market value** for VAT calculation (deemed supply).

```php
// Line item for free promotional item
[
    'description' => 'Free promotional item',
    'quantity' => 1,
    'unitPrice' => 0.00,           // Invoice shows 0
    'isFreeItem' => true,
    'marketValue' => 100.00,       // VAT calculated on this
    'taxRate' => 15,
    // VAT amount = 15.00 (15% of 100.00 market value)
]
```

### 5.8 Prepayment Invoice Linking

**Per ZATCA**: Final invoices must reference prepayment invoices (type 386).

```php
$xmlData = new InvoiceXmlData(
    // ... other fields
    prepaymentInvoiceIds: ['PREP-001', 'PREP-002'],
);
// Generates BillingReference elements with DocumentTypeCode = 386
```

### 5.9 Multi-Currency Support

**Per ZATCA Official Guidelines**: Foreign currency invoices are fully supported with these requirements:

1. **DocumentCurrencyCode (BT-5)**: Can be foreign currency (USD, EUR, GBP, etc.)
2. **TaxCurrencyCode (BT-6)**: MUST be SAR for VAT reporting
3. **Two TaxTotal elements required**:
   - First TaxTotal (BT-110): Document currency with subtotals
   - Second TaxTotal (BT-111): SAR amount only for VAT accounting

```php
$xmlData = new InvoiceXmlData(
    currency: 'USD',
    originalCurrency: 'USD',
    exchangeRate: 3.75,         // 1 USD = 3.75 SAR
    exchangeRateDate: '2026-01-15',
    subtotal: 1000.00,          // USD
    taxAmount: 150.00,          // USD - converted to 562.50 SAR internally
    total: 1150.00,             // USD
);

// XmlBuilder generates:
// - DocumentCurrencyCode = USD
// - TaxCurrencyCode = SAR
// - TaxTotal[1]: 150.00 USD with subtotals
// - TaxTotal[2]: 562.50 SAR (150 × 3.75)
```

**Validation**: Foreign currencies require `exchange_rate` field to convert VAT to SAR.

### 5.10 Multi-Branch EGS Support

**Per ZATCA**: Each physical location (EGS - Electronic Generation Solution) requires separate ZATCA onboarding.

**Architecture:**
```
Organization
├── Branch A (EGS Device 1) → Own CCSID/PCSID, own certificate
├── Branch B (EGS Device 2) → Own CCSID/PCSID, own certificate
└── Branch C (EGS Device 3) → Own CCSID/PCSID, own certificate
```

**Implementation:**
- `Branch` model with `device_serial`, `onboarding_status`, `certificate_expires_at`
- `BranchService` stores credentials per branch: `zatca/{orgId}/branches/{branchId}/pcsid.json`
- `BranchOnboardingController` handles 3-step ZATCA onboarding per branch
- `ZatcaSubmissionService` uses branch credentials if invoice has `branch_id`

**API Endpoints:**
- `POST /api/organizations/branches/{id}/onboarding/ccsid` - Step 1: Request CCSID
- `POST /api/organizations/branches/{id}/onboarding/compliance-check` - Step 2: Run checks
- `POST /api/organizations/branches/{id}/onboarding/pcsid` - Step 3: Get PCSID

**ICV/PIH per Organization**: All branches share the same ICV sequence and hash chain per organization.

### 5.11 Proforma Invoice Handling

**Per ZATCA**: Proforma invoices (type 325) are NOT valid for ZATCA submission.

```php
// DocumentType enum
case Proforma = 'proforma';  // 325 - NOT for VAT reporting

// Validation
if ($documentType->isProforma()) {
    // Block ZATCA submission
    // Must convert to Tax Invoice (388) before submission
}
```

### 5.12 Invoice Reprint Validation

**Per ZATCA**: Reprints MUST retain the same invoice number and QR code.

```php
$detector = new DuplicateInvoiceDetector();
$result = $detector->validateReprint(
    $organizationId,
    $originalInvoiceNumber,
    $reprintInvoiceNumber
);

if (!$result['is_valid_reprint']) {
    // Error: Reprint must use same invoice number
}
```

---

## 6. API Reference

### 6.1 Authentication

**JWT (User Sessions)**
```
POST /api/auth/login
Authorization: Bearer {token}
```

**API Key (Server-to-Server)**
```
POST /api/api-keys
X-API-Key: cpk_live_...
```

### 6.2 Core Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/invoices | Create invoice |
| POST | /api/compliance/zatca/generate/{id} | Generate compliance data |
| POST | /api/compliance/zatca/submit/{id} | Submit to ZATCA |
| GET | /api/dashboard | User dashboard |
| GET | /api/admin/dashboard | Admin dashboard |
| GET | /api/admin/dashboard/variances | Environment variance report |
| GET | /api/admin/dashboard/hash-chain-health | Hash chain monitoring |

---

## 7. Configuration Guide

### 7.1 Key Environment Variables

```bash
# Environment
ZATCA_ENVIRONMENT=sandbox|simulation|production

# Rate Limits
ZATCA_RATE_LIMIT_PER_MINUTE=60
ZATCA_RATE_LIMIT_PER_DAY=10000
ZATCA_MAX_CONCURRENT=10

# Circuit Breaker
ZATCA_CCB_FAILURE_THRESHOLD=5
ZATCA_CCB_TIMEOUT=60
ZATCA_CCB_SUCCESS_THRESHOLD=2
ZATCA_CCB_HALF_OPEN_REQUESTS=3

# Timestamp validation has no settings. The tolerance is
# TimestampValidator::MAX_DRIFT_SECONDS, a constant of 30, and the check always
# runs — there is nothing to enable.

# Hash Chain Monitoring
ZATCA_HASH_CHAIN_P95_WARNING=50
ZATCA_HASH_CHAIN_P99_CRITICAL=200
```

---

## 8. Operational Workflows

### 8.1 Artisan Commands

```bash
php artisan compliance:index-health --alert
php artisan compliance:partition-maintenance --create-future
```

### 8.2 Scheduled Tasks

```php
// Every 15 minutes
Schedule::command('compliance:index-health --alert')
    ->everyFifteenMinutes();

// Monthly on 1st at 3 AM
Schedule::command('compliance:partition-maintenance')
    ->monthlyOn(1, '03:00');
```

---

## 9. Security Considerations

### 9.1 Authentication Layers
- TLS 1.2+ enforced
- JWT tokens with 1-hour expiry
- API keys with scoped permissions
- Organization-scoped data isolation

### 9.2 Certificate Security
- Private keys encrypted at rest
- OCSP/CRL revocation checking
- Certificate lineage tracking
- Key compromise handler

### 9.3 Webhook Security
- HMAC-SHA256 signatures
- 5-minute freshness window
- Event deduplication
- Secret rotation support

---

## 10. Monitoring & Maintenance

### 10.1 Key Metrics

| Metric | Warning | Critical |
|--------|---------|----------|
| Certificate expiry | < 30 days | < 7 days |
| Queue stuck items | > 10 | > 100 |
| Success rate | < 90% | < 50% |
| p99 latency | > 200ms | > 500ms |
| Hash chain p95 | > 50ms | > 200ms |

### 10.2 Health Endpoints

```
GET /api/health                  → Basic health
GET /api/dashboard/health        → Org-scoped health
GET /api/admin/dashboard/health  → Platform health
GET /api/admin/dashboard/hash-chain-health → Hash chain monitoring
```

---

## 11. Compliance Policies

### 11.1 Time Authority Precedence

**POLICY**: When disputes arise about invoice timestamps:

1. If XAdES-T is enabled → **TSA timestamp is authoritative**
2. Otherwise → **System UTC timestamp at signing time is authoritative**

This policy provides legal defensibility when ZATCA or auditors dispute timing.

### 11.2 Certificate Overlap Resolution

**POLICY**: When old and new certificates are both valid during overlap period:

- **Always use the newest active certificate once issued**
- Exception: Historical reconciliation may use older certificate if explicitly requested
- Default overlap grace period: 7 days

```php
// config/zatca.php
'policies' => [
    'certificate_overlap' => [
        'use_newest' => true,
        'allow_historical_signing' => false,
        'overlap_grace_days' => 7,
    ],
],
```

### 11.3 Sandbox vs Production Variance Tracking

**PURPOSE**: Track cases where behavior differs between sandbox and production.

```
GET /api/admin/dashboard/variances

Response:
{
  "variances": [
    {
      "rule_code": "BR-KSA-31",
      "sandbox_result": "passed",
      "production_result": "failed",
      "variance_type": "validation_difference",
      "detected_at": "2026-01-31T10:30:00Z"
    }
  ],
  "statistics": {
    "total_variances": 15,
    "unresolved": 3,
    "by_rule_code": {"BR-KSA-31": 5, "BR-KSA-40": 3}
  }
}
```

**USE**: Evidence for customer/regulator discussions about "passed in sandbox, failed in prod" scenarios.

### 11.4 Legal Hold vs Retention

**POLICY**: Legal hold supersedes retention and deletion policies until explicitly released.

```php
'policies' => [
    'legal_hold' => [
        'enabled' => true,
        'supersedes_retention' => true,  // Always
        'requires_authorization' => true,
        'audit_all_operations' => true,
    ],
    'retention' => [
        'invoices_years' => 7,           // ZATCA requirement
        'audit_logs_years' => 7,
        'certificates_permanent' => true, // Never delete
    ],
],
```

### 11.5 Webhook Consumer Responsibilities

**POLICY**: Consumers are responsible for implementing security checks. Masaar is not liable for issues caused by failure to verify signatures or check freshness.

**MANDATORY CHECKS**:

| Check | Description | Failure Action |
|-------|-------------|----------------|
| Signature Verification | HMAC-SHA256 with webhook secret | Return 401, do not process |
| Timestamp Freshness | Reject if > 5 min old or > 30s future | Return 400, do not process |
| Event Deduplication | Track event_id for 24 hours | Return 200, do not reprocess |
| Idempotent Processing | Use idempotency_key for safe retries | Ensure operations are idempotent |

### 11.6 Hash Chain Longevity Monitoring

**PURPOSE**: Detect slow hash chain queries before they become critical.

```
GET /api/admin/dashboard/hash-chain-health

Response:
{
  "metrics": {
    "avg_ms": 12.5,
    "p95_ms": 45.2,
    "p99_ms": 89.1,
    "row_count": 1500000,
    "thresholds": {
      "p95_warning": 50,
      "p99_critical": 200
    }
  },
  "status": "healthy",
  "recommendation": null
}
```

**ALERT TRIGGERS**:
- Warning: p95 > 50ms
- Critical: p99 > 200ms

**MITIGATION**: Partition hash_chain_history table or add indexes.

### 11.7 Idempotency Scope Declaration

**SCOPE**: Idempotency applies per **organization + endpoint + idempotency_key**, valid for 24 hours.

| Component | Description |
|-----------|-------------|
| Organization | Requests from different orgs with same key are independent |
| Endpoint | Same key to /clearance vs /reporting are independent |
| Key | SHA256(invoice_id + hash + signed_xml) |
| Window | 24 hours from first request |

**CLIENT IMPLICATIONS**:
- Do NOT assume global idempotency across organizations
- Retries within 24h with same key return cached response
- After 24h, same key creates new submission

### 11.8 Disaster Recovery Objectives

**OPERATIONAL TARGETS** (not guarantees - depends on infrastructure):

| Metric | Target | Description |
|--------|--------|-------------|
| **RPO** | 5 minutes | Maximum acceptable data loss |
| **RTO** | 30 minutes | Maximum acceptable downtime |
| Backup Frequency | Continuous | Database replication |
| Backup Retention | 30 days | Point-in-time recovery window |

```php
// config/zatca.php
'disaster_recovery' => [
    'rpo_minutes' => 5,
    'rto_minutes' => 30,
    'backup_frequency' => 'continuous',
    'backup_retention_days' => 30,
],
```

**REQUIREMENTS FOR ACHIEVING TARGETS**:
- Database: Synchronous replication to standby
- Storage: Cross-region encrypted backups
- Certificates: Separate encrypted backup location
- Monitoring: Automated failover triggers

### 11.9 Data Residency & Sovereignty

**POLICY**: Masaar supports Saudi Arabia–resident deployments. Data residency is determined by infrastructure configuration, not application logic.

```php
// config/zatca.php
'data_residency' => [
    'policy' => 'Infrastructure-determined, application-agnostic',
    'supported_regions' => ['SA', 'GCC'],
    'cross_border_transfer' => false,
],
```

**DEPLOYMENT REQUIREMENTS FOR KSA COMPLIANCE**:

| Component | Requirement |
|-----------|-------------|
| Database | Deploy in SA region (AWS me-south-1, Azure UAE North) |
| Certificates | Store in SA-resident HSM or encrypted storage |
| Backups | Replication stays within approved regions |
| Logs | Retain in-region per ZATCA 7-year requirement |

**CLARIFICATION**: The application is jurisdiction-agnostic. Residency compliance is an infrastructure responsibility, not an application feature.

### 11.10 Human Override Governance

**POLICY**: Critical operations may require dual authorization. This is a governance recommendation for enterprise deployments.

**OPERATIONS SUBJECT TO DUAL-AUTHORIZATION POLICY**:

| Operation | Risk Level | Recommended Control |
|-----------|------------|---------------------|
| Certificate revocation | Critical | Two authorized admins |
| Legal hold release | Critical | Legal + IT approval |
| Kill switch extension (>24h) | High | Management approval |
| Bulk data deletion | Critical | Two authorized admins |
| Production config change | High | Change management process |

```php
// config/zatca.php
'governance' => [
    'dual_authorization_recommended' => true,
    'critical_operations' => [
        'certificate_revocation',
        'legal_hold_release',
        'kill_switch_extension',
        'bulk_data_deletion',
        'production_config_change',
    ],
    'enforcement' => 'organizational_policy',
    'audit_all_overrides' => true,
],
```

**CLARIFICATION**: Enforcement is organizational policy, not application logic. Masaar logs all critical operations for audit purposes. Organizations must implement their own approval workflows.

### 11.11 Clock Source Integrity

**POLICY**: System clock must be synchronized via NTP or cloud provider time sync. Timestamp accuracy is critical for ZATCA compliance.

```php
// config/zatca.php
'clock_integrity' => [
    'ntp_required' => true,
    'max_acceptable_drift_ms' => 1000,  // 1 second
    'monitoring_enabled' => true,
    'cloud_sync_preferred' => true,
    'tsa_overrides_local' => true,
],
```

**RECOMMENDED CONFIGURATION**:

| Cloud Provider | Time Sync Method |
|----------------|------------------|
| AWS | Amazon Time Sync Service (chrony) |
| Azure | Windows Time Service (w32time) |
| GCP | Google NTP servers |
| On-Premises | Stratum 1/2 NTP servers with monitoring |

**MONITORING THRESHOLDS**:
- Warning: Clock drift > 500ms
- Critical: Clock drift > 1 second
- Action: Alert ops team, investigate NTP configuration

**TSA OVERRIDE**: When XAdES-T is enabled, the TSA timestamp is authoritative regardless of local clock accuracy. This provides an independent, trusted time source for legal defensibility.

---

## 12. Explicit Non-Goals

**IMPORTANT**: This section defines what Masaar explicitly does NOT do. This protects scope, liability, and roadmap.

### 12.1 Tax Law Interpretation
- Masaar does **NOT** interpret tax law correctness
- We validate format and business rules, not legal compliance
- Tax advice must come from qualified professionals

### 12.2 Business Data Correction
- Masaar does **NOT** auto-correct business data
- Invalid VAT numbers, incorrect amounts, or wrong exemption codes are rejected, not fixed
- Data correction is the responsibility of the calling system

### 12.3 Cross-Tenant Operations
- Masaar does **NOT** support cross-tenant hash chains
- Each organization has its own isolated ICV sequence
- No data sharing between organizations

### 12.4 Retroactive Invoice Mutation
- Masaar does **NOT** allow retroactive changes to submitted invoices
- Once CLEARED/REPORTED, invoices are immutable
- Corrections require credit/debit notes

### 12.5 AI-Based Compliance Decisions
- Masaar does **NOT** use AI for compliance decisions
- All validation is deterministic and rule-based
- No machine learning in the critical path

### 12.6 Real-Time Tax Calculations
- Masaar does **NOT** calculate taxes
- Tax amounts must be provided by the calling system
- We validate calculations match line items

### 12.7 Payment Processing
- Masaar is **NOT** a payment processor
- We handle invoice compliance, not payment collection
- Integration with payment systems is out of scope

---

## 13. Out-of-Scope Tax Types

### 13.1 Import VAT / Customs VAT

**Status**: NOT handled by ZATCA e-invoicing

| Aspect | Details |
|--------|---------|
| Authority | Saudi Customs Authority |
| Reason | Customs VAT is paid at import, outside e-invoicing scope |
| Process | Separate customs declaration at port of entry |
| Recovery | Record as input VAT in accounting system for recovery |

**Recommendation**: Handle import VAT in your accounting/ERP system, not in e-invoicing.

### 13.2 Excise Tax

**Status**: NOT handled by ZATCA e-invoicing

| Aspect | Details |
|--------|---------|
| Authority | ZATCA (separate excise system) |
| Products | Tobacco, energy drinks, sweetened beverages |
| Reason | Excise tax has dedicated reporting system |
| Returns | Separate excise tax returns required |

**Recommendation**: Build a dedicated excise tax module if your business requires it.

### 13.3 Deferred VAT

**Status**: Metadata tracking only

| Aspect | Details |
|--------|---------|
| Authority | ZATCA |
| Schemes | Import deferral, cash accounting, sector-specific |
| Reason | Regulatory-specific, varies by sector |
| Tracking | `InvoiceXmlData.isDeferredVat` flag for metadata |

**Usage**:
```php
$xmlData = new InvoiceXmlData(
    // ... other fields
    isDeferredVat: true,  // Flag for tracking only
);
```

**Recommendation**: Consult with tax advisor for deferred VAT scheme eligibility and handling.

### 13.4 VAT Return Filing

**Status**: Data preparation only (filing is manual)

| Aspect | Details |
|--------|---------|
| Authority | ZATCA Portal |
| Process | Portal submission required |
| Data | Use `VatPeriodTracker::getPeriodSummary()` |
| Payment | Separate process |

**Integration**:
```php
$tracker = new VatPeriodTracker();
$summary = $tracker->getPeriodSummary($organizationId, '2026-01');

// Returns:
// - total_invoices, total_credit_notes, total_debit_notes
// - gross_sales, gross_adjustments, net_taxable
// - vat_collected, vat_adjusted, net_vat_payable
// - cross_period_adjustments (for reconciliation)
```

**Recommendation**: Export `getPeriodSummary()` data to prepare your VAT return, then file manually via ZATCA portal.

---

## Appendix A: ZATCA Business Rules

| Code | Description |
|------|-------------|
| BR-KSA-01 | Invoice number required |
| BR-KSA-13 | VAT number format (15 digits, starts/ends with 3) |
| BR-KSA-25 | Buyer VAT required for B2B |
| BR-KSA-40 | Tax category must be S, Z, E, or O |
| BR-KSA-46 | Exemption reason required for Z, E, O |

## Appendix B: Tax Exemption Codes

| Code | Description |
|------|-------------|
| VATEX-SA-32 | Export of goods |
| VATEX-SA-33 | Export of services |
| VATEX-SA-EDU | Private educational services |
| VATEX-SA-HEA | Private healthcare services |

## Appendix C: Invoice Type Codes

| Type | Subtype | Description |
|------|---------|-------------|
| 388 | 01 | Standard Invoice (B2B) |
| 388 | 02 | Simplified Invoice (B2C) |
| 381 | 01/02 | Credit Note |
| 383 | 01/02 | Debit Note |

---

*Document Version: 1.2*
*Last Updated: January 2026*
*Platform Version: Masaar 1.0.0*
