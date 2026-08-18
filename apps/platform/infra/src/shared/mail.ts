import * as aws from "@pulumi/aws";
import * as pulumi from "@pulumi/pulumi";
import { PlatformConfig } from "../config.js";

export interface Mail {
  domainIdentity: aws.ses.DomainIdentity;
  dkimTokens: pulumi.Output<string[]>;
  /** Attached to the task role only — never to the shared app-access policy. */
  sendPolicy: aws.iam.Policy;
}

/**
 * SES as the production mailer, with DKIM on the sending domain.
 *
 * The send permission is a **separate policy attached only to the ECS task role**,
 * and not added to the shared managed policy in `policies.ts`. That file argues at
 * length that the local IAM user and the task role must carry identical
 * permissions, and the argument is right — for OCR and Transcribe, where a gap
 * would surface in production and nowhere else.
 *
 * Mail inverts it. Local development sends to Mailpit, so there is no gap to
 * discover; what parity would actually buy is a long-lived access key on a laptop
 * that can send mail as the company domain. The rule exists to stop production
 * being *less* capable than local, not to require local to be as dangerous as
 * production.
 *
 * The `ses:FromAddress` condition narrows it further: even the task role can only
 * send as the configured envelope sender.
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
    description: "SES send for the deployed application container only",
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
