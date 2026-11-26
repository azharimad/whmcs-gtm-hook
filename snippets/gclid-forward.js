/**
 * Forward ad click IDs (gclid / gbraid / wbraid) and UTM parameters from the
 * marketing site to links that point at WHMCS, so Ads and GA4 can still
 * attribute an order that finishes on a different host.
 *
 * Install on the main website only (template <head> or footer), NOT in WHMCS.
 *   <script src="/assets/gclid-forward.js" defer></script>
 * or paste the IIFE inline.
 *
 * Covers anchors whose href contains any of the patterns below. Edit
 * LINK_PATTERNS to match your WHMCS URLs (cart.php, clientarea.php,
 * billing.example.com, ...).
 */
(function () {
  'use strict';

  var LINK_PATTERNS = [
    '/cart.php',
    'clientarea.php',
    'submitticket.php',
    'billing.',
    'my.',
    '/order',
    '/checkout'
  ];

  var CARRY = [
    'gclid',
    'gbraid',
    'wbraid',
    'dclid',
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_term',
    'utm_content',
    'utm_id'
  ];

  function currentParams() {
    var params = new URLSearchParams(window.location.search);
    var carry = new URLSearchParams();
    for (var i = 0; i < CARRY.length; i++) {
      var value = params.get(CARRY[i]);
      if (value) {
        carry.set(CARRY[i], value);
      }
    }
    return carry;
  }

  function decorate(carry) {
    var links = document.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
      var link = links[i];
      var href = link.getAttribute('href');
      if (!href || /^(#|mailto:|tel:|javascript:)/i.test(href)) {
        continue;
      }

      var target;
      try {
        target = new URL(href, window.location.href);
      } catch (e) {
        continue;
      }

      var matches = false;
      for (var j = 0; j < LINK_PATTERNS.length; j++) {
        if (target.href.indexOf(LINK_PATTERNS[j]) !== -1) {
          matches = true;
          break;
        }
      }
      if (!matches) {
        continue;
      }

      var changed = false;
      carry.forEach(function (value, key) {
        if (target.searchParams.get(key) !== value) {
          target.searchParams.set(key, value);
          changed = true;
        }
      });

      if (changed) {
        link.setAttribute('href', target.toString());
      }
    }
  }

  function run() {
    var carry = currentParams();
    if (!Array.from(carry.keys()).length) {
      return;
    }
    decorate(carry);

    // Links injected after load (sliders, popups, lazy sections).
    if (window.MutationObserver) {
      var pending = null;
      var observer = new MutationObserver(function () {
        if (pending) {
          return;
        }
        pending = window.setTimeout(function () {
          pending = null;
          decorate(carry);
        }, 150);
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();