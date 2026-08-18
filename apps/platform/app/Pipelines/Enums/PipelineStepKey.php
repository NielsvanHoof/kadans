<?php

declare(strict_types=1);

namespace App\Pipelines\Enums;

/**
 * Every stage key a pipeline type can declare in PipelineType::stepKeys().
 *
 * Steps are looked up by key at runtime, so an unrecognised key surfaces as a
 * ModelNotFoundException inside a queue worker rather than at boot. Keeping the
 * vocabulary in one enum makes the set discoverable and the lookups typed.
 */
enum PipelineStepKey: string
{
    case Submit = 'submit';
    case ProviderProcessing = 'provider_processing';
    case ProcessResults = 'process_results';
    case Transcribe = 'transcribe';
    case BuildArchive = 'build_archive';
    case StoreArchive = 'store_archive';
}
