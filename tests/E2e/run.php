<?php

declare(strict_types=1);

/**
 * End-to-end exercise against a real MySQL database.
 *
 * Drives real HTTP through the real kernel — no RefreshDatabase, no SQLite, no
 * doubles for anything the application owns. Only ZATCA itself is out of reach,
 * so submission stops at the network boundary; everything before it is real.
 *
 * This exists because a green SQLite suite does not prove the application runs.
 * SQLite does not enforce foreign key types, so invoice submissions declared
 * bigint against UUID keys passed every test and MySQL refused the migration.
 * The tenant scope broke ICV allocation in a way no test saw, because the test
 * runner is a console process and the scope stands down there.
 *
 * It is deliberately not a PHPUnit test: the framework's testing traits swap in
 * the very things this is meant to exercise. phpunit.xml declares only the Unit
 * and Feature suites, so this file is not collected by the normal run.
 *
 *     php tests/E2e/run.php
 *
 * Exits non-zero on the first uncaught error or any failed check.
 */

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\ValueObjects\OrganizationContext;
use App\Domains\Pipeline\Services\InvoiceDrafter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

$root = dirname(__DIR__, 2);
require "$root/vendor/autoload.php";

/** @var Application $app */
$app = require "$root/bootstrap/app.php";

// A request has to be bound before the kernel boots, because middleware and the
// tenant scope both ask whether one is present.
$app->instance('request', Request::create('/', 'GET'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// The scope stands down in console processes so queue workers and commands can
// see every tenant. This harness is a console process pretending to serve HTTP,
// and the isolation checks below are the point of it.
(new ReflectionProperty($app, 'isRunningInConsole'))->setValue($app, false);

// The platform licence gate runs on every API route and refuses without a key.
// It is a commercial control rather than a property of the application, and
// whether it is on here would otherwise depend on the operator's own .env.
// PlatformLicenseTest covers the gate itself.
config(['platform-license.enabled' => false]);

// Laravel's handler renders an uncaught exception as a full HTML error page —
// several hundred kilobytes of markup with the message buried in it. Replacing
// it after bootstrap keeps a failure readable in a terminal and in CI logs.
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, sprintf(
        "\nUNCAUGHT %s\n  %s\n  at %s:%d\n",
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    exit(1);
});

$pass = 0;
$fail = 0;
$jar = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";

        return;
    }

    $fail++;
    echo "  FAIL  $label".($detail !== '' ? "\n          $detail" : '')."\n";
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function send(Kernel $kernel, string $method, string $uri, array $data = [], array $headers = []): Response
{
    global $jar;

    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $response = $kernel->handle(Request::create($uri, $method, $data, $jar, [], $server));

    foreach ($response->headers->getCookies() as $cookie) {
        $jar[$cookie->getName()] = $cookie->getValue();
    }

    return $response;
}

function token(Kernel $kernel): string
{
    preg_match(
        '/name="_token" value="([^"]+)"/',
        (string) send($kernel, 'GET', '/login')->getContent(),
        $matches
    );

    return $matches[1] ?? '';
}

echo "\n=== schema ===\n";
check('running on mysql', DB::connection()->getDriverName() === 'mysql');

$declared = 1; // the migrations table itself
foreach (glob($root.'/database/migrations/*.php') ?: [] as $migration) {
    $declared += preg_match_all('/Schema::create\(/', (string) file_get_contents($migration));
}
check('every declared table built', count(DB::select('SHOW TABLES')) === $declared,
    $declared.' declared, '.count(DB::select('SHOW TABLES')).' built');

echo "\n=== seed ===\n";
// Children before parents: these foreign keys are ON DELETE RESTRICT, so the
// order is the constraint graph rather than anything alphabetical.
foreach ([
    'invoice_lines', 'invoices',
    'license_audit_logs', 'license_rate_limits', 'license_usage',
    'organization_licenses', 'usage_events', 'licenses',
    'organization_user', 'audit_logs', 'organizations', 'users',
] as $table) {
    DB::table($table)->delete();
}

$identity = ['country' => 'SA', 'street' => 'King Fahd Road', 'building_number' => '1234',
    'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345'];

$acme = Organization::create($identity + ['name' => 'Acme', 'vat_number' => '300000000000003']);
$rival = Organization::create($identity + ['name' => 'Rival', 'vat_number' => '300000000000011']);
check('organizations created with vat', $acme->fresh()->vat_number === '300000000000003');

$admin = User::create(['name' => 'Admin', 'email' => 'admin@masaar.test',
    'password' => Hash::make('secret-password'), 'status' => 'active']);
$admin->forceFill(['is_platform_admin' => true])->save();
check('platform admin created', $admin->fresh()->isPlatformAdmin());

