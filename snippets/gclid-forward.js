/**
 * Forward ad click IDs (gclid / gbraid / wbraid / dclid) and UTM parameters
 * from a marketing site to the checkout / client portal on another host.
 *
 * Install on the site where the ad lands — NOT inside the portal itself.
 *
 *   <script src="/gclid-forward.js" defer></script>
 *
 * Configure CONFIG.hosts with your portal hostname (recommended) or leave it
 * empty and rely on CONFIG.patterns. Then debug in the browser console:
 *
 *   __gclidForward.debug()
 *
 * It prints the carried parameters, the stored first-touch values, the portal
 * links it found, and the URL it would produce for a sample link.
 */
(function () {
  'use strict';

  var CONFIG = {
    // Any URL whose hostname contains one of these is treated as the portal.
    // Recommended: put your portal hostname here, e.g. ['portal.example.com'].
    hosts: [],

    // Fallback matching when hosts is empty: substring match on the full URL.
    patterns: ['/cart.php', 'clientarea.php', 'submitticket.php', 'billing.', '/order', '/checkout', '/portal'],

    // Parameters carried across the hop.
    carry: [
      'gclid', 'gbraid', 'wbraid', 'dclid',
      'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'
    ],

    // Remember the values for the rest of the session, so a visitor who lands
    // on a page without the parameters still gets them on the portal link.
    persist: true,
    storageKey: 'gcf_params',

    // Set true to log every decoration to the console.
    debug: false
  };

  /* ------------------------------- helpers -------------------------------- */

  function absolute(url, base) {
    try {
      return new URL(url, base || window.location.href);
    } catch (e) {
      return null;
    }
  }

  function readStorage() {
    if (!CONFIG.persist) {
      return {};
    }
    try {
      return JSON.parse(window.sessionStorage.getItem(CONFIG.storageKey) || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  function writeStorage(values) {
    if (!CONFIG.persist) {
      return;
    }
    try {
      window.sessionStorage.setItem(CONFIG.storageKey, JSON.stringify(values));
    } catch (e) {
      /* private mode or quota - ignore */
    }
  }

  /** Current URL parameters merged with what was seen earlier in the session. */
  function params() {
    var out = readStorage();
    var search = new URLSearchParams(window.location.search);
    var fresh = {};

    for (var i = 0; i < CONFIG.carry.length; i++) {
      var key = CONFIG.carry[i];
      var value = search.get(key);
      if (value) {
        out[key] = value;
        fresh[key] = value;
      }
    }

    if (Object.keys(fresh).length) {
      writeStorage(out);
    }

    return out;
  }

  function isPortal(url) {
    var target = absolute(url);
    if (!target) {
      return false;
    }

    var i;
    if (CONFIG.hosts.length) {
      for (i = 0; i < CONFIG.hosts.length; i++) {
        if (target.hostname.indexOf(CONFIG.hosts[i]) !== -1) {
          return true;
        }
      }
      return false;
    }

    for (i = 0; i < CONFIG.patterns.length; i++) {
      if (target.href.indexOf(CONFIG.patterns[i]) !== -1) {
        return true;
      }
    }
    return false;
  }

  /** Returns the URL with the carried parameters appended, or the input. */
  function decorate(url) {
    var target = absolute(url);
    if (!target || !isPortal(target.href)) {
      return url;
    }

    var values = params();
    var changed = false;

    for (var key in values) {
      if (Object.prototype.hasOwnProperty.call(values, key) && values[key]) {
        if (target.searchParams.get(key) !== values[key]) {
          target.searchParams.set(key, values[key]);
          changed = true;
        }
      }
    }

    return changed ? target.toString() : url;
  }

  function log() {
    if (CONFIG.debug && window.console) {
      window.console.log.apply(window.console, ['[gclid-forward]'].concat([].slice.call(arguments)));
    }
  }

  /* ------------------------------ DOM wiring ------------------------------ */

  var stats = { scanned: 0, matched: 0 };

  function decorateAnchor(anchor) {
    if (!anchor || !anchor.getAttribute) {
      return;
    }
    var href = anchor.getAttribute('href');
    if (!href || /^(#|mailto:|tel:|javascript:)/i.test(href)) {
      return;
    }

    var next = decorate(href);
    if (next !== href) {
      anchor.setAttribute('href', next);
      stats.matched++;
      log('decorated', next);
    }
  }

  function scan() {
    var links = document.querySelectorAll('a[href]');
    stats.scanned += links.length;
    for (var i = 0; i < links.length; i++) {
      decorateAnchor(links[i]);
    }
  }

  // Click-time decoration catches links built or re-rendered after load.
  document.addEventListener('click', function (event) {
    var anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
    if (anchor) {
      decorateAnchor(anchor);
    }
  }, true);

  // window.open("...portal...") bypasses anchors entirely.
  var nativeOpen = window.open;
  if (typeof nativeOpen === 'function') {
    window.open = function (url) {
      var args = [].slice.call(arguments);
      if (typeof url === 'string') {
        args[0] = decorate(url);
      }
      return nativeOpen.apply(window, args);
    };
  }

  function start() {
    params();   // record first touch
    scan();

    if (window.MutationObserver) {
      var pending = null;
      new MutationObserver(function () {
        if (pending) {
          return;
        }
        pending = window.setTimeout(function () {
          pending = null;
          scan();
        }, 150);
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  /* ------------------------------- debugging ------------------------------ */

  window.__gclidForward = {
    config: CONFIG,
    params: params,
    isPortal: isPortal,
    decorate: decorate,
    rescan: scan,
    debug: function (sampleUrl) {
      var values = params();
      var links = document.querySelectorAll('a[href]');
      var matched = [];

      for (var i = 0; i < links.length; i++) {
        var href = links[i].getAttribute('href');
        if (href && isPortal(href)) {
          matched.push(href);
        }
      }

      var report = {
        pageUrl: window.location.href,
        carriedParams: values,
        usingStoredValues: !new URLSearchParams(window.location.search).get('gclid'),
        linksOnPage: links.length,
        portalLinks: matched,
        matchedBy: CONFIG.hosts.length ? 'hosts' : 'patterns',
        sample: sampleUrl ? { url: sampleUrl, decorated: decorate(sampleUrl) } : null,
        linksDecorated: stats.matched
      };

      if (window.console) {
        window.console.log('[gclid-forward] debug', report);
      }
      return report;
    }
  };
})();