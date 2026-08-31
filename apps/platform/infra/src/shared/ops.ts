import * as aws from "@pulumi/aws";
import type { PlatformConfig } from "../config.js";
import type { Database } from "./database.js";

export interface OperationsAlerting {
  /** Absent when no alarm email is configured, so there is nothing to notify. */
  topic?: aws.sns.Topic;
}

/**
 * One SNS topic for everything that needs a human.
 *
 * Deliberately one rather than per-subsystem. Someone on call needs a single
 * place to subscribe and a single place to look, and a topic per concern is how
 * you end up with an alarm nobody was subscribed to.
 *
 * SMS is separate from email and covers only the page-worthy alarms. A number
 * that receives every alarm by text stops being read, which is worse than not
 * having one.
 */
export function createOperationsAlerting(
  config: PlatformConfig,
  encryptionKey: aws.kms.Key,
): OperationsAlerting {
  const { environment, alarmEmailAddress, alarmSmsNumber } = config;

  if (alarmEmailAddress === undefined) {
    return {};
  }

  const topic = new aws.sns.Topic(environment.resourceName("alerts"), {
    name: environment.resourceName("alerts"),
    kmsMasterKeyId: encryptionKey.arn,
  });

  new aws.sns.TopicSubscription(environment.resourceName("alerts-email"), {
    topic: topic.arn,
    protocol: "email",
    endpoint: alarmEmailAddress,
  });

  if (alarmSmsNumber !== undefined) {
    new aws.sns.TopicSubscription(environment.resourceName("alerts-sms"), {
      topic: topic.arn,
      protocol: "sms",
      endpoint: alarmSmsNumber,
    });
  }

  /*
   * CloudWatch may publish to SNS by default; EventBridge, Budgets and RDS event
   * subscriptions may not. Without this they all match, fire, and silently fail
   * to deliver — the failure mode where the monitoring looks healthy precisely
   * because nothing is arriving.
   */
  new aws.sns.TopicPolicy(environment.resourceName("alerts-policy"), {
    arn: topic.arn,
    policy: aws.iam.getPolicyDocumentOutput({
      statements: [{
        sid: "AllowServicePublish",
        effect: "Allow",
        principals: [{
          type: "Service",
          identifiers: [
            "cloudwatch.amazonaws.com",
            "events.amazonaws.com",
            "budgets.amazonaws.com",
            "rds.amazonaws.com",
          ],
        }],
        actions: ["sns:Publish"],
        resources: [topic.arn],
        conditions: [{
          test: "StringEquals",
          variable: "aws:SourceAccount",
          values: [aws.getCallerIdentityOutput().accountId],
        }],
      }],
    }).json,
  });

  return { topic };
}

/**
 * A monthly cost budget with an actual and a forecast trigger.
 *
 * Both, because they answer different questions. The 80% *actual* alert says the
 * money is already spent. The 100% *forecast* alert fires early in a month whose
 * run rate has changed — while there is still time to do something about it.
 */
export function createBudget(config: PlatformConfig, topic?: aws.sns.Topic): void {
  const { environment, alarmEmailAddress } = config;

  if (alarmEmailAddress === undefined) {
    return;
  }

  const subscribers = {
    subscriberEmailAddresses: [alarmEmailAddress],
    subscriberSnsTopicArns: topic === undefined ? undefined : [topic.arn],
  };

  new aws.budgets.Budget(environment.resourceName("budget"), {
    name: environment.resourceName("budget"),
    budgetType: "COST",
    timeUnit: "MONTHLY",
    limitAmount: String(environment.settings.monthlyBudgetUsd),
    limitUnit: "USD",
    notifications: [{
      notificationType: "ACTUAL",
      comparisonOperator: "GREATER_THAN",
      threshold: 80,
      thresholdType: "PERCENTAGE",
      ...subscribers,
    }, {
      notificationType: "FORECASTED",
      comparisonOperator: "GREATER_THAN",
      threshold: 100,
      thresholdType: "PERCENTAGE",
      ...subscribers,
    }],
  });
}

/**
 * RDS-only alarms: failover events and free-storage exhaustion.
 *
 * ALB / ECS / cache alarms were removed with the compute plane (TASK-43.3).
 */
export function createDatabaseAlarms(
  config: PlatformConfig,
  database: Database,
  topic?: aws.sns.Topic,
): void {
  const { environment } = config;

  if (topic === undefined) {
    return;
  }

  const alarmActions = [topic.arn];

  /*
   * RDS failover has no metric — it is an event.
   *
   * A Multi-AZ failover is invisible in CPU or connection graphs, and the whole
   * point of paying for Multi-AZ is that the application survives it. That makes
   * it exactly the thing to be told about: it succeeded, and something caused it.
   */
  new aws.rds.EventSubscription(environment.resourceName("database-events"), {
    name: environment.resourceName("database-events"),
    snsTopic: topic.arn,
    sourceType: "db-instance",
    sourceIds: [database.instance.identifier],
    eventCategories: ["failover", "failure", "maintenance", "deletion", "low storage"],
  });

  new aws.cloudwatch.MetricAlarm(environment.resourceName("database-storage-alarm"), {
    name: environment.resourceName("database-storage-alarm"),
    alarmDescription: "RDS free storage is running out",
    namespace: "AWS/RDS",
    metricName: "FreeStorageSpace",
    dimensions: { DBInstanceIdentifier: database.instance.identifier },
    statistic: "Minimum",
    period: 300,
    evaluationPeriods: 1,
    // 2 GiB of a 20 GiB volume. Postgres stops accepting writes when it fills.
    threshold: 2 * 1024 * 1024 * 1024,
    comparisonOperator: "LessThanThreshold",
    treatMissingData: "breaching",
    alarmActions,
    okActions: alarmActions,
  });
}

/**
 * Cross-region replication of the database's automated backups.
 *
 * The cheap DR answer, and the reason it is not simply "we have backups": an
 * automated backup lives in the same region as the instance it came from, so a
 * regional failure takes the database and its recovery point together. RDS can
 * replicate those backups continuously to another region, which costs storage
 * and nothing else — no standby instance, no Aurora.
 *
 * **This is the one deliberate exception to eu-west-1 being the region for
 * everything.** A copy in the same region is a second copy of the same failure
 * domain, not disaster recovery. The default destination is eu-central-1 so the
 * data stays inside the EU; `crossRegionSnapshotCopy: false` in the environment
 * settings turns the whole thing off and keeps the stack single-region.
 *
 * The replication resource is created *in the destination region*, which is why
 * it needs its own provider.
 */
export function createDisasterRecovery(config: PlatformConfig, database: Database): void {
  const { environment, disasterRecoveryRegion } = config;

  if (!environment.settings.crossRegionSnapshotCopy) {
    return;
  }

  const destination = new aws.Provider(
    environment.resourceName(disasterRecoveryRegion),
    // The provider types the region as a union of known names, and this one comes
    // from config; an unknown value fails at apply time with a clear AWS error.
    { region: disasterRecoveryRegion as aws.Region },
  );

  new aws.rds.InstanceAutomatedBackupsReplication(
    environment.resourceName("database-backup-replica"),
    {
      sourceDbInstanceArn: database.instance.arn,
      retentionPeriod: environment.settings.databaseBackupRetentionDays,
      // KMS keys are regional, so the source key cannot encrypt the replica.
      // Omitted so the destination region's AWS-managed RDS key is used: an
      // unreadable backup is not a backup, and a customer managed key here would
      // need creating, granting and rotating in a region nothing else touches.
    },
    { provider: destination },
  );
}
