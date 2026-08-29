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
    // namePrefix, not name, and deliberately unlike every other resource here.
    //
    // `family` is immutable, so a major version bump replaces this group. With a
    // fixed name the replacement cannot be created while the old one still holds
    // the name, so Pulumi deletes first — and RDS refuses that while the instance
    // is still a member ("InvalidDBParameterGroupState"). The stack then wedges
    // with the group half-deleted and the engine still on the old major.
    //
    // A generated suffix lets the new group exist alongside the old one: create
    // it, point the instance at it, then delete the old. Do not "tidy" this back
    // to a fixed name — postgres18 -> 19 would deadlock the same way.
    namePrefix: `${environment.resourceName("db-params")}-`,
    family: "postgres18",
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
    engineVersion: "18",
    // Required for the 17 -> 18 modify: without it RDS refuses a major version
    // change and the apply fails rather than upgrading. AWS has no downgrade
    // path, so the pre-upgrade snapshot is the only way back — take one and read
    // the CloudWatch `upgrade` log export before assuming the apply went clean.
    // Safe to drop back to false once every stack is on 18.
    allowMajorVersionUpgrade: true,
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
    applyImmediately: settings.databaseApplyImmediately,

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
