import assert from "node:assert/strict";
import test from "node:test";
import {
  GITHUB_OIDC_AUDIENCE,
  GITHUB_OIDC_ISSUER,
  assertPinnedRepository,
  githubEnvironmentSubject,
} from "./trust.js";

test("the subject uses the environment form, not the ref form", () => {
  // deploy.yml declares `environment: production`, which makes GitHub emit
  // `environment:` in the subject instead of `ref:refs/heads/...`. Pinning the
  // ref form here is the single most common reason AssumeRoleWithWebIdentity
  // fails, so assert the shape explicitly.
  assert.equal(
    githubEnvironmentSubject("NielsvanHoof/qompose", "production"),
    "repo:NielsvanHoof/qompose:environment:production",
  );

  assert.equal(
    githubEnvironmentSubject("NielsvanHoof/qompose", "staging"),
    "repo:NielsvanHoof/qompose:environment:staging",
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
