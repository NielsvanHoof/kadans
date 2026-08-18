import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "../config.js";
import { Cache } from "./cache.js";
import { Database } from "./database.js";
import { DocumentInfrastructure } from "./documents.js";
import { LoadBalancer } from "./loadBalancer.js";
import { ServiceLogGroups, ServiceLogRole } from "./logs.js";
import { Network } from "./network.js";
import { PlatformSecretStore } from "./secrets.js";

export interface Compute {
  cluster: aws.ecs.Cluster;
  /** Task definitions with no service, started on demand with RunTask. */
  scheduleTaskDefinition: aws.ecs.TaskDefinition;
  migrateTaskDefinition: aws.ecs.TaskDefinition;
}

export interface ComputeDependencies {
  network: Network;
  edge: LoadBalancer;
  database: Database;
  cache: Cache;
  secrets: PlatformSecretStore;
  documents: DocumentInfrastructure;
  logGroups: ServiceLogGroups;
  taskRole: aws.iam.Role;
  executionRole: aws.iam.Role;
  repository: aws.ecr.Repository;
}

/**
 * ECS Fargate on ARM, running one image as several services.
 *
 * ARM64 is not a free 20% discount — it is a contract with the build. `docker
 * buildx bake` must produce `linux/arm64`, and an amd64 image scheduled here fails
 * with an exec-format error at task start, long after `pulumi up` has reported
 * success. `stack.production.test.ts` asserts the architecture for that reason.
 *
 * Every service runs the same artifact and differs only in the role passed as the
 * container command, which is exactly what `docker/production/entrypoint.sh` was
 * built to dispatch on.
 */
export function createCompute(
  config: PlatformConfig,
  dependencies: ComputeDependencies,
): Compute {
  const { environment } = config;
  const { settings } = environment;

  const cluster = new aws.ecs.Cluster(environment.resourceName("cluster"), {
    name: environment.resourceName("cluster"),
    settings: [{
      // On because the worker-stopped alarm reads RunningTaskCount, which AWS
      // only publishes under ECS/ContainerInsights. Without this the metric
      // never exists, treatMissingData is notBreaching, and a dead worker never
      // pages. The cost is real; the alternative is an alarm that cannot fire.
      name: "containerInsights",
      value: "enabled",
    }],
  });

  const image = pulumi.interpolate`${dependencies.repository.repositoryUrl}:${config.imageTag}`;
  const environmentVariables = taskEnvironment(config, dependencies);
  const secretReferences = taskSecrets(dependencies);

  const defineTask = (role: ServiceLogRole, essential = true): aws.ecs.TaskDefinition =>
    new aws.ecs.TaskDefinition(environment.resourceName(`${role}-task`), {
      family: environment.resourceName(role),
      requiresCompatibilities: ["FARGATE"],
      networkMode: "awsvpc",
      cpu: settings.taskCpu,
      memory: settings.taskMemory,
      runtimePlatform: {
        cpuArchitecture: "ARM64",
        operatingSystemFamily: "LINUX",
      },
      taskRoleArn: dependencies.taskRole.arn,
      executionRoleArn: dependencies.executionRole.arn,
      containerDefinitions: pulumi
        .all([image, environmentVariables, secretReferences, dependencies.logGroups[role].name])
        .apply(([imageUri, variables, secrets, logGroupName]) =>
          JSON.stringify([{
            name: role,
            image: imageUri,
            command: [role],
            essential,
            portMappings: role === "web" || role === "reverb"
              ? [{ containerPort: 8080, protocol: "tcp" }]
              : [],
            environment: variables,
            secrets,
            logConfiguration: {
              logDriver: "awslogs",
              options: {
                "awslogs-group": logGroupName,
                "awslogs-region": config.region,
                "awslogs-stream-prefix": role,
              },
            },
            // Reap a wedged Octane or queue worker instead of leaving a task that
            // passes ECS's process check while serving nothing.
            stopTimeout: 30,
          }])
        ),
    });

  const networkConfiguration = {
    subnets: dependencies.network.publicSubnetIds,
    securityGroups: [dependencies.network.taskSecurityGroup.id],
    // Mandatory without NAT: a task with no public IP in a public subnet has no
    // route to ECR and cannot pull its own image. See network.ts.
    assignPublicIp: true,
  };

  const defineService = (
    role: ServiceLogRole,
    desiredCount: number,
    loadBalancer?: { targetGroup: aws.lb.TargetGroup; dependsOn: pulumi.Resource },
  ): aws.ecs.Service =>
    new aws.ecs.Service(environment.resourceName(`${role}-service`), {
      name: environment.resourceName(role),
      cluster: cluster.arn,
      taskDefinition: defineTask(role).arn,
      desiredCount,
      launchType: "FARGATE",
      networkConfiguration,
      // Rolling deploy with a circuit breaker: a task that will not stabilise rolls
      // itself back rather than draining the healthy ones behind it.
      deploymentCircuitBreaker: { enable: true, rollback: true },
      deploymentMinimumHealthyPercent: desiredCount > 1 ? 100 : 0,
      deploymentMaximumPercent: 200,
      loadBalancers: loadBalancer === undefined ? [] : [{
        targetGroupArn: loadBalancer.targetGroup.arn,
        containerName: role,
        containerPort: 8080,
      }],
      // Give the app time to boot and warm caches before the first probe counts.
      healthCheckGracePeriodSeconds: loadBalancer === undefined ? undefined : 120,
      // A target group cannot be attached to a service before its listener exists,
      // and Pulumi cannot infer that ordering from the ARNs alone.
    }, loadBalancer === undefined ? {} : { dependsOn: [loadBalancer.dependsOn] });

  defineService("web", settings.webDesiredCount, {
    targetGroup: dependencies.edge.webTargetGroup,
    dependsOn: dependencies.edge.httpsListener,
  });
  defineService("reverb", settings.reverbDesiredCount, {
    targetGroup: dependencies.edge.reverbTargetGroup,
    dependsOn: dependencies.edge.httpsListener,
  });
  defineService("worker", settings.workerDesiredCount);
  defineService("textract-consumer", settings.consumerDesiredCount);
  defineService("transcribe-consumer", settings.consumerDesiredCount);

  return {
    cluster,
    scheduleTaskDefinition: defineTask("schedule"),
    // Migrations stay a one-shot role and are never run on web start: several web
    // tasks boot concurrently (.ai/rules/docker.md).
    migrateTaskDefinition: defineTask("migrate"),
  };
}

