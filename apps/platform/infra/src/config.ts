import * as pulumi from "@pulumi/pulumi";
import {
  DeploymentEnvironment,
  deploymentEnvironmentFromStack,
} from "./environment.js";

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
}

export function loadPlatformConfig(): PlatformConfig {
  const config = new pulumi.Config();
  const environment = deploymentEnvironmentFromStack(pulumi.getStack());
  const region = new pulumi.Config("aws").get("region") ?? "eu-west-1";

  return {
    environment,
    region,
    alarmEmailAddress: config.get("alarmEmailAddress") ?? undefined,
    bedrockModelArns: config.getObject<string[]>("bedrockModelArns")
      ?? defaultBedrockModelArns(region),
  };
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
