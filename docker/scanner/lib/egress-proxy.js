'use strict';

const http = require('node:http');
const net = require('node:net');

// Chromium sends HTTP requests and HTTPS CONNECT tunnels through this proxy.
// Resolving + connecting here pins the validated IP, closing DNS-rebinding races
// that page request interception alone cannot prevent. It also covers workers,
// redirects, frames, WebSockets and browser-initiated resource requests.
async function createEgressProxy(policy) {
  const sockets = new Set();
  const track = socket => {
    sockets.add(socket);
    socket.once('close', () => sockets.delete(socket));
    socket.on('error', () => {});
    socket.setTimeout(60000, () => socket.destroy());
    return socket;
  };
  const server = http.createServer(async (req, res) => {
    let target;
    try { target = await policy.resolve(req.url); }
    catch { res.writeHead(403); res.end('Destination blocked'); return; }
    if (target.url.protocol !== 'http:') { res.writeHead(400); res.end(); return; }
    const headers = { ...req.headers, host: target.url.host };
    delete headers['proxy-authorization'];
    delete headers['proxy-connection'];
    const outgoing = http.request({
      hostname: target.address, family: target.family, port: target.port,
      path: target.url.pathname + target.url.search, method: req.method,
      headers, agent: false, timeout: 30000,
    }, upstream => {
      res.writeHead(upstream.statusCode, upstream.headers);
      upstream.pipe(res);
    });
    outgoing.on('socket', track);
    outgoing.on('timeout', () => outgoing.destroy(new Error('Upstream timeout')));
    outgoing.on('error', () => { if (!res.headersSent) res.writeHead(502); res.end(); });
    req.on('aborted', () => outgoing.destroy());
    res.on('close', () => outgoing.destroy());
    req.pipe(outgoing);
  });
  server.on('connection', track);
  server.on('clientError', (_, socket) => socket.destroy());
  server.on('upgrade', (_, socket) => socket.destroy());
  server.on('connect', async (req, client, head) => {
    let target;
    try {
      // CONNECT authority only: reject paths, credentials and malformed ports.
      if (!/^(?:\[[0-9a-f:]+\]|[^\s/:@?#]+):[0-9]+$/i.test(req.url)) throw new Error('Invalid CONNECT');
      target = await policy.resolve(`https://${req.url}/`);
    } catch { client.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); return; }
    if (client.destroyed) return;
    const upstream = track(net.connect({ host: target.address, family: target.family, port: target.port }));
    upstream.once('connect', () => {
      if (client.destroyed) { upstream.destroy(); return; }
      client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      if (head.length) upstream.write(head);
      client.pipe(upstream);
      upstream.pipe(client);
    });
    upstream.once('error', () => client.destroy());
    client.once('close', () => upstream.destroy());
  });
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  return {
    url: `http://127.0.0.1:${server.address().port}`,
    async close() {
      for (const socket of sockets) socket.destroy();
      await new Promise(resolve => server.close(resolve));
    },
  };
}

module.exports = { createEgressProxy };
