import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { ORIGIN_VERIFY_HEADER, PlatformConfig, requireOriginVerifySecret } from "../config.js";
import { LoadBalancer } from "./loadBalancer.js";

export interface Edge {
  distribution: aws.cloudfront.Distribution;
  webAcl?: aws.wafv2.WebAcl;
  certificate: aws.acm.Certificate;
}

/**
 * CloudFront in front of the ALB.
 *
 * Three things it buys, in the order they matter here: TLS termination and
 * connection reuse close to the user (the ALB lives in one region, the staff do
 * not), edge caching for the hashed Vite bundle, and an origin that can no longer
 * be addressed directly.
 *
 * **The certificate must live in us-east-1.** CloudFront reads its certificate
 * from that region and only that region, no matter where the distribution serves
 * — which is why this file carries its own provider. Reusing the regional
 * certificate from `loadBalancer.ts` fails at apply time with an error that names
 * the certificate rather than the region, and is the single most common way a
 * CloudFront stack does not come up.
 */
export function createEdge(
  config: PlatformConfig,
  loadBalancer: LoadBalancer,
): Edge {
  const { environment, appHostname, tenantHostnamePattern } = config;

  // CloudFront and CLOUDFRONT-scoped WAF are both us-east-1-only resources.
  const globalProvider = new aws.Provider(environment.resourceName("us-east-1"), {
    region: "us-east-1",
  });
  const globalOptions = { provider: globalProvider };

  const certificate = new aws.acm.Certificate(
    environment.resourceName("edge-certificate"),
    {
      domainName: appHostname,
      subjectAlternativeNames: [tenantHostnamePattern],
      validationMethod: "DNS",
      tags: { Name: environment.resourceName("edge-certificate") },
    },
    globalOptions,
  );

  const webAcl = config.wafEnabled ? createWebAcl(config, globalOptions) : undefined;

  // Managed policies rather than hand-rolled ones: AWS keeps them current, and
  // the ids are global constants, so looking them up by name reads better than
  // pasting a UUID nobody can verify.
  const cachingDisabled = managedPolicyId(
    aws.cloudfront.getCachePolicyOutput({ name: "Managed-CachingDisabled" }).id,
    "Managed-CachingDisabled",
  );
  const cachingOptimized = managedPolicyId(
    aws.cloudfront.getCachePolicyOutput({ name: "Managed-CachingOptimized" }).id,
    "Managed-CachingOptimized",
  );
  const allViewer = managedPolicyId(
    aws.cloudfront.getOriginRequestPolicyOutput({ name: "Managed-AllViewer" }).id,
    "Managed-AllViewer",
  );

  const originId = environment.resourceName("alb-origin");

  /**
   * Everything the application serves is per-user.
   *
   * CachingDisabled plus AllViewer forwards every header, cookie and query string
   * and stores nothing. That is not a missed optimisation — an Inertia response
   * carries the signed-in user's workspace, and a shared cache entry here would
   * hand one firm's dossier list to another. The only thing worth caching is the
   * hashed bundle, which gets its own behaviour below.
   */
  const passThroughBehaviour = {
    targetOriginId: originId,
    viewerProtocolPolicy: "redirect-to-https",
    allowedMethods: ["GET", "HEAD", "OPTIONS", "PUT", "POST", "PATCH", "DELETE"],
    cachedMethods: ["GET", "HEAD"],
    compress: true,
    cachePolicyId: cachingDisabled,
    originRequestPolicyId: allViewer,
  };

  const distribution = new aws.cloudfront.Distribution(
    environment.resourceName("edge"),
    {
      enabled: true,
      comment: `Qompose ${environment.name} platform edge`,
      aliases: [appHostname, tenantHostnamePattern],
      httpVersion: "http2and3",
      isIpv6Enabled: true,
      priceClass: "PriceClass_100",
      webAclId: webAcl?.arn,

      origins: [{
        originId,
        domainName: loadBalancer.loadBalancer.dnsName,
        customOriginConfig: {
          httpPort: 80,
          httpsPort: 443,
          // HTTPS to the origin as well, so the leg CloudFront adds is not the
          // weak one. The ALB certificate covers the hostname being forwarded.
          originProtocolPolicy: "https-only",
          originSslProtocols: ["TLSv1.2"],
          // Reverb holds connections open; the ALB is configured for that too.
          originReadTimeout: 60,
          originKeepaliveTimeout: 60,
        },
        customHeaders: [{
          // Paired with the prefix-list rule on the ALB security group. The
          // prefix list narrows the origin to CloudFront; this narrows it to
          // *our* distribution, which the prefix list alone cannot do.
          name: ORIGIN_VERIFY_HEADER,
          value: requireOriginVerifySecret(config),
        }],
      }],

      defaultCacheBehavior: passThroughBehaviour,

      orderedCacheBehaviors: [{
        /*
         * The hashed Vite bundle, and the only thing here worth caching.
         *
         * Safe precisely because the filenames carry a content hash: a changed
         * asset is a different path, so a long TTL can never serve a stale one
         * and no invalidation is needed on deploy.
         */
        pathPattern: "/build/*",
        ...passThroughBehaviour,
        allowedMethods: ["GET", "HEAD", "OPTIONS"],
        cachePolicyId: cachingOptimized,
        // Managed-CachingOptimized forwards nothing, so no origin request policy.
        originRequestPolicyId: undefined,
      }, {
        /*
         * Reverb's WebSocket path. CloudFront proxies WebSockets, but only when
         * the behaviour forwards everything and caches nothing — a cached
         * handshake is not a thing that can work.
         */
        pathPattern: "/app/*",
        ...passThroughBehaviour,
      }],

      viewerCertificate: {
        acmCertificateArn: certificate.arn,
        sslSupportMethod: "sni-only",
        minimumProtocolVersion: "TLSv1.2_2021",
      },

      restrictions: {
        geoRestriction: { restrictionType: "none" },
      },

      tags: { Name: environment.resourceName("edge") },
    },
  );

  return { distribution, webAcl, certificate };
}

