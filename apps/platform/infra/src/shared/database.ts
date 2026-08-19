import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "../config.js";
import { Network } from "./network.js";

export interface Database {
  instance: aws.rds.Instance;
  /**
   * ARN of the Secrets Manager secret RDS manages for the master user.
   *
   * JSON-shaped `{"username": …, "password": …}`, which is why the ECS task
   * definition reads `${arn}:password::` rather than the whole secret.
   */
  masterSecretArn: pulumi.Output<string>;
  databaseName: string;
  username: string;
}

const DATABASE_NAME = "qompose";
const MASTER_USERNAME = "qompose";

/**
 * Postgres 17 on RDS, sized to match the local Sail container.
 *
 * The master password uses `manageMasterUserPassword`, so RDS mints and rotates it
 * into its own Secrets Manager secret and Pulumi never sees the value. That is
 * worth more than it sounds: the usual alternatives either put a real password in
 * Pulumi state (recoverable by anyone who can read the stack) or add a random
 * provider whose output is *also* in state. Here there is nothing to leak, and
 * ECS resolves the secret at task start with the execution role.
 */
export function createDatabase(
  config: PlatformConfig,
  network: Network,
  encryptionKey: aws.kms.Key,
): Database {
  const { environment } = config;
  const { settings } = environment;

  const subnetGroup = new aws.rds.SubnetGroup(environment.resourceName("db-subnets"), {
    name: environment.resourceName("db-subnets"),
    subnetIds: network.publicSubnetIds,
    tags: { Name: environment.resourceName("db-subnets") },
  });

  const parameterGroup = new aws.rds.ParameterGroup(environment.resourceName("db-params"), {
    name: environment.resourceName("db-params"),
    family: "postgres17",
    parameters: [{
      // Refuse non-TLS connections outright rather than trusting every client to
      // ask for TLS. Laravel's pgsql driver negotiates TLS by default, so this
      // costs nothing and closes the case where something else does not.
      name: "rds.force_ssl",
      value: "1",
    }],
  });

  const instance = new aws.rds.Instance(environment.resourceName("database"), {
    identifier: environment.resourceName("database"),
    engine: "postgres",
    engineVersion: "17",
    instanceClass: settings.databaseInstanceClass,
    allocatedStorage: settings.databaseAllocatedStorageGb,
    // gp3 is the current general-purpose default and is cheaper than gp2 per IOPS.
    storageType: "gp3",
    storageEncrypted: true,
    kmsKeyId: encryptionKey.arn,

    dbName: DATABASE_NAME,
    username: MASTER_USERNAME,
    manageMasterUserPassword: true,
    masterUserSecretKmsKeyId: encryptionKey.keyId,

    dbSubnetGroupName: subnetGroup.name,
    parameterGroupName: parameterGroup.name,
    vpcSecurityGroupIds: [network.databaseSecurityGroup.id],
    // No public IP: the instance is only reachable from the task security group.
    publiclyAccessible: false,

    multiAz: settings.databaseMultiAz,
    backupRetentionPeriod: settings.databaseBackupRetentionDays,
    // Outside Dutch office hours, and before the maintenance window below.
    backupWindow: "02:00-03:00",
    maintenanceWindow: "Mon:03:15-Mon:04:15",
    autoMinorVersionUpgrade: true,

    deletionProtection: settings.deletionProtection,
    skipFinalSnapshot: !settings.deletionProtection,
    finalSnapshotIdentifier: settings.deletionProtection
      ? `${environment.resourceName("database")}-final`
      : undefined,
    copyTagsToSnapshot: true,

    enabledCloudwatchLogsExports: ["postgresql", "upgrade"],

    tags: { Name: environment.resourceName("database") },
  });

  // Present only because manageMasterUserPassword is on; index 0 is the current secret.
  const masterSecretArn = instance.masterUserSecrets.apply((secrets) => secrets[0].secretArn);

  return { instance, masterSecretArn, databaseName: DATABASE_NAME, username: MASTER_USERNAME };
}
