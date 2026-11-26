/**
 * Tests for snippets/gclid-forward.js — stubbed browser, no dependencies.
 *   node tests/gclid-forward.test.js
 */
const fs = require('fs');
const path = require('path');

const SRC = fs.readFileSync(path.join(__dirname, '..', 'snippets', 'gclid-forward.js'), 'utf8');

function makeLink(href) {
  const attrs = { href };
  return {
    getAttribute: (n) => (n in attrs ? attrs[n] : null),
    setAttribute: (n, v) => { attrs[n] = v; },
    get href() { return new URL(attrs.href, 'https://example.com/').toString(); },
    closest: (sel) => (sel === 'a[href]' ? linkRef.current : null),
    attrs,
  };
}

const linkRef = { current: null };

function boot({ url, links = [], hosts = [], patterns, storage }) {
  const store = storage || new Map();
  const listeners = {};
  const opened = [];
  const nodes = links.map(makeLink);

  global.window = {
    location: new URL(url),
    sessionStorage: {
      getItem: (k) => (store.has(k) ? store.get(k) : null),
      setItem: (k, v) => store.set(k, v),
    },
    MutationObserver: undefined,
    console: { log: () => {} },
    open: (...args) => { opened.push(args[0]); return null; },
  };

  global.document = {
    readyState: 'complete',
    body: {},
    querySelectorAll: () => nodes,
    addEventListener: (type, fn) => { listeners[type] = fn; },
  };

  eval(SRC);

  const api = global.window.__gclidForward;
  if (hosts.length) api.config.hosts = hosts;
  if (patterns) api.config.patterns = patterns;
  api.rescan();

  return { api, nodes, store, listeners, opened };
}

const results = [];
function check(label, ok) {
  results.push({ label, ok });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`);
}

/* 1. host matching */
let ctx = boot({
  url: 'https://example.com/?gclid=CLICK1&utm_source=google&utm_campaign=brand&ref=fb',
  links: ['https://portal.example.com/cart.php?a=add&pid=1', 'https://example.com/about'],
  hosts: ['portal.example.com'],
});
let portal = new URL(ctx.nodes[0].attrs.href);
check('portal link gets gclid', portal.searchParams.get('gclid') === 'CLICK1');
check('portal link gets utm_source', portal.searchParams.get('utm_source') === 'google');
check('portal link gets utm_campaign', portal.searchParams.get('utm_campaign') === 'brand');
check('non-ad param not forwarded', portal.searchParams.get('ref') === null);
check('existing query preserved', portal.searchParams.get('pid') === '1');
check('marketing link untouched', ctx.nodes[1].attrs.href === 'https://example.com/about');

/* 2. pattern matching when hosts is empty */
ctx = boot({
  url: 'https://example.com/?gclid=CLICK2',
  links: ['/order/cloud-vps', '/contact', 'https://portal.example.com/'],
});
ctx.api.config.patterns = ['/order', 'portal.example.com'];
ctx.api.rescan();
check('pattern match decorates relative link', new URL(ctx.nodes[0].attrs.href, 'https://example.com/').searchParams.get('gclid') === 'CLICK2');
check('pattern match decorates external portal', new URL(ctx.nodes[2].attrs.href).searchParams.get('gclid') === 'CLICK2');
check('non-matching link untouched', ctx.nodes[1].attrs.href === '/contact');

/* 3. no parameters at all */
ctx = boot({
  url: 'https://example.com/pricing',
  links: ['https://portal.example.com/cart.php'],
  hosts: ['portal.example.com'],
});
check('no gclid anywhere -> link unchanged', ctx.nodes[0].attrs.href === 'https://portal.example.com/cart.php');

/* 4. persistence across pages in the same session */
const store = new Map();
const first = boot({
  url: 'https://example.com/?gclid=PERSIST1&utm_source=google',
  links: [],
  hosts: ['portal.example.com'],
  storage: store,
});
const second = boot({
  url: 'https://example.com/pricing',            // no params on this page
  links: ['https://portal.example.com/cart.php'],
  hosts: ['portal.example.com'],
  storage: store,                                 // same sessionStorage
});
check('stored first-touch reused on later page', new URL(second.nodes[0].attrs.href).searchParams.get('gclid') === 'PERSIST1');
check('stored utm reused too', new URL(second.nodes[0].attrs.href).searchParams.get('utm_source') === 'google');

/* 5. click-time decoration for links built after load */
ctx = boot({
  url: 'https://example.com/?gclid=LATE1',
  links: ['https://portal.example.com/cart.php'],
  hosts: ['portal.example.com'],
});
ctx.api.rescan();
const lateHref = ctx.nodes[0].attrs.href;
ctx.listeners.click && ctx.listeners.click({ target: { closest: () => ctx.nodes[0] } });
check('click handler decorates anchor', new URL(ctx.nodes[0].attrs.href).searchParams.get('gclid') === 'LATE1');
check('href stable after decoration', lateHref === ctx.nodes[0].attrs.href);

/* 6. window.open interception */
ctx = boot({
  url: 'https://example.com/?gclid=OPEN1',
  links: [],
  hosts: ['portal.example.com'],
});
global.window.open('https://portal.example.com/checkout');
check('window.open decorated', new URL(ctx.opened[0]).searchParams.get('gclid') === 'OPEN1');

/* 7. debug report */
ctx = boot({
  url: 'https://example.com/?gclid=DBG1',
  links: ['https://portal.example.com/cart.php', 'https://example.com/about'],
  hosts: ['portal.example.com'],
});
const report = ctx.api.debug('https://portal.example.com/cart.php');
check('debug finds portal links', report.portalLinks.length === 1);
check('debug reports carried params', report.carriedParams.gclid === 'DBG1');
check('debug decorates sample', new URL(report.sample.decorated).searchParams.get('gclid') === 'DBG1');
check('debug reports match strategy', report.matchedBy === 'hosts');

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} assertions passed`);
process.exit(failed.length ? 1 : 0);