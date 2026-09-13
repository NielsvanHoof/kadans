<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Drivers;

use App\AudioProcessing\Contracts\AudioTranscriber;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use Aws\TranscribeService\TranscribeServiceClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

use function get_debug_type;
use function hash;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Starts async Amazon Transcribe for audio already on S3.
 * Completion arrives later via EventBridge → SQS → CompleteTranscribeJobAction.
 */
final readonly class AmazonTranscribeAudioTranscriber implements AudioTranscriber
{
    /**
     * Every locale Amazon Transcribe accepts for `LanguageOptions`.
     *
     * Copied from the SDK's own API model — `LanguageCode` in
     * vendor/aws/aws-sdk-php/src/data/transcribe/2017-10-26/api-2.json.php —
     * rather than curated down to the locales this product ships in, so an
     * operator can still configure any locale the provider supports.
     *
     * Held as literals because that is what makes the check below a type
     * narrowing and not just a runtime assertion: `startTranscriptionJob()`
     * declares `LanguageOptions` as exactly this union, and a list built from
     * an env var cannot satisfy it until each value has been proven to be one
     * of these. Refresh alongside the SDK if AWS adds a locale.
     *
     * Intentionally carries no `@var`: a `list<string>` annotation here would
     * widen the literals straight back to `string` and undo the narrowing.
     */
    public const array SUPPORTED_LANGUAGES = [
        'ab-GE', 'af-ZA', 'am-ET', 'ar-AE', 'ar-SA', 'ast-ES',
        'az-AZ', 'ba-RU', 'be-BY', 'bg-BG', 'bn-IN', 'bs-BA',
        'ca-ES', 'ckb-IQ', 'ckb-IR', 'cs-CZ', 'cy-GB', 'cy-WL',
        'da-DK', 'de-CH', 'de-DE', 'el-GR', 'en-AB', 'en-AU',
        'en-GB', 'en-IE', 'en-IN', 'en-NZ', 'en-US', 'en-WL',
        'en-ZA', 'es-ES', 'es-MX', 'es-US', 'et-EE', 'et-ET',
        'eu-ES', 'fa-AF', 'fa-IR', 'fi-FI', 'fr-CA', 'fr-FR',
        'ga-IE', 'gd-GB', 'gl-ES', 'gu-IN', 'ha-NG', 'he-IL',
        'hi-IN', 'hr-HR', 'ht-HT', 'hu-HU', 'hy-AM', 'id-ID',
        'is-IS', 'it-IT', 'ja-JP', 'jv-ID', 'ka-GE', 'kab-DZ',
        'kk-KZ', 'km-KH', 'kn-IN', 'ko-KR', 'ky-KG', 'lg-IN',
        'lt-LT', 'lv-LV', 'mhr-RU', 'mi-NZ', 'mk-MK', 'ml-IN',
        'mn-MN', 'mr-IN', 'ms-MY', 'mt-MT', 'my-MM', 'ne-NP',
        'nl-NL', 'no-NO', 'or-IN', 'pa-IN', 'pl-PL', 'ps-AF',
        'pt-BR', 'pt-PT', 'ro-RO', 'ru-RU', 'rw-RW', 'si-LK',
        'sk-SK', 'sl-SI', 'so-SO', 'sq-AL', 'sr-RS', 'su-ID',
        'sv-SE', 'sw-BI', 'sw-KE', 'sw-RW', 'sw-TZ', 'sw-UG',
        'ta-IN', 'te-IN', 'th-TH', 'tl-PH', 'tr-TR', 'tt-RU',
        'ug-CN', 'uk-UA', 'uz-UZ', 'vi-VN', 'wo-SN', 'zh-CN',
        'zh-HK', 'zh-TW', 'zu-ZA',
    ];

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
        $languageOptions = $this->acceptedLanguages($languageOptions);
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

    /**
     * Prove every configured locale is one Amazon Transcribe accepts.
     *
     * These used to be filtered to "non-empty string" and passed straight on,
     * so `nl-NL1` in TRANSCRIBE_LANGUAGE_OPTIONS was only caught by AWS — once
     * per recording, asynchronously, after the upload had been accepted and the
     * pipeline had started, as an opaque provider error. Failing here turns a
     * configuration typo into an immediate, named error.
     *
     * @param  array<array-key, mixed>  $languages
     * @return list<value-of<self::SUPPORTED_LANGUAGES>>
     */
    private function acceptedLanguages(array $languages): array
    {
        $accepted = [];

        foreach ($languages as $language) {
            throw_unless(
                is_string($language) && in_array($language, self::SUPPORTED_LANGUAGES, true),
                InvalidArgumentException::class,
                sprintf(
                    'Amazon Transcribe does not accept the language [%s].',
                    is_string($language) ? $language : get_debug_type($language),
                ),
            );

            $accepted[] = $language;
        }

        return $accepted;
    }
}
