import * as aws from "@pulumi/aws";
import { PlatformConfig } from "../config.js";

export interface ContainerRegistry {
  repository: aws.ecr.Repository;
}

/**
 * ECR repository for the deployed application image.
 *
 * `IMMUTABLE` tags are the point. CI already builds one artifact per commit with
 * an SBOM and provenance (`.github/workflows/platform.yml`), and the ECS task
 * definition pins a specific tag via `imageTag` config — none of which means
 * anything if `:v1.4.2` can be repointed at different bytes tomorrow. Immutability
 * is what makes "roll back to that tag" a true statement.
 */
export function createContainerRegistry(
  config: PlatformConfig,
  encryptionKey: aws.kms.Key,
): ContainerRegistry {
  const { environment } = config;

  const repository = new aws.ecr.Repository(environment.resourceName("app"), {
    name: environment.resourceName("app"),
    imageTagMutability: "IMMUTABLE",
    imageScanningConfiguration: { scanOnPush: true },
    encryptionConfigurations: [{
      encryptionType: "KMS",
      kmsKey: encryptionKey.arn,
    }],
    forceDelete: !environment.settings.deletionProtection,
  });

  new aws.ecr.LifecyclePolicy(environment.resourceName("app-lifecycle"), {
    repository: repository.name,
    policy: JSON.stringify({
      rules: [{
        rulePriority: 1,
        description: "Keep the last 20 images; older ones are recoverable from CI",
        selection: {
          tagStatus: "any",
          countType: "imageCountMoreThan",
          countNumber: 20,
        },
        action: { type: "expire" },
      }],
    }),
  });

  return { repository };
}
