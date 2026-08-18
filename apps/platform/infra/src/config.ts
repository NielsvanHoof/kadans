import * as pulumi from "@pulumi/pulumi";
import {
  DeploymentEnvironment,
  deploymentEnvironmentFromStack,
} from "./environments/environment.js";

/**
 * Values the application needs but must never carry in plaintext.
 *
 * Sourced from Pulumi config secrets rather than generated in-program. Generating
 * them would mean adding `@pulumi/random` and, more importantly, would put the
 * only copy of APP_KEY inside Pulumi state — where losing the stack means every
 * encrypted column and every signed URL becomes unreadable. A value you had to
 * paste in is a value you still have.
 *
 *   pulumi config set --secret appKey "$(php artisan key:generate --show)"
 */
export interface PlatformSecrets {
  appKey: pulumi.Output<string>;
  reverbAppId: pulumi.Output<string>;
  reverbAppSecret: pulumi.Output<string>;
  sentryDsn: pulumi.Output<string>;
  /** Valkey AUTH token; ElastiCache requires 16–128 printable characters. */
  cacheAuthToken: pulumi.Output<string>;
}

export interface PlatformConfig {
  environment: DeploymentEnvironment;
  /** Region the stack deploys into; also scopes Bedrock model ARNs. */
  region: string;
  /** Optional email for Textract results DLQ CloudWatch alarms. */
  alarmEmailAddress?: string;
  /**
   * Bedrock resources the application may invoke.
   *
   * Override with `pulumi config set --path bedrockModelArns[0] <arn>` to narrow
   * this to the single model in AI_DOCUMENT_OVERVIEW_MODEL once it stops changing.
   */
  bedrockModelArns: string[];

  /** Apex domain, e.g. `qompose.nl`. The marketing site lives here; we never touch it. */
  domain: string;
  /** Central staff hostname this stack serves, e.g. `app.qompose.nl`. */
  appHostname: string;
  /** Wildcard for per-tenant hostnames. Provisioned now, routed by TASK-34.5. */
  tenantHostnamePattern: string;
  /**
   * Route53 zone for `domain`, when AWS hosts the DNS.
   *
   * Optional on purpose. Without it the stack still builds the certificate and the
   * load balancer, and exports the records to create by hand — but it skips ACM
   * validation, which would otherwise sit and block for 45 minutes waiting for a
   * record nobody is going to write.
   */
  hostedZoneId?: string;
  /**
   * Container image tag the ECS services run.
   *
   * The ECR repository is tag-immutable, so this is a real, specific build — a
   * commit SHA in practice. It is config rather than derived because rolling back
   * is then `pulumi config set imageTag <older-sha>`, not a revert-and-rebuild.
   */
  imageTag: string;
  /** Envelope sender for SES; must be inside `domain` for DKIM to align. */
  mailFromAddress: string;
  /**
   * Reverb's client-facing application key.
   *
   * Plain config, not a secret, because it is not one: it is compiled into the
   * JavaScript bundle as `VITE_REVERB_APP_KEY` and served to every visitor. Storing
   * it as a Pulumi secret would be theatre that also makes the release pipeline
   * harder — the image build needs this value, so a secret would have to be
   * decrypted and handed to a Docker build argument to produce a public asset.
   * `reverbAppSecret` is the one that actually authenticates.
   */
  reverbPublicKey: string;

  secrets: PlatformSecrets;
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
    bedrockModelArns: config.getObject<string[]>("bedrockModelArns")
      ?? defaultBedrockModelArns(region),

    domain,
    appHostname: config.get("appHostname") ?? `app.${zone}`,
    tenantHostnamePattern: config.get("tenantHostnamePattern") ?? `*.${zone}`,
    hostedZoneId: config.get("hostedZoneId") ?? undefined,
    imageTag: config.get("imageTag") ?? "bootstrap",
    mailFromAddress: config.get("mailFromAddress") ?? `no-reply@${zone}`,
    reverbPublicKey: config.require("reverbPublicKey"),

    secrets: {
      appKey: config.requireSecret("appKey"),
      reverbAppId: config.requireSecret("reverbAppId"),
      reverbAppSecret: config.requireSecret("reverbAppSecret"),
      sentryDsn: config.requireSecret("sentryDsn"),
      cacheAuthToken: config.requireSecret("cacheAuthToken"),
    },
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
 * Deliberately still family-wide, for two reasons worth stating rather than
 * quietly "tightening" into an outage:
 *
 *  - The app selects models per environment and per agent today (config/ai.php
 *    → agents.*), so the set in use is not fixed at deploy time.
 *  - Foundation-model ARNs keep a wildcard **region** on purpose: a cross-region
 *    inference profile such as `eu.anthropic.*` routes to several regions and
 *    requires InvokeModel on the underlying model in each one. Pinning the region
 *    here would break it in a way that only shows up at runtime.
 *
 * What is tightened: the inference-profile ARN is scoped to this stack's region
 * instead of every region. The rest becomes a config knob — see PlatformConfig.
 */
export function defaultBedrockModelArns(region: string): string[] {
  return [
    "arn:aws:bedrock:*::foundation-model/anthropic.*",
    "arn:aws:bedrock:*::foundation-model/openai.*",
    "arn:aws:bedrock:*::foundation-model/amazon.*",
    `arn:aws:bedrock:${region}:*:inference-profile/*`,
  ];
}
