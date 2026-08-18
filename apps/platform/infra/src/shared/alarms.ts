import * as aws from "@pulumi/aws";
import { PlatformConfig } from "../config.js";
import { DocumentInfrastructure } from "./documents.js";

export interface OcrAlarms {
  /** Absent when no alarm email is configured, so there is nothing to notify. */
  topic?: aws.sns.Topic;
}

/**
 * Optional alarms when OCR / Transcribe results land on a dead-letter queue.
 *
 * The alarm SNS topic uses the same OCR CMK as the messaging plane so CloudWatch
 * can publish encrypted notifications (key policy already grants cloudwatch.amazonaws.com).
 */
export function createOcrAlarms(
  config: PlatformConfig,
  documents: DocumentInfrastructure,
  encryptionKey: aws.kms.Key,
): OcrAlarms {
  const { environment, alarmEmailAddress } = config;

  if (!alarmEmailAddress) {
    return {};
  }

  const topic = new aws.sns.Topic(environment.resourceName("ocr-alarms"), {
    name: environment.resourceName("ocr-alarms"),
    kmsMasterKeyId: encryptionKey.arn,
  });
  new aws.sns.TopicSubscription(environment.resourceName("ocr-alarms-email"), {
    topic: topic.arn,
    protocol: "email",
    endpoint: alarmEmailAddress,
  });

  /*
   * CloudWatch may publish here by default; EventBridge may not. The malware
   * findings rule targets this topic, and without an explicit grant the rule
   * matches, fires, and silently fails to deliver — which is the worst possible
   * failure mode for the one alert that says a client uploaded malware.
   */
  new aws.sns.TopicPolicy(environment.resourceName("ocr-alarms-policy"), {
    arn: topic.arn,
    policy: aws.iam.getPolicyDocumentOutput({
      statements: [{
        sid: "AllowEventBridgeAndCloudWatchPublish",
        effect: "Allow",
        principals: [{
          type: "Service",
          identifiers: ["events.amazonaws.com", "cloudwatch.amazonaws.com"],
        }],
        actions: ["sns:Publish"],
        resources: [topic.arn],
        conditions: [{
          test: "StringEquals",
          variable: "aws:SourceAccount",
          values: [aws.getCallerIdentityOutput().accountId],
        }],
      }],
    }).json,
  });

  new aws.cloudwatch.MetricAlarm(environment.resourceName("textract-dlq-alarm"), {
    name: environment.resourceName("textract-dlq-alarm"),
    alarmDescription: "Textract OCR results landed on the dead-letter queue",
    namespace: "AWS/SQS",
    metricName: "ApproximateNumberOfMessagesVisible",
    dimensions: { QueueName: documents.resultsDeadLetterQueue.name },
    statistic: "Maximum",
    period: 300,
    evaluationPeriods: 1,
    threshold: 1,
    comparisonOperator: "GreaterThanOrEqualToThreshold",
    treatMissingData: "notBreaching",
    alarmActions: [topic.arn],
  });

  new aws.cloudwatch.MetricAlarm(environment.resourceName("transcribe-dlq-alarm"), {
    name: environment.resourceName("transcribe-dlq-alarm"),
    alarmDescription: "Amazon Transcribe results landed on the dead-letter queue",
    namespace: "AWS/SQS",
    metricName: "ApproximateNumberOfMessagesVisible",
    dimensions: { QueueName: documents.transcribeResultsDeadLetterQueue.name },
    statistic: "Maximum",
    period: 300,
    evaluationPeriods: 1,
    threshold: 1,
    comparisonOperator: "GreaterThanOrEqualToThreshold",
    treatMissingData: "notBreaching",
    alarmActions: [topic.arn],
  });

  // Delivery failures never reach the consumer redrive DLQ — alarm those too.
  new aws.cloudwatch.MetricAlarm(environment.resourceName("transcribe-delivery-dlq-alarm"), {
    name: environment.resourceName("transcribe-delivery-dlq-alarm"),
    alarmDescription: "EventBridge failed to deliver Transcribe events to the results queue",
    namespace: "AWS/SQS",
    metricName: "ApproximateNumberOfMessagesVisible",
    dimensions: { QueueName: documents.transcribeDeliveryDeadLetterQueue.name },
    statistic: "Maximum",
    period: 300,
    evaluationPeriods: 1,
    threshold: 1,
    comparisonOperator: "GreaterThanOrEqualToThreshold",
    treatMissingData: "notBreaching",
    alarmActions: [topic.arn],
  });

  return { topic };
}
