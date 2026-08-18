import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "../config.js";
import { Edge } from "./edge.js";
import { LoadBalancer } from "./loadBalancer.js";
import { Mail } from "./mail.js";

export interface PlatformDns {
  /**
   * Records to create when the zone is not in Route53.
   *
   * Empty when `hostedZoneId` is set, because the stack wrote them itself.
   */
  manualRecords: pulumi.Output<Array<{ name: string; type: string; value: string }>>;
  certificateValidated: boolean;
}

/** One certificate, two names — so exactly two ACM validation records. */
const CERTIFICATE_NAME_COUNT = 2;

/**
 * Where `app.` and the tenant wildcard resolve to.
 *
 * CloudFront when the edge is on, the load balancer when it is not — and the ALB
 * must not keep an alias record once the edge exists, or the origin stays
 * publicly addressable by name and the WAF and TLS policy at the edge become
 * optional for anyone who knows that name.
 */
interface AliasTarget {
  dnsName: pulumi.Output<string>;
  zoneId: pulumi.Output<string>;
}
/** SES issues a fixed set of three DKIM tokens per domain. */
const DKIM_TOKEN_COUNT = 3;

/**
 * DNS for the platform hostnames — and, more importantly, DNS the platform does
 * **not** touch.
 *
 * The marketing site owns `www.qompose.nl` and the apex, and this stack must not
 * move them. That reads like a constraint needing careful ordering; it is not,
 * because of how resolution actually works: a wildcard record only answers a name
 * that has **no explicit record of its own**. `www` has one. So does the apex, and
 * so will `mail`, `api`, or any other reserved host the moment someone creates it.
 * Publishing `*.qompose.nl` cannot capture them, so there is no carve-out list to
 * maintain here — this stack simply never writes those names.
 *
 * `hostedZoneId` is optional because the zone may not be in Route53 at all. Without
 * it the stack still builds the certificate and the load balancer and exports the
 * records to create by hand. What it skips is `acm.CertificateValidation`, which
 * blocks until the validation record appears — with no zone to write it into, that
 * turns every `pulumi up` into a 45-minute wait ending in a timeout.
 */
export function createPlatformDns(
  config: PlatformConfig,
  loadBalancer: LoadBalancer,
  mail: Mail,
  edge?: Edge,
): PlatformDns {
  const { environment, hostedZoneId, appHostname, tenantHostnamePattern } = config;

  const target: AliasTarget = edge === undefined
    ? {
      dnsName: loadBalancer.loadBalancer.dnsName,
      zoneId: loadBalancer.loadBalancer.zoneId,
    }
    : {
      dnsName: edge.distribution.domainName,
      zoneId: edge.distribution.hostedZoneId,
    };

  if (hostedZoneId === undefined) {
    return {
      certificateValidated: false,
      manualRecords: describeRecords(config, loadBalancer, mail, target),
    };
  }

  /*
   * Indexing into the Output rather than creating records inside an `apply`.
   *
   * Pulumi lifts property and index access over `Output<T[]>`, so this reads a
   * value that does not exist yet without ever unwrapping it. Creating resources
   * inside an `apply` callback compiles and mostly works, but those resources do
   * not appear in `pulumi preview` — which is the one thing preview exists for, and
   * a poor property to give up on the resources that gate TLS.
   *
   * The count is static because the certificate carries a fixed two names.
   * `allowOverwrite` covers the case where both resolve to the same validation
   * record — which happens when the app hostname *is* the apex the wildcard covers.
   * The contents are identical, so the second write is a no-op rather than a
   * conflict.
   */
  const validationFqdns = Array.from({ length: CERTIFICATE_NAME_COUNT }, (_unused, index) => {
    const validation = loadBalancer.certificate.domainValidationOptions[index];

    return new aws.route53.Record(
      environment.resourceName(`certificate-validation-${index}`),
      {
        zoneId: hostedZoneId,
        name: validation.resourceRecordName,
        type: validation.resourceRecordType,
        records: [validation.resourceRecordValue],
        ttl: 60,
        allowOverwrite: true,
      },
    ).fqdn;
  });

  new aws.acm.CertificateValidation(environment.resourceName("certificate-validation"), {
    certificateArn: loadBalancer.certificate.arn,
    validationRecordFqdns: validationFqdns,
  });

  /*
   * The edge certificate lives in us-east-1 and is a different certificate, but it
   * covers the same two names — so ACM issues the same validation record values,
   * and the records written above already satisfy it. Only the validation
   * resource is separate, and it is pointed at the records that already exist
   * rather than writing duplicates Route53 would reject.
   */
  if (edge !== undefined) {
    new aws.acm.CertificateValidation(environment.resourceName("edge-certificate-validation"), {
      certificateArn: edge.certificate.arn,
      validationRecordFqdns: validationFqdns,
    });
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

  // Alias records, not CNAMEs: an alias resolves for free, works at a zone apex,
  // and follows the load balancer's addresses with no TTL window in between.
  for (const [label, hostname] of [["app", appHostname], ["tenants", tenantHostnamePattern]]) {
    new aws.route53.Record(environment.resourceName(`${label}-record`), {
      zoneId: hostedZoneId,
      name: hostname,
      type: "A",
      aliases: [{
        name: target.dnsName,
        zoneId: target.zoneId,
        // CloudFront has no health to evaluate; an alias to it must say so.
        evaluateTargetHealth: edge === undefined,
      }],
    });
  }

  return { certificateValidated: true, manualRecords: pulumi.output([]) };
}

/** The records a human has to create when the zone lives somewhere other than Route53. */
function describeRecords(
  config: PlatformConfig,
  loadBalancer: LoadBalancer,
  mail: Mail,
  target: AliasTarget,
): pulumi.Output<Array<{ name: string; type: string; value: string }>> {
  return pulumi
    .all([
      loadBalancer.certificate.domainValidationOptions,
      mail.dkimTokens,
      mail.domainIdentity.domain,
      target.dnsName,
    ])
    .apply(([validations, dkimTokens, mailDomain, aliasDnsName]) => [
      ...validations.map((validation) => ({
        name: validation.resourceRecordName,
        type: validation.resourceRecordType,
        value: validation.resourceRecordValue,
      })),
      ...dkimTokens.map((token) => ({
        name: `${token}._domainkey.${mailDomain}`,
        type: "CNAME",
        value: `${token}.dkim.amazonses.com`,
      })),
      { name: config.appHostname, type: "CNAME", value: aliasDnsName },
      { name: config.tenantHostnamePattern, type: "CNAME", value: aliasDnsName },
    ]);
}
