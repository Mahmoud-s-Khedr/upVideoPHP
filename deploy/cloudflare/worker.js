/**
 * Cloudflare Worker — HLS segment delivery with edge token validation.
 *
 * Intercepts:  GET /api/stream/:uuid/:label/seg:N.ts
 *              GET /api/stream/:uuid/:label/seg:N.ts?token=...
 *
 * Everything else is forwarded to the PHP origin unchanged.
 *
 * Flow per request:
 *   1. Token validation — replicates PHP StreamToken::verify() (HMAC-SHA256).
 *      Token source: ?token= query param  OR  stream_token cookie.
 *   2. Cache lookup — key is the stable path only (token stripped).
 *      Cache TTL: 1 hour (segments are immutable once written).
 *   3. Cache miss → build an AWS SigV4 Authorization-header signed request,
 *      fetch from B2, store in caches.default, return to client.
 *   4. Cache hit → return immediately; no origin hit, no B2 cost.
 *
 * Authorization-header signing (not presigned URL query params) matches the
 * official Backblaze/Cloudflare template. The signature never appears in URLs,
 * Worker logs, or Cloudflare analytics.
 *
 * IMPORTANT — B2 bucket info prerequisite:
 *   By default Cloudflare does not cache responses to requests that contained
 *   an Authorization header. Set your B2 bucket info to:
 *     {"Cache-Control": "public, max-age=3600"}
 *   (B2 console → Buckets → your bucket → Bucket Settings → Bucket Info)
 *   Reference: https://developers.cloudflare.com/cache/concepts/cache-control/#conditions
 *
 * Required Wrangler secrets (set via `wrangler secret put`):
 *   STREAM_TOKEN_SECRET   — same value as PHP's STREAM_TOKEN_SECRET .env key
 *   B2_KEY_ID             — Backblaze B2 key ID (read-only, bucket-scoped)
 *   B2_APP_KEY            — Backblaze B2 application key
 *   B2_BUCKET             — bucket name
 *
 * Non-secret vars in wrangler.toml:
 *   B2_REGION             — e.g. us-west-004
 *   B2_ENDPOINT_HOST      — e.g. s3.us-west-004.backblazeb2.com  (no https://)
 *
 * B2 bucket stays private — the signed Authorization header is used only
 * inside the Worker's internal fetch and is never forwarded to the client.
 *
 * Reference:
 *   https://www.backblaze.com/docs/cloud-storage-deliver-private-backblaze-b2-content-through-cloudflare-cdn
 *   https://github.com/backblaze-b2-samples/cloudflare-b2
 */

const SEGMENT_ROUTE = /^\/api\/stream\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/(\d+p)\/(seg\d+)\.ts$/;
const SEGMENT_CACHE_TTL = 3600;  // seconds — cached at Cloudflare edge

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const match = url.pathname.match(SEGMENT_ROUTE);

    // Not a segment request — pass through to PHP origin
    if (!match) {
      return fetch(request);
    }

    const [, uuid, label, seg] = match;

    // -------------------------------------------------------------------------
    // 1. Token validation
    // -------------------------------------------------------------------------
    const token = extractToken(request, url);
    if (!token) {
      return errorResponse(401, 'UNAUTHORIZED', 'Stream token required.');
    }

    try {
      await verifyStreamToken(token, uuid, env.STREAM_TOKEN_SECRET, request);
    } catch (err) {
      return errorResponse(401, 'UNAUTHORIZED', err.message);
    }

    // -------------------------------------------------------------------------
    // 2. Cache lookup  (key = stable path, no token)
    // -------------------------------------------------------------------------
    const cacheKey = new Request(
      `${url.origin}/api/stream/${uuid}/${label}/${seg}.ts`,
      { method: 'GET' }
    );
    const cache = caches.default;

    let cachedResponse = await cache.match(cacheKey);
    if (cachedResponse) {
      // Return a mutable copy with a cache-status header
      return addCacheHeader(cachedResponse, 'HIT');
    }

    // -------------------------------------------------------------------------
    // 3. Cache miss — fetch from B2 using Authorization-header signed request
    //    (official Backblaze pattern — signature stays inside the Worker)
    // -------------------------------------------------------------------------
    const b2Key = `videos/${uuid}/${label}/${seg}.ts`;
    let signedRequest;
    try {
      signedRequest = await buildB2SignedRequest(b2Key, env);
    } catch (err) {
      console.error('B2 signing error:', err);
      return errorResponse(500, 'INTERNAL_ERROR', 'Failed to sign storage request.');
    }

    let b2Response;
    try {
      b2Response = await fetch(signedRequest);
    } catch (err) {
      console.error('B2 fetch error:', err);
      return errorResponse(502, 'BAD_GATEWAY', 'Storage fetch failed.');
    }

    if (!b2Response.ok) {
      if (b2Response.status === 404 || b2Response.status === 403) {
        return errorResponse(404, 'NOT_FOUND', 'Segment not found.');
      }
      return errorResponse(502, 'BAD_GATEWAY', `B2 returned HTTP ${b2Response.status}.`);
    }

    // -------------------------------------------------------------------------
    // 4. Build cacheable response, store in cache, return to client
    // -------------------------------------------------------------------------
    const responseToCache = new Response(b2Response.body, {
      status: 200,
      headers: {
        'Content-Type':  'video/mp2t',
        'Cache-Control': `public, max-age=${SEGMENT_CACHE_TTL}, immutable`,
        'X-Segment-Cache': 'MISS',
      },
    });

    // waitUntil: cache write is best-effort after response is sent to client
    ctx.waitUntil(cache.put(cacheKey, responseToCache.clone()));

    return responseToCache;
  },
};

