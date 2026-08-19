import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "../config.js";

export interface Network {
  vpc: aws.ec2.Vpc;
  cidrBlock: string;
  publicSubnetIds: pulumi.Output<string>[];
  loadBalancerSecurityGroup: aws.ec2.SecurityGroup;
  taskSecurityGroup: aws.ec2.SecurityGroup;
  databaseSecurityGroup: aws.ec2.SecurityGroup;
  cacheSecurityGroup: aws.ec2.SecurityGroup;
}

const VPC_CIDR = "10.0.0.0/16";

/**
 * Two public subnets, no NAT gateway, no private subnets.
 *
 * This is the single biggest cost decision in the stack and the one most likely
 * to be "corrected" by someone reading it later, so the reasoning belongs here.
 *
 * The textbook layout puts tasks in private subnets and buys a NAT gateway per AZ
 * so they can reach ECR, Secrets Manager and SES. At roughly €35/month each plus
 * per-GB processing, that is more than the database and the cache combined — for
 * a stack whose entire point is to be lean. VPC endpoints are the other way out,
 * but you need four or five of them (ecr.api, ecr.dkr, s3, secretsmanager, logs)
 * at ~€7/month each, which lands in the same place.
 *
 * So confinement moves from topology to security groups. A task gets a public IP,
 * but the only thing that may open a connection *to* it on 8080 is the load
 * balancer's security group — not a CIDR, the group itself, so it stays true as
 * addresses change. The database and the cache accept only from the task group.
 * Nothing in this VPC accepts a connection from the internet except the ALB.
 *
 * What is genuinely given up: a misconfigured security group is now an exposure
 * rather than an inconvenience, and egress is unfiltered. That is the trade. If
 * this ever holds something that must not be able to phone out, buy the NAT.
 *
 * Two AZs is a floor, not a preference — an ALB requires subnets in at least two,
 * and an RDS subnet group spanning two is a prerequisite for `multiAz`.
 */
export function createNetwork(config: PlatformConfig): Network {
  const { environment } = config;
  const zones = aws.getAvailabilityZonesOutput({ state: "available" });

  const vpc = new aws.ec2.Vpc(environment.resourceName("vpc"), {
    cidrBlock: VPC_CIDR,
    enableDnsHostnames: true,
    // Required for the RDS and ElastiCache endpoint names to resolve inside the VPC.
    enableDnsSupport: true,
    tags: { Name: environment.resourceName("vpc") },
  });

  const internetGateway = new aws.ec2.InternetGateway(
    environment.resourceName("igw"),
    { vpcId: vpc.id, tags: { Name: environment.resourceName("igw") } },
  );

  const routeTable = new aws.ec2.RouteTable(environment.resourceName("public-routes"), {
    vpcId: vpc.id,
    routes: [{ cidrBlock: "0.0.0.0/0", gatewayId: internetGateway.id }],
    tags: { Name: environment.resourceName("public-routes") },
  });

  const publicSubnetIds = [0, 1].map((index) => {
    const subnet = new aws.ec2.Subnet(environment.resourceName(`public-${index}`), {
      vpcId: vpc.id,
      // /20 per subnet: room for tasks to scale without ever renumbering the VPC.
      cidrBlock: `10.0.${index * 16}.0/20`,
      availabilityZone: zones.names[index],
      mapPublicIpOnLaunch: true,
      tags: { Name: environment.resourceName(`public-${index}`) },
    });

    new aws.ec2.RouteTableAssociation(
      environment.resourceName(`public-${index}-routes`),
      { subnetId: subnet.id, routeTableId: routeTable.id },
    );

    return subnet.id;
  });

  const loadBalancerSecurityGroup = new aws.ec2.SecurityGroup(
    environment.resourceName("alb-sg"),
    {
      vpcId: vpc.id,
      description: environment.settings.edgeEnabled
        ? "CloudFront-only ingress for the platform load balancer"
        : "Public HTTP/HTTPS ingress for the platform load balancer",
      ingress: loadBalancerIngress(environment.settings.edgeEnabled),
      egress: [allowAllEgress("Forward to the task security group")],
      tags: { Name: environment.resourceName("alb-sg") },
    },
  );

  const taskSecurityGroup = new aws.ec2.SecurityGroup(
    environment.resourceName("task-sg"),
    {
      vpcId: vpc.id,
      description: "Fargate tasks; reachable only from the load balancer",
      ingress: [{
        protocol: "tcp",
        fromPort: 8080,
        toPort: 8080,
        // The group, not its addresses. Tasks have public IPs here, so a CIDR
        // rule would be the difference between "the ALB" and "the internet".
        securityGroups: [loadBalancerSecurityGroup.id],
        description: "Octane web and Reverb, from the load balancer only",
      }],
      egress: [allowAllEgress("ECR, Secrets Manager, SES, S3, Bedrock — no NAT, so direct")],
      tags: { Name: environment.resourceName("task-sg") },
    },
  );

  const databaseSecurityGroup = new aws.ec2.SecurityGroup(
    environment.resourceName("database-sg"),
    {
      vpcId: vpc.id,
      description: "Postgres; reachable only from the application tasks",
      ingress: [{
        protocol: "tcp",
        fromPort: 5432,
        toPort: 5432,
        securityGroups: [taskSecurityGroup.id],
        description: "Postgres from Fargate tasks",
      }],
      tags: { Name: environment.resourceName("database-sg") },
    },
  );

  const cacheSecurityGroup = new aws.ec2.SecurityGroup(
    environment.resourceName("cache-sg"),
    {
      vpcId: vpc.id,
      description: "Valkey; reachable only from the application tasks",
      ingress: [{
        protocol: "tcp",
        fromPort: 6379,
        toPort: 6379,
        securityGroups: [taskSecurityGroup.id],
        description: "Valkey from Fargate tasks",
      }],
      tags: { Name: environment.resourceName("cache-sg") },
    },
  );

  return {
    vpc,
    cidrBlock: VPC_CIDR,
    publicSubnetIds,
    loadBalancerSecurityGroup,
    taskSecurityGroup,
    databaseSecurityGroup,
    cacheSecurityGroup,
  };
}

