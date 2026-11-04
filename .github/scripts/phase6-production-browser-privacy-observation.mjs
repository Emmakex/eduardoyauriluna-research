#!/usr/bin/env node
/**
 * Read-only, anonymous browser observation of privacy-relevant public behavior.
 * Report hostnames, browser storage KEY names, cookie NAMES and request TYPES only.
 * No form submission, no consent interaction, no request paths, queries, headers,
 * cookie values, storage values, browser screenshot or raw HTML in output.
 */
import { chromium } from 'playwright';

const BASE = 'https://eduardoyauriluna.com';
const HOST = 'eduardoyauriluna.com';
const PATHS = ['/', '/es/', '/insights/', '/es/notas/', '/contact/', '/es/contacto/', '/privacy-policy/', '/es/privacidad/'];
const TYPE_ALLOWLIST = new Set(['document', 'stylesheet', 'script', 'image', 'font', 'xhr', 'fetch', 'other', 'websocket', 'media', 'eventsource', 'manifest', 'texttrack']);
const capped = (s) => String(s).slice(0, 120);
const allowedKey = (s) => /^[\w.:-]{1,100}$/.test(s);

function hostname(raw) {
  try {
    const url = new URL(raw);
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.hostname.toLowerCase() : null;
  } catch {
    return null;
  }
}
function assert(cond, message) {
  if (!cond) throw new Error(message);
}
function selfTest() {
  assert(hostname('https://example.com/a?private=secret') === 'example.com', 'host extraction failed');
  assert(hostname('mailto:secret@example.com') === null, 'non-http scheme allowed');
  assert(allowedKey('wp-settings-1'), 'normal key invalid');
  assert(!allowedKey('contact@example.com'), 'value-like key should not be logged');
  console.log('PASS: privacy browser observation metadata-only self-test');
}

async function observe(browser, path) {
  const context = await browser.newContext({
    locale: path.startsWith('/es/') ? 'es-ES' : 'en-GB',
    userAgent: 'EduardoResearch-Phase6-Privacy-Browser/1.0',
  });
  const page = await context.newPage();
  const external = new Map();
  page.on('request', (request) => {
    const host = hostname(request.url());
    if (!host || host === HOST) return;
    const kind = request.resourceType();
    const accepted = TYPE_ALLOWLIST.has(kind) ? kind : 'other';
    if (!external.has(host)) external.set(host, new Set());
    external.get(host).add(accepted);
  });
  try {
    const response = await page.goto(new URL(path, BASE).href, {
      waitUntil: 'domcontentloaded',
      timeout: 45_000,
    });
    assert(response && response.status() === 200, `${path}: page must return 200`);
    assert(hostname(page.url()) === HOST, `${path}: page redirected off the canonical host`);
    await page.waitForTimeout(1200);
    const storage = await page.evaluate(() => {
      const collect = (store) => {
        try {
          return Array.from({ length: Math.min(store.length, 100) }, (_, i) => store.key(i))
            .filter((v) => typeof v === 'string');
        } catch {
          return [];
        }
      };
      return { local: collect(localStorage), session: collect(sessionStorage) };
    });
    const names = (items) => [...new Set(items.map((s) => capped(s)).filter(allowedKey))].sort();
    const cookies = await context.cookies();
    const report = {
      path,
      response_status: 200,
      third_party_requests: [...external.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([host, types]) => ({ host, resource_types: [...types].sort() })),
      cookies: [...new Map(cookies
        .filter((c) => allowedKey(c.name) && /^[a-z0-9.-]+$/i.test(c.domain))
        .map((c) => [c.domain + ':' + c.name, { name: capped(c.name), domain: capped(c.domain) }])).values()]
        .sort((a, b) => (a.domain + a.name).localeCompare(b.domain + b.name)),
      local_storage_keys: names(storage.local),
      session_storage_keys: names(storage.session),
    };
    console.log(JSON.stringify(report));
    return report;
  } finally {
    await context.close();
  }
}

async function main() {
  if (process.argv.includes('--self-test')) {
    selfTest();
    return;
  }
  const browser = await chromium.launch({ headless: true });
  try {
    const reports = [];
    for (const path of PATHS) {
      reports.push(await observe(browser, path));
    }
    const hosts = [...new Set(reports.flatMap((r) => r.third_party_requests.map((x) => x.host)))].sort();
    console.log(JSON.stringify({
      verdict: 'OBSERVED_ONLY_NOT_COMPLIANCE_CERTIFICATION',
      pages_checked: reports.length,
      third_party_request_hosts_seen: hosts,
      cookie_names_seen: [...new Set(reports.flatMap((r) => r.cookies.map((c) => c.name)))].sort(),
      local_storage_keys_seen: [...new Set(reports.flatMap((r) => r.local_storage_keys))].sort(),
      session_storage_keys_seen: [...new Set(reports.flatMap((r) => r.session_storage_keys))].sort(),
      limitations: [
        'Unauthenticated, no consent clicks or form submission; browser observed only 8 fixed URLs',
        'Request domains do not prove processing purpose or vendor relationship',
        'Absent cookies here do not prove absence after interaction, authentication, or different devices',
        'Hosting, logs, data processors, legal basis and retention require separate factual verification',
      ],
    }));
  } finally {
    await browser.close();
  }
}

await main();