// =============================================================================
// Token helpers — mirrors PHP StreamToken::verify()
// =============================================================================

/**
 * Extract stream token from ?token= query param or stream_token cookie.
 * Cookie takes precedence for browser clients (avoid logging tokens in URLs).
 */
function extractToken(request, url) {
  const cookie = request.headers.get('Cookie') || '';
  const cookieMatch = cookie.match(/(?:^|;\s*)stream_token=([^;]+)/);
  if (cookieMatch) {
    return decodeURIComponent(cookieMatch[1]);
  }
  return url.searchParams.get('token') || null;
}

/**
 * Verify a PHP-issued HMAC-SHA256 stream token.
 *
 * PHP wire format:  base64url(payload) + "." + base64url(HMAC-SHA256(secret, payload))
 * Payload format:   "{video_uuid}:{expires_at_unix}:{ip}"
 *
 * Throws on any validation failure (invalid format, bad signature, expiry, UUID/IP mismatch).
 */
async function verifyStreamToken(token, expectedUuid, secret, request) {
  const dotIdx = token.indexOf('.');
  if (dotIdx === -1) throw new Error('Malformed token: missing separator.');

  const encodedPayload = token.slice(0, dotIdx);
  const encodedSig     = token.slice(dotIdx + 1);

  let payload;
  let sigBytes;
  try {
    payload  = new TextDecoder().decode(base64urlDecode(encodedPayload));
    sigBytes = base64urlDecode(encodedSig);
  } catch {
    throw new Error('Malformed token: base64url decode failed.');
  }

  // Verify HMAC signature
  const secretBytes = new TextEncoder().encode(secret);
  const cryptoKey   = await crypto.subtle.importKey(
    'raw', secretBytes, { name: 'HMAC', hash: 'SHA-256' }, false, ['verify']
  );
  const payloadBytes  = new TextEncoder().encode(payload);
  const signatureValid = await crypto.subtle.verify('HMAC', cryptoKey, sigBytes, payloadBytes);
  if (!signatureValid) throw new Error('Invalid token signature.');

  // Parse payload
  const parts = payload.split(':');
  if (parts.length < 2) throw new Error('Malformed token payload.');
  const [tokenUuid, expiresAtStr, boundIp = ''] = parts;

  // Expiry check
  if (Math.floor(Date.now() / 1000) > parseInt(expiresAtStr, 10)) {
    throw new Error('Token has expired.');
  }

  // UUID binding
  if (tokenUuid !== expectedUuid) {
    throw new Error('Token UUID does not match requested resource.');
  }

  // IP binding (optional — empty string means not bound)
  if (boundIp !== '') {
    const clientIp = request.headers.get('CF-Connecting-IP') || '';
    if (boundIp !== clientIp) {
      throw new Error('Token IP binding mismatch.');
    }
  }
}

// =============================================================================
// AWS Signature Version 4 — Authorization-header signing for B2 S3-compatible GET
//
// Matches the approach used by the official Backblaze/Cloudflare Worker template:
//   https://github.com/backblaze-b2-samples/cloudflare-b2
//
// The signed request carries:
//   Authorization: AWS4-HMAC-SHA256 Credential=..., SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=...
//   x-amz-date: {isoDateTime}
//   x-amz-content-sha256: UNSIGNED-PAYLOAD
//
// The Authorization header is only used for the Worker's internal fetch to B2.
// It is never forwarded to nor visible by the client.
// =============================================================================

