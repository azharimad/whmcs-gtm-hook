<?php
/**
 * WHMCS hook diagnostics for gtm.php
 * -----------------------------------------------------------------------------
 * Temporary file. Upload as: includes/hooks/zzz-gtm-debug.php
 *
 * It writes one line per client-area page view to includes/hooks/gtm-debug.log
 * so you can tell the difference between:
 *   "hook tidak ke-load"      -> file path / permission / syntax error
 *   "hook jalan tapi off"     -> ID salah, mode off, atau staff login
 *   "hook jalan dan render"   -> masalahnya di sisi GTM (tag belum publish)
 *
 * Hapus file ini setelah selesai debugging.
 * -----------------------------------------------------------------------------
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$logFile = __DIR__ . '/gtm-debug.log';

$write = function (string $event, array $data) use ($logFile) {
    $line = date('c') . ' [' . $event . '] ' . json_encode($data) . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

// 1. Apakah file ini sendiri dieksekusi WHMCS?
$write('debug-hook-loaded', [
    'file'        => __FILE__,
    'whmcs_root'  => defined('ROOTDIR') ? ROOTDIR : '',
    'php'         => PHP_VERSION,
]);

// 2. Apakah gtm.php ikut ke-load, dan apa hasil resolusi ID/mode-nya?
add_hook('ClientAreaPage', 1, function ($vars) use ($write) {
    $write('client-area-page', [
        'gtm_hook_loaded' => function_exists('hm_gtm_mode'),
        'mode'            => function_exists('hm_gtm_mode') ? hm_gtm_mode() : 'n/a',
        'container'       => function_exists('hm_gtm_valid_gtm') ? hm_gtm_valid_gtm() : 'n/a',
        'restricted'      => function_exists('hm_gtm_container_restricted') ? hm_gtm_container_restricted() : 'n/a',
        'trackable'       => function_exists('hm_gtm_trackable') ? hm_gtm_trackable() : 'n/a',
        'template'        => $vars['templatefile'] ?? ($vars['filename'] ?? ''),
        'logged_in'       => !empty($_SESSION['uid']),
    ]);
});

// 3. Apakah hook head benar-benar dipanggil, dan berapa karakter outputnya?
add_hook('ClientAreaHeadOutput', 1, function ($vars) use ($write) {
    $len = 0;
    if (function_exists('hm_gtm_trackable') && function_exists('hm_gtm_mode') && hm_gtm_mode() !== 'off') {
        $len = function_exists('hm_gtm_valid_gtm') && hm_gtm_valid_gtm() !== '' ? 1 : 0;
    }
    $write('head-output-hook', [
        'template' => $vars['templatefile'] ?? '',
        'mode'     => function_exists('hm_gtm_mode') ? hm_gtm_mode() : 'n/a',
        'trackable' => function_exists('hm_gtm_trackable') ? hm_gtm_trackable() : 'n/a',
        'would_render' => (bool) $len,
    ]);
    return '';
});