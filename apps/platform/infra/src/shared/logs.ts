import * as aws from "@pulumi/aws";
import { PlatformConfig } from "../config.js";

/** Container roles that get their own log group. Mirrors the roles in docker/production/entrypoint.sh. */
export const SERVICE_LOG_ROLES = [
  "web",
  "worker",
  "reverb",
  "textract-consumer",
  "transcribe-consumer",
  "schedule",
  "migrate",
] as const;

export type ServiceLogRole = (typeof SERVICE_LOG_ROLES)[number];

export type ServiceLogGroups = Record<ServiceLogRole, aws.cloudwatch.LogGroup>;

/**
 * One log group per container role, not one for the whole cluster.
 *
 * They all run the same image, so a single group would interleave web requests,
 * queue output and per-minute scheduler runs into one stream — and the scheduler
 * alone writes 1,440 task starts a day. Separate groups mean "why did the worker
 * stop" is a filter, not an archaeology exercise, and retention can differ later
 * without moving anything.
 */
export function createServiceLogGroups(
  config: PlatformConfig,
  encryptionKey: aws.kms.Key,
): ServiceLogGroups {
  const { environment } = config;

  const groups = SERVICE_LOG_ROLES.map((role) => [
    role,
    new aws.cloudwatch.LogGroup(environment.resourceName(`${role}-logs`), {
      name: `/qompose/${environment.name}/${role}`,
      retentionInDays: environment.settings.logRetentionDays,
      // Needs the logs.<region> grant in the platform key policy — see encryption.ts.
      kmsKeyId: encryptionKey.arn,
    }),
  ] as const);

  return Object.fromEntries(groups) as ServiceLogGroups;
}
