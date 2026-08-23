import * as pulumi from "@pulumi/pulumi";

export interface CapturedResource {
  type: string;
  name: string;
  inputs: Record<string, unknown>;
}

export interface CapturedStack {
  resources: CapturedResource[];
  /**
   * Requested `getPolicyDocument` inputs.
   *
   * Captured because the mocked `json` is identical for every document by
   * construction, so assertions have to read the statements that were asked for.
   */
  policyDocuments: Array<Record<string, unknown>>;
  ofType(type: string): CapturedResource[];
  /**
   * Look a resource up by its component name, through the same scheme
   * `DeploymentEnvironment.resourceName()` uses.
   *
   * Exact, not substring. A substring match here silently returned the wrong
   * resource — `"alb"` found `qompose-production-alb-sg`, `"database"` found
   * `qompose-production-database-sg` — and the assertions then read `undefined`
   * off it, which looks like a missing input rather than a missing resource.
   */
  named(component: string): CapturedResource | undefined;
  hasType(type: string): boolean;
}

const ACCOUNT_ID = "123456789012";
const REGION = "eu-west-1";

/**
 * Run the whole Pulumi program under mocks and capture what it declared.
 *
 * One caller per process. `pulumi.runtime.setMocks` is process-global and
 * `import("../index.js")` is module-cached, so a second stack in the same process
 * would silently reuse the first one's resources. That is why the production and
 * staging assertions live in separate `*.test.ts` files: `node --test` forks per
 * file, which is the only isolation available here.
 */
export async function runStack(
  stackName: string,
  configOverrides: Record<string, string> = {},
): Promise<CapturedStack> {
  const resources: CapturedResource[] = [];
  const policyDocuments: Array<Record<string, unknown>> = [];

    pulumi.runtime.setAllConfig({
    "aws:region": REGION,
    "qompose-platform:alarmEmailAddress": "ops@example.com",
    "qompose-platform:domain": "qompose.nl",
    "qompose-platform:mailFromAddress": "no-reply@qompose.nl",
    "qompose-platform:databasePassword": "fixture-database-password",
    ...configOverrides,
  }, []);

  await pulumi.runtime.setMocks({
    call: (args) => {
      if (args.token.includes("getCallerIdentity")) {
        return {
          accountId: ACCOUNT_ID,
          arn: `arn:aws:iam::${ACCOUNT_ID}:root`,
          userId: "root",
        };
      }

      if (args.token.includes("getAvailabilityZones")) {
        return { names: [`${REGION}a`, `${REGION}b`, `${REGION}c`] };
      }

      // CloudFront's managed policies and the CloudFront origin-facing prefix
      // list. Without these the lookups resolve to their own inputs, the id comes
      // back undefined, and the resulting throw inside an `apply` surfaces as
      // "Promise resolution is still pending" — a hang, not a readable failure.
      if (
        args.token.includes("getCachePolicy")
        || args.token.includes("getOriginRequestPolicy")
      ) {
        return { id: `managed-${String(args.inputs.name)}` };
      }

      if (args.token.includes("getManagedPrefixList")) {
        return { id: "pl-cloudfront-origin-facing", name: args.inputs.name };
      }

      if (args.token.includes("getPolicyDocument")) {
        policyDocuments.push(args.inputs as Record<string, unknown>);

        return { json: JSON.stringify({ Version: "2012-10-17", Statement: [] }) };
      }

      return args.inputs;
    },
    newResource: (args) => {
      const state: Record<string, unknown> = {
        ...args.inputs,
        arn: `arn:aws:test:${REGION}:${ACCOUNT_ID}:${args.name}`,
        name: args.inputs.name ?? args.name,
        id: args.inputs.name ?? args.name,
        secret: "fixture-resource-value",
        ...typeSpecificState(args.type, args.name, args.inputs),
      };

      resources.push({
        type: args.type,
        name: args.name,
        inputs: unwrapSecrets(args.inputs) as Record<string, unknown>,
      });

      return { id: `${args.name}-id`, state };
    },
  }, "qompose-platform", stackName);

  await import("../index.js");
  await pulumi.runtime.disconnect();

  return {
    resources,
    policyDocuments,
    ofType: (type) => resources.filter((resource) => resource.type === type),
    named: (component) =>
      resources.find((resource) => resource.name === `qompose-${stackName}-${component}`),
    hasType: (type) => resources.some((resource) => resource.type === type),
  };
}