/**
 * The lookups return `Output<string | undefined>` because a name that matches
 * nothing is a valid response. It is not a valid outcome here — a missing policy
 * id would silently produce a distribution with default caching, which for
 * authenticated pages means serving one user's response to another.
 */
function managedPolicyId(
  id: pulumi.Output<string | undefined> | undefined,
  name: string,
): pulumi.Output<string> {
  return pulumi.output(id).apply((value) => {
    if (value === undefined) {
      throw new Error(`AWS managed CloudFront policy "${name}" was not found.`);
    }

    return value;
  });
}

/**
 * AWS managed rule groups, with the two exclusions that keep the platform usable.
 *
 * Turning on `AWSManagedRulesCommonRuleSet` unmodified breaks this application
 * immediately, and the failure looks like a mysterious 403 from CloudFront rather
 * than anything a developer would connect to WAF:
 *
 *  - **SizeRestrictions_BODY** rejects request bodies over 8 KB. Document uploads
 *    are allowed up to 20 MB, so every real upload is blocked.
 *  - **CrossSiteScripting_BODY** inspects body content for script-like patterns.
 *    Staff paste client correspondence into notes and questionnaire answers, and
 *    a quoted email signature containing markup is enough to trip it.
 *
 * Both are set to `count` rather than removed: they still record what they would
 * have blocked, so the decision stays visible in the metrics instead of becoming
 * a silent hole.
 */
function createWebAcl(
  config: PlatformConfig,
  options: pulumi.CustomResourceOptions,
): aws.wafv2.WebAcl {
  const { environment } = config;

  return new aws.wafv2.WebAcl(environment.resourceName("edge-waf"), {
    name: environment.resourceName("edge-waf"),
    // CLOUDFRONT scope is only valid in us-east-1 — same constraint as the cert.
    scope: "CLOUDFRONT",
    defaultAction: { allow: {} },
    visibilityConfig: {
      cloudwatchMetricsEnabled: true,
      metricName: environment.resourceName("edge-waf"),
      sampledRequestsEnabled: true,
    },
    rules: [{
      name: "common-rules",
      priority: 1,
      overrideAction: { none: {} },
      statement: {
        managedRuleGroupStatement: {
          vendorName: "AWS",
          name: "AWSManagedRulesCommonRuleSet",
          ruleActionOverrides: [{
            name: "SizeRestrictions_BODY",
            actionToUse: { count: {} },
          }, {
            name: "CrossSiteScripting_BODY",
            actionToUse: { count: {} },
          }],
        },
      },
      visibilityConfig: {
        cloudwatchMetricsEnabled: true,
        metricName: `${environment.resourceName("edge-waf")}-common`,
        sampledRequestsEnabled: true,
      },
    }, {
      name: "known-bad-inputs",
      priority: 2,
      overrideAction: { none: {} },
      statement: {
        managedRuleGroupStatement: {
          vendorName: "AWS",
          name: "AWSManagedRulesKnownBadInputsRuleSet",
        },
      },
      visibilityConfig: {
        cloudwatchMetricsEnabled: true,
        metricName: `${environment.resourceName("edge-waf")}-bad-inputs`,
        sampledRequestsEnabled: true,
      },
    }, {
      name: "ip-reputation",
      priority: 3,
      overrideAction: { none: {} },
      statement: {
        managedRuleGroupStatement: {
          vendorName: "AWS",
          name: "AWSManagedRulesAmazonIpReputationList",
        },
      },
      visibilityConfig: {
        cloudwatchMetricsEnabled: true,
        metricName: `${environment.resourceName("edge-waf")}-ip-reputation`,
        sampledRequestsEnabled: true,
      },
    }],
    tags: { Name: environment.resourceName("edge-waf") },
  }, options);
}
