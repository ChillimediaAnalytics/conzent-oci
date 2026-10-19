/**
 * OCI Cookie Scanner — HTTP API Server
 *
 * Endpoints:
 *   GET  /health           — Health check (no auth)
 *   POST /scan             — Scan a URL for cookies (auth required)
 *   POST /scan/batch       — Scan multiple URLs (auth required)
 *   GET  /status/:jobId    — Check scan job status (auth required)
 *
 * Auth: X-Api-Key header must match SCANNER_API_KEY env var.
 *
 * Environment:
 *   PORT                 — HTTP port (default 8300)
 *   SCANNER_API_KEY      — Required API key for authentication
 *   SCANNER_CALLBACK_KEY — X-Api-Key sent on result callbacks for the configured trusted webhook; API callers cannot override it
 *   SCANNER_CALLBACK_ALLOWED_URLS — Exact trusted webhook URLs (comma-separated)
 *   SCANNER_BLOCKED_HOSTS — Additional hostnames/IPs scans cannot reach
 *   MAX_CONCURRENT       — Max concurrent browser scans (default 3)
 *   SCAN_TIMEOUT         — Per-page timeout in ms (default 60000)
 */

const http = require('http');
const { timingSafeEqual } = require('node:crypto');
const { createCallbackSender } = require('./lib/callback');
const { CookieScanner } = require('./lib/scanner');

const PORT = parseInt(process.env.PORT || '8300', 10);
const API_KEY = process.env.SCANNER_API_KEY || '';
const MAX_CONCURRENT = parseInt(process.env.MAX_CONCURRENT || '3', 10);
const SCAN_TIMEOUT = parseInt(process.env.SCAN_TIMEOUT || '60000', 10);
const MAX_BATCH_URLS = Math.min(Math.max(parseInt(process.env.SCANNER_MAX_BATCH_URLS || '500', 10) || 500, 1), 10000);
const CALLBACK_KEY = process.env.SCANNER_CALLBACK_KEY || '';
const CALLBACK_URLS = (process.env.SCANNER_CALLBACK_ALLOWED_URLS || '').split(',').map(s => s.trim()).filter(Boolean);

// Fail closed in every environment; production also rejects short secrets.
if (!API_KEY || (process.env.NODE_ENV === 'production' && (Buffer.byteLength(API_KEY) < 32 || Buffer.byteLength(CALLBACK_KEY) < 32 || CALLBACK_URLS.length === 0))) {
  console.error('Scanner requires SCANNER_API_KEY; production also requires 32-byte API/callback secrets and SCANNER_CALLBACK_ALLOWED_URLS.');
  process.exit(1);
}
const callbacks = createCallbackSender({ allowedUrls: CALLBACK_URLS, apiKey: CALLBACK_KEY });

// What this scanner understands, so the app can tell an upgraded scanner from
// an old one before asking it for a pass it cannot run.
const SCANNER_VERSION = '1.3.0';
const CAPABILITIES = ['consent_phases', 'block_urls', 'callback_auth', 'consent_mode_probe', 'consent_mode_timeline'];

// In-memory job store (jobs are ephemeral — results pushed to main app via callback)
const jobs = new Map();
let jobCounter = 0;

// Scanner instance (manages browser pool)
const scanner = new CookieScanner({ maxConcurrent: MAX_CONCURRENT, timeout: SCAN_TIMEOUT });

