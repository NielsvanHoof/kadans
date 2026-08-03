/**
 * GitHub OIDC subject-claim construction, kept as pure functions so the rules can
 * be unit tested without touching AWS.
 *
 * This is the part of the setup that is easiest to get wrong and hardest to
 * notice, so the reasoning lives here rather than in a comment on a resource.
 */

/** The audience `aws-actions/configure-aws-credentials` requests. */
export const GITHUB_OIDC_AUDIENCE = "sts.amazonaws.com";

export const GITHUB_OIDC_ISSUER = "token.actions.githubusercontent.com";

/**
 * Build the `sub` claim for a workflow job that declares `environment: <name>`.
 *
 * **The trap.** Most examples show `repo:owner/repo:ref:refs/heads/main`. That is
 * wrong for our deploy workflow: when a job declares an `environment:`, GitHub
 * replaces the ref portion of the subject with the environment, producing
 * `repo:owner/repo:environment:production`. A trust policy pinned to the ref form
 * will fail with "Not authorized to perform sts:AssumeRoleWithWebIdentity", and
 * the error says nothing about why.
 *
 * `.github/workflows/deploy.yml` declares `environment: production`, so this is
 * the form we must match.
 */
export function githubEnvironmentSubject(
  repository: string,
  environment: string,
): string {
  assertPinnedRepository(repository);
  assertPinnedEnvironment(environment);

  return `repo:${repository}:environment:${environment}`;
}

/**
 * Reject anything that would let a repository other than ours assume the role.
 *
 * A trust policy that checks only the audience — or pins the subject to a pattern
 * containing a wildcard — can be assumed by *any* repository on GitHub, including
 * one an attacker creates specifically to do so. That turns a public identity
 * provider into an open door to the AWS account, so it is worth failing the deploy
 * rather than shipping it.
 */
export function assertPinnedRepository(repository: string): void {
  if (repository.includes("*") || repository.includes("?")) {
    throw new Error(
      `githubRepository must not contain wildcards, received "${repository}". `
        + "A wildcard subject lets any GitHub repository assume this role.",
    );
  }

  if (!/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/.test(repository)) {
    throw new Error(
      `githubRepository must be in "owner/repo" form, received "${repository}".`,
    );
  }
}

function assertPinnedEnvironment(environment: string): void {
  if (environment.includes("*") || environment.includes("?")) {
    throw new Error(
      `environment must not contain wildcards, received "${environment}".`,
    );
  }

  if (environment.trim() === "") {
    throw new Error("environment must not be empty.");
  }
}
