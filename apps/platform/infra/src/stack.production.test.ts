import assert from "node:assert/strict";
import test, { before } from "node:test";
import { CapturedStack, containerDefinitions, runStack } from "./testing/harness.js";

/*
 * This file replaces the pre-compute stack test, which asserted that no VPC, ECS
 * cluster, service or RDS instance existed — an executable form of decision-2
 * ("Accept no application compute in Pulumi for v1.0"). TASK-34.2 retires that
 * decision, so the guard rail is inverted rather than deleted.
 *
 * One stack per file: pulumi.runtime.setMocks is process-global and index.js is
 * module-cached, so staging lives in stack.staging.test.ts. See testing/harness.ts.
 */

// Definite assignment: `before` runs ahead of every test in the file, but the
// compiler cannot see that. Top-level await is unavailable — tsc emits CommonJS.
let stack!: CapturedStack;

before(async () => {
  stack = await runStack("production");
});

test("the data plane still provisions documents, OCR and Transcribe plumbing", () => {
  assert.equal(stack.hasType("aws:s3/bucketV2:BucketV2"), true);
  assert.equal(stack.hasType("aws:sns/topic:Topic"), true);
  // Textract results + DLQ, Transcribe results + DLQ, EventBridge delivery DLQ.
  assert.equal(stack.ofType("aws:sqs/queue:Queue").length, 5);
  assert.ok(stack.named("textract-publish-role"));
  assert.ok(stack.named("transcribe-complete"));
});

test("compute, edge and data stores are provisioned", () => {
  assert.equal(stack.hasType("aws:ec2/vpc:Vpc"), true);
  assert.equal(stack.hasType("aws:ecs/cluster:Cluster"), true);
  assert.equal(stack.hasType("aws:rds/instance:Instance"), true);
  assert.equal(stack.hasType("aws:elasticache/replicationGroup:ReplicationGroup"), true);
  assert.equal(stack.hasType("aws:lb/loadBalancer:LoadBalancer"), true);
  assert.equal(stack.hasType("aws:ecr/repository:Repository"), true);
  assert.equal(stack.hasType("aws:secretsmanager/secret:Secret"), true);
});

test("IAM role tag values use IAM's restricted character set", () => {
  const allowed = /^[\p{L}\p{Z}\p{N}_.:/=+\-@]*$/u;

  for (const role of stack.ofType("aws:iam/role:Role")) {
    const tags = (role.inputs.tags ?? {}) as Record<string, string>;

    for (const [key, value] of Object.entries(tags)) {
      assert.match(value, allowed, `${role.name} tag ${key} is invalid for IAM`);
    }
  }
});

// One artifact, five long-running roles, dispatched by docker/production/entrypoint.sh.
test("every entrypoint role that needs a service has one", () => {
  const services = stack.ofType("aws:ecs/service:Service").map((service) => service.inputs.name);

  assert.deepEqual(services.sort(), [
    "qompose-production-reverb",
    "qompose-production-textract-consumer",
    "qompose-production-transcribe-consumer",
    "qompose-production-web",
    "qompose-production-worker",
  ]);
});

// migrate is one-shot on purpose: several web tasks boot concurrently, so running
// migrations on web start would race them (.ai/rules/docker.md).
test("migrate and schedule are task definitions without services", () => {
  const families = stack.ofType("aws:ecs/taskDefinition:TaskDefinition")
    .map((task) => task.inputs.family);

  assert.ok(families.includes("qompose-production-migrate"));
  assert.ok(families.includes("qompose-production-schedule"));

  const serviceNames = stack.ofType("aws:ecs/service:Service").map((service) => service.inputs.name);

  assert.equal(serviceNames.includes("qompose-production-migrate"), false);
  assert.equal(serviceNames.includes("qompose-production-schedule"), false);
});

/*
 * ARM64 is a contract with the build, not a preference: CI must bake linux/arm64.
 * An amd64 image scheduled here fails with an exec-format error at first task
 * start — long after `pulumi up` reports success.
 */
test("task definitions are ARM64", () => {
  const tasks = stack.ofType("aws:ecs/taskDefinition:TaskDefinition");

  assert.ok(tasks.length > 0);

  for (const task of tasks) {
    assert.deepEqual(task.inputs.runtimePlatform, {
      cpuArchitecture: "ARM64",
      operatingSystemFamily: "LINUX",
    });
  }
});

/*
 * The 60-second default silently kills Reverb: the socket opens, then drops every
 * minute. A WebSocket is idle by definition between messages.
 */
