'use strict';

const dns = require('node:dns').promises;
const net = require('node:net');

class UnsafeDestinationError extends Error {
  constructor(message = 'Destination is not a public HTTP(S) address') {
    super(message);
    this.name = 'UnsafeDestinationError';
    this.statusCode = 400;
  }
}

function isPublicAddress(address) {
  if (net.isIP(address) === 4) {
    const [a, b, c] = address.split('.').map(Number);
    return !(a === 0 || a === 10 || a === 127 || a >= 224 ||
      (a === 100 && b >= 64 && b <= 127) || (a === 169 && b === 254) ||
      (a === 172 && b >= 16 && b <= 31) ||
      (a === 192 && (b === 168 || (b === 0 && (c === 0 || c === 2)) || (b === 88 && c === 99))) ||
      (a === 198 && (b === 18 || b === 19 || (b === 51 && c === 100))) ||
      (a === 203 && b === 0 && c === 113));
  }
  if (net.isIP(address) !== 6) return false;
  // Only global-unicast IPv6. This excludes loopback, mapped IPv4, NAT64,
  // link-local, ULA, multicast and transition mechanisms by default.
  const first = parseInt(address.split(':')[0] || '0', 16);
  if (first < 0x2000 || first > 0x3fff) return false;
  const second = parseInt(address.split(':')[1] || '0', 16);
  return !(first === 0x2001 && (second < 0x200 || second === 0xdb8)) &&
    !(first === 0x2002) && !(first === 0x3fff && second <= 0x0fff);
}

function hostnameOf(url) {
  return url.hostname.replace(/^\[|\]$/g, '').toLowerCase().replace(/\.$/, '');
}

function parseWebUrl(value) {
  let url;
  try { url = new URL(value); } catch { throw new UnsafeDestinationError('Invalid URL'); }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password ||
      !url.hostname || url.hash) {
    throw new UnsafeDestinationError('Only HTTP(S) URLs without credentials or fragments are allowed');
  }
  return url;
}

function createNetworkPolicy({
  lookup = (host) => dns.lookup(host, { all: true, verbatim: true }),
  blockedHosts = (process.env.SCANNER_BLOCKED_HOSTS || '').split(',').map(s => s.trim().toLowerCase()).filter(Boolean),
  allowedPorts = [80, 443],
  // Dependency injection for isolated local tests only. Never comes from API options or env.
  allowTestAddress = () => false,
} = {}) {
  const blocked = new Set(blockedHosts);
  return {
    async resolve(value, { trustedCallback = false } = {}) {
      const url = parseWebUrl(value);
      const host = hostnameOf(url);
      const port = Number(url.port || (url.protocol === 'https:' ? 443 : 80));
      if (!trustedCallback && (!allowedPorts.includes(port) || blocked.has(host) ||
          host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.local'))) {
        throw new UnsafeDestinationError();
      }
      let addresses;
      try {
        addresses = net.isIP(host) ? [{ address: host, family: net.isIP(host) }] : await lookup(host);
      } catch { throw new UnsafeDestinationError('Destination DNS lookup failed'); }
      if (!Array.isArray(addresses) || addresses.length === 0 || addresses.some(({ address }) =>
        !net.isIP(address) || (!trustedCallback && (blocked.has(address.toLowerCase()) ||
          (!isPublicAddress(address) && !allowTestAddress(address, url)))))) {
        throw new UnsafeDestinationError();
      }
      // The connection uses this exact validated address, never a second DNS lookup.
      return { url, host, port, address: addresses[0].address, family: net.isIP(addresses[0].address) };
    },
  };
}

module.exports = { createNetworkPolicy, isPublicAddress, parseWebUrl, hostnameOf, UnsafeDestinationError };
