---
paths:
  - 'app/**/Contracts/**'
---

# Contracts

## Contracts are Laravel role nouns
Name a contract for what the collaborator is, not a verb phrase: AudioTranscriber, DocumentOcr, DocumentOverviewNormalizer, ReadinessCheck, PipelineRunner — like Laravel's Mailer/Hasher/EventPusher. Use -able or Should* only when the implementer is that thing (Authenticatable on User, ShouldQueue on a job). Transcribable would belong on AudioRecording, not the driver. Do not suffix Interface, prefix Abstract, or suffix Trait.
