# Masaar - Security Key Rotation Policy

## Overview

This document outlines the key and credential rotation policies for the Masaar ZATCA e-invoicing platform. Regular rotation of cryptographic keys and credentials is essential for maintaining security and compliance.

## 1. ZATCA Certificate Rotation

### 1.1 Certificate Lifecycle

ZATCA-issued certificates have a defined validity period. Monitor certificate expiry and plan rotation in advance.

```php
use App\Domains\Compliance\Fatoora\Services\CertificateService;

$certificateService = app(CertificateService::class);

// Check certificate expiry
$expiryDate = $certificateService->getExpiryDate($certificatePem);
$daysUntilExpiry = $expiryDate->diff(new DateTimeImmutable())->days;

if ($daysUntilExpiry <= 30) {
    // Trigger certificate renewal process
    Log::warning('ZATCA certificate expiring soon', [
        'expires_at' => $expiryDate->format('Y-m-d H:i:s'),
        'days_remaining' => $daysUntilExpiry,
    ]);
}
```

### 1.2 Certificate Renewal Process

1. **Generate new CSR** with the same organization details
2. **Submit CSR** to ZATCA compliance portal
3. **Receive new certificate** from ZATCA
4. **Validate new certificate** before deployment
5. **Update certificate** in production
6. **Verify signing** with new certificate
7. **Archive old certificate** for audit purposes

```php
// Generate new CSR for renewal
$csrData = new CsrData(
    organizationName: 'Your Company Name',
    organizationUnit: 'ERP System Unit',
    commonName: 'your-egs-unit-serial',
    vatNumber: '300000000000003',
    location: 'Riyadh',
    industry: 'Retail',
    serialNumber: 'EGS/1/2025/serial'
);

$result = $certificateService->generateCsr($csrData);

// Submit $result['csr'] to ZATCA for new certificate
```

### 1.3 Certificate Rotation Schedule

| Certificate Type | Rotation Frequency | Warning Period |
|------------------|-------------------|----------------|
| Production CSID  | Before expiry     | 30 days        |
| Compliance CSID  | Before expiry     | 14 days        |

## 2. API Key Rotation

### 2.1 API Key Lifecycle Policy

- **Maximum age**: 90 days (recommended)
- **Grace period**: 7 days overlap for migration
- **Revocation**: Immediate upon suspected compromise

### 2.2 API Key Rotation Process

```php
// 1. Generate new API key
$newApiKey = ApiKey::create([
    'organization_id' => $organization->id,
    'name' => 'Production Key - ' . now()->format('Y-m'),
    'scopes' => ['invoices:create', 'invoices:read', 'compliance:submit'],
    'expires_at' => now()->addDays(90),
]);

// 2. Notify integrators of new key
event(new ApiKeyRotated($organization, $newApiKey));

// 3. Set deprecation on old key (7-day grace period)
$oldApiKey->update([
    'deprecated_at' => now(),
    'expires_at' => now()->addDays(7),
]);

// 4. After grace period, revoke old key
$oldApiKey->revoke();
```

### 2.3 Client Update Example

```php
// PHP SDK - Update API key
$client = new MasaarClient([
    'base_url' => 'https://api.your-domain.com',
    'api_key' => $newApiKey, // Use new rotated key
]);

// Verify new key works
$health = $client->health();
```

## 3. JWT Token Rotation

### 3.1 Token Configuration

```env
# .env configuration
JWT_SECRET=your-256-bit-secret-key
JWT_TTL=60          # Token lifetime in minutes
JWT_REFRESH_TTL=20160  # Refresh token lifetime (14 days)
```

### 3.2 JWT Secret Rotation

When rotating the JWT secret:

1. **Generate new secret**:
   ```bash
   php artisan jwt:secret --force
   ```

2. **Update environment** on all servers
3. **All active sessions** will be invalidated
4. **Users must re-authenticate**

### 3.3 Token Refresh Flow

```php
// Client-side token refresh before expiry
$response = $httpClient->post('/api/auth/refresh', [
    'headers' => [
        'Authorization' => 'Bearer ' . $currentToken,
    ],
]);

$newToken = $response['access_token'];
$expiresIn = $response['expires_in'];
```

## 4. Encryption Key Rotation

