import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "../config.js";

export interface PlatformSecretStore {
  secret: aws.secretsmanager.Secret;
  /** JSON keys inside the secret, in the form ECS `valueFrom` expects. */
  keys: readonly string[];
}

/**
 * Application secrets, as one JSON secret rather than one secret per value.
 *
 * ECS can pull an individual JSON key out of a secret — `arn:APP_KEY::` — so a
 * single secret costs one €0.35/month slot instead of one per value, and the
 * execution role needs exactly one `secretsmanager:GetSecretValue` grant instead
 * of several that someone will forget to extend. The container still receives
 * them as separate environment variables; the grouping is invisible to the
 * application.
 *
 * The database password is deliberately *not* in here. RDS manages its own secret
 * and rotates it (`database.ts`), and copying the value into this one would create
 * a second copy that silently goes stale at the first rotation.
 *
 * `REDIS_PASSWORD` is the ElastiCache AUTH token under the name Laravel reads
 * (`config/database.php`). The same value is passed to the replication group as
 * `authToken`; without this key the group requires AUTH and the container never
 * sends it.
 *
 * Neither is `REVERB_APP_KEY`: it ships inside the JavaScript bundle, so it is a
 * plain environment variable. See `PlatformConfig.reverbPublicKey`.
 */
export function createPlatformSecrets(
  config: PlatformConfig,
  encryptionKey: aws.kms.Key,
): PlatformSecretStore {
  const { environment, secrets } = config;

  const secret = new aws.secretsmanager.Secret(environment.resourceName("app-secrets"), {
    name: environment.resourceName("app-secrets"),
    description: `Qompose ${environment.name} application secrets consumed by ECS`,
    kmsKeyId: encryptionKey.arn,
    // Staging must be destroyable; production keeps the 30-day recovery window.
    recoveryWindowInDays: environment.settings.deletionProtection ? 30 : 0,
  });

  new aws.secretsmanager.SecretVersion(environment.resourceName("app-secrets-version"), {
    secretId: secret.id,
    secretString: pulumi.all([
      secrets.appKey,
      secrets.reverbAppId,
      secrets.reverbAppSecret,
      secrets.sentryDsn,
      secrets.cacheAuthToken,
    ]).apply(([appKey, reverbAppId, reverbAppSecret, sentryDsn, cacheAuthToken]) =>
      JSON.stringify({
        APP_KEY: appKey,
        REVERB_APP_ID: reverbAppId,
        REVERB_APP_SECRET: reverbAppSecret,
        SENTRY_LARAVEL_DSN: sentryDsn,
        REDIS_PASSWORD: cacheAuthToken,
      })
    ),
  });

  return {
    secret,
    keys: [
      "APP_KEY",
      "REVERB_APP_ID",
      "REVERB_APP_SECRET",
      "SENTRY_LARAVEL_DSN",
      "REDIS_PASSWORD",
    ],
  };
}
