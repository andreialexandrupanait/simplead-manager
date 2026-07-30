/**
 * SPEC §5.1 — the second observation point.
 *
 * "Regula anti-fals-pozitiv: două eșecuri consecutive înainte de a deschide un
 * incident, ideal din două locații. Altfel o sincopă de rețea pe VPS declanșează
 * downtime fals pe toată flota."
 *
 * Manager's own checks all leave from one Hetzner box, so a hiccup on that box's
 * uplink looks exactly like sixty sites going down at once. This Worker runs on
 * Cloudflare's edge — a genuinely different network path — and answers one
 * question: "can YOU reach this URL?"
 *
 * It is deliberately not a proxy:
 *   - a shared secret is required, so it cannot be used by anyone who finds it;
 *   - only http/https URLs are probed;
 *   - the response body is never returned, only status, timing and the colo that
 *     answered — there is nothing here worth stealing bandwidth for.
 */

const MAX_TIMEOUT_MS = 15000;
const DEFAULT_TIMEOUT_MS = 10000;

export default {
  async fetch(request, env) {
    if (request.method !== 'GET') {
      return json({ error: 'method_not_allowed' }, 405);
    }

    const url = new URL(request.url);

    if (url.pathname === '/health') {
      return json({ ok: true, colo: request.cf?.colo ?? null });
    }

    if (url.pathname !== '/probe') {
      return json({ error: 'not_found' }, 404);
    }

    // Constant-time-ish comparison is overkill for a probe secret, but rejecting
    // without a hint is not: a wrong secret gets the same 401 as a missing one.
    const provided = request.headers.get('x-probe-secret') || '';
    if (!env.PROBE_SECRET || provided !== env.PROBE_SECRET) {
      return json({ error: 'unauthorized' }, 401);
    }

    const target = url.searchParams.get('url');
    if (!target) {
      return json({ error: 'missing_url' }, 400);
    }

    let parsed;
    try {
      parsed = new URL(target);
    } catch {
      return json({ error: 'invalid_url' }, 400);
    }

    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
      return json({ error: 'unsupported_scheme' }, 400);
    }

    const timeout = Math.min(
      parseInt(url.searchParams.get('timeout_ms') || '', 10) || DEFAULT_TIMEOUT_MS,
      MAX_TIMEOUT_MS,
    );

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    const started = Date.now();

    try {
      // GET rather than HEAD: plenty of WordPress hosts answer HEAD differently
      // (or not at all), and what we care about is what a visitor would get.
      // Redirects are followed for the same reason.
      const response = await fetch(parsed.toString(), {
        method: 'GET',
        redirect: 'follow',
        signal: controller.signal,
        headers: {
          'User-Agent': 'SimpleAdManager-UptimeProbe/1.0 (+second-location check)',
          Accept: 'text/html,*/*',
        },
        cf: { cacheTtl: 0, cacheEverything: false },
      });

      return json({
        ok: response.status >= 200 && response.status < 400,
        status: response.status,
        ms: Date.now() - started,
        colo: request.cf?.colo ?? null,
        error: null,
      });
    } catch (e) {
      const aborted = e?.name === 'AbortError';

      return json({
        ok: false,
        status: null,
        ms: Date.now() - started,
        colo: request.cf?.colo ?? null,
        error: aborted ? 'timeout' : String(e?.message ?? e),
      });
    } finally {
      clearTimeout(timer);
    }
  },
};

function json(body, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json; charset=utf-8' },
  });
}