test("the load balancer keeps WebSocket connections alive", () => {
  const alb = stack.named("alb");

  assert.ok(alb);
  assert.equal(alb.inputs.idleTimeout, 3600);
  assert.equal(alb.inputs.enableDeletionProtection, true);
});

test("HTTP is redirected to HTTPS and Reverb has its own listener rule", () => {
  const listeners = stack.ofType("aws:lb/listener:Listener");
  const http = listeners.find((listener) => listener.inputs.port === 80);
  const https = listeners.find((listener) => listener.inputs.port === 443);

  assert.ok(http);
  assert.equal((http.inputs.defaultActions as Array<Record<string, unknown>>)[0].type, "redirect");
  assert.ok(https);

  const rule = stack.named("reverb-rule");

  assert.ok(rule, "expected a listener rule forwarding /app/* to Reverb");
  assert.deepEqual(
    (rule.inputs.conditions as Array<Record<string, any>>)[0].pathPattern.values,
    ["/app/*"],
  );
});

test("the readiness endpoint is what the web target group probes", () => {
  const targetGroup = stack.named("web-tg");

  assert.ok(targetGroup);
  assert.equal((targetGroup.inputs.healthCheck as Record<string, unknown>).path, "/ready");
});

/*
 * Tasks carry public IPs (no NAT — see network.ts), so an 8080 ingress written as a
 * CIDR would be the difference between "the load balancer" and "the internet".
 */
test("task ingress references the load balancer group, never a CIDR", () => {
  const taskSecurityGroup = stack.named("task-sg");

  assert.ok(taskSecurityGroup);

  const ingress = (taskSecurityGroup.inputs.ingress as Array<Record<string, any>>)[0];

  assert.equal(ingress.fromPort, 8080);
  assert.ok(Array.isArray(ingress.securityGroups) && ingress.securityGroups.length === 1);
  assert.equal(ingress.cidrBlocks, undefined);
});

test("the database and cache accept connections only from tasks", () => {
  for (const [name, port] of [["database-sg", 5432], ["cache-sg", 6379]] as const) {
    const group = stack.named(name);

    assert.ok(group, `expected security group ${name}`);

    const ingress = (group.inputs.ingress as Array<Record<string, any>>)[0];

    assert.equal(ingress.fromPort, port);
    assert.ok(Array.isArray(ingress.securityGroups) && ingress.securityGroups.length === 1);
    assert.equal(ingress.cidrBlocks, undefined);
  }
});

test("the certificate covers the app hostname and the tenant wildcard", () => {
  const certificate = stack.named("certificate");

  assert.ok(certificate);
  assert.equal(certificate.inputs.domainName, "app.qompose.nl");
  assert.deepEqual(certificate.inputs.subjectAlternativeNames, ["*.qompose.nl"]);
});

// The marketing site keeps www and the apex. A wildcard only answers names with no
// explicit record, so the carve-out is "never write those names" — see dns.ts.
test("no DNS record is created for www or the apex", () => {
  const records = stack.ofType("aws:route53/record:Record").map((record) => record.inputs.name);

  assert.equal(records.includes("www.qompose.nl"), false);
  assert.equal(records.includes("qompose.nl"), false);
});

test("production is Multi-AZ, protected, and refuses plaintext Postgres", () => {
  const database = stack.named("database");

  assert.ok(database);
  assert.equal(database.inputs.multiAz, true);
  assert.equal(database.inputs.deletionProtection, true);
  assert.equal(database.inputs.storageEncrypted, true);
  assert.equal(database.inputs.publiclyAccessible, false);
  // No password in Pulumi state: RDS mints and rotates it into its own secret.
  assert.equal(database.inputs.manageMasterUserPassword, true);
  assert.equal(database.inputs.password, undefined);

  const parameters = stack.named("db-params");

  assert.ok(parameters);
  assert.deepEqual(parameters.inputs.parameters, [{ name: "rds.force_ssl", value: "1" }]);
});

/*
 * ProductionConfigurationValidator fails application boot without cache TLS, so an
 * unencrypted group would deploy green and then refuse every request.
 */
test("the cache is encrypted in transit and fails over on two nodes", () => {
  const cache = stack.named("cache");

  assert.ok(cache);
  assert.equal(cache.inputs.engine, "valkey");
  assert.equal(cache.inputs.transitEncryptionEnabled, true);
  assert.equal(cache.inputs.atRestEncryptionEnabled, true);
  assert.equal(cache.inputs.numCacheClusters, 2);
  assert.equal(cache.inputs.automaticFailoverEnabled, true);
  assert.equal(cache.inputs.multiAzEnabled, true);
});

