'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const net = require('node:net');
const fs = require('node:fs');
const { spawn } = require('node:child_process');
const path = require('node:path');
const { once } = require('node:events');
const { createNetworkPolicy, isPublicAddress } = require('../lib/network-policy');
const { createEgressProxy } = require('../lib/egress-proxy');
const { createCallbackSender } = require('../lib/callback');
const { CookieScanner } = require('../lib/scanner');

const listen = server => new Promise(resolve => server.listen(0, '127.0.0.1', () => resolve(server.address().port)));
const close = server => new Promise(resolve => { server.closeAllConnections(); server.close(resolve); });
const getThroughProxy = (proxy, url) => new Promise((resolve, reject) => {
  const request = http.get(proxy, { path: url, agent: false }, response => {
    let body = ''; response.on('data', chunk => body += chunk);
    response.on('end', () => resolve({ status: response.statusCode, body }));
  });
  request.on('error', reject);
});

const deniedAddresses = [
  '0.0.0.0', '0.1.2.3', '10.1.2.3', '100.64.0.1', '100.127.255.255',
  '127.0.0.1', '169.254.169.254', '172.16.0.1', '172.31.255.255',
  '192.168.1.1', '192.0.0.1', '192.0.2.1', '192.88.99.1', '198.18.0.1',
  '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255',
  '::', '::1', '::ffff:127.0.0.1', '64:ff9b::a9fe:a9fe', 'fc00::1', 'fd00::1',
  'fe80::1', 'ff02::1', '2001::1', '2001:db8::1', '2002:7f00:1::', '3fff::1',
];

test('reserved, local and transition addresses are denied; global unicast works', () => {
  for (const address of deniedAddresses) assert.equal(isPublicAddress(address), false, address);
  for (const address of ['8.8.8.8', '1.1.1.1', '185.20.15.92', '2606:4700:4700::1111']) {
    assert.equal(isPublicAddress(address), true, address);
  }
});

test('URL canonicalization, DNS answers and operator blocked hosts fail closed', async () => {
  const policy = createNetworkPolicy({ lookup: async () => [{ address: '127.0.0.1', family: 4 }], blockedHosts: ['185.20.15.92'] });
  for (const url of [
    'file:///etc/passwd', 'ftp://example.org/', 'http://user:pass@example.org/',
    'http://127.1/', 'http://2130706433/', 'http://0x7f000001/', 'http://[::1]/',
    'http://localhost/', 'http://foo.localhost/', 'http://foo.local/',
    'http://metadata.google.internal/', 'http://185.20.15.92/', 'http://example.org:22/',
  ]) await assert.rejects(policy.resolve(url), undefined, url);
  const mixed = createNetworkPolicy({ lookup: async () => [
    { address: '8.8.8.8', family: 4 }, { address: '10.0.0.1', family: 4 },
  ] });
  await assert.rejects(mixed.resolve('https://mixed.example/'));
  const ownCloud = createNetworkPolicy({ blockedHosts: ['185.20.15.92'], lookup: async () => [{ address: '185.20.15.92', family: 4 }] });
  await assert.rejects(ownCloud.resolve('https://alias-to-our-cloud.example/'));
  const publicPolicy = createNetworkPolicy({ lookup: async () => [{ address: '8.8.8.8', family: 4 }] });
  assert.equal((await publicPolicy.resolve('https://public.example/')).address, '8.8.8.8');
});

test('egress proxy rejects direct private HTTP and HTTPS CONNECT without contacting fixture', async () => {
  let hits = 0;
  const fixture = http.createServer((_, res) => { hits++; res.end('private'); });
  const port = await listen(fixture);
  const proxy = await createEgressProxy(createNetworkPolicy({ allowedPorts: [80, 443, port] }));
  try {
    assert.equal((await getThroughProxy(proxy.url, `http://127.0.0.1:${port}/`)).status, 403);
    const answer = await new Promise((resolve, reject) => {
      const socket = net.connect(new URL(proxy.url).port, '127.0.0.1', () => socket.write(`CONNECT 127.0.0.1:${port} HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n`));
      let result = ''; socket.on('data', b => result += b); socket.on('end', () => resolve(result)); socket.on('error', reject);
    });
    assert.match(answer, /403 Forbidden/);
    assert.equal(hits, 0);
  } finally { await proxy.close(); await close(fixture); }
});

test('DNS rebinding after initial validation is blocked again at proxy connection', async () => {
  let calls = 0;
  const policy = createNetworkPolicy({ lookup: async () => [{ address: ++calls === 1 ? '8.8.8.8' : '127.0.0.1', family: 4 }] });
  await policy.resolve('http://rebind.example/');
  const proxy = await createEgressProxy(policy);
  try { assert.equal((await getThroughProxy(proxy.url, 'http://rebind.example/')).status, 403); }
  finally { await proxy.close(); }
});

test('trusted callback reaches exact internal webhook, rejects other paths and never follows redirects', async () => {
  const received = [];
  const fixture = http.createServer((req, res) => {
    received.push({ path: req.url, key: req.headers['x-api-key'] });
    if (req.url === '/redirect') { res.writeHead(302, { location: '/stolen' }); res.end(); }
    else res.end('ok');
  });
  const port = await listen(fixture);
  const base = `http://127.0.0.1:${port}`;
  const sender = createCallbackSender({ allowedUrls: [`${base}/api/v1/scan-webhook`, `${base}/redirect`], apiKey: 'callback-secret' });
  try {
    assert.throws(() => sender.validate(`${base}/admin`));
    assert.throws(() => sender.validate(`${base}/api/v1/scan-webhook?leak=1`));
    assert.throws(() => sender.validate('http://169.254.169.254/latest/meta-data/'));
    await sender.send(`${base}/api/v1/scan-webhook`, { status: 'completed' });
    await assert.rejects(sender.send(`${base}/redirect`, {}), /302/);
    assert.deepEqual(received, [
      { path: '/api/v1/scan-webhook', key: 'callback-secret' },
      { path: '/redirect', key: 'callback-secret' },
    ]);
  } finally { await close(fixture); }
});

