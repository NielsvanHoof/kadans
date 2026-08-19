import * as aws from "@pulumi/aws";
import { PlatformConfig } from "../config.js";
import { Network } from "./network.js";

export interface Cache {
  replicationGroup: aws.elasticache.ReplicationGroup;
}

/**
 * Valkey for sessions, cache, and the queue — all three, on one group.
 *
 * Valkey rather than Redis: it is the fork AWS backs, it is materially cheaper on
 * ElastiCache, and it is wire-compatible, so `phpredis` and Laravel neither know
 * nor care.
 *
 * Transit encryption is not optional here, and not only for the obvious reason:
 * `ProductionConfigurationValidator` fails application boot when Redis is
 * configured without TLS (`.ai/rules/config.md`). An unencrypted group would
 * deploy cleanly and then refuse to serve a single request.
 *
 * Failover settings are derived from `cacheNodeCount` rather than exposed as their
 * own knobs: ElastiCache rejects `automaticFailoverEnabled` and `multiAzEnabled`
 * on a single-node group, which is exactly the staging shape, so two independent
 * knobs could only ever be set to an invalid pair.
 */
export function createCache(
  config: PlatformConfig,
  network: Network,
  encryptionKey: aws.kms.Key,
): Cache {
  const { environment } = config;
  const { settings } = environment;
  const replicated = settings.cacheNodeCount > 1;

  const subnetGroup = new aws.elasticache.SubnetGroup(
    environment.resourceName("cache-subnets"),
    {
      name: environment.resourceName("cache-subnets"),
      subnetIds: network.publicSubnetIds,
    },
  );

  const replicationGroup = new aws.elasticache.ReplicationGroup(
    environment.resourceName("cache"),
    {
      replicationGroupId: environment.resourceName("cache"),
      description: `Qompose ${environment.name} sessions, cache, and queue`,
      engine: "valkey",
      engineVersion: "8.0",
      nodeType: settings.cacheNodeType,
      numCacheClusters: settings.cacheNodeCount,
      parameterGroupName: "default.valkey8",
      port: 6379,

      subnetGroupName: subnetGroup.name,
      securityGroupIds: [network.cacheSecurityGroup.id],

      atRestEncryptionEnabled: true,
      kmsKeyId: encryptionKey.arn,
      // Required by ProductionConfigurationValidator; REDIS_SCHEME=tls in compute.ts.
      transitEncryptionEnabled: true,
      authToken: config.secrets.cacheAuthToken,

      automaticFailoverEnabled: replicated,
      multiAzEnabled: replicated,

      snapshotRetentionLimit: replicated ? 5 : 1,
      snapshotWindow: "01:00-02:00",
      maintenanceWindow: "mon:04:30-mon:05:30",
      applyImmediately: !settings.deletionProtection,

      tags: { Name: environment.resourceName("cache") },
    },
  );

  return { replicationGroup };
}
