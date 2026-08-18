/**
 * Pipeline domain types. Payload shapes are generated from the PHP Data objects.
 */

/** Wire payload of the workspace `pipeline.updated` broadcast. */
export type PipelineUpdatedPayload =
    App.Pipelines.Data.PipelineUpdatedPayloadData;

export type PipelineStatus = App.Pipelines.Enums.PipelineStatus;

export type PipelineStepStatus = App.Pipelines.Enums.PipelineStepStatus;

export type PipelineIndexRow = App.Pipelines.Data.PipelineIndexRowData;

export type PipelineSubject = App.Pipelines.Data.PipelineSubjectData;

export type PipelineStepDetail = App.Pipelines.Data.PipelineStepData;

export type PipelineDetail = App.Pipelines.Data.PipelineShowData;