echo "\n=== guards ===\n";
foreach (['/admin', '/admin/organizations', '/portal', '/portal/submissions'] as $uri) {
    check("guest redirected from $uri", send($kernel, 'GET', $uri)->getStatusCode() === 302);
}
check('api admin rejects guest',
    in_array(send($kernel, 'GET', '/api/admin/dashboard')->getStatusCode(), [401, 403], true));

echo "\n=== credentials ===\n";
$issued = License::createWithCredentials([
    'org_id' => $acme->id,
    'organization_name' => 'Acme',
    'contact_email' => 'erp@acme.test',
    'tier' => 'starter',
]);

check('secret stored only as a hash',
    $issued['license']->api_secret_hash !== $issued['api_secret']
    && $issued['license']->verifySecret($issued['api_secret'])
    && ! $issued['license']->verifySecret('wrong-secret'));

// ValidateLicense reads headers only. A credential in the query string lands in
// access logs, browser history and the Referer header, so accepting one would
// leak it however carefully the rest is handled.
check('query-string credentials refused',
    send($kernel, 'GET', '/api/v1/invoices?api_key='.$issued['api_key']
        .'&api_secret='.$issued['api_secret'])->getStatusCode() !== 200);

// The refusal above is also what a credential that never authenticates at all
// would produce, so the working case has to be proven separately.
$authenticated = send($kernel, 'GET', '/api/v1/invoices', [], [
    'X-API-Key' => $issued['api_key'],
    'X-API-Secret' => $issued['api_secret'],
]);
check('header credentials authenticate', $authenticated->getStatusCode() === 200,
    'got '.$authenticated->getStatusCode());

check('a wrong secret is refused',
    send($kernel, 'GET', '/api/v1/invoices', [], [
        'X-API-Key' => $issued['api_key'],
        'X-API-Secret' => 'wrong',
    ])->getStatusCode() !== 200);

echo "\n=== tenant isolation ===\n";
foreach ([[$acme, 'ACME'], [$rival, 'RIVAL']] as [$org, $prefix]) {
    for ($i = 1; $i <= 2; $i++) {
        $draft = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $org->id, 'invoice_number' => "$prefix-$i", 'type' => 'standard',
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'currency' => 'SAR',
            'buyer_name' => 'Buyer', 'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));

        // The counter is allocated at issuance, not on save — a draft carries
        // no ICV, because a document that may never be issued must not consume
        // a number in the chain. So the checks below have to issue.
        Invoice::withoutTenantScope(fn () => app(Submitter::class)->generate($draft, $org));
    }
}
check('four invoices persisted', Invoice::withoutTenantScope(fn () => Invoice::count()) === 4);

// ICV is allocated per tenant from MAX(icv), and the tenant scope once filtered
// that lookup to nothing, handing every invoice ICV 1.
$icv = Invoice::withoutTenantScope(
    fn () => Invoice::where('org_id', $acme->id)->orderBy('icv')->pluck('icv')->all()
);
check('icv sequential per tenant', $icv === [1, 2], json_encode($icv));

// The licence request above resolved a tenant, and this harness reuses one
// container where a deployment builds a fresh one per request. Dropping the
// resolver restores the unresolved state this check is about.
$app->forgetInstance(TenantResolver::class);
check('no context yields nothing', Invoice::count() === 0,
    Invoice::count().' rows visible without a tenant');

app(TenantResolver::class)->setContext(OrganizationContext::forMachine($acme->id));
$seen = Invoice::pluck('invoice_number')->all();
sort($seen);
check('tenant sees only its own', $seen === ['ACME-1', 'ACME-2'], json_encode($seen));

echo "\n=== login ===\n";
check('csrf enforced', send($kernel, 'POST', '/login',
    ['email' => 'admin@masaar.test', 'password' => 'secret-password'])->getStatusCode() === 419);

$bad = send($kernel, 'POST', '/login',
    ['_token' => token($kernel), 'email' => 'admin@masaar.test', 'password' => 'nope']);
check('bad password refused', $bad->getStatusCode() === 302);
check('failure audited', DB::table('audit_logs')->where('action', 'security.login.failed')->count() >= 1);

$good = send($kernel, 'POST', '/login',
    ['_token' => token($kernel), 'email' => 'admin@masaar.test', 'password' => 'secret-password']);
check('login succeeds', $good->getStatusCode() === 302);
check('success audited', DB::table('audit_logs')->where('action', 'security.login.succeeded')->count() >= 1);
check('console reachable when signed in', send($kernel, 'GET', '/admin/organizations')->getStatusCode() === 200);

echo "\n=== compliance documents (real crypto) ===\n";
$b2c = Invoice::withoutTenantScope(fn () => Invoice::create([
    'org_id' => $acme->id, 'invoice_number' => 'ACME-B2C', 'type' => 'simplified',
    'status' => 'draft', 'issue_date' => now()->toDateString(), 'currency' => 'SAR',
    'buyer_name' => 'Walk-in', 'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
]));
$b2c->load('lines');

