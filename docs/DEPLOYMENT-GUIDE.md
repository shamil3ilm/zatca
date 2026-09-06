# Masaar Deployment Guide

Complete deployment guide for production-ready ZATCA e-invoicing infrastructure.

## Table of Contents

1. [Infrastructure Requirements](#1-infrastructure-requirements)
2. [Server Setup](#2-server-setup)
3. [Database Configuration](#3-database-configuration)
4. [Redis Setup](#4-redis-setup)
5. [Queue Workers (Supervisor)](#5-queue-workers-supervisor)
6. [Time Synchronization](#6-time-synchronization)
7. [SSL/TLS Configuration](#7-ssltls-configuration)
8. [Storage Configuration](#8-storage-configuration)
9. [Environment Configuration](#9-environment-configuration)
10. [Pre-Launch Verification](#10-pre-launch-verification)
11. [Disaster Recovery Testing](#11-disaster-recovery-testing)
12. [Monitoring & Alerting](#12-monitoring--alerting)

---

## 1. Infrastructure Requirements

### Minimum Production Specs

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| CPU | 2 cores | 4 cores |
| RAM | 4 GB | 8 GB |
| Storage | 50 GB SSD | 100 GB SSD |
| Network | 100 Mbps | 1 Gbps |

### Required Services

| Service | Purpose | Critical |
|---------|---------|----------|
| PostgreSQL 15+ | Primary database | ✅ YES |
| Redis 7+ | Cache, queues, ICV locks | ✅ YES |
| Nginx | Web server | ✅ YES |
| PHP 8.4-FPM | Application runtime | ✅ YES |
| Supervisor | Queue workers | ✅ YES |
| NTP | Time synchronization | ✅ YES |
| S3/MinIO | XML archival | ✅ YES |

---

## 2. Server Setup

### Ubuntu 22.04 LTS

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y \
    nginx \
    php8.4-fpm \
    php8.4-cli \
    php8.4-pgsql \
    php8.4-redis \
    php8.4-xml \
    php8.4-curl \
    php8.4-mbstring \
    php8.4-zip \
    php8.4-bcmath \
    php8.4-gmp \
    supervisor \
    redis-server \
    certbot \
    python3-certbot-nginx

# Install PostgreSQL 15
sudo sh -c 'echo "deb http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list'
wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc | sudo apt-key add -
sudo apt update
sudo apt install -y postgresql-15
```

### PHP Configuration

Edit `/etc/php/8.4/fpm/php.ini`:

```ini
; CRITICAL: Set timezone to UTC
date.timezone = UTC

; Memory for XML processing
memory_limit = 256M

; Upload limits
upload_max_filesize = 10M
post_max_size = 10M

; Execution time for ZATCA API calls
max_execution_time = 120

; OpenSSL for cryptographic operations
extension=openssl
```

---

## 3. Database Configuration

### PostgreSQL Setup

```bash
# Create database and user
sudo -u postgres psql << EOF
CREATE USER masaar WITH PASSWORD 'your-strong-password-here';
CREATE DATABASE masaar_prod OWNER masaar;
GRANT ALL PRIVILEGES ON DATABASE masaar_prod TO masaar;
EOF
```

### Transaction Isolation (CRITICAL)

Verify isolation level:

```sql
-- Connect to database
psql -U masaar -d masaar_prod

-- Check isolation level (MUST be READ COMMITTED or higher)
SHOW default_transaction_isolation;
-- Expected: read committed

-- If needed, set in postgresql.conf:
-- default_transaction_isolation = 'read committed'
```

**WARNING**: Never use `READ UNCOMMITTED`. It will cause ICV race conditions.

### PostgreSQL Tuning

Edit `/etc/postgresql/15/main/postgresql.conf`:

```ini
# Connection settings
max_connections = 100
shared_buffers = 2GB
effective_cache_size = 6GB
work_mem = 32MB

# WAL for point-in-time recovery
wal_level = replica
max_wal_senders = 3
wal_keep_size = 1GB

# Logging
log_statement = 'mod'
log_min_duration_statement = 1000
```

---

## 4. Redis Setup

### Configuration

Edit `/etc/redis/redis.conf`:

```ini
# Persistence (CRITICAL for ICV state)
appendonly yes
appendfsync everysec

# Memory
maxmemory 1gb
maxmemory-policy allkeys-lru

# Security
requirepass your-redis-password-here
bind 127.0.0.1

# Disable dangerous commands
rename-command FLUSHDB ""
rename-command FLUSHALL ""
rename-command DEBUG ""
```

### Verify Redis

```bash
redis-cli -a your-redis-password ping
# Expected: PONG
```

---

## 5. Queue Workers (Supervisor)

### Supervisor Configuration

Create `/etc/supervisor/conf.d/masaar.conf`:

```ini
;--------------------------------------------
; ZATCA Submissions Worker (HIGH PRIORITY)
;--------------------------------------------
; Dedicated workers for clearance/reporting
; Do NOT mix with long-running jobs
;--------------------------------------------
[program:masaar-zatca-submissions]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/masaar/artisan queue:work redis --queue=zatca-submissions --sleep=3 --tries=3 --max-time=3600 --memory=256
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/masaar/zatca-submissions.log
stopwaitsecs=120

;--------------------------------------------
; Webhook Delivery Worker
;--------------------------------------------
; Separate worker to prevent blocking submissions
;--------------------------------------------
[program:masaar-webhooks]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/masaar/artisan queue:work redis --queue=webhooks --sleep=3 --tries=5 --max-time=3600 --memory=128
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/masaar/webhooks.log
stopwaitsecs=60

;--------------------------------------------
; Default Queue Worker
;--------------------------------------------
; For non-critical background jobs
;--------------------------------------------
[program:masaar-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/masaar/artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-time=3600 --memory=128
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/masaar/default.log
stopwaitsecs=60

;--------------------------------------------
; Laravel Scheduler
;--------------------------------------------
[program:masaar-scheduler]
process_name=%(program_name)s
command=/bin/bash -c "while [ true ]; do php /var/www/masaar/artisan schedule:run --verbose --no-interaction >> /var/log/masaar/scheduler.log 2>&1; sleep 60; done"
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/masaar/scheduler.log
```

### Create Log Directory

```bash
sudo mkdir -p /var/log/masaar
sudo chown www-data:www-data /var/log/masaar
```

### Start Workers

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all

# Verify status
sudo supervisorctl status
```

Expected output:
```
masaar-zatca-submissions:masaar-zatca-submissions_00   RUNNING   pid 12345, uptime 0:01:00
masaar-zatca-submissions:masaar-zatca-submissions_01   RUNNING   pid 12346, uptime 0:01:00
masaar-webhooks:masaar-webhooks_00                      RUNNING   pid 12347, uptime 0:01:00
masaar-default:masaar-default_00                        RUNNING   pid 12348, uptime 0:01:00
masaar-scheduler                                           RUNNING   pid 12349, uptime 0:01:00
```

---

## 6. Time Synchronization (CRITICAL)

### Why This Matters

- XAdES-T timestamps require accurate time
- ZATCA may reject invoices with significant clock drift
- Audit disputes can arise from timestamp inconsistencies

### NTP Configuration

```bash
# Install chrony (better than ntpd)
sudo apt install -y chrony

# Configure chrony
sudo tee /etc/chrony/chrony.conf << EOF
# Use multiple NTP servers for accuracy
server 0.pool.ntp.org iburst
server 1.pool.ntp.org iburst
server 2.pool.ntp.org iburst
server time.google.com iburst

# Allow stepping clock at startup
makestep 1.0 3

# Record drift
driftfile /var/lib/chrony/drift

# Log
logdir /var/log/chrony
EOF

# Restart chrony
sudo systemctl restart chrony
sudo systemctl enable chrony
```

### Verify Time Sync

```bash
# Check sync status
chronyc tracking

# Expected output should show:
# Leap status     : Normal
# Stratum         : 2-4
# System time     : 0.000XXXXXX seconds fast/slow of NTP time

# Check sources
chronyc sources -v

# Verify drift is < 1 second
chronyc tracking | grep "System time"
```

### Monitor Clock Drift

The platform already refuses an invoice whose timestamp is more than 30 seconds
out — `TimestampValidator::MAX_DRIFT_SECONDS`, a constant rather than a setting,
so there is no environment variable to tune it. What follows is host-level
monitoring, which tells you before invoices start being refused.

Add to the scheduler in `routes/console.php` — this application is Laravel 12
and has no `app/Console/Kernel.php`:

```php
Schedule::call(function () {
    $drift = shell_exec("chronyc tracking | grep 'System time' | awk '{print $4}'");
    $driftSeconds = abs((float) $drift);

    if ($driftSeconds > 1) {
        Log::critical('Clock drift exceeds 1 second', ['drift' => $driftSeconds]);
        // Alert ops team
    }
})->everyFiveMinutes();
```

---

## 7. SSL/TLS Configuration

### Let's Encrypt with Nginx

```bash
# Obtain certificate
sudo certbot --nginx -d api.masaar.sa -d sandbox.masaar.sa

# Verify auto-renewal
sudo certbot renew --dry-run
```

### Nginx Configuration

Create `/etc/nginx/sites-available/masaar`:

```nginx
server {
    listen 80;
    server_name api.masaar.sa sandbox.masaar.sa;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.masaar.sa;

    root /var/www/masaar/public;
    index index.php;

    ssl_certificate /etc/letsencrypt/live/api.masaar.sa/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.masaar.sa/privkey.pem;

    # Modern SSL configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Health check endpoint (no auth required)
    location /api/health {
        try_files $uri /index.php?$query_string;
    }
}
```

Enable site:

```bash
sudo ln -s /etc/nginx/sites-available/masaar /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 8. Storage Configuration

### S3-Compatible Storage (MinIO or AWS S3)

Signed XMLs must be retained for **7 years** per ZATCA regulations.

```bash
# Install AWS CLI
sudo apt install -y awscli

# Configure (for AWS S3 or MinIO)
aws configure
```

### Storage Structure

```
s3://masaar-invoices/
├── {organization_id}/
│   ├── {year}/
│   │   ├── {month}/
│   │   │   ├── {invoice_uuid}.xml      # Signed XML
│   │   │   ├── {invoice_uuid}.json     # Metadata
│   │   │   └── {invoice_uuid}.qr.png   # QR code image
```

### Lifecycle Policy (AWS S3)

```json
{
    "Rules": [
        {
            "ID": "ZatcaRetention",
            "Status": "Enabled",
            "Filter": {},
            "Transitions": [
                {
                    "Days": 365,
                    "StorageClass": "STANDARD_IA"
                },
                {
                    "Days": 730,
                    "StorageClass": "GLACIER"
                }
            ],
            "Expiration": {
                "Days": 2555
            }
        }
    ]
}
```

Note: 2555 days ≈ 7 years (ZATCA retention requirement)

---

## 9. Environment Configuration

### Production .env

```env
#--------------------------------------------
# Application
#--------------------------------------------
APP_NAME=Masaar
APP_ENV=production
APP_KEY=base64:your-generated-key
APP_DEBUG=false
APP_URL=https://api.masaar.sa

#--------------------------------------------
# Database (CRITICAL)
#--------------------------------------------
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=masaar_prod
DB_USERNAME=masaar
DB_PASSWORD=your-strong-password

#--------------------------------------------
# Redis (CRITICAL for ICV atomicity)
#--------------------------------------------
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=your-redis-password
REDIS_PORT=6379

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

#--------------------------------------------
# Storage (7-year retention)
#--------------------------------------------
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=me-south-1
AWS_BUCKET=masaar-invoices
AWS_USE_PATH_STYLE_ENDPOINT=false

#--------------------------------------------
# ZATCA Configuration
#--------------------------------------------
# The endpoint follows from the environment — config/fatoora.php holds one URL
# per environment under 'endpoints', so there is no separate API URL to set.
ZATCA_ENVIRONMENT=production

# TLS verification. Defaults to true; the only reason to set it is to turn it
# off, which you should not do against production.
ZATCA_SSL_VERIFY=true

# Certificate notifications
ZATCA_CERT_NOTIFICATIONS_ENABLED=true
ZATCA_CERT_NOTIFY_CHANNELS=mail,webhook
ZATCA_CERT_WARNING_DAYS=30
ZATCA_CERT_CRITICAL_DAYS=7

# Credential encryption at rest
ZATCA_CREDENTIAL_DISK=local
ZATCA_CREDENTIAL_KEY=
ZATCA_CREDENTIAL_PREVIOUS_KEYS=

#--------------------------------------------
# Queue Configuration
#--------------------------------------------
# Null means the application's own QUEUE_CONNECTION.
ZATCA_QUEUE_CONNECTION=
ZATCA_QUEUE_NAME=zatca-submissions
ZATCA_QUEUE_WEBHOOKS=webhooks
ZATCA_QUEUE_TRIES=3
ZATCA_QUEUE_TIMEOUT=120

#--------------------------------------------
# Logging
#--------------------------------------------
LOG_CHANNEL=stack
LOG_LEVEL=info
LOG_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/xxx

#--------------------------------------------
# Mail (for notifications)
#--------------------------------------------
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=your-mailgun-username
MAIL_PASSWORD=your-mailgun-password
MAIL_FROM_ADDRESS=noreply@masaar.sa
MAIL_FROM_NAME="Masaar Notifications"
```

### Deploy Application

```bash
cd /var/www/masaar

# Install dependencies
composer install --no-dev --optimize-autoloader

# Generate key (first time only)
php artisan key:generate

# Run migrations
php artisan migrate --force

# Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

---

## 10. Pre-Launch Verification

### Verification Script

Create `scripts/verify-deployment.sh`:

```bash
#!/bin/bash

echo "=========================================="
echo "Masaar Pre-Launch Verification"
echo "=========================================="

ERRORS=0

# 1. Check PHP version
echo -n "PHP 8.4+: "
PHP_VERSION=$(php -v | head -n 1 | cut -d " " -f 2 | cut -d "." -f 1,2)
if [[ "$PHP_VERSION" == "8.4" ]]; then
    echo "✅ OK ($PHP_VERSION)"
else
    echo "❌ FAIL (got $PHP_VERSION)"
    ((ERRORS++))
fi

# 2. Check timezone
echo -n "Timezone UTC: "
TZ=$(php -r "echo date_default_timezone_get();")
if [[ "$TZ" == "UTC" ]]; then
    echo "✅ OK"
else
    echo "❌ FAIL (got $TZ)"
    ((ERRORS++))
fi

# 3. Check Redis connection
echo -n "Redis connection: "
if php artisan tinker --execute="Redis::ping();" 2>/dev/null | grep -q "PONG"; then
    echo "✅ OK"
else
    echo "❌ FAIL"
    ((ERRORS++))
fi

# 4. Check database connection
echo -n "Database connection: "
if php artisan tinker --execute="DB::connection()->getPdo();" 2>/dev/null; then
    echo "✅ OK"
else
    echo "❌ FAIL"
    ((ERRORS++))
fi

# 5. Check database isolation level
echo -n "DB Isolation Level: "
ISOLATION=$(php artisan tinker --execute="echo DB::select(\"SHOW default_transaction_isolation\")[0]->default_transaction_isolation;" 2>/dev/null)
if [[ "$ISOLATION" == "read committed" ]] || [[ "$ISOLATION" == "serializable" ]]; then
    echo "✅ OK ($ISOLATION)"
else
    echo "❌ FAIL (got $ISOLATION)"
    ((ERRORS++))
fi

# 6. Check NTP sync
echo -n "NTP Sync: "
if chronyc tracking 2>/dev/null | grep -q "Normal"; then
    echo "✅ OK"
else
    echo "❌ FAIL (chrony not running or out of sync)"
    ((ERRORS++))
fi

# 7. Check clock drift
echo -n "Clock Drift: "
DRIFT=$(chronyc tracking 2>/dev/null | grep "System time" | awk '{print $4}')
DRIFT_ABS=$(echo "$DRIFT" | tr -d '-')
if (( $(echo "$DRIFT_ABS < 1" | bc -l) )); then
    echo "✅ OK (${DRIFT}s)"
else
    echo "❌ FAIL (${DRIFT}s drift)"
    ((ERRORS++))
fi

# 8. Check queue workers
echo -n "Queue Workers: "
WORKERS=$(supervisorctl status | grep -c "RUNNING")
if [[ $WORKERS -ge 4 ]]; then
    echo "✅ OK ($WORKERS running)"
else
    echo "⚠️ WARNING (only $WORKERS running, expected 4+)"
fi

# 9. Check SSL certificate
echo -n "SSL Certificate: "
EXPIRY=$(echo | openssl s_client -servername api.masaar.sa -connect api.masaar.sa:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
if [[ -n "$EXPIRY" ]]; then
    echo "✅ OK (expires: $EXPIRY)"
else
    echo "❌ FAIL (cannot verify)"
    ((ERRORS++))
fi

# 10. Check storage
echo -n "S3 Storage: "
if php artisan tinker --execute="Storage::disk('s3')->exists('.');" 2>/dev/null; then
    echo "✅ OK"
else
    echo "❌ FAIL"
    ((ERRORS++))
fi

echo "=========================================="
if [[ $ERRORS -eq 0 ]]; then
    echo "✅ All checks passed. Ready for launch!"
    exit 0
else
    echo "❌ $ERRORS critical errors found. Fix before launch."
    exit 1
fi
```

### Run Verification

```bash
chmod +x scripts/verify-deployment.sh
./scripts/verify-deployment.sh
```

---

## 11. Disaster Recovery Testing

### Monthly DR Test Procedure

```bash
#!/bin/bash
# scripts/test-disaster-recovery.sh

echo "=========================================="
echo "Disaster Recovery Test"
echo "Started: $(date)"
echo "=========================================="

# 1. Record pre-disaster state
echo "Step 1: Recording pre-disaster state..."
PRE_INVOICE_COUNT=$(php artisan tinker --execute="echo App\Domains\Invoice\Models\Invoice::count();")
PRE_LATEST_ICV=$(php artisan tinker --execute="echo App\Domains\Invoice\Models\Invoice::max('icv');")
PRE_LATEST_HASH=$(php artisan tinker --execute="echo App\Domains\Invoice\Models\Invoice::latest()->first()?->hash;")

echo "  Invoices: $PRE_INVOICE_COUNT"
echo "  Latest ICV: $PRE_LATEST_ICV"
echo "  Latest Hash: $PRE_LATEST_HASH"

# 2. Create backup
echo "Step 2: Creating backup..."
BACKUP_FILE="/tmp/dr-test-$(date +%Y%m%d-%H%M%S).sql"
pg_dump -U masaar masaar_prod > "$BACKUP_FILE"
echo "  Backup: $BACKUP_FILE ($(du -h $BACKUP_FILE | cut -f1))"

# 3. Simulate disaster (create test database)
echo "Step 3: Simulating disaster..."
sudo -u postgres psql -c "CREATE DATABASE masaar_dr_test;"

# 4. Restore from backup
echo "Step 4: Restoring from backup..."
RESTORE_START=$(date +%s)
psql -U masaar masaar_dr_test < "$BACKUP_FILE"
RESTORE_END=$(date +%s)
RESTORE_TIME=$((RESTORE_END - RESTORE_START))
echo "  Restore time: ${RESTORE_TIME}s"

# 5. Verify hash chain integrity
echo "Step 5: Verifying hash chain integrity..."
# This should be a dedicated artisan command
php artisan fatoora:verify-hash-chain --database=masaar_dr_test

# 6. Compare counts
echo "Step 6: Comparing invoice counts..."
POST_INVOICE_COUNT=$(psql -U masaar -d masaar_dr_test -t -c "SELECT COUNT(*) FROM invoices;")
if [[ "$PRE_INVOICE_COUNT" == "$POST_INVOICE_COUNT" ]]; then
    echo "  ✅ Invoice count matches: $POST_INVOICE_COUNT"
else
    echo "  ❌ Invoice count mismatch: $PRE_INVOICE_COUNT vs $POST_INVOICE_COUNT"
fi

# 7. Cleanup
echo "Step 7: Cleaning up..."
sudo -u postgres psql -c "DROP DATABASE masaar_dr_test;"
rm "$BACKUP_FILE"

echo "=========================================="
echo "Disaster Recovery Test Complete"
echo "Restore Time: ${RESTORE_TIME}s"
echo "=========================================="
```

### Hash Chain Verification Command

Create `app/Console/Commands/VerifyHashChain.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domains\Invoice\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyHashChain extends Command
{
    protected $signature = 'fatoora:verify-hash-chain {--database=}';
    protected $description = 'Verify PIH (Previous Invoice Hash) chain integrity';

    public function handle(): int
    {
        if ($database = $this->option('database')) {
            config(['database.connections.pgsql.database' => $database]);
            DB::purge('pgsql');
        }

        $this->info('Verifying hash chain integrity...');

        $invoices = Invoice::orderBy('icv')->get(['id', 'icv', 'hash', 'previous_invoice_hash']);

        $errors = 0;
        $previousHash = base64_encode(str_repeat("\0", 32)); // First invoice PIH

        foreach ($invoices as $invoice) {
            if ($invoice->previous_invoice_hash !== $previousHash) {
                $this->error("ICV {$invoice->icv}: PIH mismatch");
                $this->line("  Expected: {$previousHash}");
                $this->line("  Got: {$invoice->previous_invoice_hash}");
                $errors++;
            }
            $previousHash = $invoice->hash;
        }

        if ($errors === 0) {
            $this->info("✅ Hash chain verified. {$invoices->count()} invoices checked.");
            return Command::SUCCESS;
        }

        $this->error("❌ {$errors} hash chain errors found.");
        return Command::FAILURE;
    }
}
```

---

## 12. Monitoring & Alerting

### Key Metrics to Monitor

| Metric | Warning | Critical | Action |
|--------|---------|----------|--------|
| Clock drift | > 0.5s | > 1s | Check NTP |
| Queue depth (zatca) | > 100 | > 500 | Scale workers |
| API response time | > 5s | > 30s | Check ZATCA status |
| Certificate expiry | 30 days | 7 days | Renew certificate |
| Disk usage | > 80% | > 90% | Archive or expand |
| Failed jobs | > 10/hour | > 50/hour | Investigate errors |

### Prometheus Metrics (Example)

```php
// Add to a middleware or dedicated endpoint
$metrics = [
    'masaar_invoices_total' => Invoice::count(),
    'masaar_invoices_cleared' => Invoice::where('zatca_status', 'cleared')->count(),
    'masaar_invoices_failed' => Invoice::where('zatca_status', 'failed')->count(),
    'masaar_queue_depth' => Redis::llen('queues:zatca-submissions'),
    'masaar_certificate_days_remaining' => $certificateService->getDaysUntilExpiry(),
];
```

### Slack Alert Example

```php
// In exception handler or dedicated alert service
if ($clockDrift > 1.0) {
    Notification::route('slack', config('logging.channels.slack.url'))
        ->notify(new ClockDriftAlert($clockDrift));
}
```

---

## Quick Reference

### Common Commands

```bash
# Check application status
php artisan about

# View queue status
php artisan queue:monitor zatca-submissions,webhooks,default

# Clear caches (careful in production)
php artisan cache:clear
php artisan config:clear

# Check failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Check certificate expiry
php artisan fatoora:check-certificate

# Verify hash chain
php artisan fatoora:verify-hash-chain
```

### Emergency Procedures

**ZATCA API Down:**
```bash
# Pause ZATCA submission workers
sudo supervisorctl stop masaar-zatca-submissions:*

# Jobs will accumulate in Redis
# Resume when ZATCA is back
sudo supervisorctl start masaar-zatca-submissions:*
```

**Database Recovery:**
```bash
# Stop all workers
sudo supervisorctl stop all

# Restore from backup
pg_restore -U masaar -d masaar_prod backup.dump

# Verify hash chain
php artisan fatoora:verify-hash-chain

# Resume workers
sudo supervisorctl start all
```

---

*Document Version: 1.0*
*Last Updated: 2026-02-02*