test("the scheduler runs every minute via RunTask, with no retries", () => {
  const rule = stack.named("schedule");

  assert.ok(rule);
  assert.equal(rule.inputs.scheduleExpression, "rate(1 minute)");

  const target = stack.named("schedule-target");

  assert.ok(target);
  assert.ok((target.inputs.ecsTarget as Record<string, unknown>).taskDefinitionArn);
  // Retrying a missed minute is how two schedulers end up running at once.
  assert.deepEqual(target.inputs.retryPolicy, { maximumRetryAttempts: 0 });
});

/*
 * AWS matches ecs:RunTask against family:* or family:revision. Appending :* to
 * Pulumi's revisioned .arn produced family:1:*, which does not match family:1
 * and EventBridge would get AccessDenied every minute.
 */
test("the scheduler may run any revision of the schedule task definition", () => {
  const document = stack.policyDocuments.find((policyDocument) =>
    JSON.stringify(policyDocument).includes("RunScheduleTask")
  );

  assert.ok(document);

  const statements = document.statements as Array<Record<string, any>>;
  const runTask = statements.find((statement) => statement.sid === "RunScheduleTask");

  assert.ok(runTask);
  assert.deepEqual(runTask.resources, [
    `arn:aws:ecs:eu-west-1:123456789012:task-definition/qompose-production-schedule:*`,
  ]);
});

/*
 * The task role only works if the SDK is allowed to fall through to the container
 * credential provider, which it does only when these are absent.
 */
test("no container receives static AWS credentials", () => {
  const tasks = stack.ofType("aws:ecs/taskDefinition:TaskDefinition");

  for (const task of tasks) {
    const names = containerDefinitions(task)[0].environment.map(
      (variable: { name: string }) => variable.name,
    );

    assert.equal(names.includes("AWS_ACCESS_KEY_ID"), false);
    assert.equal(names.includes("AWS_SECRET_ACCESS_KEY"), false);
  }
});

/*
 * ProductionConfigurationValidator fails boot in production and staging on any of
 * these (.ai/rules/config.md). Getting one wrong deploys cleanly and then serves
 * nothing, so they are asserted rather than assumed.
 */
test("task environment satisfies the production configuration validator", () => {
  const web = stack.ofType("aws:ecs/taskDefinition:TaskDefinition")
    .find((task) => task.inputs.family === "qompose-production-web");

  assert.ok(web);

  const container = containerDefinitions(web)[0];
  const variables = Object.fromEntries(
    container.environment.map((variable: { name: string; value: string }) =>
      [variable.name, variable.value]
    ),
  );

  assert.equal(variables.APP_ENV, "production");
  assert.equal(variables.APP_DEBUG, "false");
  assert.equal(variables.APP_URL, "https://app.qompose.nl");
  assert.equal(variables.CACHE_STORE, "redis");
  assert.equal(variables.QUEUE_CONNECTION, "redis");
  assert.equal(variables.SESSION_DRIVER, "redis");
  assert.equal(variables.SESSION_ENCRYPT, "true");
  assert.equal(variables.SESSION_SECURE_COOKIE, "true");
  assert.equal(variables.REDIS_SCHEME, "tls");
  assert.equal(variables.FILESYSTEM_DISK, "s3");
  assert.equal(variables.BROADCAST_CONNECTION, "reverb");
  assert.equal(variables.OCR_DRIVER, "textract");
  assert.equal(variables.TRANSCRIPTION_DRIVER, "transcribe");
  assert.equal(variables.MAIL_MAILER, "ses");
  // Rejected when unset, REMOTE_ADDR, or "*".
  assert.equal(variables.TRUSTED_PROXIES, "10.0.0.0/16");
  assert.equal(variables.LOG_STACK.includes("single"), false);
  assert.equal(variables.LOG_STACK.includes("daily"), false);

  /*
   * The browser Sentry DSN, which this stack originally omitted entirely — the
   * container would have deployed green and then failed boot on
   * `services.sentry.browser_dsn`, because config/services.php reads it from this
   * variable. It is also what ContentSecurityPolicy derives the allowed ingest
   * origin from, so an empty one silences the browser SDK twice over.
   *
   * Distinct from SENTRY_LARAVEL_DSN: separate Sentry projects, separate DSNs.
   */
  assert.ok(variables.VITE_SENTRY_DSN, "the browser DSN must reach the container");
  assert.equal(variables.VITE_SENTRY_ENVIRONMENT, "production");
});