### 4.1 Application Encryption Key

```bash
# Generate new encryption key
php artisan key:generate

# For zero-downtime rotation, use multiple keys
# APP_KEY=base64:new_key
# APP_PREVIOUS_KEYS=base64:old_key1,base64:old_key2
```

### 4.2 Database Encryption

For encrypted database fields, implement gradual re-encryption:

```php
// Re-encrypt sensitive data with new key
Invoice::chunk(1000, function ($invoices) {
    foreach ($invoices as $invoice) {
        // Reading decrypts with old key, saving encrypts with new key
        $invoice->sensitive_data = $invoice->sensitive_data;
        $invoice->save();
    }
});
```

## 5. Private Key Security

### 5.1 Storage Requirements

- Store private keys encrypted at rest
- Use hardware security modules (HSM) for production
- Never commit private keys to version control
- Limit access to authorized personnel only

### 5.2 Private Key Backup

```bash
# Encrypt private key backup
openssl enc -aes-256-cbc -salt -pbkdf2 \
    -in private-key.pem \
    -out private-key.pem.enc

# Store encrypted backup in secure location
# Document decryption passphrase separately
```

### 5.3 Emergency Key Revocation

In case of suspected key compromise:

1. **Immediately generate new key pair**
2. **Submit new CSR to ZATCA**
3. **Revoke old CSID** via ZATCA portal
4. **Audit all invoices** signed with compromised key
5. **Notify affected parties** per incident response plan
6. **Document incident** for compliance records

## 6. Rotation Schedule Summary

| Credential Type    | Rotation Frequency | Automated | Alert Threshold |
|--------------------|-------------------|-----------|-----------------|
| ZATCA Certificate  | Before expiry     | No        | 30 days         |
| API Keys           | 90 days           | Yes       | 14 days         |
| JWT Secret         | 180 days          | No        | 30 days         |
| Encryption Key     | 365 days          | No        | 60 days         |
| Webhook Secrets    | 90 days           | Yes       | 14 days         |

## 7. Monitoring and Alerts

### 7.1 Automated Monitoring

Certificate expiry monitoring is a command, and it is already scheduled in
`routes/console.php`:

```php
Schedule::command('fatoora:check-certificate --notify')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/zatca-certificate.log'));
```

Run it by hand to see the current state:

```bash
php artisan fatoora:check-certificate                    # all organizations
php artisan fatoora:check-certificate --organization=ID  # one
php artisan fatoora:check-certificate --notify           # and send notifications
```

Thresholds come from `ZATCA_CERT_WARNING_DAYS` (default 30) and
`ZATCA_CERT_CRITICAL_DAYS` (default 7); channels from
`ZATCA_CERT_NOTIFY_CHANNELS`.

This section previously carried a closure to paste into
`app/Console/Kernel.php`. That file does not exist — the application is Laravel
12 and schedules in `routes/console.php` — and the closure read
`Organization::whereHas('zatcaCertificate')`, a relation the model does not
have. Certificates live in `CredentialStore`, encrypted on disk, which is why
reading them is a command rather than a query.

### 7.2 Audit Logging

All key rotation events must be logged:

```php
// Log key rotation event
Log::channel('security')->info('Credential rotated', [
    'type' => 'api_key',
    'organization_id' => $organization->id,
    'old_key_id' => $oldKey->id,
    'new_key_id' => $newKey->id,
    'rotated_by' => auth()->id(),
    'reason' => 'scheduled_rotation',
    'ip_address' => request()->ip(),
]);
```

## 8. Compliance Requirements

### 8.1 ZATCA Requirements

- Maintain valid CSID at all times
- Re-onboard if certificate expires
- Keep audit trail of all certificate operations

### 8.2 Security Best Practices

- Use strong, randomly generated secrets (256-bit minimum)
- Implement least-privilege access to credentials
- Encrypt credentials in transit and at rest
- Maintain separation between environments

## 9. Emergency Contacts

In case of security incidents involving credentials:

1. **Security Team**: security@{YOUR_DOMAIN}
2. **ZATCA Support**: (As per your ZATCA agreement)
3. **On-call Engineer**: (Your internal contact)

---

**Document Version**: 1.0
**Last Updated**: January 2026
**Review Schedule**: Quarterly