try {
    $documents = app(DocumentBuilder::class);
    $first = $documents->generateComplianceData($b2c, $acme);
    check('xml generated', ! empty($first['xml']));
    check('hash generated', ! empty($first['hash']));
    check('qr generated', ! empty($first['qr_code']));

    // The hash chains invoices together, so the same invoice hashing to two
    // different values would break every subsequent document.
    $second = $documents->generateComplianceData($b2c, $acme);
    check('hash deterministic', $first['hash'] === $second['hash']);

    // The PIH chain, end to end. Persisting the hash is what puts this invoice
    // into the chain; the next one must carry it.
    // Numbered as well as hashed. The predecessor lookup orders by ICV, so a
    // document with a hash and no number is not in the chain at all — and the
    // tenant already has issued invoices above, whose numbers this has to sit
    // after to be the one $next chains to.
    $b2c->forceFill([
        'hash' => $first['hash'],
        'icv' => Invoice::generateNextIcv((string) $acme->id),
    ])->save();

    $next = Invoice::withoutTenantScope(fn () => Invoice::create([
        'org_id' => $acme->id, 'invoice_number' => 'ACME-B2C-2', 'type' => 'simplified',
        'status' => 'draft', 'issue_date' => now()->toDateString(), 'currency' => 'SAR',
        'buyer_name' => 'Walk-in', 'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
    ]));
    $next->load('lines');

    // The predecessor is found by ICV — "the highest-numbered hashed document
    // below mine" — so the question only has an answer once this document has
    // a number of its own. Issuance allocates it, and Submitter::generate()
    // allocates before it reads the chain for exactly this reason. Asking an
    // unnumbered draft returns null, which is a state production never reaches.
    $next->icv = Invoice::generateNextIcv((string) $acme->id);

    check('next invoice chains to the previous',
        $next->previous_invoice_hash === $first['hash'],
        'got '.var_export($next->previous_invoice_hash, true));

    // The genesis PIH — 32 zero bytes — is what a null previous hash becomes.
    // Three of the five document paths were producing it for every invoice,
    // so each document claimed to be the first in its chain.
    $genesis = base64_encode(str_repeat("\0", 32));

    $chained = $documents->generateComplianceData(
        invoice: $next,
        organization: $acme,
        previousInvoiceHash: $next->previous_invoice_hash,
    );

    check('document embeds the previous hash', str_contains($chained['xml'], $first['hash']));
    check('document is not the genesis pih', ! str_contains($chained['xml'], $genesis));
} catch (Throwable $e) {
    check('compliance generation', false, $e::class.': '.$e->getMessage());
}

echo "\n=== pipeline drafting ===\n";
try {
    // 3 x 19.99 at 15% is 68.9655, and bcmath truncates rather than rounds:
    // without an explicit half-up step this comes out a cent short.
    $drafted = app(InvoiceDrafter::class)->draft([
        'invoice_number' => 'ACME-PIPE', 'type' => 'standard', 'document_type' => 'invoice',
        'issue_date' => now()->toDateString(), 'buyer_name' => 'Buyer',
        'lines' => [['description' => 'Widget', 'quantity' => 3, 'unit_price' => '19.99']],
    ], $acme->id);
    check('draft totals correct', $drafted->fresh()->total === '68.97', 'total='.$drafted->fresh()->total);
    check('line persisted with class_code', $drafted->fresh(['lines'])->lines->count() === 1);
} catch (Throwable $e) {
    check('pipeline drafting', false, $e::class.': '.$e->getMessage());
}

echo "\n=== scheduled commands ===\n";
// Every command routes/console.php schedules, run as scheduled.
//
// The check is that it starts and completes without throwing, which is exactly
// how these fail. fatoora:verify-hash-chain declared a --verbose that collides
// with Symfony's built-in and threw before its first line, every week since it
// was scheduled. fatoora:check-certificate queried a zatca_certificate column
// that does not exist and threw every morning. Both were registered, so a test
// that only checked registration saw nothing wrong.
//
// A non-zero exit is a report, not a crash — verify-hash-chain returns one when
// it finds a break, which it will here, since these invoices were created
// directly rather than through the chain manager.
foreach ([
    'compliance:index-health --alert',
    'compliance:partition-maintenance --create-future --months-ahead=2',
    'compliance:cleanup-offline-queue',
    'fatoora:process-offline --limit=1',
    'fatoora:check-certificate --notify',
    'fatoora:verify-hash-chain',
    'license:cleanup-rate-limits',
    'license:check-expiration',
    'license:report-usage',
] as $command) {
    try {
        Artisan::call($command);
        check("artisan $command", true);
    } catch (Throwable $e) {
        check("artisan $command", false, $e::class.': '.$e->getMessage());
    }
}

echo "\n".str_repeat('-', 56)."\nPASS: $pass   FAIL: $fail\n";

exit($fail > 0 ? 1 : 0);
