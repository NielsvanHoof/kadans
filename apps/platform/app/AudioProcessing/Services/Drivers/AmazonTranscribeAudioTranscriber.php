<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Drivers;

use App\AudioProcessing\Contracts\StartsAudioTranscription;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use Aws\TranscribeService\TranscribeServiceClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

use function array_filter;
use function array_values;
use function hash;
use function is_array;
use function is_string;

/**
 * Starts async Amazon Transcribe for audio already on S3.
 * Completion arrives later via EventBridge → SQS → CompleteTranscribeJobAction.
 */
final readonly class AmazonTranscribeAudioTranscriber implements StartsAudioTranscription
{
    public function __construct(
        private TranscribeServiceClient $transcribe,
        private Repository $config,
    ) {}

    public function start(AudioRecording $recording): void
    {
        $bucket = $this->config->get('transcription.transcribe.bucket');
        $languageOptions = $this->config->get('transcription.transcribe.language_options', ['nl-NL', 'en-US']);
        $maxSpeakers = max(2, (int) $this->config->get('transcription.transcribe.max_speaker_labels', 10));
        $outputPrefix = mb_trim((string) $this->config->get('transcription.transcribe.output_prefix', 'transcriptions'), '/');

        throw_if(! is_string($bucket) || $bucket === '', InvalidArgumentException::class, 'Amazon Transcribe bucket is not configured.');
        throw_if(! is_array($languageOptions), InvalidArgumentException::class, 'Amazon Transcribe language options are not configured.');
        $languageOptions = array_values(array_filter($languageOptions, static fn (mixed $language): bool => is_string($language) && $language !== ''));
        throw_if($languageOptions === [], InvalidArgumentException::class, 'Amazon Transcribe language options are empty.');

        // Job name must be unique per StartTranscriptionJob; include path hash for revisions.
        $jobName = "audio-{$recording->id}-".mb_substr(hash('sha256', $recording->path), 0, 16);
        $outputKey = "$outputPrefix/{$recording->tenant_id}/{$recording->id}/$jobName.json";

        Log::info('Transcription: starting Amazon Transcribe StartTranscriptionJob.', [
            'audio_recording_id' => $recording->id,
            'tenant_id' => $recording->tenant_id,
            'bucket' => $bucket,
            's3_key' => $recording->path,
            'job_name' => $jobName,
            'language_options' => $languageOptions,
        ]);

        $this->transcribe->startTranscriptionJob([
            'TranscriptionJobName' => $jobName,
            'IdentifyLanguage' => true,
            'LanguageOptions' => $languageOptions,
            'Media' => [
                'MediaFileUri' => "s3://{$bucket}/{$recording->path}",
            ],
            'OutputBucketName' => $bucket,
            'OutputKey' => $outputKey,
            'Settings' => [
                'ShowSpeakerLabels' => true,
                'MaxSpeakerLabels' => $maxSpeakers,
            ],
        ]);

        $updated = AudioRecording::query()
            ->whereKey($recording->id)
            ->where('path', $recording->path)
            ->where('transcription_status', TranscriptionStatus::Processing->value)
            ->update([
                'transcribe_job_id' => $jobName,
                'transcript' => null,
                'segments' => null,
                'transcription_error' => null,
                'transcription_finished_at' => null,
            ]);

        if ($updated !== 1) {
            Log::warning('Transcription: discarded Transcribe job id for a stale upload revision.', [
                'audio_recording_id' => $recording->id,
                'tenant_id' => $recording->tenant_id,
                'transcribe_job_id' => $jobName,
                'expected_path' => $recording->path,
            ]);

            return;
        }

        $recording->refresh();

        throw_if($recording->transcribe_job_id !== $jobName, RuntimeException::class, 'Failed to persist Amazon Transcribe job id.');

        Log::info('Transcription: Amazon Transcribe job started — awaiting EventBridge/SQS completion.', [
            'audio_recording_id' => $recording->id,
            'tenant_id' => $recording->tenant_id,
            'transcribe_job_id' => $jobName,
        ]);
    }
}
