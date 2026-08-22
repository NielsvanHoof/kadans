import assert from "node:assert/strict";
import test from "node:test";
import {
  GITHUB_OIDC_AUDIENCE,
  GITHUB_OIDC_ISSUER,
  assertPinnedRepository,
  githubEnvironmentSubject,
} from "./trust.js";

test("the subject uses the immutable repository and environment form", () => {
  // deploy.yml declares an environment, and this repository uses GitHub's
  // immutable owner/repository IDs in its default subject prefix.
  assert.equal(
    githubEnvironmentSubject(
      "NielsvanHoof/qompose",
      "production",
      "26738111",
      "1301716861",
    ),
    "repo:NielsvanHoof@26738111/qompose@1301716861:environment:production",
  );

  assert.equal(
    githubEnvironmentSubject(
      "NielsvanHoof/qompose",
      "staging",
      "26738111",
      "1301716861",
    ),
    "repo:NielsvanHoof@26738111/qompose@1301716861:environment:staging",
  );
});

test("legacy repositories can omit immutable IDs", () => {
  assert.equal(
    githubEnvironmentSubject("owner/repo", "staging"),
    "repo:owner/repo:environment:staging",
  );
});

test("immutable owner and repository IDs must be configured together", () => {
  assert.throws(
    () => githubEnvironmentSubject("owner/repo", "staging", "123"),
    /must be configured together/,
  );
  assert.throws(
    () => githubEnvironmentSubject("owner/repo", "staging", undefined, "456"),
    /must be configured together/,
  );
});

test("immutable owner and repository IDs must be numeric", () => {
  assert.throws(
    () => githubEnvironmentSubject("owner/repo", "staging", "owner", "456"),
    /githubRepositoryOwnerId must be a numeric GitHub ID/,
  );
  assert.throws(
    () => githubEnvironmentSubject("owner/repo", "staging", "123", "repo"),
    /githubRepositoryId must be a numeric GitHub ID/,
  );
});

test("each environment gets a distinct subject", () => {
  const production = githubEnvironmentSubject("owner/repo", "production");
  const staging = githubEnvironmentSubject("owner/repo", "staging");

  assert.notEqual(production, staging);
});

test("wildcard repositories are rejected", () => {
  // A wildcard subject would let any repository on GitHub assume the role.
  assert.throws(
    () => githubEnvironmentSubject("owner/*", "production"),
    /must not contain wildcards/,
  );
  assert.throws(
    () => githubEnvironmentSubject("*", "production"),
    /must not contain wildcards/,
  );
  assert.throws(
    () => assertPinnedRepository("owner/re?o"),
    /must not contain wildcards/,
  );
});

test("malformed repositories are rejected", () => {
  assert.throws(() => assertPinnedRepository("just-an-owner"), /owner\/repo/);
  assert.throws(() => assertPinnedRepository(""), /owner\/repo/);
  assert.throws(() => assertPinnedRepository("owner/repo/extra"), /owner\/repo/);
});

test("well-formed repositories are accepted", () => {
  assert.doesNotThrow(() => assertPinnedRepository("NielsvanHoof/qompose"));
  assert.doesNotThrow(() => assertPinnedRepository("some-org/some.repo_name"));
});

test("wildcard environments are rejected", () => {
  assert.throws(
    () => githubEnvironmentSubject("owner/repo", "*"),
    /must not contain wildcards/,
  );
  assert.throws(
    () => githubEnvironmentSubject("owner/repo", "   "),
    /must not be empty/,
  );
});

test("the issuer and audience match what configure-aws-credentials sends", () => {
  assert.equal(GITHUB_OIDC_ISSUER, "token.actions.githubusercontent.com");
  assert.equal(GITHUB_OIDC_AUDIENCE, "sts.amazonaws.com");
});