/**
 * Plain environment for the container.
 *
 * Most of this is not preference. `ProductionConfigurationValidator` **fails boot**
 * in production and staging on unsafe configuration (`.ai/rules/config.md`) — non-
 * HTTPS APP_URL, a non-Redis cache/queue/session store, Redis without TLS, a
 * filesystem that is not S3, the mock OCR or transcription drivers, an unset or
 * wildcard trusted-proxy list. A stack that gets these wrong deploys green and then
 * refuses every request, so they belong here as an explicit list rather than as
 * defaults someone assumes.
 *
 * AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY are conspicuously absent. Both
 * credential paths in the application skip explicit credentials when those are
 * empty, so the SDK falls through to the ECS container credential provider and
 * picks up the task role — which is the entire reason the task role exists.
 */
function taskEnvironment(
  config: PlatformConfig,
  dependencies: ComputeDependencies,
): pulumi.Output<Array<{ name: string; value: string }>> {
  const { environment, appHostname } = config;

  return pulumi
    .all([
      dependencies.database.instance.address,
      dependencies.cache.replicationGroup.primaryEndpointAddress,
      dependencies.documents.bucket.bucket,
      dependencies.documents.resultsQueue.url,
      dependencies.documents.transcribeResultsQueue.url,
      dependencies.documents.textractTopic.arn,
      dependencies.documents.textractPublishRole.arn,
    ])
    .apply((
      [
        databaseHost,
        cacheHost,
        bucket,
        textractQueueUrl,
        transcribeQueueUrl,
        textractTopicArn,
        textractPublishRoleArn,
      ],
    ) =>
      Object.entries({
        APP_NAME: "Qompose",
        APP_ENV: environment.name,
        APP_DEBUG: "false",
        APP_URL: `https://${appHostname}`,
        LOG_CHANNEL: "stack",
        // A file-only log stack is rejected by the validator, and rightly: a
        // container's local log is gone the moment the task is replaced.
        LOG_STACK: "stderr,sentry_logs",
        LOG_LEVEL: "info",

        DB_CONNECTION: "pgsql",
        DB_HOST: databaseHost,
        DB_PORT: "5432",
        DB_DATABASE: dependencies.database.databaseName,
        DB_USERNAME: dependencies.database.username,

        REDIS_CLIENT: "phpredis",
        REDIS_HOST: cacheHost,
        REDIS_PORT: "6379",
        // Valkey is provisioned with transit encryption; without this the client
        // connects in plaintext, is refused, and the validator would reject it anyway.
        REDIS_SCHEME: "tls",
        CACHE_STORE: "redis",
        QUEUE_CONNECTION: "redis",
        REDIS_QUEUE_RETRY_AFTER: "660",
        SESSION_DRIVER: "redis",
        SESSION_ENCRYPT: "true",
        SESSION_SECURE_COOKIE: "true",
        SESSION_DOMAIN: appHostname,

        BROADCAST_CONNECTION: "reverb",
        // Public by construction — it is already in the JS bundle. See config.ts.
        REVERB_APP_KEY: config.reverbPublicKey,
        REVERB_HOST: appHostname,
        REVERB_PORT: "443",
        REVERB_SCHEME: "https",
        REVERB_SERVER_HOST: "0.0.0.0",
        REVERB_SERVER_PORT: "8080",

        FILESYSTEM_DISK: "s3",
        AWS_DEFAULT_REGION: config.region,
        AWS_BUCKET: bucket,

        // GuardDuty tags every object in this bucket; the gate reads that tag.
        // ProductionConfigurationValidator fails boot if this is not true here.
        SECURITY_MALWARE_SCANNING_ENABLED: "true",

        OCR_DRIVER: "textract",
        TRANSCRIPTION_DRIVER: "transcribe",
        TEXTRACT_SNS_TOPIC_ARN: textractTopicArn,
        TEXTRACT_PUBLISH_ROLE_ARN: textractPublishRoleArn,
        TEXTRACT_RESULTS_QUEUE_URL: textractQueueUrl,
        TRANSCRIBE_RESULTS_QUEUE_URL: transcribeQueueUrl,

        MAIL_MAILER: "ses",
        MAIL_FROM_ADDRESS: config.mailFromAddress,
        MAIL_FROM_NAME: "Qompose",

        /*
         * The browser Sentry DSN, needed server-side for two things the bundle
         * cannot do: allow the ingest origin in the Content-Security-Policy, and
         * satisfy ProductionConfigurationValidator, which fails boot when
         * `services.sentry.browser_dsn` is empty. The bundle carries its own
         * inlined copy from build time — see docker-bake.hcl.
         */
        VITE_SENTRY_DSN: config.sentryBrowserDsn,
        VITE_SENTRY_ENVIRONMENT: environment.name,
        // Ties an issue to the exact build that produced it.
        SENTRY_RELEASE: config.imageTag,
        VITE_SENTRY_RELEASE: config.imageTag,

        SCOUT_DRIVER: "database",
        OCTANE_SERVER: "frankenphp",

        // The validator rejects an unset list and rejects REMOTE_ADDR / "*". The
        // VPC CIDR is the honest answer: the ALB's addresses live inside it and
        // nothing else can reach the task port.
        TRUSTED_PROXIES: dependencies.network.cidrBlock,
      }).map(([name, value]) => ({ name, value }))
    );
}

/** Secrets ECS resolves at task start; values never appear in the task definition. */
function taskSecrets(
  dependencies: ComputeDependencies,
): pulumi.Output<Array<{ name: string; valueFrom: string }>> {
  return pulumi
    .all([dependencies.secrets.secret.arn, dependencies.database.masterSecretArn])
    .apply(([appSecretArn, masterSecretArn]) => [
      ...dependencies.secrets.keys.map((key) => ({
        // `arn:JSON_KEY::` pulls one key out of the JSON secret — the reason
        // secrets.ts stores one secret rather than one per value. See secrets.ts.
        name: key,
        valueFrom: `${appSecretArn}:${key}::`,
      })),
      { name: "DB_PASSWORD", valueFrom: `${masterSecretArn}:password::` },
    ]);
}
