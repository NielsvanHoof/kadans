import * as pulumi from "@pulumi/pulumi";
import type {
  DeploymentEnvironment} from "./environments/environment.js";
import {
  deploymentEnvironmentFromStack,
} from "./environments/environment.js";

/**
 * Stack configuration for the data-plane program.
 *
 * Compute (ECS/ALB/edge/cache) was removed in TASK-43.3 — Laravel Cloud owns the
 * application runtime. This config therefore only carries what the remaining
 * program needs: environment knobs, optional alerting contacts, Bedrock ARNs,
 * hostnames for CORS/SES, and mail. Application secrets such as APP_KEY live in
 * Laravel Cloud, not here.
 *
 * The RDS master password is read inside `database.ts` from the Pulumi config
 * secret `databasePassword` so it never appears as a stack output.
 */
export interface PlatformConfig {
  environment: DeploymentEnvironment;
  /** Region the stack deploys into; also scopes Bedrock model ARNs. */
  region: string;
  /** Optional email for operational alarms and budget notifications. */
  alarmEmailAddress?: string;
  /**
   * Optional E.164 number for the alarms that should wake someone.
   *
   * Only the page-worthy ones subscribe to SMS. An address that receives every
   * alarm by text stops being read, which is worse than not having it.
   */
  alarmSmsNumber?: string;
  /**
   * Region that holds the replicated RDS automated backups.
   *
   * eu-west-1 is the region for everything else, and this is the one deliberate
   * exception — a backup copy in the same region as the database is not disaster
   * recovery, it is a second copy of the same failure domain. Defaults to
   * eu-central-1 so the data stays inside the EU. Set `crossRegionSnapshotCopy`
   * to false in the environment settings to keep everything in one region.
   */
  disasterRecoveryRegion: string;
  /**
   * Bedrock resources the application may invoke.
   *
   * Override with `pulumi config set --path bedrockModelArns[0] <arn>` to narrow
   * this to the single model in AI_DOCUMENT_OVERVIEW_MODEL once it stops changing.
   */
  bedrockModelArns: string[];

  /** Apex domain, e.g. `qompose.nl`. The marketing site lives here; we never touch it. */
  domain: string;
  /** Central staff hostname helpers, e.g. `app.qompose.nl` (CORS / docs). */
  appHostname: string;
  /** Wildcard for per-tenant hostnames (CORS / docs). */
  tenantHostnamePattern: string;
  /**
   * Route53 zone for `domain`, when AWS hosts the DNS.
   *
   * Optional on purpose. Without it the stack still builds SES and exports the
   * DKIM records to create by hand.
   */
  hostedZoneId?: string;
  /** Envelope sender for SES; must be inside `domain` for DKIM to align. */
  mailFromAddress: string;
  /**
   * Additional browser origins for presigned document uploads (merged with the
   * environment defaults and the derived app/tenant hostnames).
   */
  uploadAllowedOrigins: string[];
}

export function loadPlatformConfig(): PlatformConfig {
  const config = new pulumi.Config();
  const environment = deploymentEnvironmentFromStack(pulumi.getStack());
  const region = new pulumi.Config("aws").get("region") ?? "eu-west-1";
  const domain = config.get("domain") ?? "qompose.nl";
  const zone = hostnameZone(domain, environment.settings.dnsSubdomain);

  return {
    environment,
    region,
    alarmEmailAddress: config.get("alarmEmailAddress") ?? undefined,
    alarmSmsNumber: config.get("alarmSmsNumber") ?? undefined,
    disasterRecoveryRegion: config.get("disasterRecoveryRegion") ?? "eu-central-1",
    bedrockModelArns: config.getObject<string[]>("bedrockModelArns")
      ?? defaultBedrockModelArns(region),

    domain,
    appHostname: config.get("appHostname") ?? `app.${zone}`,
    tenantHostnamePattern: config.get("tenantHostnamePattern") ?? `*.${zone}`,
    hostedZoneId: config.get("hostedZoneId") ?? undefined,
    mailFromAddress: config.get("mailFromAddress") ?? `no-reply@${zone}`,
    uploadAllowedOrigins: config.getObject<string[]>("uploadAllowedOrigins") ?? [],
  };
}

/**
 * The DNS level this environment owns: the apex for production, a dedicated
 * label for everything else. See `EnvironmentSettings.dnsSubdomain`.
 */
export function hostnameZone(domain: string, subdomain: string | null): string {
  return subdomain === null ? domain : `${subdomain}.${domain}`;
}

/**
 * Foundation models and inference profiles the OCR overview step may call.
 *
 * Deliberately still family-wide within a vendor: the app selects models per
 * environment and per agent today, and foundation-model ARNs keep a wildcard
 * region so cross-region inference profiles keep working. The inference-profile
 * ARN is scoped to this stack's region.
 *
 * Anthropic is absent on purpose. Invoking those models needs an account-level
 * use-case submission this account has not made, so they answer every request
 * with a 404 that reads like a bad model id (.ai/rules/ai.md). Withholding the
 * IAM grant turns that into an AccessDenied naming the model instead, and stops
 * an env var from routing client document fields at a model we cannot call.
 * Granting it again is a two-line change once the submission clears.
 */
export function defaultBedrockModelArns(region: string): string[] {
  return [
    "arn:aws:bedrock:*::foundation-model/openai.*",
    "arn:aws:bedrock:*::foundation-model/amazon.*",
    `arn:aws:bedrock:${region}:*:inference-profile/*`,
  ];
}
