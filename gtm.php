<?php
/**
 * WHMCS Google Tag Manager + GA4 integration
 * -----------------------------------------------------------------------------
 * File  : includes/hooks/gtm.php
 * Test  : on WHMCS 8.x / 9.x (PHP 8.1+)
 *
 * Isi:
 *  1. ClientAreaHeadOutput  -> GTM container script (head)
 *  2. ClientAreaHeaderOutput-> GTM noscript iframe (top of body)
 *  3. ShoppingCartCheckoutCompletePage -> GA4 "purchase" event ke dataLayer
 *
 * Catatan: file ini TIDAK memuat gtag.js sendiri. Tag GA4 dibuat di dalam GTM,
 * supaya pageview tidak terhitung dua kali.
 * -----------------------------------------------------------------------------
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/* ============================ KONFIGURASI ================================= */

// Ganti dua nilai ini dengan milik Anda (sekarang masih dummy).
const HM_GTM_CONTAINER_ID     = 'GTM-XXXXXXX';   // GTM container (GTM-xxxxxxx)
const HM_GA4_MEASUREMENT_ID   = 'G-XXXXXXXXXX';  // hanya dipakai untuk dokumentasi / validasi

// Kirim event purchase dari halaman order-complete?
const HM_GTM_SEND_PURCHASE    = true;

// Consent Mode v2 default state sebelum banner consent tampil:
//   'granted' -> tracking jalan tanpa banner (paling umum untuk B2B/hosting)
//   'denied'  -> tidak ada cookie analytics/ads sampai user setuju
const HM_GTM_CONSENT_DEFAULT  = 'granted';

// Jangan tracking staff/admin yang sedang login di client area.
const HM_GTM_SKIP_STAFF       = true;

/* ============================== HELPERS =================================== */

/**
 * Container ID aktif, atau '' kalau halaman ini tidak boleh di-track.
 */
function hm_gtm_container_id(): string
{
    $id = trim(HM_GTM_CONTAINER_ID);

    // Belum dikonfigurasi (masih dummy) -> jangan render apa pun.
    if ($id === '' || $id === 'GTM-XXXXXXX' || stripos($id, 'GTM-') !== 0) {
        return '';
    }

    if (HM_GTM_SKIP_STAFF) {
        try {
            $user = new \WHMCS\Authentication\CurrentUser();
            // Staff yang login, termasuk "Login as Owner" (masquerade).
            if ($user->isAuthenticatedAdmin() || $user->isMasqueradingAdmin()) {
                return '';
            }
        } catch (\Throwable $e) {
            // Kelas tidak tersedia di versi lama: abaikan, tetap track.
        }
    }

    return $id;
}

/**
 * JSON aman untuk disisipkan ke dalam <script>.
 */
function hm_gtm_json(array $data): string
{
    return json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
    ) ?: '{}';
}

/* ======================= 1. GTM CONTAINER (HEAD) ========================== */

add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    $id = hm_gtm_container_id();
    if ($id === '') {
        return '';
    }

    $consent = HM_GTM_CONSENT_DEFAULT === 'denied' ? 'denied' : 'granted';

    return <<<HTML
<!-- Google Tag Manager (WHMCS hook) -->
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', {
  'ad_storage': '{$consent}',
  'ad_user_data': '{$consent}',
  'ad_personalization': '{$consent}',
  'analytics_storage': '{$consent}'
});
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{$id}');
</script>
<!-- End Google Tag Manager -->
HTML;
});

/* ==================== 2. GTM NOSCRIPT (TOP OF BODY) ======================= */

add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
    $id = hm_gtm_container_id();
    if ($id === '') {
        return '';
    }

    return '<!-- Google Tag Manager (noscript) -->'
        . '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . htmlspecialchars($id, ENT_QUOTES) . '"'
        . ' height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>'
        . '<!-- End Google Tag Manager (noscript) -->';
});

/* ================= 3. GA4 PURCHASE EVENT (ORDER COMPLETE) ================= */

add_hook('ShoppingCartCheckoutCompletePage', 1, function ($vars) {
    if (!HM_GTM_SEND_PURCHASE || hm_gtm_container_id() === '') {
        return '';
    }

    $orderId = (int) ($vars['orderid'] ?? 0);
    if ($orderId <= 0) {
        return '';
    }

    // Anti dobel: halaman complete bisa di-refresh / dibuka ulang.
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

        $client = localAPI('GetClientsDetails', ['clientid' => (int) $order['userid']]);
        $currency = $client['client']['currency_code'] ?? '';
        if ($currency === '') {
            $currency = 'USD'; // fallback: ganti sesuai mata uang utama Anda
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

        $event = [
            'event'        => 'purchase',
            'invoice_paid' => !empty($vars['ispaid']),
            'ecommerce'    => [
                'transaction_id' => (string) ($vars['ordernumber'] ?? $orderId),
                'value'          => round($value, 2),
                'currency'       => strtoupper($currency),
                'items'          => $items,
            ],
        ];

        $_SESSION[$sessionKey][$orderId] = true;

        return '<script>window.dataLayer=window.dataLayer||[];'
            . 'dataLayer.push({ecommerce:null});'
            . 'dataLayer.push(' . hm_gtm_json($event) . ');</script>';
    } catch (\Throwable $e) {
        // Jangan pernah merusak halaman order-complete karena tracking.
        return '';
    }
});