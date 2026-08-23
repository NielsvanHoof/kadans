import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "../config.js";
import { Network } from "./network.js";

export interface Database {
  instance: aws.rds.Instance;
  /** Static master password from Pulumi config secret `databasePassword`. */
  masterPassword: pulumi.Output<string>;
  databaseName: string;
  username: string;
}

const DATABASE_NAME = "qompose";
const MASTER_USERNAME = "qompose";

/**
 * Postgres 17 on RDS, sized to match the local Sail container.
 *
 * Always public for Laravel Cloud: password comes from Pulumi config secret
 * `databasePassword` (not Secrets Manager), because Cloud pastes env vars and
 * cannot resolve a Secrets Manager ARN at boot without extra AWS wiring. The
 * secret still lives in encrypted Pulumi config / state, not in git.
 *
 * Do not export the password as a stack output — read it from config when filling
 * Cloud env vars.
 */
export function createDatabase(
  config: PlatformConfig,
  network: Network,
  encryptionKey: aws.kms.Key,
): Database {
  const { environment } = config;
  const { settings } = environment;

  if (!settings.databasePubliclyAccessible) {
    throw new Error(
      "createDatabase requires databasePubliclyAccessible after TASK-43.3.",
    );
  }

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

  const pulumiConfig = new pulumi.Config();
  const masterPassword = pulumiConfig.requireSecret("databasePassword");

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
    // Omit manageMasterUserPassword entirely — AWS rejects it paired with password.
    password: masterPassword,

    dbSubnetGroupName: subnetGroup.name,
    parameterGroupName: parameterGroup.name,
    vpcSecurityGroupIds: [network.databaseSecurityGroup.id],
    publiclyAccessible: true,

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

  return {
    instance,
    masterPassword,
    databaseName: DATABASE_NAME,
    username: MASTER_USERNAME,
  };
}
