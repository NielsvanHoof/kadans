<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Audio;

use App\AudioProcessing\Contracts\ChunksAudio;
use App\AudioProcessing\Data\AudioChunk;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use RuntimeException;

use function sprintf;

/**
 * Probes duration with ffprobe, normalizes to mono 16 kHz mp3, then splits
 * into fixed-length chunks when the recording exceeds the configured minimum.
 */
final class FfmpegAudioChunker implements ChunksAudio
{
    public function chunk(string $localPath, string $workDirectory): array
    {
        throw_unless(is_file($localPath), RuntimeException::class, "Audio file not found at [{$localPath}].");

        throw_if(! is_dir($workDirectory) && ! mkdir($workDirectory, 0755, true) && ! is_dir($workDirectory), RuntimeException::class, "Unable to create work directory [{$workDirectory}].");

        $durationSeconds = $this->probeDuration($localPath);
        $normalizedPath = $workDirectory.DIRECTORY_SEPARATOR.'normalized.mp3';

        // Mono 16 kHz keeps uploads small and matches what Whisper-style models expect.
        $this->runFfmpeg([
            '-y',
            '-i', $localPath,
            '-ac', '1',
            '-ar', '16000',
            '-c:a', 'libmp3lame',
            '-q:a', '5',
            $normalizedPath,
        ]);

        $minDuration = (float) config('transcription.chunking.min_duration_seconds', 120);
        $maxChunk = (float) config('transcription.chunking.max_chunk_seconds', 90);

        // Short recordings stay as a single chunk — no need to split.
        if ($durationSeconds <= $minDuration) {
            return [
                new AudioChunk(
                    path: $normalizedPath,
                    startSeconds: 0.0,
                    durationSeconds: $durationSeconds,
                    index: 0,
                ),
            ];
        }

        $chunks = [];
        $index = 0;
        $offset = 0.0;

        while ($offset < $durationSeconds) {
            $remaining = $durationSeconds - $offset;
            $sliceDuration = min($maxChunk, $remaining);
            $chunkPath = $workDirectory.DIRECTORY_SEPARATOR.sprintf('chunk_%03d.mp3', $index);

            // -ss before -i is a fast seek; we re-encode so cuts are clean.
            $this->runFfmpeg([
                '-y',
                '-ss', $this->formatSeconds($offset),
                '-t', $this->formatSeconds($sliceDuration),
                '-i', $normalizedPath,
                '-ac', '1',
                '-ar', '16000',
                '-c:a', 'libmp3lame',
                '-q:a', '5',
                $chunkPath,
            ]);

            $chunks[] = new AudioChunk(
                path: $chunkPath,
                startSeconds: $offset,
                durationSeconds: $sliceDuration,
                index: $index,
            );

            $offset += $sliceDuration;
            $index++;
        }

        return $chunks;
    }

    private function probeDuration(string $localPath): float
    {
        $binary = (string) config('transcription.chunking.ffprobe_binary', 'ffprobe');

        $result = Process::timeout(60)->run([
            $binary,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $localPath,
        ]);

        throw_if($result->failed(), ProcessFailedException::class, $result);

        $raw = mb_trim($result->output());
        $duration = is_numeric($raw) ? (float) $raw : 0.0;

        throw_if($duration <= 0.0, RuntimeException::class, "ffprobe returned an invalid duration for [{$localPath}].");

        return $duration;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runFfmpeg(array $arguments): void
    {
        $binary = (string) config('transcription.chunking.ffmpeg_binary', 'ffmpeg');

        $result = Process::timeout(300)->run([
            $binary,
            ...$arguments,
        ]);

        throw_if($result->failed(), ProcessFailedException::class, $result);
    }

    private function formatSeconds(float $seconds): string
    {
        return number_format($seconds, 3, '.', '');
    }
}
