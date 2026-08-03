import * as pulumi from "@pulumi/pulumi";
import { assertPinnedRepository } from "./trust.js";

export interface BootstrapConfig {
  /** "owner/repo" — the only repository allowed to assume the deploy roles. */
  githubRepository: string;
  /** GitHub Environment names to mint a deploy role for. */
  environments: string[];
  region: string;
  /**
   * Reuse an OIDC provider that already exists in the account instead of creating
   * one. AWS allows only a single provider per issuer URL, so a fresh `pulumi up`
   * against an account that was set up by hand will otherwise fail with
   * EntityAlreadyExists.
   */
  existingOidcProviderArn?: string;
}

export function loadBootstrapConfig(): BootstrapConfig {
  const config = new pulumi.Config();
  const githubRepository = config.require("githubRepository");

  // Fail during preview rather than provisioning an over-permissive trust policy.
  assertPinnedRepository(githubRepository);

  return {
    githubRepository,
    environments: config.getObject<string[]>("environments") ?? ["production"],
    region: new pulumi.Config("aws").get("region") ?? "eu-west-1",
    existingOidcProviderArn: config.get("existingOidcProviderArn") ?? undefined,
  };
}
