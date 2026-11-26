<?php
/**
 * WHMCS Google Tag Manager + GA4 integration (linked setup)
 * -----------------------------------------------------------------------------
 * File  : includes/hooks/gtm.php
 * Test  : WHMCS 8.x / 9.x, PHP 8.1+
 *
 * Fungsi:
 *  1. ClientAreaHeadOutput   -> consent default, data layer context, container
 *  2. ClientAreaHeaderOutput -> GTM noscript iframe (top of body)
 *  3. ShoppingCartCheckoutCompletePage -> GA4 "purchase" (GTM atau gtag.js)
 *
 * "Saling terkait" artinya: ID didefinisikan di satu tempat (file ini).
 * Measurement ID GA4 ikut di-push ke dataLayer sebagai hm_ga4_id, jadi tag
 * Google di dalam GTM bisa memakai {{DL - hm_ga4_id}}.
 *
 * Prefix ID yang dikenal:
 *   GTM-  container Tag Manager          -> HM_GTM_CONTAINER_ID
 *   G-    GA4 measurement ID             -> HM_GA4_MEASUREMENT_ID
 *   GT-   Google tag payung (GA4 + Ads)  -> HM_GOOGLE_TAG_ID
 *   AW-   Google Ads conversion ID       -> HM_GOOGLE_TAG_ID
 *
 * Mode:
 *   auto -> GTM kalau container valid; kalau tidak, gtag.js kalau ada
 *           G- / GT- / AW-; kalau tidak ada apa-apa -> off.
 *   gtm  -> hanya container GTM (tag GA4 dikelola di dalam GTM).
 *   gtag -> gtag.js langsung, tanpa GTM (fallback / setup cepat).
 *   off  -> tidak tracking sama sekali.
 * -----------------------------------------------------------------------------
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/* ============================ KONFIGURASI ================================= */

const HM_GTM_MODE             = 'auto';          // auto | gtm | gtag | off
const HM_GTM_CONTAINER_ID     = 'GTM-XXXXXXX';   // Admin > Install Google Tag Manager
const HM_GA4_MEASUREMENT_ID   = 'G-XXXXXXXXXX';  // GA4 > Admin > Data Streams
const HM_GOOGLE_TAG_ID        = '';              // opsional: GT-... atau AW-...

const HM_GTM_SEND_PURCHASE    = true;             // event purchase di order-complete
const HM_GTM_CONSENT_DEFAULT  = 'granted';        // granted | denied
const HM_GTM_SKIP_STAFF       = true;             // skip staff & Login as Owner

/* ============================== HELPERS =================================== */

function hm_gtm_valid_gtm(): string
{
    $id = trim(HM_GTM_CONTAINER_ID);
    if ($id === '' || $id === 'GTM-XXXXXXX') {
        return '';
    }
    return preg_match('/^GTM-[A-Z0-9]{4,}$/i', $id) ? strtoupper($id) : '';
}

function hm_gtm_valid_ga4(): string
{
    $id = trim(HM_GA4_MEASUREMENT_ID);
    if ($id === '' || $id === 'G-XXXXXXXXXX') {
        return '';
    }
    return preg_match('/^G-[A-Z0-9]{4,}$/i', $id) ? strtoupper($id) : '';
}

/**
 * Google tag payung: GT- (GA4 + Ads), AW- (Ads saja), DC- (Floodlight).
 */
function hm_gtm_valid_google_tag(): string
{
    $id = trim(HM_GOOGLE_TAG_ID);
    return preg_match('/^(GT|AW|DC)-[A-Z0-9]{4,}$/i', $id) ? strtoupper($id) : '';
}

/**
 * ID untuk mode gtag: G- (eksplisit) lebih dulu, GT-/AW- sebagai cadangan.
 */
function hm_gtm_gtag_id(): string
{
    return hm_gtm_valid_ga4() ?: hm_gtm_valid_google_tag();
}

/**
 * Mode efektif: 'gtm' | 'gtag' | 'off'.
 */
function hm_gtm_mode(): string
{
    static $mode = null;
    if ($mode !== null) {
        return $mode;
    }

    $declared = strtolower(trim(HM_GTM_MODE));
    $gtm      = hm_gtm_valid_gtm();
    $direct   = hm_gtm_gtag_id();

    if ($declared === 'off') {
        return $mode = 'off';
    }
    if ($declared === 'gtm') {
        return $mode = $gtm !== '' ? 'gtm' : 'off';
    }
    if ($declared === 'gtag') {
        return $mode = $direct !== '' ? 'gtag' : 'off';
    }

    // auto: container menang, lalu gtag.js langsung.
    if ($gtm !== '') {
        return $mode = 'gtm';
    }
    if ($direct !== '') {
        return $mode = 'gtag';
    }
    return $mode = 'off';
}

/**
 * Apakah request ini boleh di-track.
 */
function hm_gtm_trackable(): bool
{
    if (hm_gtm_mode() === 'off') {
        return false;
    }

    if (HM_GTM_SKIP_STAFF) {
        try {
            $user = new \WHMCS\Authentication\CurrentUser();
            if ($user->isAuthenticatedAdmin() || $user->isMasqueradingAdmin()) {
                return false;
            }
        } catch (\Throwable $e) {
            // Kelas tidak tersedia di versi lama: tetap track.
        }
    }

    return true;
}

/**
 * Konteks yang dipakai bersama GTM dan GA4.
 */