/**
 * Attributes the program reads back off a resource.
 *
 * Anything omitted here surfaces as `undefined` inside an `apply`, which fails as a
 * TypeError somewhere unrelated rather than as a missing mock — so keep this in step
 * with what the components actually dereference.
 */
function typeSpecificState(
  type: string,
  name: string,
  inputs: Record<string, unknown>,
): Record<string, unknown> {
  switch (type) {
    case "aws:ecs/taskDefinition:TaskDefinition": {
      // RunTask IAM matches family:* or family:revision. The scheduler policy
      // uses arnWithoutRevision; without this the mock ARN is the generic test
      // shape and the assertion cannot see the family wildcard.
      const family = String(inputs.family ?? name);

      return {
        arn: `arn:aws:ecs:${REGION}:${ACCOUNT_ID}:task-definition/${family}:1`,
        arnWithoutRevision: `arn:aws:ecs:${REGION}:${ACCOUNT_ID}:task-definition/${family}`,
      };
    }
    case "aws:s3/bucketV2:BucketV2":
      return { bucket: `${name}-bucket` };
    case "aws:sqs/queue:Queue":
      return { url: `https://sqs.${REGION}.amazonaws.com/${ACCOUNT_ID}/${name}` };
    case "aws:iam/accessKey:AccessKey":
      return { id: "fixture-access-key-id", secret: "fixture-resource-value" };
    case "aws:ecr/repository:Repository":
      return { repositoryUrl: `${ACCOUNT_ID}.dkr.ecr.${REGION}.amazonaws.com/${name}` };
    case "aws:rds/instance:Instance":
      return {
        address: `${name}.abc123.${REGION}.rds.amazonaws.com`,
        masterUserSecrets: [{
          secretArn: `arn:aws:secretsmanager:${REGION}:${ACCOUNT_ID}:secret:${name}-master`,
          kmsKeyId: "test-key",
          secretStatus: "active",
        }],
      };
    case "aws:elasticache/replicationGroup:ReplicationGroup":
      return { primaryEndpointAddress: `${name}.abc123.ng.0001.euw1.cache.amazonaws.com` };
    case "aws:lb/loadBalancer:LoadBalancer":
      return { dnsName: `${name}-1234567890.${REGION}.elb.amazonaws.com`, zoneId: "Z32O12XQLNTSW2" };
    case "aws:acm/certificate:Certificate":
      return {
        domainValidationOptions: [{
          domainName: "app.qompose.nl",
          resourceRecordName: "_abc123.app.qompose.nl",
          resourceRecordType: "CNAME",
          resourceRecordValue: "_def456.acm-validations.aws",
        }, {
          domainName: "*.qompose.nl",
          resourceRecordName: "_abc123.qompose.nl",
          resourceRecordType: "CNAME",
          resourceRecordValue: "_ghi789.acm-validations.aws",
        }],
      };
    case "aws:ses/domainDkim:DomainDkim":
      return { dkimTokens: ["token1", "token2", "token3"] };
    default:
      return {};
  }
}

/**
 * Pulumi's marker for a wrapped value, and the signature that means "secret".
 *
 * A single secret anywhere inside a property wraps the **whole property** in
 * `{ [SPECIAL_SIG]: SECRET_SIG, value: … }` — so one secret inside a listener
 * rule's conditions turns the entire conditions *array* into an object, and an
 * assertion indexing it reads undefined. That surfaces as "cannot read
 * properties of undefined", which points at the assertion rather than at the
 * wrapper actually responsible.
 */
const SPECIAL_SIG_KEY = "4dabf18193072939515e22adb298388d";
const SECRET_SIG = "1b47061264138c4ac30d75fd1eb44270";

/** Strip those wrappers recursively so assertions read the values themselves. */
function unwrapSecrets(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(unwrapSecrets);
  }

  if (value === null || typeof value !== "object") {
    return value;
  }

  const record = value as Record<string, unknown>;

  if (record[SPECIAL_SIG_KEY] === SECRET_SIG) {
    return unwrapSecrets(record.value);
  }

  return Object.fromEntries(
    Object.entries(record).map(([key, entry]) => [key, unwrapSecrets(entry)]),
  );
}

/** Container definitions are serialised JSON; assertions need them back as objects. */
export function containerDefinitions(
  taskDefinition: CapturedResource,
): Array<Record<string, any>> {
  return JSON.parse(taskDefinition.inputs.containerDefinitions as string);
}
