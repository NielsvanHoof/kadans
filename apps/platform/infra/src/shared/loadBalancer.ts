import * as aws from "@pulumi/aws";
import { ORIGIN_VERIFY_HEADER, PlatformConfig, requireOriginVerifySecret } from "../config.js";
import { Network } from "./network.js";

export interface LoadBalancer {
  loadBalancer: aws.lb.LoadBalancer;
  certificate: aws.acm.Certificate;
  httpsListener: aws.lb.Listener;
  webTargetGroup: aws.lb.TargetGroup;
  reverbTargetGroup: aws.lb.TargetGroup;
}

/**
 * Seconds an idle connection survives on the ALB.
 *
 * The default is 60, and it is the single most effective way to make this stack
 * look like it works while quietly breaking real-time: Reverb connects, the client
 * sees an open WebSocket, and every connection is dropped a minute later. Raising
 * it is not a tuning preference — a WebSocket is idle by definition between
 * messages.
 */
const IDLE_TIMEOUT_SECONDS = 3600;

/**
 * Public edge for the platform: one ACM certificate covering the central hostname
 * and the tenant wildcard, one Multi-AZ ALB, two target groups.
 *
 * The wildcard is provisioned now and routed later. `TrustHosts` currently accepts
 * only the exact `APP_URL` host (`config/production.php`), and tenancy resolves
 * from the path (`/workspaces/{tenant:slug}`), so a `{slug}.qompose.nl` request
 * reaches the ALB and is refused by the application until TASK-34.5 lands. Having
 * the certificate ready means that task is an application change, not a
 * certificate-validation wait.
 */
export function createLoadBalancer(
  config: PlatformConfig,
  network: Network,
): LoadBalancer {
  const { environment, appHostname, tenantHostnamePattern } = config;

  const certificate = new aws.acm.Certificate(environment.resourceName("certificate"), {
    domainName: appHostname,
    subjectAlternativeNames: [tenantHostnamePattern],
    validationMethod: "DNS",
    tags: { Name: environment.resourceName("certificate") },
  });

  const loadBalancer = new aws.lb.LoadBalancer(environment.resourceName("alb"), {
    name: environment.resourceName("alb"),
    loadBalancerType: "application",
    subnets: network.publicSubnetIds,
    securityGroups: [network.loadBalancerSecurityGroup.id],
    idleTimeout: IDLE_TIMEOUT_SECONDS,
    enableHttp2: true,
    // Drop requests whose headers do not parse rather than passing them through
    // and letting the framework guess. Desync protection is the ALB default.
    dropInvalidHeaderFields: true,
    enableDeletionProtection: environment.settings.deletionProtection,
    tags: { Name: environment.resourceName("alb") },
  });

  const webTargetGroup = new aws.lb.TargetGroup(environment.resourceName("web-tg"), {
    name: environment.resourceName("web-tg"),
    port: 8080,
    protocol: "HTTP",
    targetType: "ip",
    vpcId: network.vpc.id,
    // Give Octane time to finish in-flight requests before the target is cut off.
    deregistrationDelay: 30,
    healthCheck: {
      // routes/health.php — anonymous and throttled 60/min precisely for this.
      path: "/ready",
      matcher: "200",
      interval: 30,
      timeout: 5,
      healthyThreshold: 2,
      unhealthyThreshold: 3,
    },
  });

  const reverbTargetGroup = new aws.lb.TargetGroup(environment.resourceName("reverb-tg"), {
    name: environment.resourceName("reverb-tg"),
    port: 8080,
    protocol: "HTTP",
    targetType: "ip",
    vpcId: network.vpc.id,
    deregistrationDelay: 30,
    healthCheck: {
      // Reverb serves no health route. A plain GET / gets a protocol-level refusal
      // rather than a 200, so the check asserts "something is listening and
      // answering HTTP", which is all a WebSocket server can honestly report.
      path: "/",
      matcher: "200-499",
      interval: 30,
      timeout: 5,
      healthyThreshold: 2,
      unhealthyThreshold: 3,
    },
  });

  new aws.lb.Listener(environment.resourceName("http-listener"), {
    loadBalancerArn: loadBalancer.arn,
    port: 80,
    protocol: "HTTP",
    defaultActions: [{
      type: "redirect",
      redirect: { protocol: "HTTPS", port: "443", statusCode: "HTTP_301" },
    }],
  });

  const { edgeEnabled } = environment.settings;

  /*
   * With CloudFront in front, the default action is a refusal and every route to
   * a target group requires the shared origin header.
   *
   * Expressing it this way round is forced by the ALB: listener rules match
   * conditions positively, so there is no "reject requests *without* this
   * header". Making the header a condition on each forwarding rule, and letting
   * anything unmatched fall through to a fixed 403, is the same policy stated in
   * the only grammar available.
   */
  const originVerified: aws.types.input.lb.ListenerRuleCondition[] = edgeEnabled
    ? [{
      httpHeader: {
        httpHeaderName: ORIGIN_VERIFY_HEADER,
        values: [requireOriginVerifySecret(config)],
      },
    }]
    : [];

  const httpsListener = new aws.lb.Listener(environment.resourceName("https-listener"), {
    loadBalancerArn: loadBalancer.arn,
    port: 443,
    protocol: "HTTPS",
    // TLS 1.2 floor; the 1.3 policy still negotiates 1.2 for older clients.
    sslPolicy: "ELBSecurityPolicy-TLS13-1-2-2021-06",
    certificateArn: certificate.arn,
    defaultActions: edgeEnabled
      ? [{
        type: "fixed-response",
        fixedResponse: {
          contentType: "text/plain",
          statusCode: "403",
          messageBody: "Direct origin access is not permitted.",
        },
      }]
      : [{ type: "forward", targetGroupArn: webTargetGroup.arn }],
  });

  // /app/* is the Pusher-protocol path Reverb serves, so this rule has to win over
  // the web rule below. Lower priority number = evaluated first.
  new aws.lb.ListenerRule(environment.resourceName("reverb-rule"), {
    listenerArn: httpsListener.arn,
    priority: 10,
    conditions: [{ pathPattern: { values: ["/app/*"] } }, ...originVerified],
    actions: [{ type: "forward", targetGroupArn: reverbTargetGroup.arn }],
  });

  if (edgeEnabled) {
    // Without the edge this is the listener's default action, so the rule would
    // be redundant; with it, this is what turns a verified request into traffic.
    new aws.lb.ListenerRule(environment.resourceName("web-rule"), {
      listenerArn: httpsListener.arn,
      priority: 20,
      conditions: originVerified,
      actions: [{ type: "forward", targetGroupArn: webTargetGroup.arn }],
    });
  }

  return { loadBalancer, certificate, httpsListener, webTargetGroup, reverbTargetGroup };
}
