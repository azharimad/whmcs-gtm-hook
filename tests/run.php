<?php
/**
 * CLI test harness for gtm.php — no WHMCS installation required.
 *
 * Usage:
 *   php tests/run.php
 *
 * Each suite builds a temporary copy of the hook with different constants,
 * stubs WHMCS' add_hook() / localAPI() / CurrentUser, and asserts the rendered
 * output for every mode: off, gtm, gtag (GA4-only fallback), plus staff.
 */

$root = dirname(__DIR__);
$hook = $root . '/gtm.php';

if (($argv[1] ?? '') === 'worker') {
    run_worker($argv[2] ?? $hook, $argv[3] ?? 'off', $argv[4] ?? 'empty');
    exit($GLOBALS['failed'] ? 1 : 0);
}

/* ------------------------------ orchestrator ------------------------------ */

$src = file_get_contents($hook);
if ($src === false) {
    fwrite(STDERR, "cannot read $hook\n");
    exit(2);
}

function variant(string $src, array $consts): string
{
    foreach ($consts as $name => $value) {
        $src = preg_replace(
            '/const ' . preg_quote($name, '/') . "\s*=\s*'[^']*';/",
            "const $name = '$value';",
            $src,
            1
        );
    }
    return $src;
}

$tmps = [];
$make = function (string $code) use (&$tmps): string {
    $f = tempnam(sys_get_temp_dir(), 'gtm_variant_') . '.php';
    file_put_contents($f, $code);
    $tmps[] = $f;
    return $f;
};

$linked = ['HM_GTM_CONTAINER_ID' => 'GTM-TEST123', 'HM_GA4_MEASUREMENT_ID' => 'G-TEST456'];

$suites = [
    // label => [file, expected mode, env, expected rendered output]
    'dummy IDs (auto -> off)'  => [$make($src), 'off', [], 'empty'],
    'GTM only'                 => [$make(variant($src, ['HM_GTM_CONTAINER_ID' => 'GTM-TEST123'])), 'gtm', [], 'gtm'],
    'GTM + GA4 linked'         => [$make(variant($src, $linked)), 'gtm', [], 'gtm'],
    'GA4 only (gtag fallback)' => [$make(variant($src, ['HM_GA4_MEASUREMENT_ID' => 'G-TEST456'])), 'gtag', [], 'gtag'],
    'mode=off with both IDs'   => [$make(variant($src, $linked + ['HM_GTM_MODE' => 'off'])), 'off', [], 'empty'],
    'staff in client area'     => [$make(variant($src, $linked)), 'gtm', ['STUB_ADMIN' => '1'], 'empty'],
    'masquerading admin'       => [$make(variant($src, $linked)), 'gtm', ['STUB_MASQ' => '1'], 'empty'],
];

