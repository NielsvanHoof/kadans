<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Drivers;

use App\AudioProcessing\Contracts\ChunksAudio;
use App\AudioProcessing\Contracts\TranscribesAudio;
use App\AudioProcessing\Data\AudioChunk;
use App\AudioProcessing\Data\TranscriptionResult;
use App\AudioProcessing\Services\Audio\TranscriptionResultMerger;
use App\Dossiers\Models\AudioRecording;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Transcription;
use RuntimeException;
use Throwable;

use function count;
use function is_resource;

/**
 * Real speech-to-text via the Laravel AI SDK. Which provider (OpenAI, ElevenLabs,
 * Mistral, Gemini) is used is decided by config/ai.php's `default_for_transcription`,
 * not here — this driver only shapes our own request/response boundary.
 *
 * Long files are normalized and split locally (ffmpeg)
 */
final readonly class LaravelAiAudioTranscriber implements TranscribesAudio
{
    public function __construct(
        private ChunksAudio $chunker,
        private TranscriptionResultMerger $merger,
    ) {}

    public function transcribe(AudioRecording $recording): TranscriptionResult
    {
        $workDirectory = storage_path('app/transcription-tmp/'.Str::uuid()->toString());

        try {
            throw_if(! mkdir($workDirectory, 0755, true) && ! is_dir($workDirectory), RuntimeException::class, "Unable to create transcription work directory [{$workDirectory}].");

            $sourcePath = $this->downloadToLocal($recording, $workDirectory);
            $chunkingEnabled = (bool) config('transcription.chunking.enabled', true);

            $chunks = $chunkingEnabled
                ? $this->chunker->chunk($sourcePath, $workDirectory)
                : [
                    new AudioChunk(
                        path: $sourcePath,
                        startSeconds: 0.0,
                        durationSeconds: 0.0,
                        index: 0,
                    ),
                ];

            Log::info('Transcription: local chunks ready for provider.', [
                'audio_recording_id' => $recording->id,
                'tenant_id' => $recording->tenant_id,
                'chunk_count' => count($chunks),
                'chunking_enabled' => $chunkingEnabled,
            ]);

            $parts = [];

            foreach ($chunks as $chunk) {
                $parts[] = [
                    'chunk' => $chunk,
                    'result' => $this->transcribeChunk($chunk),
                ];
            }

            return $this->merger->merge($parts);
        } finally {
            $this->deleteDirectory($workDirectory);
        }
    }

    private function downloadToLocal(AudioRecording $recording, string $workDirectory): string
    {
        $extension = pathinfo($recording->path, PATHINFO_EXTENSION);
        $extension = $extension !== '' ? $extension : 'bin';
        $localPath = $workDirectory.DIRECTORY_SEPARATOR.'source.'.$extension;

        $stream = Storage::disk($recording->disk)->readStream($recording->path);

        throw_unless(is_resource($stream), RuntimeException::class, "Unable to read audio [{$recording->path}] from disk [{$recording->disk}].");

        $target = fopen($localPath, 'wb');

        throw_unless(is_resource($target), RuntimeException::class, "Unable to open local path [{$localPath}] for writing.");

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        return $localPath;
    }

    private function transcribeChunk(AudioChunk $chunk): TranscriptionResult
    {
        $httpTimeout = (int) config('transcription.timeouts.http', 270);

        $response = Transcription::fromPath($chunk->path)
            ->diarize()
            // OpenAI's diarized transcription endpoint 400s without this — it's not implied by ->diarize().
            ->withProviderOptions(['chunking_strategy' => 'auto'])
            // Keep this under the job's own timeout with headroom for download + ffmpeg + merge.
            ->timeout($httpTimeout)
            ->generate();

        /** @var list<array{text: string, speaker: string, start_seconds: float, end_seconds: float}> $segments */
        $segments = $response->segments
            ->map(fn (TranscriptionSegment $segment): array => [
                'text' => $segment->text,
                'speaker' => $segment->speaker,
                'start_seconds' => $segment->startSeconds,
                'end_seconds' => $segment->endSeconds,
            ])
            ->values()
            ->all();

        return new TranscriptionResult(
            text: $response->text,
            segments: $segments,
        );
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        try {
            $entries = scandir($directory);

            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry === '.') {
                    continue;
                }
                if ($entry === '..') {
                    continue;
                }
                $path = $directory.DIRECTORY_SEPARATOR.$entry;

                if (is_dir($path)) {
                    $this->deleteDirectory($path);

                    continue;
                }

                @unlink($path);
            }

            @rmdir($directory);
        } catch (Throwable) {
            // Best-effort cleanup — leftover tmp dirs are cheaper than failing the job.
        }
    }
}