test("secrets are resolved by ECS, never written into the task definition", () => {
  const web = stack.ofType("aws:ecs/taskDefinition:TaskDefinition")
    .find((task) => task.inputs.family === "qompose-production-web");

  assert.ok(web);

  const container = containerDefinitions(web)[0];
  const secretNames = container.secrets.map((secret: { name: string }) => secret.name);

  assert.deepEqual(secretNames.sort(), [
    "APP_KEY",
    "DB_PASSWORD",
    "REDIS_PASSWORD",
    "REVERB_APP_ID",
    "REVERB_APP_SECRET",
    "SENTRY_LARAVEL_DSN",
  ]);

  // REVERB_APP_KEY is deliberately NOT here: it is compiled into the JavaScript
  // bundle and served to every visitor, so treating it as a secret would mean
  // decrypting one to produce a public asset. See config.ts.
  const variableNamesIncludingReverb = container.environment.map(
    (variable: { name: string }) => variable.name,
  );

  assert.equal(variableNamesIncludingReverb.includes("REVERB_APP_KEY"), true);

  // The JSON-key form is the whole reason one secret holds these values.
  const appKey = container.secrets.find((secret: { name: string }) => secret.name === "APP_KEY");

  assert.ok(appKey.valueFrom.endsWith(":APP_KEY::"));

  const redisPassword = container.secrets.find(
    (secret: { name: string }) => secret.name === "REDIS_PASSWORD",
  );

  assert.ok(redisPassword.valueFrom.endsWith(":REDIS_PASSWORD::"));

  const variableNames = container.environment.map((variable: { name: string }) => variable.name);

  assert.equal(variableNames.includes("APP_KEY"), false);
  assert.equal(variableNames.includes("REDIS_PASSWORD"), false);
});

test("the app secret JSON includes the Valkey AUTH token as REDIS_PASSWORD", () => {
  const version = stack.named("app-secrets-version");

  assert.ok(version);

  const parsed = JSON.parse(version.inputs.secretString as string);

  assert.equal(typeof parsed.REDIS_PASSWORD, "string");
  assert.equal(parsed.REDIS_PASSWORD.length > 0, true);
});

/*
 * SES deliberately does NOT join the managed policy shared with the local Sail IAM
 * user. Parity exists so production is never less capable than local; it is not a
 * reason to put company-domain mail credentials on a laptop. See mail.ts.
 */
test("SES send is attached to the task role but stays out of the shared policy", () => {
  assert.ok(stack.named("mail-send"), "expected a dedicated SES send policy");
  assert.ok(stack.named("app-task-extra-0"), "expected SES attached to the task role");

  const shared = stack.policyDocuments.find((document) =>
    JSON.stringify(document).includes("ListDocumentsBucket")
  );

  assert.ok(shared);
  assert.equal(JSON.stringify(shared).includes("ses:SendEmail"), false);
});

test("the execution role is scoped to the two secrets it resolves", () => {
  assert.ok(stack.named("app-execution-role"));

  const document = stack.policyDocuments.find((policyDocument) =>
    JSON.stringify(policyDocument).includes("ResolveTaskSecrets")
  );

  assert.ok(document);

  const statements = document.statements as Array<Record<string, any>>;
  const resolve = statements.find((statement) => statement.sid === "ResolveTaskSecrets");

  assert.ok(resolve);
  assert.equal(resolve.resources.length, 2);
  assert.equal(resolve.resources.includes("*"), false);
});

/*
 * Tagging is DISABLED by default in the GuardDuty API, and that default is the
 * one failure that looks like success: scanning still runs and findings still
 * fire, but no object is ever tagged — so the application gate reads nothing,
 * fails closed, and refuses every download in production.
 */
test("malware scanning writes its verdict back onto the object", () => {
  const plan = stack.named("malware-protection");

  assert.ok(plan);
  assert.deepEqual(plan.inputs.actions, [{ taggings: [{ status: "ENABLED" }] }]);
  assert.ok(
    (plan.inputs.protectedResource as Record<string, any>).s3Bucket.bucketName,
    "the plan must be attached to the documents bucket",
  );
});

/*
 * The bucket is SSE-KMS with a customer managed key. Without a decrypt grant the
 * scanner tags every object ACCESS_DENIED, the gate correctly refuses all of them,
 * and nothing in the resulting outage points at the key policy.
 */