// ── HTTP Server ─────────────────────────────────────────

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`);
  const method = req.method;

  // CORS headers for flexibility
  res.setHeader('Content-Type', 'application/json');

  try {
    // Health check — no auth. Reports 503 while the browser is unavailable so
    // the Docker healthcheck and the app's scan-server monitor see the true
    // state. The process stays up either way — a browser failure must never
    // crash-loop this container.
    if (method === 'GET' && url.pathname === '/health') {
      return respond(res, browserReady ? 200 : 503, {
        status: browserReady ? 'ok' : 'degraded',
        scanner: 'oci-scanner',
        version: SCANNER_VERSION,
        capabilities: CAPABILITIES,
        browserReady,
        activeJobs: scanner.activeCount,
        maxConcurrent: MAX_CONCURRENT,
        uptime: Math.floor(process.uptime()),
      });
    }

    // Auth check for all other endpoints
    if (!authenticateRequest(req)) {
      return respond(res, 401, { error: 'Invalid or missing API key' });
    }

    // POST /scan — Single URL scan (synchronous)
    if (method === 'POST' && url.pathname === '/scan') {
      const body = await readBody(req);
      if (!body.url) {
        return respond(res, 400, { error: 'url is required' });
      }

      if (jobs.size > 100 || scanner.activeCount >= MAX_CONCURRENT) return respond(res, 429, { error: 'Scanner is busy' });
      const result = await scanner.scanUrl(body.url, body.options || {});
      return respond(res, 200, { success: true, data: result });
    }

    // POST /scan/batch — Multi-URL scan (async with job ID)
    if (method === 'POST' && url.pathname === '/scan/batch') {
      const body = await readBody(req);
      if (!body.urls || !Array.isArray(body.urls) || body.urls.length === 0) {
        return respond(res, 400, { error: 'urls array is required' });
      }

      if (body.urls.length > MAX_BATCH_URLS) return respond(res, 400, { error: `At most ${MAX_BATCH_URLS} URLs per batch` });
      if (jobs.size >= 100 || scanner.activeCount >= MAX_CONCURRENT) return respond(res, 429, { error: 'Scanner is busy' });
      for (const scanUrl of body.urls) await scanner.networkPolicy.resolve(scanUrl);
      const callbackUrl = body.callback_url || null;
      if (callbackUrl) callbacks.validate(callbackUrl);
      const jobId = String(++jobCounter);
      const scanId = body.scan_id || null;

      jobs.set(jobId, {
        id: jobId,
        scanId,
        // Echoed back so the app can tell this dispatch's results from a
        // stale one after a retry re-dispatched the same scan.
        dispatchId: body.dispatch_id || null,
        status: 'running',
        total: body.urls.length,
        completed: 0,
        failed: 0,
        results: [],
        startedAt: new Date().toISOString(),
      });

      // Process asynchronously
      processBatchScan(jobId, body.urls, body.options || {}, callbackUrl).catch(err => {
        console.error(`Batch job ${jobId} error:`, err.message);
      });

      return respond(res, 202, {
        success: true,
        job_id: jobId,
        total: body.urls.length,
        message: 'Scan queued',
      });
    }

    // GET /status/:jobId — Check job status
    if (method === 'GET' && url.pathname.startsWith('/status/')) {
      const jobId = url.pathname.split('/status/')[1];
      const job = jobs.get(jobId);

      if (!job) {
        return respond(res, 404, { error: 'Job not found' });
      }

      return respond(res, 200, {
        success: true,
        data: {
          id: job.id,
          scan_id: job.scanId,
          status: job.status,
          total: job.total,
          completed: job.completed,
          failed: job.failed,
          results: job.status === 'completed' ? job.results : [],
          started_at: job.startedAt,
          completed_at: job.completedAt || null,
        },
      });
    }

    // 404
    return respond(res, 404, { error: 'Not found' });

  } catch (err) {
    console.error('Request error:', err.message);
    const status = err.statusCode || 500;
    return respond(res, status, { error: status < 500 ? err.message : 'Internal server error' });
  }
});

// ── Batch Processing ────────────────────────────────────

async function processBatchScan(jobId, urls, options, callbackUrl) {
  const job = jobs.get(jobId);

  for (const url of urls) {
    try {
      const result = await scanner.scanUrl(url, options);
      job.results.push(result);
      job.completed++;
    } catch (err) {
      job.results.push({
        url,
        error: err.message,
        cookies: [],
        localStorage: [],
        beacons: [],
      });
      job.failed++;
      job.completed++;
    }
  }

  job.status = 'completed';
  job.completedAt = new Date().toISOString();

  // Send results to callback URL if provided
  if (callbackUrl) {
    try {
      await callbacks.send(callbackUrl, {
        job_id: jobId,
        scan_id: job.scanId,
        ...(job.dispatchId ? { dispatch_id: job.dispatchId } : {}),
        status: 'completed',
        total: job.total,
        completed: job.completed,
        failed: job.failed,
        results: job.results,
      });
    } catch (err) {
      console.error(`Callback to ${callbackUrl} failed:`, err.message);
    }
  }

  // Clean up job after 1 hour
  setTimeout(() => jobs.delete(jobId), 3600000);
}

// ── Helpers ─────────────────────────────────────────────

function authenticateRequest(req) {
  const provided = req.headers['x-api-key'];
  if (!API_KEY || typeof provided !== 'string') return false;
  const expected = Buffer.from(API_KEY);
  const actual = Buffer.from(provided);
  return expected.length === actual.length && timingSafeEqual(expected, actual);
}

function respond(res, status, data) {
  res.writeHead(status);
  res.end(JSON.stringify(data));
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let bytes = 0;
    req.on('data', chunk => {
      bytes += chunk.length;
      if (bytes > 1024 * 1024) {
        const error = new Error('Request body is too large');
        error.statusCode = 413;
        reject(error);
        req.resume();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      try {
        const data = JSON.parse(Buffer.concat(chunks).toString());
        if (!data || Array.isArray(data) || typeof data !== 'object') throw new Error();
        resolve(data);
      } catch {
        const error = new Error('Invalid JSON body');
        error.statusCode = 400;
        reject(error);
      }
    });
    req.on('error', reject);
  });
}

// ── Startup ─────────────────────────────────────────────

let browserReady = false;
let shuttingDown = false;

// Launch Chromium with unbounded backoff retry. A launch failure must NOT crash
// the process — otherwise `restart: always` turns one bad launch into an
// infinite container restart loop (exactly what took the scanner down on
// deploy). The HTTP server starts regardless so /health stays reachable.
async function initBrowserWithRetry() {
  const backoffs = [2000, 5000, 15000, 30000, 60000];
  let attempt = 0;
  while (!shuttingDown) {
    try {
      await scanner.initialize();
      browserReady = true;
      console.log('Browser ready.');

      // If Chromium dies later, flip to degraded and re-launch.
      if (scanner.browser) {
        scanner.browser.once('disconnected', () => {
          if (shuttingDown) return;
          browserReady = false;
          console.error('Browser disconnected — reinitializing...');
          initBrowserWithRetry();
        });
      }
      return;
    } catch (err) {
      browserReady = false;
      const delay = backoffs[Math.min(attempt, backoffs.length - 1)];
      attempt++;
      console.error(`Browser launch failed (attempt ${attempt}): ${err && err.message}. Retrying in ${delay / 1000}s...`);
      await new Promise(r => setTimeout(r, delay));
    }
  }
}

// Listen first — the container is "up" as soon as the API answers, independent
// of the browser. /health reports the browser state separately.
server.listen(PORT, '0.0.0.0', () => {
  console.log(`OCI Scanner listening on port ${PORT}`);
  console.log(`Max concurrent scans: ${MAX_CONCURRENT}`);
  console.log(`Scan timeout: ${SCAN_TIMEOUT}ms`);
  console.log('API key: configured');
});

initBrowserWithRetry();

// Last-resort backstops: never let a stray async error exit the process and
// trigger the restart loop. Log loudly and keep serving.
process.on('unhandledRejection', (reason) => {
  console.error('Unhandled rejection:', reason);
});
process.on('uncaughtException', (err) => {
  console.error('Uncaught exception:', err);
});

// Graceful shutdown
async function shutdown() {
  shuttingDown = true;
  console.log('Shutting down...');
  server.close();
  try {
    await scanner.close();
  } catch (err) {
    console.error('Error closing scanner:', err && err.message);
  }
  process.exit(0);
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
