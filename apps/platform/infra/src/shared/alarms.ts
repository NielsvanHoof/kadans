import * as aws from "@pulumi/aws";
import type { PlatformConfig } from "../config.js";
import type { DocumentInfrastructure } from "./documents.js";

export function createOcrAlarms(
  config: PlatformConfig,
  documents: DocumentInfrastructure,
  topic?: aws.sns.Topic,
): void {
  const { environment } = config;

  if (topic === undefined) {
    return;
  }

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
}
