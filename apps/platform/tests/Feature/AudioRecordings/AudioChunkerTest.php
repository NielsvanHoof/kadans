<?php

declare(strict_types=1);

use App\AudioProcessing\Contracts\ChunksAudio;
use App\AudioProcessing\Services\Audio\FfmpegAudioChunker;
use Illuminate\Support\Facades\Process;

test('chunker keeps a short recording as a single normalized chunk', function (): void {
    $workDirectory = sys_get_temp_dir().'/audio-chunker-short-'.uniqid('', true);
    mkdir($workDirectory);

    $source = $workDirectory.'/source.mp3';

    // 5-second sine wave — well under the default 120s split threshold.
    Process::timeout(60)->run([
        'ffmpeg', '-y',
        '-f', 'lavfi',
        '-i', 'sine=frequency=440:duration=5',
        '-ac', '1',
        '-ar', '16000',
        $source,
    ])->throw();

    config([
        'transcription.chunking.min_duration_seconds' => 120,
        'transcription.chunking.max_chunk_seconds' => 90,
    ]);

    $chunks = resolve(FfmpegAudioChunker::class)->chunk($source, $workDirectory.'/out');

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->index)->toBe(0)
        ->and($chunks[0]->startSeconds)->toBe(0.0)
        ->and($chunks[0]->durationSeconds)->toBeLessThan(6.0)
        ->and($chunks[0]->path)
        ->toBeFile();

    // Cleanup
    foreach (glob($workDirectory.'/**/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
})->group('ffmpeg');

test('chunker splits a long recording into max-sized slices', function (): void {
    $workDirectory = sys_get_temp_dir().'/audio-chunker-long-'.uniqid('', true);
    mkdir($workDirectory);

    $source = $workDirectory.'/source.mp3';

    // 25-second tone with a 10s max chunk and 15s min-duration threshold → 3 chunks.
    Process::timeout(60)->run([
        'ffmpeg', '-y',
        '-f', 'lavfi',
        '-i', 'sine=frequency=440:duration=25',
        '-ac', '1',
        '-ar', '16000',
        $source,
    ])->throw();

    config([
        'transcription.chunking.min_duration_seconds' => 15,
        'transcription.chunking.max_chunk_seconds' => 10,
    ]);

    $chunks = resolve(ChunksAudio::class)->chunk($source, $workDirectory.'/out');

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]->startSeconds)->toBe(0.0)
        ->and($chunks[1]->startSeconds)->toBe(10.0)
        ->and($chunks[2]->startSeconds)->toBe(20.0)
        ->and($chunks[2]->durationSeconds)->toBeLessThanOrEqual(10.0);

    foreach ($chunks as $chunk) {
        expect($chunk->path)
            ->toBeFile()
            ->and(filesize($chunk->path))->toBeGreaterThan(0);
    }
})->group('ffmpeg');
