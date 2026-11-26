const fs = require('fs');

function makeLink(href) {
  const attrs = { href };
  return {
    getAttribute: (n) => attrs[n] ?? null,
    setAttribute: (n, v) => { attrs[n] = v; },
    href: () => attrs.href,
    attrs,
  };
}

const links = [
  makeLink('https://billing.example.com/cart.php?a=add&pid=1'),
  makeLink('https://example.com/about'),
  makeLink('#pricing'),
  makeLink('/order/cloud-vps'),              // relative link to WHMCS on same host
  makeLink('https://example.com/contact?utm_source=newsletter'),
];

global.window = {
  location: new URL('https://example.com/?gclid=TESTGCLID&utm_source=google&utm_campaign=brand&ref=fb'),
  MutationObserver: undefined,
  setTimeout: setTimeout,
};
global.document = {
  readyState: 'complete',
  querySelectorAll: () => links,
  body: {},
  addEventListener: () => {},
};

eval(fs.readFileSync('snippets/gclid-forward.js', 'utf8'));

const get = (l) => l.attrs.href;
const assert = (label, cond) => console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}`);

const whmcs = new URL(get(links[0]));
assert('whmcs absolute link keeps gclid', whmcs.searchParams.get('gclid') === 'TESTGCLID');
assert('whmcs absolute link keeps utm_source', whmcs.searchParams.get('utm_source') === 'google');
assert('whmcs absolute link keeps utm_campaign', whmcs.searchParams.get('utm_campaign') === 'brand');
assert('non-ad param (ref) not forwarded', whmcs.searchParams.get('ref') === null);
assert('original params preserved', whmcs.searchParams.get('pid') === '1');

assert('marketing page link untouched', get(links[1]) === 'https://example.com/about');
assert('anchor link untouched', get(links[2]) === '#pricing');

const relative = new URL(get(links[3]), 'https://example.com/');
assert('relative whmcs path decorated', relative.searchParams.get('gclid') === 'TESTGCLID');

const contact = new URL(get(links[4]));
assert('existing utm preserved', contact.searchParams.get('utm_source') === 'newsletter');
assert('non-whmcs link not given gclid', contact.searchParams.get('gclid') === null);

// No click id in URL -> nothing should be touched.
const before = links.map(get);
global.window.location = new URL('https://example.com/pricing');
eval(fs.readFileSync('snippets/gclid-forward.js', 'utf8'));
const after = links.map(get);
assert('no gclid in URL -> links unchanged', JSON.stringify(before) === JSON.stringify(after));