import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import type { PlatformConfig } from "../config.js";
import type { Mail } from "./mail.js";

export interface PlatformDns {
  /**
   * Records to create when the zone is not in Route53.
   *
   * Empty when `hostedZoneId` is set, because the stack wrote them itself.
   */
  manualRecords: pulumi.Output<Array<{ name: string; type: string; value: string }>>;
}

/** SES issues a fixed set of three DKIM tokens per domain. */
const DKIM_TOKEN_COUNT = 3;

/**
 * DNS for SES DKIM only.
 *
 * ALB / CloudFront alias wiring was removed with the compute plane (TASK-43.3).
 * The marketing site still owns `www` and the apex; this stack never writes those
 * names.
 *
 * `hostedZoneId` is optional because the zone may not be in Route53. Without it
 * the stack still creates the SES identity and exports the DKIM records to create
 * by hand.
 */
export function createPlatformDns(config: PlatformConfig, mail: Mail): PlatformDns {
  const { environment, hostedZoneId } = config;

  if (hostedZoneId === undefined) {
    return {
      manualRecords: describeDkimRecords(mail),
    };
  }

  for (let index = 0; index < DKIM_TOKEN_COUNT; index += 1) {
    const token = mail.dkimTokens[index];

    new aws.route53.Record(environment.resourceName(`mail-dkim-${index}`), {
      zoneId: hostedZoneId,
      name: pulumi.interpolate`${token}._domainkey.${mail.domainIdentity.domain}`,
      type: "CNAME",
      records: [pulumi.interpolate`${token}.dkim.amazonses.com`],
      ttl: 600,
    });
  }

  return { manualRecords: pulumi.output([]) };
}

/** The DKIM records a human has to create when the zone lives outside Route53. */
function describeDkimRecords(
  mail: Mail,
): pulumi.Output<Array<{ name: string; type: string; value: string }>> {
  return pulumi
    .all([mail.dkimTokens, mail.domainIdentity.domain])
    .apply(([dkimTokens, mailDomain]) =>
      dkimTokens.map((token) => ({
        name: `${token}._domainkey.${mailDomain}`,
        type: "CNAME",
        value: `${token}.dkim.amazonses.com`,
      })),
    );
}
