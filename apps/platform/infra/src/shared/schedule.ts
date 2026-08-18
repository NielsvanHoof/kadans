import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "../config.js";
import { Compute } from "./compute.js";
import { Network } from "./network.js";

export interface Schedule {
  rule: aws.cloudwatch.EventRule;
}

/**
 * `schedule:run` once a minute, via EventBridge → RunTask.
 *
 * Laravel's scheduler expects to be invoked every minute; the usual answer is an
 * always-on container running `schedule:work`. This runs a one-shot Fargate task
 * instead, which is what the task asks for and is defensible — but it is a trade,
 * not a free win, and the shape of it is worth stating:
 *
 *  - **Saved:** one 0.25 vCPU task running permanently, roughly €7/month.
 *  - **Paid:** ~43,000 task starts a month. Fargate bills per second with a
 *    one-minute minimum, so this lands in a similar place, and each run pays full
 *    application boot before `schedule:run` decides there is nothing due.
 *
 * The real win is operational rather than financial: there is no long-lived
 * process to wedge, and a failed run is a discrete, visible event rather than a
 * container that quietly stopped ticking.
 */
export function createSchedule(
  config: PlatformConfig,
  compute: Compute,
  network: Network,
  taskRole: aws.iam.Role,
  executionRole: aws.iam.Role,
): Schedule {
  const { environment } = config;

  const invocationRole = new aws.iam.Role(environment.resourceName("scheduler-role"), {
    name: environment.resourceName("scheduler"),
    path: "/qompose/",
    assumeRolePolicy: aws.iam.getPolicyDocumentOutput({
      statements: [{
        effect: "Allow",
        principals: [{ type: "Service", identifiers: ["events.amazonaws.com"] }],
        actions: ["sts:AssumeRole"],
      }],
    }).json,
    tags: { Purpose: "EventBridge RunTask for the Laravel scheduler" },
  });

  new aws.iam.RolePolicy(environment.resourceName("scheduler-policy"), {
    role: invocationRole.id,
    policy: aws.iam.getPolicyDocumentOutput({
      statements: [{
        sid: "RunScheduleTask",
        effect: "Allow",
        actions: ["ecs:RunTask"],
        // AWS matches RunTask against family:* or family:revision. Pulumi's
        // .arn already includes the revision, so appending :* produced
        // family:1:* which does not match family:1.
        resources: [
          pulumi.interpolate`${compute.scheduleTaskDefinition.arnWithoutRevision}:*`,
        ],
        conditions: [{
          test: "ArnEquals",
          variable: "ecs:cluster",
          values: [compute.cluster.arn],
        }],
      }, {
        // RunTask hands the task its roles, so the caller must be allowed to pass
        // them. Scoped to these two: without the condition this is a route to
        // starting a task as any role in the account.
        sid: "PassTaskRoles",
        effect: "Allow",
        actions: ["iam:PassRole"],
        resources: [taskRole.arn, executionRole.arn],
        conditions: [{
          test: "StringEquals",
          variable: "iam:PassedToService",
          values: ["ecs-tasks.amazonaws.com"],
        }],
      }],
    }).json,
  });

  const rule = new aws.cloudwatch.EventRule(environment.resourceName("schedule"), {
    name: environment.resourceName("schedule"),
    description: "Run php artisan schedule:run every minute",
    scheduleExpression: "rate(1 minute)",
  });

  new aws.cloudwatch.EventTarget(environment.resourceName("schedule-target"), {
    rule: rule.name,
    arn: compute.cluster.arn,
    roleArn: invocationRole.arn,
    ecsTarget: {
      taskDefinitionArn: compute.scheduleTaskDefinition.arn,
      launchType: "FARGATE",
      taskCount: 1,
      networkConfiguration: {
        subnets: network.publicSubnetIds,
        securityGroups: [network.taskSecurityGroup.id],
        assignPublicIp: true,
      },
    },
    // No retries: the next tick is 60 seconds away and will pick up anything due.
    // Retrying a missed minute is how two schedulers end up running at once.
    retryPolicy: { maximumRetryAttempts: 0 },
  });

  return { rule };
}