test("the scanner can decrypt the documents it is asked to scan", () => {
  const document = stack.policyDocuments.find((policyDocument) =>
    JSON.stringify(policyDocument).includes("InspectDocumentsBucket")
  );

  assert.ok(document);

  const statements = document.statements as Array<Record<string, any>>;
  const sids = statements.map((statement) => statement.sid);

  assert.ok(sids.includes("TagScannedObjects"));
  assert.ok(sids.includes("DecryptDocuments"));
  assert.ok(sids.includes("ManageGuardDutyEventRule"));
  assert.ok(sids.includes("MonitorGuardDutyEventRule"));
  assert.ok(sids.includes("AllowPutValidationObject"));

  const inspect = statements.find((statement) => statement.sid === "InspectDocumentsBucket");

  assert.ok(inspect);
  assert.ok(inspect.actions.includes("s3:GetBucketNotification"));
  assert.ok(inspect.actions.includes("s3:PutBucketNotification"));

  const tagging = statements.find((statement) => statement.sid === "TagScannedObjects");

  assert.ok(tagging);
  assert.ok(tagging.actions.includes("s3:PutObjectVersionTagging"));
  assert.ok(tagging.actions.includes("s3:GetObjectVersionTagging"));

  const manageRule = statements.find((statement) => statement.sid === "ManageGuardDutyEventRule");

  assert.ok(manageRule);
  assert.ok(manageRule.actions.includes("events:PutRule"));
  assert.ok(manageRule.actions.includes("events:DeleteRule"));
  assert.ok(manageRule.actions.includes("events:PutTargets"));
  assert.ok(manageRule.actions.includes("events:RemoveTargets"));
  assert.ok(
    JSON.stringify(manageRule).includes("DO-NOT-DELETE-AmazonGuardDutyMalwareProtectionS3"),
  );
  assert.ok(JSON.stringify(manageRule).includes("events:ManagedBy"));

  const monitorRule = statements.find((statement) => statement.sid === "MonitorGuardDutyEventRule");

  assert.ok(monitorRule);
  assert.ok(monitorRule.actions.includes("events:DescribeRule"));
  assert.ok(monitorRule.actions.includes("events:ListTargetsByRule"));
});

// The app reads the verdict; it must never be able to rewrite it.
test("the application may read scan tags but not write them", () => {
  const shared = stack.policyDocuments.find((policyDocument) =>
    JSON.stringify(policyDocument).includes("ListDocumentsBucket")
  );

  assert.ok(shared);

  const statements = shared.statements as Array<Record<string, any>>;
  const tagging = statements.find((statement) => statement.sid === "ReadDocumentScanStatus");

  assert.ok(tagging, "expected s3:GetObjectTagging for the download gate");
  assert.deepEqual(tagging.actions, ["s3:GetObjectTagging"]);
  assert.equal(JSON.stringify(shared).includes("s3:PutObjectTagging"), false);
});

test("malware findings are routed somewhere a human will see them", () => {
  const rule = stack.named("malware-findings");

  assert.ok(rule);

  const pattern = JSON.parse(rule.inputs.eventPattern as string);

  assert.deepEqual(pattern.source, ["aws.guardduty"]);
  assert.deepEqual(pattern.detail.scanResultDetails.scanResultStatus, ["THREATS_FOUND"]);
  assert.ok(stack.named("malware-findings-target"));

  // EventBridge cannot publish to SNS without an explicit grant; the rule would
  // match, fire, and silently fail to deliver.
  const topicPolicy = stack.policyDocuments.find((policyDocument) =>
    JSON.stringify(policyDocument).includes("AllowServicePublish")
  );

  assert.ok(topicPolicy, "expected an SNS topic policy allowing events.amazonaws.com");
  assert.ok(JSON.stringify(topicPolicy).includes("events.amazonaws.com"));
});

/*
 * The alerts topic is encrypted with the OCR CMK. An SNS topic policy that allows
 * RDS and Budgets to Publish is not enough: they also need kms:Decrypt /
 * kms:GenerateDataKey* on the key, or they match, fire, and silently fail.
 */
test("the alerts encryption key lets RDS and Budgets publish", () => {
  const key = stack.named("ocr-key");

  assert.ok(key);

  const policy = JSON.parse(key.inputs.policy as string);
  const publish = policy.Statement.find(
    (statement: { Sid: string }) => statement.Sid === "AllowAwsServicesToPublishEncryptedMessages",
  );

  assert.ok(publish);
  assert.ok(publish.Principal.Service.includes("rds.amazonaws.com"));
  assert.ok(publish.Principal.Service.includes("events.rds.amazonaws.com"));
  assert.ok(publish.Principal.Service.includes("budgets.amazonaws.com"));
  assert.ok(publish.Action.includes("kms:GenerateDataKey*"));
});

/*
 * S3 data events are opt-in and billed per event. They are the only record of who
 * actually read a client document, as opposed to who clicked download.
 */
