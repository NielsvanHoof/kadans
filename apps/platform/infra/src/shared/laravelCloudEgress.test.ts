import assert from "node:assert/strict";
import test from "node:test";
import { LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS } from "./laravelCloudEgress.js";

/**
 * Loud drift check for AC #5.
 *
 * Laravel Cloud publishes egress IPs for security-group allowlists. When the
 * published set moves and this constant does not, Cloud cannot reach RDS — which
 * we want to notice in CI, not in production.
 *
 * Skipped when offline / the API is unreachable so local unit runs stay usable.
 */
test("committed Laravel Cloud eu-west-1 CIDRs match the published API", async (t) => {
  let response: Response;

  try {
    response = await fetch("https://cloud.laravel.com/api/ip?region=eu-west-1", {
      signal: AbortSignal.timeout(10_000),
    });
  } catch (error) {
    t.skip(`Laravel Cloud IP API unreachable: ${String(error)}`);
    return;
  }

  if (!response.ok) {
    t.skip(`Laravel Cloud IP API returned HTTP ${response.status}`);
    return;
  }

  const body = await response.json() as { ipv4?: string[] } | string[];
  const ipv4 = Array.isArray(body) ? body : body.ipv4;

  assert.ok(Array.isArray(ipv4) && ipv4.length > 0, "API must return ipv4 addresses");

  const published = [...ipv4]
    .map((address) => (address.includes("/") ? address : `${address}/32`))
    .sort();
  const committed = [...LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS].sort();

  assert.deepEqual(
    committed,
    published,
    "Update src/shared/laravelCloudEgress.ts from https://cloud.laravel.com/api/ip?region=eu-west-1",
  );
});