function hm_gtm_context(array $vars): array
{
    return [
        'hm_ga4_id'        => hm_gtm_valid_ga4(),
        'hm_google_tag_id' => hm_gtm_valid_google_tag(),
        'hm_channel'       => hm_gtm_mode(),
        'hm_page'          => (string) ($vars['templatefile'] ?? $vars['filename'] ?? ''),
        'hm_logged_in'     => !empty($_SESSION['uid']),
    ];
}

function hm_gtm_json(array $data): string
{
    return json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
    ) ?: '{}';
}

/* ====================== 1. HEAD: CONSENT + CONTEXT ======================== */

add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    if (!hm_gtm_trackable()) {
        return '';
    }

    $mode    = hm_gtm_mode();
    $consent = HM_GTM_CONSENT_DEFAULT === 'denied' ? 'denied' : 'granted';
    $context = hm_gtm_json(hm_gtm_context(is_array($vars) ? $vars : []));
    $gtm     = hm_gtm_valid_gtm();

    $out = "<!-- WHMCS tracking: consent + context -->\n"
        . "<script>\n"
        . "window.dataLayer = window.dataLayer || [];\n"
        . "function gtag(){window.dataLayer.push(arguments);}\n"
        . "gtag('consent', 'default', {\n"
        . "  'ad_storage': '{$consent}',\n"
        . "  'ad_user_data': '{$consent}',\n"
        . "  'ad_personalization': '{$consent}',\n"
        . "  'analytics_storage': '{$consent}'\n"
        . "});\n"
        . "window.dataLayer.push({$context});\n"
        . "</script>\n";

    if ($mode === 'gtm') {
        return $out . <<<HTML
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{$gtm}');</script>
<!-- End Google Tag Manager -->
HTML;
    }

    // mode gtag: GA4 / Google tag dipasang langsung, GTM dilewati.
    $tagId = hm_gtm_gtag_id();

    return $out
        . '<script async src="https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($tagId) . '"></script>' . "\n"
        . "<script>gtag('js', new Date());gtag('config', '{$tagId}');</script>\n";
});

/* =================== 2. NOSCRIPT GTM (TOP OF BODY) ======================== */

add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
    if (hm_gtm_mode() !== 'gtm' || !hm_gtm_trackable()) {
        return '';
    }

    $gtm = htmlspecialchars(hm_gtm_valid_gtm(), ENT_QUOTES);

    return '<!-- Google Tag Manager (noscript) -->'
        . '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $gtm . '"'
        . ' height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>'
        . '<!-- End Google Tag Manager (noscript) -->';
});

/* ================== 3. GA4 PURCHASE (ORDER COMPLETE) ====================== */

add_hook('ShoppingCartCheckoutCompletePage', 1, function ($vars) {
    if (!HM_GTM_SEND_PURCHASE || !hm_gtm_trackable()) {
        return '';
    }

    $orderId = (int) ($vars['orderid'] ?? 0);
    if ($orderId <= 0) {
        return '';
    }

    // Anti dobel saat halaman di-refresh / dibuka ulang.
    $sessionKey = 'hm_gtm_purchase_sent';
    if (!empty($_SESSION[$sessionKey][$orderId])) {
        return '';
    }

    try {
        $orders = localAPI('GetOrders', ['id' => $orderId]);
        $order  = $orders['orders']['order'][0] ?? null;
        if (!$order) {
            return '';
        }

        $client   = localAPI('GetClientsDetails', ['clientid' => (int) $order['userid']]);
        $currency = strtoupper((string) ($client['client']['currency_code'] ?? ''));
        if ($currency === '') {
            $currency = 'USD'; // fallback, sesuaikan mata uang utama
        }

        $items = [];
        $value = 0.0;
        foreach ($order['lineitems']['lineitem'] ?? [] as $line) {
            $price = round((float) ($line['amount'] ?? 0), 2);
            $qty   = max(1, (int) ($line['qty'] ?? 1));

            $items[] = [
                'item_name'     => (string) ($line['product'] ?? 'Item'),
                'item_category' => (string) ($line['type'] ?? ''),
                'price'         => $price,
                'quantity'      => $qty,
            ];
            $value += $price * $qty;
        }

        if (!$items) {
            return '';
        }

        $transactionId = (string) ($vars['ordernumber'] ?? $orderId);
        $paid          = !empty($vars['ispaid']);

        if (hm_gtm_mode() === 'gtm') {
            // GTM: event + objek ecommerce lewat dataLayer.
            $event = [
                'event'        => 'purchase',
                'invoice_paid' => $paid,
                'ecommerce'    => [
                    'transaction_id' => $transactionId,
                    'value'          => round($value, 2),
                    'currency'       => $currency,
                    'items'          => $items,
                ],
            ];
            $js = 'window.dataLayer=window.dataLayer||[];'
                . 'window.dataLayer.push({ecommerce:null});'
                . 'window.dataLayer.push(' . hm_gtm_json($event) . ');';
        } else {
            // gtag.js: bentuk event GA4 native, bukan objek ecommerce dataLayer.
            $params = [
                'transaction_id' => $transactionId,
                'value'          => round($value, 2),
                'currency'       => $currency,
                'invoice_paid'   => $paid,
                'items'          => $items,
            ];
            $js = 'window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};'
                . "gtag('event', 'purchase', " . hm_gtm_json($params) . ');';
        }

        $_SESSION[$sessionKey][$orderId] = true;

        return '<script>' . $js . '</script>';
    } catch (\Throwable $e) {
        // Tracking tidak boleh merusak halaman order-complete.
        return '';
    }
});