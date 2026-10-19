'use strict';

const http = require('node:http');
const https = require('node:https');
const { createNetworkPolicy, parseWebUrl, UnsafeDestinationError } = require('./network-policy');

function createCallbackSender({ allowedUrls = [], apiKey = '', policy = createNetworkPolicy() } = {}) {
  const allowed = new Set(allowedUrls.map(value => parseWebUrl(value).href));
  function validate(value) {
    const url = parseWebUrl(value);
    if (!allowed.has(url.href)) throw new UnsafeDestinationError('Callback URL is not in the configured allowlist');
    if (!apiKey) throw new Error('Callback authentication key is not configured');
    return url;
  }
  return {
    validate,
    async send(value, data) {
      const url = validate(value);
      // Only an exact operator-configured callback may reach an internal app.
      // Scan URLs never use this exception, and an API caller cannot replace it.
      const target = await policy.resolve(url.href, { trustedCallback: true });
      const payload = JSON.stringify(data);
      return new Promise((resolve, reject) => {
        const req = (url.protocol === 'https:' ? https : http).request({
          hostname: target.host, port: target.port, servername: target.host,
          lookup: (_, options, callback) => callback(null,
            options.all ? [{ address: target.address, family: target.family }] : target.address,
            target.family),
          path: url.pathname + url.search, method: 'POST', agent: false,
          headers: {
            'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload),
            'X-Api-Key': apiKey,
          },
        }, res => {
          res.resume();
          // Never follow a redirect with the webhook secret attached.
          if (res.statusCode < 200 || res.statusCode >= 300) {
            reject(new Error(`Callback answered ${res.statusCode}`));
          } else resolve();
        });
        req.on('error', reject);
        req.setTimeout(10000, () => req.destroy(new Error('Callback timeout')));
        req.end(payload);
      });
    },
  };
}

module.exports = { createCallbackSender };
