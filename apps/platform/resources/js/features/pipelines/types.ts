/**
 * Pipeline domain types. Payload shapes are generated from the PHP Data objects.
 */

/** Wire payload of the workspace `pipeline.updated` broadcast. */
export type PipelineUpdatedPayload =
    App.DocumentProcessing.Data.PipelineUpdatedPayloadData;

export type PipelineStatus = App.DocumentProcessing.Enums.PipelineStatus;

export type PipelineStepStatus =
    App.DocumentProcessing.Enums.PipelineStepStatus;

export type PipelineIndexRow = App.DocumentProcessing.Data.PipelineIndexRowData;

export type PipelineSubject = App.DocumentProcessing.Data.PipelineSubjectData;

export type PipelineStepDetail = App.DocumentProcessing.Data.PipelineStepData;

export type PipelineDetail = App.DocumentProcessing.Data.PipelineShowData;
