<?php

declare(strict_types=1);

use App\AudioProcessing\Services\Transcribe\TranscribeExtractionPipeline;
use Aws\Result;
use Aws\TranscribeService\TranscribeServiceClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Tests\TestCase;

pest()
    ->extend(TestCase::class);

test('transcribe extraction pipeline downloads from configured s3 bucket and maps transcript', function (): void {
    $jobId = 'audio-42-testjob';
    $transcriptKey = 'transcriptions/1/42/audio-42-testjob.json';
    $transcriptJson = json_encode([
        'results' => [
            'transcripts' => [['transcript' => 'Mapped transcript text.']],
        ],
    ], JSON_THROW_ON_ERROR);

    $clientMock = Mockery::mock(TranscribeServiceClient::class);
    $clientMock->shouldReceive('getTranscriptionJob')
        ->once()
        ->with(['TranscriptionJobName' => $jobId])
        ->andReturn(new Result([
            'TranscriptionJob' => [
                'Transcript' => [
                    'TranscriptFileUri' => "https://s3.eu-west-1.amazonaws.com/qompose-docs/{$transcriptKey}",
                ],
            ],
        ]));

    $diskMock = Mockery::mock(FilesystemAdapter::class);
    $diskMock->shouldReceive('get')
        ->once()
        ->with($transcriptKey)
        ->andReturn($transcriptJson);

    $filesystemsMock = Mockery::mock(FilesystemManager::class);
    $filesystemsMock->shouldReceive('disk')
        ->once()
        ->with('s3')
        ->andReturn($diskMock);

    $configMock = Mockery::mock(Repository::class);
    $configMock->shouldReceive('get')
        ->once()
        ->with('transcription.transcribe.bucket')
        ->andReturn('qompose-docs');

    $result = new TranscribeExtractionPipeline(
        $clientMock,
        resolve(\App\AudioProcessing\Services\Transcribe\TranscribeTranscriptMapper::class),
        $filesystemsMock,
        $configMock,
    )->run($jobId);

    expect($result->text)->toBe('Mapped transcript text.');
});