test("CloudTrail records object reads on the documents bucket", () => {
  const trail = stack.named("trail");

  assert.ok(trail);
  assert.equal(trail.inputs.enableLogFileValidation, true);

  const selectors = trail.inputs.advancedEventSelectors as Array<Record<string, any>>;
  const dataEvents = selectors.find((selector) => selector.name.includes("Documents bucket"));

  assert.ok(dataEvents, "expected a data-event selector for the documents bucket");

  const fields = dataEvents.fieldSelectors as Array<Record<string, any>>;

  assert.deepEqual(
    fields.find((field) => field.field === "eventCategory")?.equals,
    ["Data"],
  );
  assert.deepEqual(
    fields.find((field) => field.field === "resources.type")?.equals,
    ["AWS::S3::Object"],
  );
  // Scoped, not account-wide: otherwise every read of the trail generates another
  // trail entry, and the bill compounds.
  assert.ok(fields.find((field) => field.field === "resources.ARN"));
});

test("the audit log bucket is private and ages its own contents out", () => {
  assert.ok(stack.named("audit-logs-public-access"));
  const lifecycle = stack.named("audit-logs-lifecycle");

  assert.ok(lifecycle);

  const lifecycleRules = lifecycle.inputs.rules as Array<Record<string, any>>;

  assert.deepEqual(lifecycleRules[0].transitions, [{ days: 90, storageClass: "GLACIER" }]);
  assert.deepEqual(lifecycleRules[0].expiration, { days: 365 });

  const encryption = stack.named("audit-logs-encryption");

  assert.ok(encryption);

  // SSE-S3, not the platform CMK: an audit log encrypted with a key the audited
  // party controls is weaker evidence, and every reader would need a grant.
  const rules = encryption.inputs.rules as Array<Record<string, any>>;

  assert.equal(rules[0].applyServerSideEncryptionByDefault.sseAlgorithm, "AES256");
});

test("the deployed container runs with the download gate switched on", () => {
  const web = stack.ofType("aws:ecs/taskDefinition:TaskDefinition")
    .find((task) => task.inputs.family === "qompose-production-web");

  assert.ok(web);

  const variables = Object.fromEntries(
    containerDefinitions(web)[0].environment.map((
      variable: { name: string; value: string },
    ) => [variable.name, variable.value]),
  );

  assert.equal(variables.SECURITY_MALWARE_SCANNING_ENABLED, "true");
});

/*
 * The certificate CloudFront uses must be issued in us-east-1, whatever region
 * the distribution serves. Reusing the regional ALB certificate fails at apply
 * time with an error naming the certificate rather than the region, which is the
 * most common way a CloudFront stack does not come up.
 */
test("the edge certificate is a separate us-east-1 certificate", () => {
  const provider = stack.ofType("pulumi:providers:aws")
    .find((resource) => resource.name.includes("us-east-1"));

  assert.ok(provider, "expected a us-east-1 provider for CloudFront resources");
  assert.equal(provider.inputs.region, "us-east-1");

  const edgeCertificate = stack.named("edge-certificate");

  assert.ok(edgeCertificate);
  assert.equal(edgeCertificate.inputs.domainName, "app.qompose.nl");
  assert.deepEqual(edgeCertificate.inputs.subjectAlternativeNames, ["*.qompose.nl"]);

  // Distinct from the regional certificate the load balancer uses.
  assert.ok(stack.named("certificate"));
});

test("the distribution serves both hostnames over TLS", () => {
  const distribution = stack.named("edge");

  assert.ok(distribution);
  assert.deepEqual(distribution.inputs.aliases, ["app.qompose.nl", "*.qompose.nl"]);

  const viewerCertificate = distribution.inputs.viewerCertificate as Record<string, unknown>;

  assert.equal(viewerCertificate.minimumProtocolVersion, "TLSv1.2_2021");
  assert.ok(viewerCertificate.acmCertificateArn);

  // HTTPS to the origin as well, so the leg CloudFront adds is not the weak one.
  const origin = (distribution.inputs.origins as Array<Record<string, any>>)[0];

  assert.equal(origin.customOriginConfig.originProtocolPolicy, "https-only");
});

/*
 * Every application response is per-user. A shared cache entry on the default
 * behaviour would hand one firm's dossier list to another, so only the hashed
 * bundle may be cached — and it is safe precisely because a changed asset is a
 * different path.
 */