$exit = 0;
foreach ($suites as $label => [$file, $expected, $env, $rendered]) {
    echo "\n== $label ==\n";
    foreach ($env as $k => $v) {
        putenv("$k=$v");
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' worker ' . escapeshellarg($file) . ' ' . escapeshellarg($expected)
        . ' ' . escapeshellarg($rendered) . ' 2>&1';
    $out = [];
    exec($cmd, $out, $code);
    echo implode("\n", $out), "\n";
    if ($code !== 0) {
        $exit = 1;
    }
    foreach ($env as $k => $v) {
        putenv($k);
    }
}

foreach ($tmps as $f) {
    @unlink($f);
}

echo $exit === 0 ? "\nAll suites passed.\n" : "\nFAILURES present.\n";
exit($exit);

/* --------------------------------- worker --------------------------------- */

function run_worker(string $file, string $expected, string $rendered): void
{
    define('WHMCS', true);

    $GLOBALS['failed'] = false;
    $GLOBALS['hooks']  = [];

    eval(<<<'PHP'
namespace WHMCS\Authentication {
    class CurrentUser {
        public function isAuthenticatedAdmin(): bool { return getenv('STUB_ADMIN') === '1'; }
        public function isMasqueradingAdmin(): bool { return getenv('STUB_MASQ') === '1'; }
    }
}
namespace {
    function add_hook($name, $priority, $cb) { $GLOBALS['hooks'][$name] = $cb; }
    function localAPI($cmd, $params = []) {
        if ($cmd === 'GetOrders') {
            return ['result' => 'success', 'orders' => ['order' => [[
                'id' => 42, 'ordernumber' => '1234567890', 'userid' => 7,
                'lineitems' => ['lineitem' => [
                    ['product' => 'Shared Hosting Basic', 'type' => 'Hosting', 'amount' => 5.00, 'qty' => 1],
                    ['product' => 'SSL Certificate',     'type' => 'Addon',   'amount' => 2.50, 'qty' => 2],
                ]],
            ]]]];
        }
        if ($cmd === 'GetClientsDetails') {
            return ['result' => 'success', 'client' => ['currency_code' => 'IDR']];
        }
        return ['result' => 'error'];
    }
}
PHP
    );

    require $file;

    $_SESSION = ['uid' => 7];

    $mode = hm_gtm_mode();
    check("mode resolves to '$expected'", $mode === $expected, "got: $mode");
    check('3 hooks registered', count($GLOBALS['hooks']) === 3);

    $vars = ['templatefile' => 'viewinvoice'];
    $head = $GLOBALS['hooks']['ClientAreaHeadOutput']($vars);
    $body = $GLOBALS['hooks']['ClientAreaHeaderOutput']($vars);

    if ($rendered === 'empty') {
        check('head renders nothing', $head === '');
        check('body renders nothing', $body === '');
        check('purchase renders nothing', $GLOBALS['hooks']['ShoppingCartCheckoutCompletePage']([
            'orderid' => 42, 'ordernumber' => '1234567890', 'ispaid' => 1,
        ]) === '');
        return;
    }

    // Consent + context push berlaku di kedua mode.
    check('consent default pushed', str_contains($head, "gtag('consent', 'default'"));
    preg_match('/window\.dataLayer\.push\((\{[^}]*\})\);/', $head, $cm);
    $ctx = json_decode($cm[1] ?? '', true) ?: [];
    check('context page type pushed', ($ctx['hm_page'] ?? '') === 'viewinvoice');
    check('context logged-in flag pushed', ($ctx['hm_logged_in'] ?? null) === true);
    check('context channel matches mode', ($ctx['hm_channel'] ?? '') === $mode);

    if ($rendered === 'gtm') {
        check('GTM container in head', str_contains($head, 'googletagmanager.com/gtm.js'));
        check('container ID passed', str_contains($head, "'GTM-TEST123'"));
        check('no direct gtag.js in gtm mode', !str_contains($head, 'gtag/js?id='));
        check('noscript iframe in body', str_contains($body, 'ns.html?id=GTM-TEST123'));
    } else {
        check('gtag.js loaded directly', str_contains($head, 'gtag/js?id=G-TEST456'));
        check('gtag config called', str_contains($head, "gtag('config', 'G-TEST456')"));
        check('no GTM container in gtag mode', !str_contains($head, 'gtm.js'));
        check('no noscript in gtag mode', $body === '');
        check('context exposes GA4 ID', ($ctx['hm_ga4_id'] ?? '') === 'G-TEST456');
    }

    // Purchase event.
    $purchase = $GLOBALS['hooks']['ShoppingCartCheckoutCompletePage']([
        'orderid' => 42, 'ordernumber' => '1234567890', 'ispaid' => 1,
    ]);
    check('purchase event emitted', $purchase !== '');

    $GLOBALS['hooks']['ShoppingCartCheckoutCompletePage']([
        'orderid' => 42, 'ordernumber' => '1234567890', 'ispaid' => 1,
    ]);
    $again = $GLOBALS['hooks']['ShoppingCartCheckoutCompletePage']([
        'orderid' => 42, 'ordernumber' => '1234567890', 'ispaid' => 1,
    ]);
    check('reload does not double-push', $again === '');

    if ($rendered === 'gtm') {
        check('gtm uses ecommerce object', str_contains($purchase, '{ecommerce:null}'));
        $clear = 'dataLayer.push({ecommerce:null});';
        $json  = substr($purchase, strpos($purchase, $clear) + strlen($clear));
        $json  = trim(preg_replace('/^(window\.)?dataLayer\.push\(/', '', $json));
        $json = rtrim($json, ');</script>');
        $payload = json_decode($json, true) ?: [];
        $e = $payload['ecommerce'] ?? [];
        check('event name = purchase', ($payload['event'] ?? '') === 'purchase');
    } else {
        check('gtag uses native event shape', str_contains($purchase, "gtag('event', 'purchase'"));
        check('gtag has no ecommerce object', !str_contains($purchase, '{ecommerce:null}'));
        $at = strpos($purchase, "gtag('event', 'purchase', ") + strlen("gtag('event', 'purchase', ");
        $json = substr($purchase, $at);
        $json = rtrim($json, ');</script>');
        $e = json_decode($json, true) ?: [];
        $payload = $e;
    }

    check('transaction_id = order number', ($e['transaction_id'] ?? '') === '1234567890');
    check('currency from client record', ($e['currency'] ?? '') === 'IDR');
    check('value = 5 + 2.5*2', abs((float) ($e['value'] ?? 0) - 10.0) < 0.001);
    check('two line items mapped', count($e['items'] ?? []) === 2);
    check('invoice_paid forwarded', ($payload['invoice_paid'] ?? null) === true);
}

function check(string $label, bool $ok, string $extra = ''): void
{
    printf("  %-42s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $extra !== '' ? "  ($extra)" : '');
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}