/**
 * Who may reach the load balancer.
 *
 * With CloudFront in front, the origin should not be addressable directly —
 * otherwise the WAF, the TLS policy and the edge caching are all one `curl` at
 * the ALB's DNS name away from being bypassed.
 *
 * AWS publishes a managed prefix list of the addresses CloudFront uses to reach
 * origins, which narrows this to "some CloudFront distribution". That is most of
 * the job but not all of it: the list covers *everyone's* distributions, so
 * anyone with a CloudFront account is inside it. The shared origin header added
 * in `edge.ts` and required by the listener rules in `loadBalancer.ts` is what
 * narrows it the rest of the way to ours. Neither control is sufficient alone,
 * which is why both are here.
 *
 * Port 80 is not opened in this mode: CloudFront talks HTTPS to the origin, and
 * the redirect that made a plain-HTTP listener useful happens at the edge.
 */
function loadBalancerIngress(edgeEnabled: boolean): aws.types.input.ec2.SecurityGroupIngress[] {
  if (edgeEnabled) {
    const cloudFront = aws.ec2.getManagedPrefixListOutput({
      name: "com.amazonaws.global.cloudfront.origin-facing",
    });

    return [{
      protocol: "tcp",
      fromPort: 443,
      toPort: 443,
      prefixListIds: [cloudFront.id],
      description: "HTTPS from CloudFront origin-facing ranges only",
    }];
  }

  return [{
    protocol: "tcp",
    fromPort: 80,
    toPort: 80,
    cidrBlocks: ["0.0.0.0/0"],
    ipv6CidrBlocks: ["::/0"],
    description: "HTTP, redirected to HTTPS by the listener",
  }, {
    protocol: "tcp",
    fromPort: 443,
    toPort: 443,
    cidrBlocks: ["0.0.0.0/0"],
    ipv6CidrBlocks: ["::/0"],
    description: "HTTPS",
  }];
}

function allowAllEgress(description: string): aws.types.input.ec2.SecurityGroupEgress {
  return {
    protocol: "-1",
    fromPort: 0,
    toPort: 0,
    cidrBlocks: ["0.0.0.0/0"],
    ipv6CidrBlocks: ["::/0"],
    description,
  };
}