test("only the hashed bundle is cached; app responses never are", () => {
  const distribution = stack.named("edge");

  assert.ok(distribution);

  const defaultBehaviour = distribution.inputs.defaultCacheBehavior as Record<string, any>;
  const ordered = distribution.inputs.orderedCacheBehaviors as Array<Record<string, any>>;
  const build = ordered.find((behaviour) => behaviour.pathPattern === "/build/*");
  const websocket = ordered.find((behaviour) => behaviour.pathPattern === "/app/*");

  assert.ok(build);
  assert.ok(websocket, "Reverb's path needs its own uncached behaviour");

  assert.equal(defaultBehaviour.cachePolicyId, "managed-Managed-CachingDisabled");
  assert.equal(websocket.cachePolicyId, "managed-Managed-CachingDisabled");
  assert.equal(build.cachePolicyId, "managed-Managed-CachingOptimized");

  // Cookies, headers and query strings must reach the origin on app traffic.
  assert.equal(defaultBehaviour.originRequestPolicyId, "managed-Managed-AllViewer");
});

/*
 * Turning on AWSManagedRulesCommonRuleSet unmodified breaks this application:
 * SizeRestrictions_BODY rejects bodies over 8KB and uploads go to 20-25MB, while
 * CrossSiteScripting_BODY trips on client correspondence pasted into a note.
 * Counted rather than removed, so the decision stays visible in the metrics.
 */
test("managed WAF rules do not block uploads or pasted client text", () => {
  const acl = stack.named("edge-waf");

  assert.ok(acl);
  assert.equal(acl.inputs.scope, "CLOUDFRONT");
  assert.deepEqual(acl.inputs.defaultAction, { allow: {} });

  const rules = acl.inputs.rules as Array<Record<string, any>>;
  const common = rules.find((rule) => rule.name === "common-rules");

  assert.ok(common);

  const overrides = common.statement.managedRuleGroupStatement.ruleActionOverrides as
    Array<Record<string, any>>;
  const overridden = overrides.map((override) => override.name);

  assert.ok(overridden.includes("SizeRestrictions_BODY"));
  assert.ok(overridden.includes("CrossSiteScripting_BODY"));

  for (const override of overrides) {
    assert.deepEqual(override.actionToUse, { count: {} }, "overrides must count, not allow");
  }

  assert.ok(rules.find((rule) => rule.name === "known-bad-inputs"));
  assert.ok(rules.find((rule) => rule.name === "ip-reputation"));
});

/*
 * If the ALB stays reachable by name, the WAF, the TLS policy and the caching at
 * the edge are all one curl away from being bypassed. The prefix list narrows the
 * origin to CloudFront; the header narrows it to *our* distribution, since the
 * prefix list covers everyone else's too.
 */
test("the origin cannot be reached except through our distribution", () => {
  const albSecurityGroup = stack.named("alb-sg");

  assert.ok(albSecurityGroup);

  const ingress = albSecurityGroup.inputs.ingress as Array<Record<string, any>>;

  assert.equal(ingress.length, 1, "no plain-HTTP ingress once the edge terminates TLS");
  assert.equal(ingress[0].fromPort, 443);
  assert.deepEqual(ingress[0].prefixListIds, ["pl-cloudfront-origin-facing"]);
  assert.equal(ingress[0].cidrBlocks, undefined);

  // The listener refuses anything without the shared header, and the ALB grammar
  // only matches conditions positively — so the default action is the refusal.
  const listener = stack.ofType("aws:lb/listener:Listener")
    .find((candidate) => candidate.inputs.port === 443);

  assert.ok(listener);

  const defaultAction = (listener.inputs.defaultActions as Array<Record<string, any>>)[0];

  assert.equal(defaultAction.type, "fixed-response");
  assert.equal(defaultAction.fixedResponse.statusCode, "403");

  for (const component of ["reverb-rule", "web-rule"]) {
    const rule = stack.named(component);

    assert.ok(rule, `expected ${component}`);

    const conditions = rule.inputs.conditions as Array<Record<string, any>>;

    assert.ok(
      conditions.some((condition) => condition.httpHeader?.httpHeaderName),
      `${component} must require the origin header`,
    );
  }
});

test("DNS points at the distribution, not the origin", () => {
  const distribution = stack.named("edge");

  assert.ok(distribution);
  // Without a hosted zone this stack writes no records; the alias target is
  // asserted in stack.dns.test.ts, which runs the Route53 branch.
  assert.equal(stack.ofType("aws:route53/record:Record").length, 0);
});

/*
 * One topic, not one per subsystem: on-call needs a single place to subscribe,
 * and a topic per concern is how an alarm ends up with no subscribers.
 */