/**
 * Build a signed Request for a B2 GET using AWS SigV4 Authorization-header signing.
 * Path-style endpoint: https://{host}/{bucket}/{key}
 */
async function buildB2SignedRequest(objectKey, env) {
  const host   = env.B2_ENDPOINT_HOST;  // e.g. "s3.us-west-004.backblazeb2.com"
  const bucket = env.B2_BUCKET;
  const region = env.B2_REGION;
  const keyId  = env.B2_KEY_ID;
  const appKey = env.B2_APP_KEY;

  const now             = new Date();
  const isoDateTime     = toISO8601Basic(now);      // "20260302T120000Z"
  const isoDate         = isoDateTime.slice(0, 8);  // "20260302"
  const credentialScope = `${isoDate}/${region}/s3/aws4_request`;

  // Signed headers (alphabetical): host, x-amz-content-sha256, x-amz-date
  const signedHeaders    = 'host;x-amz-content-sha256;x-amz-date';
  const canonicalHeaders =
    `host:${host}\n` +
    `x-amz-content-sha256:UNSIGNED-PAYLOAD\n` +
    `x-amz-date:${isoDateTime}\n`;

  // Canonical URI — path-style: /{bucket}/{key}
  const canonicalUri = '/' + [bucket, ...objectKey.split('/')]
    .map(segmentEncode)
    .join('/');

  const canonicalRequest = [
    'GET',
    canonicalUri,
    '',                   // empty query string
    canonicalHeaders,
    signedHeaders,
    'UNSIGNED-PAYLOAD',
  ].join('\n');

  // String to sign
  const canonicalHash = await sha256Hex(canonicalRequest);
  const stringToSign  = [
    'AWS4-HMAC-SHA256',
    isoDateTime,
    credentialScope,
    canonicalHash,
  ].join('\n');

  // Derived signing key and signature
  const signingKey = await deriveSigningKey(appKey, isoDate, region);
  const signature  = bufToHex(await hmacSignRaw(signingKey, stringToSign));

  const authorization =
    `AWS4-HMAC-SHA256 Credential=${keyId}/${credentialScope}, ` +
    `SignedHeaders=${signedHeaders}, ` +
    `Signature=${signature}`;

  return new Request(`https://${host}${canonicalUri}`, {
    method: 'GET',
    headers: {
      'Authorization':          authorization,
      'x-amz-date':             isoDateTime,
      'x-amz-content-sha256':   'UNSIGNED-PAYLOAD',
      'host':                   host,
    },
  });
}

async function deriveSigningKey(secretKey, dateStr, region) {
  const signingSecret = new TextEncoder().encode(`AWS4${secretKey}`);
  const kDate    = await hmacSignRaw(signingSecret, dateStr);
  const kRegion  = await hmacSignRaw(kDate, region);
  const kService = await hmacSignRaw(kRegion, 's3');
  const kRequest = await hmacSignRaw(kService, 'aws4_request');
  return kRequest;
}

async function hmacSignRaw(keyMaterial, data) {
  const key = await crypto.subtle.importKey(
    'raw',
    keyMaterial instanceof Uint8Array ? keyMaterial : new Uint8Array(keyMaterial),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(data));
  return new Uint8Array(sig);
}

async function sha256Hex(input) {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
  return bufToHex(new Uint8Array(digest));
}

function bufToHex(buf) {
  return [...buf].map(b => b.toString(16).padStart(2, '0')).join('');
}

// =============================================================================
// Encoding helpers
// =============================================================================

function base64urlDecode(str) {
  // Convert base64url → base64 → bytes
  const base64 = str.replace(/-/g, '+').replace(/_/g, '/');
  // Pad to multiple of 4
  const padded  = base64 + '='.repeat((4 - (base64.length % 4)) % 4);
  const binary  = atob(padded);
  const bytes   = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}

/**
 * RFC 3986 percent-encoding for URI path segments.
 * Used to build the canonical URI for the B2 S3-compatible path-style request.
 */
function segmentEncode(str) {
  return encodeURIComponent(str).replace(/[!'()*]/g, c => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);
}

function toISO8601Basic(date) {
  return date.toISOString()
    .replace(/[-:]/g, '')
    .replace(/\.\d{3}/, '');
}

// =============================================================================
// Response helpers
// =============================================================================

function errorResponse(status, code, message) {
  return new Response(
    JSON.stringify({ error: code, message }),
    {
      status,
      headers: { 'Content-Type': 'application/json' },
    }
  );
}

function addCacheHeader(response, status) {
  const r = new Response(response.body, response);
  r.headers.set('X-Segment-Cache', status);
  return r;
}
