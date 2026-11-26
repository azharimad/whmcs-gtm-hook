<?php
/**
 * CLI test harness for gtm.php — no WHMCS installation required.
 *
 * Usage:
 *   php tests/run.php
 *
 * It stubs WHMCS' add_hook()/localAPI()/CurrentUser, then checks:
 *   - hooks are registered
 *   - dummy container ID renders nothing
 *   - a real-looking ID renders the GTM container + consent default + noscript
 *   - the purchase event carries transaction_id, currency, value, items
 *   - a page reload does not push a second purchase
 *   - staff / masquerading admin sessions are not tracked
 */

$root = dirname(__DIR__);
$hook = $root . '/gtm.php';

if (($argv[1] ?? '') === 'worker') {
    run_worker($argv[2] ?? $hook, (bool) getenv('STUB_ADMIN'), (bool) getenv('STUB_MASQ'));
    exit($GLOBALS['failed'] ? 1 : 0);
}

/* ------------------------------ orchestrator ------------------------------ */

$src = file_get_contents($hook);
if ($src === false) {
    fwrite(STDERR, "cannot read $hook\n");
    exit(2);
}

$tmp = tempnam(sys_get_temp_dir(), 'gtm_live_') . '.php';
file_put_contents($tmp, preg_replace(
    "/const HM_GTM_CONTAINER_ID\s*=\s*'[^']*';/",
    "const HM_GTM_CONTAINER_ID = 'GTM-TEST123';",
    $src,
    1
));

$cases = [
    'dummy container ID'         => [$hook, [], true],
    'real container ID'          => [$tmp, [], false],
    'staff logged into clientarea' => [$tmp, ['STUB_ADMIN' => '1'], true],
    'masquerading admin (Login as Owner)' => [$tmp, ['STUB_MASQ' => '1'], true],
];

$exit = 0;
foreach ($cases as $label => [$file, $env, $expectEmpty]) {
    echo "\n== $label ==\n";
    foreach ($env as $k => $v) {
        putenv("$k=$v");
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($file) . ' 2>&1';
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

@unlink($tmp);

echo $exit === 0 ? "\nAll suites passed.\n" : "\nFAILURES present.\n";
exit($exit);

/* --------------------------------- worker --------------------------------- */

function run_worker(string $file, bool $isAdmin, bool $isMasq): void
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

    check('3 hooks registered', count($GLOBALS['hooks']) === 3);

    $head   = $GLOBALS['hooks']['ClientAreaHeadOutput']([]);
    $body   = $GLOBALS['hooks']['ClientAreaHeaderOutput']([]);
    $purchase = $GLOBALS['hooks']['ShoppingCartCheckoutCompletePage']([
        'orderid' => 42, 'ordernumber' => '1234567890', 'ispaid' => 1,
    ]);

    if ($isAdmin || $isMasq) {
        check('head empty for staff', $head === '');
        check('noscript empty for staff', $body === '');
        check('purchase empty for staff', $purchase === '');
        return;
    }

    if (strpos($file, 'gtm_live_') === false) {
        check('dummy ID renders nothing (head)', $head === '');
        check('dummy ID renders nothing (noscript)', $body === '');
        check('dummy ID renders no purchase', $purchase === '');
        return;
    }

    check('GTM container in head', str_contains($head, 'googletagmanager.com/gtm.js'));
    check('container ID passed', str_contains($head, "'GTM-TEST123'"));
    check('consent default pushed', str_contains($head, "gtag('consent', 'default'"));
    check('noscript iframe in body', str_contains($body, 'ns.html?id=GTM-TEST123'));
    check('purchase event pushed', str_contains($purchase, 'dataLayer.push'));

    // Reload: session flag must swallow the second push.
    $_SESSION = [];
    $GLOBALS['hooks']['ShoppingCartCheckoutCompletePage'](['orderid' => 42, 'ordernumber' => '1234567890', 'ispaid' => 1]);
    $again = $GLOBALS['hooks']['ShoppingCartCheckoutCompletePage'](['orderid' => 42, 'ordernumber' => '1234567890', 'ispaid' => 1]);
    check('reload does not double-push', $again === '');

    $clear = 'dataLayer.push({ecommerce:null});';
    $json  = substr($purchase, strpos($purchase, $clear) + strlen($clear));
    $json = trim(preg_replace('/^dataLayer\.push\(/', '', $json));
    $json = rtrim($json, ');</script>');
    $ev   = json_decode($json, true) ?: [];
    $e    = $ev['ecommerce'] ?? [];

    check('event name = purchase', ($ev['event'] ?? '') === 'purchase');
    check('transaction_id = order number', ($e['transaction_id'] ?? '') === '1234567890');
    check('currency from client record', ($e['currency'] ?? '') === 'IDR');
    check('value = 5 + 2.5*2', abs((float) ($e['value'] ?? 0) - 10.0) < 0.001);
    check('two line items mapped', count($e['items'] ?? []) === 2);
    check('invoice_paid flag forwarded', ($ev['invoice_paid'] ?? null) === true);
}

function check(string $label, bool $ok): void
{
    printf("  %-42s %s\n", $label, $ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}