/**
 * Laravel Cloud outbound IPv4 addresses for `eu-west-1`, as /32 CIDRs.
 *
 * Source: `GET https://cloud.laravel.com/api/ip?region=eu-west-1` (fetched
 * 2026-08-22). Cloud publishes these for security-group / firewall allowlists;
 * they change over time — a stale list means RDS is unreachable from Cloud,
 * which fails loudly (good) rather than silently.
 *
 * Re-fetch before widening production and update this constant. A drift check
 * belongs with TASK-43.3; until then the constant is the allowlist of record.
 */
export const LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS = [
  "54.170.35.8/32",
  "99.80.90.7/32",
  "18.200.179.186/32",
  "63.34.93.245/32",
  "34.251.202.78/32",
  "52.48.97.173/32",
] as const;