test("everything that needs a human publishes to one topic", () => {
  const topics = stack.ofType("aws:sns/topic:Topic")
    .map((topic) => String(topic.inputs.name))
    .sort();

  // The Textract topic is a message transport, not a notification channel — it is
  // the only other topic, and nothing subscribes a human to it.
  assert.deepEqual(topics, ["qompose-production-alerts", "qompose-production-textract-complete"]);

  const alarms = stack.ofType("aws:cloudwatch/metricAlarm:MetricAlarm");

  assert.ok(alarms.length > 0);

  for (const alarm of alarms) {
    const actions = alarm.inputs.alarmActions as string[];

    assert.equal(actions.length, 1, `${alarm.name} must notify the alerts topic`);
  }
});

test("the budget warns on spend already made and on spend still coming", () => {
  const budget = stack.named("budget");

  assert.ok(budget);
  assert.equal(budget.inputs.budgetType, "COST");
  assert.equal(budget.inputs.timeUnit, "MONTHLY");
  assert.equal(budget.inputs.limitAmount, "200");

  const notifications = budget.inputs.notifications as Array<Record<string, any>>;
  const types = notifications.map((notification) => notification.notificationType);

  // ACTUAL says the money is gone; FORECASTED fires early in a month whose run
  // rate has changed, while there is still time to act.
  assert.ok(types.includes("ACTUAL"));
  assert.ok(types.includes("FORECASTED"));
});

/*
 * ELB 5xx, not target 5xx: the former means the load balancer could not reach a
 * healthy target, the latter is the application returning errors — a different
 * and far noisier conversation.
 */
test("the 5xx alarm watches the load balancer, not the application", () => {
  const alarm = stack.named("alb-5xx-alarm");

  assert.ok(alarm);
  assert.equal(alarm.inputs.metricName, "HTTPCode_ELB_5XX_Count");
  // No traffic is not an outage.
  assert.equal(alarm.inputs.treatMissingData, "notBreaching");
});

/*
 * The inverse for health checks: missing data means no target is reporting at
 * all, which is the dangerous case rather than the quiet one.
 */
test("unhealthy-target alarms treat silence as breaching", () => {
  for (const component of ["web-unhealthy-alarm", "reverb-unhealthy-alarm"]) {
    const alarm = stack.named(component);

    assert.ok(alarm, `expected ${component}`);
    assert.equal(alarm.inputs.metricName, "UnHealthyHostCount");
    assert.equal(alarm.inputs.treatMissingData, "breaching");
  }
});

/*
 * A Multi-AZ failover is invisible in CPU or connection graphs — that is the
 * point of paying for it — so it is an event subscription, not a metric alarm.
 */
test("RDS failover is subscribed as an event, not watched as a metric", () => {
  const subscription = stack.named("database-events");

  assert.ok(subscription);
  assert.equal(subscription.inputs.sourceType, "db-instance");

  const categories = subscription.inputs.eventCategories as string[];

  assert.ok(categories.includes("failover"));
  assert.ok(categories.includes("low storage"));
});

// A stopped queue worker is silent by nature: jobs pile up in Redis and nothing
// errors. Running count below desired is the only outward sign — and that metric
// only exists when Container Insights is on.
test("a stopped queue worker raises an alarm", () => {
  const cluster = stack.named("cluster");

  assert.ok(cluster);
  assert.deepEqual(cluster.inputs.settings, [{
    name: "containerInsights",
    value: "enabled",
  }]);

  const alarm = stack.named("worker-stopped-alarm");

  assert.ok(alarm);
  assert.equal(alarm.inputs.namespace, "ECS/ContainerInsights");
  assert.equal(alarm.inputs.metricName, "RunningTaskCount");
  assert.equal(alarm.inputs.comparisonOperator, "LessThanThreshold");
});

/*
 * A backup in the same region as its database is a second copy of one failure
 * domain, not disaster recovery — so this is the one deliberate exception to
 * eu-west-1 holding everything, and the destination stays inside the EU.
 */
test("automated backups are replicated out of the primary region", () => {
  const replica = stack.named("database-backup-replica");

  assert.ok(replica);
  assert.equal(replica.inputs.retentionPeriod, 14);

  const destination = stack.ofType("pulumi:providers:aws")
    .find((provider) => provider.name.includes("eu-central-1"));

  assert.ok(destination, "the replication resource lives in the destination region");
  assert.equal(destination.inputs.region, "eu-central-1");
  assert.notEqual(destination.inputs.region, "eu-west-1");
});

test("production does not mint the Sail IAM user (that lives on local)", () => {
  assert.equal(stack.hasType("aws:iam/user:User"), false);
  assert.equal(stack.hasType("aws:iam/accessKey:AccessKey"), false);
});
