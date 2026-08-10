<?php

declare(strict_types=1);

namespace App\Platform\Services\Health;

use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

use function sprintf;

/**
 * Surface jobs that exhausted their retries.
 *
 * Spatie's QueueCheck only proves a worker is consuming; it says nothing about
 * jobs that ran and gave up. Those land in `failed_jobs` — where OCR,
 * transcription, and dossier exports pile up unwatched, and the only visible
 * symptom is a client's document stuck "processing" forever.
 */
final class FailedQueueJobsCheck extends Check
{
    private int $failWhenMoreThan = 0;

    private int $withinMinutes = 60;

    public function failWhenMoreThan(int $count): self
    {
        $this->failWhenMoreThan = $count;

        return $this;
    }

    public function within(int $minutes): self
    {
        $this->withinMinutes = $minutes;

        return $this;
    }

    public function run(): Result
    {
        $count = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subMinutes($this->withinMinutes))
            ->count();

        $result = Result::make()
            ->meta([
                'failed_jobs' => $count,
                'within_minutes' => $this->withinMinutes,
            ])
            ->shortSummary((string) $count);

        if ($count > $this->failWhenMoreThan) {
            return $result->failed(sprintf(
                '%d job(s) failed permanently in the last %d minutes.',
                $count,
                $this->withinMinutes,
            ));
        }

        return $result->ok(sprintf(
            'No jobs failed permanently in the last %d minutes.',
            $this->withinMinutes,
        ));
    }
}
