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
 * There are two traps here. A job that declares an `environment:` uses an
 * `environment:<name>` suffix instead of `ref:refs/heads/...`. New repositories
 * also receive GitHub's immutable subject prefix, which appends the numeric owner
 * and repository IDs. For this repository the resulting form is:
 *
 * `repo:owner@ownerId/repo@repositoryId:environment:production`
 *
 * A trust policy using either the ref suffix or the legacy name-only prefix fails
 * with "Not authorized to perform sts:AssumeRoleWithWebIdentity" without saying
 * which claim differed.
 */
export function githubEnvironmentSubject(
  repository: string,
  environment: string,
  repositoryOwnerId?: string,
  repositoryId?: string,
): string {
  assertPinnedRepository(repository);
  assertPinnedEnvironment(environment);

  const hasOwnerId = repositoryOwnerId !== undefined;
  const hasRepositoryId = repositoryId !== undefined;

  if (hasOwnerId !== hasRepositoryId) {
    throw new Error(
      "githubRepositoryOwnerId and githubRepositoryId must be configured together.",
    );
  }

  let repositorySubject = repository;

  if (repositoryOwnerId !== undefined && repositoryId !== undefined) {
    assertNumericId("githubRepositoryOwnerId", repositoryOwnerId);
    assertNumericId("githubRepositoryId", repositoryId);

    const [owner, name] = repository.split("/");
    repositorySubject = `${owner}@${repositoryOwnerId}/${name}@${repositoryId}`;
  }

  return `repo:${repositorySubject}:environment:${environment}`;
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

function assertNumericId(name: string, value: string): void {
  if (!/^\d+$/.test(value)) {
    throw new Error(`${name} must be a numeric GitHub ID, received "${value}".`);
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
