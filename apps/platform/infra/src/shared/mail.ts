import * as aws from "@pulumi/aws";
import type * as pulumi from "@pulumi/pulumi";
import type { PlatformConfig } from "../config.js";

export interface Mail {
  domainIdentity: aws.ses.DomainIdentity;
  dkimTokens: pulumi.Output<string[]>;
  /**
   * Attached to the application IAM user (Sail / Laravel Cloud credentials).
   *
   * Kept as a separate policy so OCR/Transcribe access and mail send stay
   * independently reviewable — and so stacks without `applicationUserAccess`
   * can still create the SES identity for DKIM without minting a send grant.
   */
  sendPolicy: aws.iam.Policy;
}

/**
 * SES as the application mailer, with DKIM on the sending domain.
 *
 * Always created (TASK-43.3): Laravel Cloud sends through SES with the shared
 * application IAM user, so mail is no longer gated on ECS compute. The send
 * permission is a **separate policy** attached to that IAM user in `index.ts`,
 * not folded into the shared OCR access policy — a laptop key that can also
 * send as the company domain is a deliberate, reviewable grant.
 *
 * The `ses:FromAddress` condition narrows it further: the user can only send as
 * the configured envelope sender.
 */
export function createMail(config: PlatformConfig): Mail {
  const { environment, mailFromAddress } = config;
  const sendingDomain = mailFromAddress.split("@")[1];

  const domainIdentity = new aws.ses.DomainIdentity(
    environment.resourceName("mail-domain"),
    { domain: sendingDomain },
  );

  const dkim = new aws.ses.DomainDkim(environment.resourceName("mail-dkim"), {
    domain: domainIdentity.domain,
  });

  const sendPolicy = new aws.iam.Policy(environment.resourceName("mail-send"), {
    name: environment.resourceName("mail-send"),
    path: "/qompose/",
    description: "SES send for the Sail / Laravel Cloud application IAM user",
    policy: aws.iam.getPolicyDocumentOutput({
      statements: [{
        sid: "SendApplicationMail",
        actions: ["ses:SendEmail", "ses:SendRawEmail"],
        resources: [domainIdentity.arn],
        conditions: [{
          test: "StringEquals",
          variable: "ses:FromAddress",
          values: [mailFromAddress],
        }],
      }],
    }).json,
  });

  return { domainIdentity, dkimTokens: dkim.dkimTokens, sendPolicy };
}
