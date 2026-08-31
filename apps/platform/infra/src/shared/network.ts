import * as aws from "@pulumi/aws";
import type * as pulumi from "@pulumi/pulumi";
import type { PlatformConfig } from "../config.js";
import type { EnvironmentSettings } from "../environments/settings.js";

export interface Network {
  vpc: aws.ec2.Vpc;
  cidrBlock: string;
  publicSubnetIds: pulumi.Output<string>[];
  databaseSecurityGroup: aws.ec2.SecurityGroup;
}

const VPC_CIDR = "10.0.0.0/16";

/**
 * Two public subnets and a database security group — no NAT, no ALB/task groups.
 *
 * Application compute lives on Laravel Cloud (TASK-43.3), so this VPC exists only
 * to host a publicly reachable RDS instance. Confinement is the database security
 * group's CIDR allowlist (Laravel Cloud egress), not private subnets.
 *
 * Resource logical names (`vpc`, `igw`, `public-routes`, `public-0`, `public-1`,
 * `database-sg`) must stay byte-identical: Pulumi keys state by them, and a rename
 * reads as delete-then-create, which would destroy the database.
 *
 * Two AZs is a floor — an RDS subnet group spanning two is a prerequisite for
 * Multi-AZ.
 */
export function createNetwork(config: PlatformConfig): Network {
  const { environment } = config;
  const zones = aws.getAvailabilityZonesOutput({ state: "available" });

  const vpc = new aws.ec2.Vpc(environment.resourceName("vpc"), {
    cidrBlock: VPC_CIDR,
    enableDnsHostnames: true,
    // Required for the RDS endpoint name to resolve inside the VPC.
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
      // /20 per subnet: room to grow without renumbering the VPC.
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

  const databaseSecurityGroup = new aws.ec2.SecurityGroup(
    environment.resourceName("database-sg"),
    {
      vpcId: vpc.id,
      description: "Postgres; reachable from Laravel Cloud egress CIDRs",
      ingress: databaseIngress(environment.settings),
      tags: { Name: environment.resourceName("database-sg") },
    },
  );

  return {
    vpc,
    cidrBlock: VPC_CIDR,
    publicSubnetIds,
    databaseSecurityGroup,
  };
}

/**
 * Who may open a Postgres connection.
 *
 * Public databases (Laravel Cloud) accept only the committed Cloud egress CIDRs.
 */
function databaseIngress(
  settings: EnvironmentSettings,
): aws.types.input.ec2.SecurityGroupIngress[] {
  if (!settings.databasePubliclyAccessible) {
    throw new Error(
      "Data-plane stacks require databasePubliclyAccessible; private ECS databases were removed in TASK-43.3.",
    );
  }

  if (settings.databaseIngressCidrs.length === 0) {
    throw new Error(
      "databasePubliclyAccessible requires a non-empty databaseIngressCidrs allowlist.",
    );
  }

  return [{
    protocol: "tcp",
    fromPort: 5432,
    toPort: 5432,
    cidrBlocks: [...settings.databaseIngressCidrs],
    description: "Postgres from Laravel Cloud egress",
  }];
}
