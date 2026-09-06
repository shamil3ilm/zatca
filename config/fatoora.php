<?php

/**
 * ZATCA E-Invoicing Configuration.
 *
 * Centralized configuration for ZATCA compliance operations.
 * All values can be overridden via environment variables.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | ZATCA Environment
    |--------------------------------------------------------------------------
    |
    | Controls which ZATCA API environment to use.
    | Options: 'sandbox', 'simulation', 'production'
    |
    */
    'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'sandbox' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
        'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
        'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    |
    | Credentials issued by ZATCA for API authentication.
    |
    */
    'credentials' => [
        'username' => env('ZATCA_USERNAME'),
        'password' => env('ZATCA_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Settings
    |--------------------------------------------------------------------------
    |
    | CSID (Cryptographic Stamp Identifier) for signing invoices.
    |
    */
    'certificate' => [
        'path' => env('ZATCA_CERTIFICATE_PATH'),
        'private_key_path' => env('ZATCA_PRIVATE_KEY_PATH'),
        'expiry_warning_days' => env('ZATCA_CERT_WARNING_DAYS', 30),
        'expiry_critical_days' => env('ZATCA_CERT_CRITICAL_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | CSID Credential Storage
    |--------------------------------------------------------------------------
    |
    | Where each taxpayer's signing keys are kept. The default keeps them on
    | the container's own filesystem, which confines the platform to a single
    | replica: a tenant onboarded on one instance cannot sign on another.
    |
    | Point this at a shared disk before scaling out. Whatever it names must be
    | private — these are the keys behind every invoice the platform stamps.
    |
    */
    'signing' => [
        'disk' => env('ZATCA_CREDENTIAL_DISK', 'local'),

        /*
        | The key these credentials are encrypted under.
        |
        | Empty means APP_KEY, which works but is broad: APP_KEY also protects
        | sessions and cookies, sits in every container and worker, and is on
        | any machine holding a production .env. A signing key is the private
        | half of a taxpayer's non-repudiation, so it is worth a secret that
        | fewer things hold and that routine APP_KEY rotation does not touch.
        |
        | Set it and run masaar:rotate-credential-key, keeping the previous
        | value in previous_keys until that reports no failures.
        */
        'key' => env('ZATCA_CREDENTIAL_KEY', ''),

        'previous_keys' => array_values(array_filter(
            explode(',', (string) env('ZATCA_CREDENTIAL_PREVIOUS_KEYS', ''))
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Settings
    |--------------------------------------------------------------------------
    */
    'timeout' => env('ZATCA_TIMEOUT', 30),
    'connect_timeout' => env('ZATCA_CONNECT_TIMEOUT', 10),
    'retry_attempts' => env('ZATCA_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('ZATCA_RETRY_DELAY', 1000), // milliseconds
    'ssl_verify' => env('ZATCA_SSL_VERIFY', true),

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Protect against excessive API usage and ensure fair resource allocation.
    |
    */
    'rate_limits' => [
        'per_minute' => env('ZATCA_RATE_LIMIT_PER_MINUTE', 60),
        'per_day' => env('ZATCA_RATE_LIMIT_PER_DAY', 10000),
        'max_concurrent' => env('ZATCA_MAX_CONCURRENT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Prevent duplicate submissions during retries.
    |
    */
    'idempotency' => [
        'window_hours' => env('ZATCA_IDEMPOTENCY_HOURS', 24),
        // SCOPE DECLARATION: Idempotency applies per organization + endpoint + idempotency_key
        // Keys are valid for 24 hours from first request. Same key from different
        // organizations or to different endpoints are treated as separate requests.
        'scope' => 'organization + endpoint + key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'large_invoice_amount' => env('ZATCA_LARGE_INVOICE_THRESHOLD', 1000000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline Mode
    |--------------------------------------------------------------------------
    |
    | Queue invoices when ZATCA is unavailable (for POS/retail scenarios).
    |
    */
    'offline' => [
        // Enable offline mode capability
        'enabled' => env('ZATCA_OFFLINE_ENABLED', true),

        // Maximum invoices that can be queued per organization
        'queue_max_size' => env('ZATCA_OFFLINE_QUEUE_MAX', 10000),

        // Retry interval for processing offline queue (seconds)
        'retry_interval' => env('ZATCA_OFFLINE_RETRY_INTERVAL', 300),

        // Maximum retry attempts before permanent failure
        'max_attempts' => env('ZATCA_OFFLINE_MAX_ATTEMPTS', 3),

        /*
        |----------------------------------------------------------------------
        | Connectivity Checking
        |----------------------------------------------------------------------
        | Settings for detecting ZATCA API availability and auto-switching
        | to offline mode when connectivity fails.
        |
        */
        'connectivity' => [
            // How often to check connectivity (seconds)
            'check_interval' => env('ZATCA_CONNECTIVITY_CHECK_INTERVAL', 30),

            // Request timeout for connectivity check (seconds)
            'timeout' => env('ZATCA_CONNECTIVITY_TIMEOUT', 10),

            // Number of failures before opening circuit breaker
            'failure_threshold' => env('ZATCA_CONNECTIVITY_FAILURE_THRESHOLD', 3),

            // Duration circuit breaker stays open (seconds)
            'circuit_open_duration' => env('ZATCA_CONNECTIVITY_CIRCUIT_DURATION', 60),
        ],

        /*
        |----------------------------------------------------------------------
        | Local Signing (Offline Capable)
        |----------------------------------------------------------------------
        | When enabled, invoices can be signed locally without server
        | connectivity. The signed invoice is queued for later submission.
        |
        | IMPORTANT: Local signing still requires the organization's
        | certificate and private key to be available locally.
        |
        */
        'local_signing' => [
            // Enable local signing for offline scenarios
            'enabled' => env('ZATCA_LOCAL_SIGNING_ENABLED', true),

            // Generate QR code locally (Phase 1 compatible)
            'generate_qr' => env('ZATCA_LOCAL_QR_ENABLED', true),

            // Store signed XML in local storage if DB is unavailable
            'local_storage_fallback' => env('ZATCA_LOCAL_STORAGE_FALLBACK', true),

            // Path for local storage fallback
            'fallback_storage_path' => env('ZATCA_FALLBACK_PATH', storage_path('app/zatca/offline')),
        ],

        /*
        |----------------------------------------------------------------------
        | Auto-Recovery
        |----------------------------------------------------------------------
        | Settings for automatic processing when connectivity is restored.
        |
        */
        'auto_recovery' => [
            // Automatically process queue when online
            'enabled' => env('ZATCA_AUTO_RECOVERY_ENABLED', true),

            // Maximum items to process per batch
            'batch_size' => env('ZATCA_AUTO_RECOVERY_BATCH', 50),

            // Delay between batches (seconds)
            'batch_delay' => env('ZATCA_AUTO_RECOVERY_DELAY', 5),

            // Process in background (via scheduler) vs synchronous
            'background' => env('ZATCA_AUTO_RECOVERY_BACKGROUND', true),
        ],

        /*
        |----------------------------------------------------------------------
        | POS/Retail Mode
        |----------------------------------------------------------------------
        | Special settings for Point-of-Sale systems that must issue
        | invoices immediately regardless of connectivity.
        |
        */
        'pos_mode' => [
            // Enable POS mode (immediate local completion)
            'enabled' => env('ZATCA_POS_MODE_ENABLED', false),

            // Return QR code immediately (don't wait for clearance)
            'immediate_qr' => env('ZATCA_POS_IMMEDIATE_QR', true),

            // Maximum offline invoice value (SAR) - higher values need warning
            'max_offline_value' => env('ZATCA_POS_MAX_OFFLINE_VALUE', 50000),

            // Warn if offline queue exceeds this size
            'queue_warning_threshold' => env('ZATCA_POS_QUEUE_WARNING', 100),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timestamp Authority (TSA) for XAdES-T
    |--------------------------------------------------------------------------
    |
    | Optional timestamp server for XAdES-T signatures.
    |
    */
    'tsa' => [
        'enabled' => env('ZATCA_TSA_ENABLED', false),
        'url' => env('ZATCA_TSA_URL'),
        'username' => env('ZATCA_TSA_USERNAME'),
        'password' => env('ZATCA_TSA_PASSWORD'),
        'timeout' => env('ZATCA_TSA_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    |
    | Configurable validation rules for ZATCA compliance.
    | These can be updated as ZATCA regulations change.
    |
    */
    'validation' => [
        // Where the ZATCA UBL 2.1 schema set is installed, relative to the
        // project root or absolute. ZATCA publishes it with their SDK, which is
        // a licensed download and cannot live in this repository, so with
        // nothing here InvoiceValidator checks that a document is well formed
        // and says so rather than implying it checked more.
        //
        // Point it at the main document, e.g.
        // resources/zatca/xsd/maindoc/UBL-Invoice-2.1.xsd, and keep the whole
        // tree: that file imports the common component schemas beside it.
        'schema_path' => env('ZATCA_SCHEMA_PATH'),

        // Allowed VAT rates in Saudi Arabia (percentage values)
        'allowed_tax_rates' => [0, 15],

        // Valid invoice type codes per UBL 2.1 / ZATCA
        // Note: '325' (Proforma) is NOT valid for ZATCA submission
        'invoice_type_codes' => [
            '388' => 'Tax Invoice',
            '381' => 'Credit Note',
            '383' => 'Debit Note',
            '386' => 'Prepayment Invoice',
        ],

        // Invoice types that can be submitted to ZATCA
        // Proforma (325) is explicitly excluded
        'zatca_submittable_types' => ['388', '381', '383', '386'],

        // Valid buyer identification schemes (for non-VAT registered buyers)
        'buyer_id_schemes' => [
            'TIN' => 'Tax Identification Number',
            'CRN' => 'Commercial Registration Number',
            'MOM' => 'Momra License',
            'MLS' => 'MLSD License',
            'SAG' => 'Sagia License',
            'NAT' => 'National ID (Saudis)',
            'GCC' => 'GCC ID',
            'IQA' => 'Iqama Number',
            'PAS' => 'Passport Number',
            'OTH' => 'Other ID',
        ],

        // Valid tax exemption reason codes (VATEX-SA-*)
        // Source: ZATCA E-Invoicing Implementation Guidelines
        'exemption_codes' => [
            // Zero-rated supplies (Z) - Article 32 & 33
            'VATEX-SA-29' => 'Supply of qualified metals',
            'VATEX-SA-29-7' => 'Supply of eligible goods to SEZ',
            'VATEX-SA-30' => 'Medicines and medical equipment',
            'VATEX-SA-31' => 'Transport services for goods/passengers',
            'VATEX-SA-32' => 'Export of goods',
            'VATEX-SA-33' => 'Export of services',
            'VATEX-SA-34-1' => 'Intra-GCC supply of goods',
            'VATEX-SA-34-2' => 'Intra-GCC supply of services',
            'VATEX-SA-34-3' => 'Intra-GCC supply to GCC government',
            'VATEX-SA-34-4' => 'Intra-GCC supply of tourist services',
            'VATEX-SA-34-5' => 'Intra-GCC supply via agent',
            'VATEX-SA-35' => 'First supply of residential real estate within 3 years',
            'VATEX-SA-36' => 'Transfer of qualifying assets between related parties',

            // Exempt supplies (E) - Article 29 & 30
            'VATEX-SA-EDU' => 'Private education services',
            'VATEX-SA-HEA' => 'Private healthcare services',
            'VATEX-SA-29-1' => 'Financial services - margin based',
            'VATEX-SA-29-2' => 'Life insurance services',
            'VATEX-SA-29-3' => 'Real estate lease - residential',

            // Out of scope (O)
            'VATEX-SA-OOS' => 'Out of scope supply',
        ],

        // Strict mode: reject invoices with unknown exemption codes
        'strict_exemption_codes' => env('ZATCA_STRICT_EXEMPTION_CODES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable/disable specific features for gradual rollout or testing.
    |
    */
    'features' => [
        'async_submission' => env('ZATCA_FEATURE_ASYNC', true),
        'offline_mode' => env('ZATCA_FEATURE_OFFLINE', true),
        'circuit_breaker' => env('ZATCA_FEATURE_CIRCUIT_BREAKER', true),
        'timestamp_authority' => env('ZATCA_FEATURE_TSA', false),
        'certificate_revocation_check' => env('ZATCA_FEATURE_CRL_CHECK', true),
        'arabic_normalization' => env('ZATCA_FEATURE_ARABIC_NORM', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for async submission queue processing.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Redis-backed so the breaker opens for every node at once.
    |
    */
    'circuit_breaker' => [
        'failure_threshold' => env('ZATCA_CCB_FAILURE_THRESHOLD', 5),
        'success_threshold' => env('ZATCA_CCB_SUCCESS_THRESHOLD', 3),
        'timeout_seconds' => env('ZATCA_CCB_TIMEOUT', 60),
        'half_open_max_requests' => env('ZATCA_CCB_HALF_OPEN_REQUESTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kill Switch
    |--------------------------------------------------------------------------
    |
    | Emergency submission halt configuration.
    |
    */
    'kill_switch' => [
        'max_duration_seconds' => env('ZATCA_KS_MAX_DURATION', 14400), // 4 hours
        'alert_threshold_seconds' => env('ZATCA_KS_ALERT_THRESHOLD', 1800), // 30 min
        'cache_ttl_seconds' => env('ZATCA_KS_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hash Chain Management
    |--------------------------------------------------------------------------
    |
    | Configuration for hash chain locking and sequence management.
    |
    */
    'hash_chain' => [
        'lock_timeout_seconds' => env('ZATCA_HASH_CHAIN_LOCK_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hash Chain Longevity Monitoring
    |--------------------------------------------------------------------------
    |
    | Monitor P95/P99 latency for hash chain queries to detect degradation
    | before it becomes critical. Alert on drift, not failure.
    |
    */
    'hash_chain_monitoring' => [
        'enabled' => env('ZATCA_HASH_CHAIN_MONITORING', true),
        'p95_warning_ms' => env('ZATCA_HASH_CHAIN_P95_WARNING', 50),
        'p99_critical_ms' => env('ZATCA_HASH_CHAIN_P99_CRITICAL', 200),
        'sample_interval_minutes' => env('ZATCA_HASH_CHAIN_SAMPLE_INTERVAL', 5),
        'alert_on_degradation' => env('ZATCA_HASH_CHAIN_ALERT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | ZATCA submissions should be processed asynchronously with dedicated
    | workers to prevent webhook backlog from blocking clearance requests.
    |
    */
    'queue' => [
        // Null means the application's own queue connection. This defaulted to
        // 'redis' while QUEUE_CONNECTION is database, and nothing read it — so
        // an operator who set it and ran a redis worker would have watched an
        // empty queue while the jobs went to the database.
        'connection' => env('ZATCA_QUEUE_CONNECTION'),

        // Read by ProcessFatooraSubmission, which dispatches onto this queue.
        // The job hardcoded 'zatca-submissions' and this key was read nowhere,
        // so renaming the queue here moved nothing.
        'name' => env('ZATCA_QUEUE_NAME', 'zatca-submissions'),

        // Read by ProcessFatooraSubmission for its retry behaviour.
        'tries' => (int) env('ZATCA_QUEUE_TRIES', 3),
        'timeout' => (int) env('ZATCA_QUEUE_TIMEOUT', 120),
        'backoff' => [10, 60, 300], // seconds between retries

        // Read by DispatchInvoiceWebhook. Separate from submissions so a slow
        // customer endpoint cannot delay a clearance.
        'webhooks_queue' => env('ZATCA_QUEUE_WEBHOOKS', 'webhooks'),

        // Recommended minimum workers per queue
        'recommended_workers' => [
            'zatca-submissions' => 2,  // High priority, dedicated
            'webhooks' => 1,           // Separate to prevent blocking
            'default' => 1,            // Other jobs
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Expiry Notifications
    |--------------------------------------------------------------------------
    |
    | Proactive notifications to organization admins before certificate
    | expiry to prevent submission failures.
    |
    */
    'certificate_notifications' => [
        // Enable expiry notifications
        'enabled' => env('ZATCA_CERT_NOTIFICATIONS_ENABLED', true),

        // Days before expiry to send notifications
        'notify_at_days' => [30, 14, 7, 3, 1],

        // Notification channels (mail, slack, webhook)
        'channels' => explode(',', env('ZATCA_CERT_NOTIFY_CHANNELS', 'mail,webhook')),

        // Block submissions on expiry (enforced by CertificateService)
        'block_on_expiry' => true,

        // Send daily reminders when < 7 days remaining
        'daily_reminders_threshold_days' => 7,
    ],

];