test('scanner API exits when key is absent or production callback configuration is missing', async () => {
  for (const override of [
    { SCANNER_API_KEY: '', NODE_ENV: 'test' },
    { SCANNER_API_KEY: 'short', NODE_ENV: 'production' },
    { SCANNER_API_KEY: 'a'.repeat(32), SCANNER_CALLBACK_KEY: 'b'.repeat(32), SCANNER_CALLBACK_ALLOWED_URLS: '', NODE_ENV: 'production' },
  ]) {
    const child = spawn(process.execPath, ['server.js'], {
      cwd: path.join(__dirname, '..'), env: { ...process.env, ...override }, stdio: 'ignore',
    });
    const [code] = await once(child, 'exit');
    assert.equal(code, 1);
  }
});

test('authenticated API denies anonymous requests and private scan / callback destinations', async () => {
  const available = http.createServer();
  const port = await listen(available); await close(available);
  const key = 'a'.repeat(32);
  const child = spawn(process.execPath, ['server.js'], {
    cwd: path.join(__dirname, '..'), env: {
      ...process.env, NODE_ENV: 'production', PORT: String(port), SCANNER_API_KEY: key,
      SCANNER_CALLBACK_KEY: 'b'.repeat(32), SCANNER_CALLBACK_ALLOWED_URLS: 'http://nginx/api/v1/scan-webhook',
      PUPPETEER_EXECUTABLE_PATH: '/nonexistent/chromium-for-security-test',
    }, stdio: 'ignore',
  });
  try {
    let ready = false;
    for (let n = 0; n < 100; n++) {
      try { await fetch(`http://127.0.0.1:${port}/health`); ready = true; break; } catch {}
      await new Promise(resolve => setTimeout(resolve, 20));
    }
    assert.equal(ready, true);
    async function post(body, auth) {
      return fetch(`http://127.0.0.1:${port}/scan`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(auth ? { 'X-Api-Key': auth } : {}) }, body: JSON.stringify(body) });
    }
    assert.equal((await post({ url: 'http://127.0.0.1/' })).status, 401);
    assert.equal((await post({ url: 'http://127.0.0.1/' }, 'wrong')).status, 401);
    assert.equal((await post({ url: 'http://127.0.0.1/' }, key)).status, 400);
    // A public literal address is validated locally; no external connection occurs.
    const callback = await fetch(`http://127.0.0.1:${port}/scan/batch`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Api-Key': key },
      body: JSON.stringify({ urls: ['https://8.8.8.8/'], callback_url: 'http://127.0.0.1/secrets' }),
    });
    assert.equal(callback.status, 400);
    assert.match((await callback.json()).error, /allowlist/);
  } finally { child.kill('SIGTERM'); await once(child, 'exit'); }
});

const chrome = process.env.PUPPETEER_EXECUTABLE_PATH || '/Users/robinwikman/Library/Caches/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-mac-x64/chrome-headless-shell';
test('real Chromium records local test cookies but blocks private redirects, frames, workers and subresources', { skip: !fs.existsSync(chrome), timeout: 30000 }, async () => {
  process.env.PUPPETEER_EXECUTABLE_PATH = chrome;
  let privateHits = 0;
  const privateService = http.createServer((_, res) => { privateHits++; res.end('PRIVATE'); });
  const privatePort = await listen(privateService);
  const privateUrl = `http://127.0.0.1:${privatePort}/internal`;
  const fixture = http.createServer((req, res) => {
    if (req.url === '/redirect') { res.writeHead(302, { location: privateUrl }); res.end(); return; }
    if (req.url === '/worker.js') { res.setHeader('Content-Type', 'application/javascript'); res.end(`fetch(${JSON.stringify(privateUrl)}).catch(()=>{});`); return; }
    res.setHeader('Content-Type', 'text/html');
    res.end(`<!doctype html><title>Fixture</title><script>document.cookie='fixture_cookie=yes; path=/';localStorage.setItem('fixture_storage','yes');fetch(${JSON.stringify(privateUrl)}).catch(()=>{});new Worker('/worker.js');</script><img src="${privateUrl}"><iframe src="${privateUrl}"></iframe>`);
  });
  const port = await listen(fixture);
  const policy = createNetworkPolicy({
    allowedPorts: [80, 443, port, privatePort],
    lookup: async host => [{ address: host === 'fixture.example' ? '127.0.0.1' : '10.0.0.1', family: 4 }],
    allowTestAddress: (address, url) => address === '127.0.0.1' && url.hostname === 'fixture.example' && Number(url.port) === port,
  });
  const scanner = new CookieScanner({ networkPolicy: policy, timeout: 10000, maxConcurrent: 1 });
  try {
    await assert.rejects(scanner.scanUrl(privateUrl), /Destination/);
    const data = await scanner.scanUrl(`http://fixture.example:${port}/`, { waitForNetworkIdle: false, extraWait: 300 });
    assert.equal(data.error, undefined, data.error);
    assert.equal(data.cookies.some(cookie => cookie.name === 'fixture_cookie'), true);
    assert.equal(data.localStorage.includes('fixture_storage'), true);
    await scanner.scanUrl(`http://fixture.example:${port}/redirect`, { waitForNetworkIdle: false, extraWait: 100 });
    assert.equal(privateHits, 0);
  } finally { await scanner.close(); await close(fixture); await close(privateService); }
});
