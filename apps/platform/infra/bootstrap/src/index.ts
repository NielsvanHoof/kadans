import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { loadBootstrapConfig } from "./config.js";
import { deployRolePolicy } from "./deployPolicy.js";
import {
  GITHUB_OIDC_AUDIENCE,
  GITHUB_OIDC_ISSUER,
  githubEnvironmentSubject,
} from "./trust.js";

/*
 * Bootstrap: the identity GitHub Actions uses to deploy everything else.
 *
 * Run this **locally, once**, with admin credentials (ideally an IAM Identity
 * Center session — not root). It is a separate Pulumi project from `infra/` on
 * purpose: the thing that grants CI its access must not itself be deployable by
 * CI, or a compromised workflow could widen its own permissions.
 *
 *   cd apps/platform/infra/bootstrap
 *   npm ci
 *   pulumi stack init bootstrap
 *   pulumi config set githubRepository <owner>/<repo>
 *   pulumi config set aws:region eu-west-1
 *   npm run up
 *
 * Then put the exported role ARN into the matching GitHub Environment as the
 * `AWS_DEPLOY_ROLE_ARN` variable. See README.md.
 */

const config = loadBootstrapConfig();
const account = aws.getCallerIdentityOutput();

// AWS permits one OIDC provider per issuer URL, so an account that was wired up
// by hand already has one — point `existingOidcProviderArn` at it rather than
// fighting EntityAlreadyExists.
const oidcProviderArn: pulumi.Output<string> = config.existingOidcProviderArn
  ? pulumi.output(config.existingOidcProviderArn)
  : new aws.iam.OpenIdConnectProvider("github-actions-oidc", {
    url: `https://${GITHUB_OIDC_ISSUER}`,
    clientIdLists: [GITHUB_OIDC_AUDIENCE],
    // thumbprintLists intentionally omitted: AWS manages and rotates the
    // thumbprint for this issuer, so pinning one only creates future breakage.
    tags: { Purpose: "GitHub Actions workload identity federation" },
  }).arn;

/**
 * One deploy role per GitHub Environment.
 *
 * Separate roles rather than one shared role so a staging deploy is structurally
 * incapable of touching production, and so each trust policy can pin its own
 * environment in the subject claim.
 */
const deployRoles = config.environments.map((environment) => {
  const subject = githubEnvironmentSubject(config.githubRepository, environment);

  const assumeRolePolicy = aws.iam.getPolicyDocumentOutput({
    statements: [{
      effect: "Allow",
      principals: [{ type: "Federated", identifiers: [oidcProviderArn] }],
      actions: ["sts:AssumeRoleWithWebIdentity"],
      conditions: [{
        test: "StringEquals",
        variable: `${GITHUB_OIDC_ISSUER}:aud`,
        values: [GITHUB_OIDC_AUDIENCE],
      }, {
        // StringEquals, never StringLike: an exact subject is what confines this
        // role to one repository and one environment. See trust.ts.
        test: "StringEquals",
        variable: `${GITHUB_OIDC_ISSUER}:sub`,
        values: [subject],
      }],
    }],
  });

  const role = new aws.iam.Role(`qompose-${environment}-deploy-role`, {
    name: `qompose-${environment}-deploy`,
    // Distinct from the /qompose/ path used by application identities, so these
    // stand out in a role listing as bootstrap-owned.
    path: "/qompose-bootstrap/",
    description: `GitHub Actions deploy role for ${config.githubRepository} (${environment})`,
    assumeRolePolicy: assumeRolePolicy.json,
    // A deploy can take a while; the default 1h is enough but leaves no headroom.
    maxSessionDuration: 3600,
    tags: {
      Purpose: "GitHub Actions deploy",
      Environment: environment,
    },
  });

  return { environment, role, subject };
});

// Every deploy role is protected from every other one, so a staging deploy cannot
// rewrite production's trust policy either.
const protectedArns: pulumi.Input<string>[] = [
  oidcProviderArn,
  ...deployRoles.map((deploy) => deploy.role.arn),
];

for (const deploy of deployRoles) {
  new aws.iam.RolePolicy(`qompose-${deploy.environment}-deploy-policy`, {
    role: deploy.role.id,
    policy: deployRolePolicy(account.accountId, protectedArns),
  });
}

export const githubOidcProviderArn = oidcProviderArn;

/** Map of environment name → role ARN, for the GitHub Environment variables. */
export const deployRoleArns = pulumi
  .all(deployRoles.map((deploy) => deploy.role.arn))
  .apply((arns) =>
    Object.fromEntries(
      arns.map((arn, index) => [deployRoles[index].environment, arn]),
    )
  );

/** The exact `sub` values the trust policies accept, for debugging failed assumes. */
export const acceptedSubjectClaims = Object.fromEntries(
  deployRoles.map((deploy) => [deploy.environment, deploy.subject]),
);
