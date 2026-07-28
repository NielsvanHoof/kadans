<?php

declare(strict_types=1);

namespace App\Jobs\Dossiers;

use App\Actions\Dossiers\GenerateDossierExportAction;
use App\Actions\Dossiers\MarkDossierExportFailedAction;
use App\Models\DossierExport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class GenerateDossierExportJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(public int $exportId) {}

    public function uniqueId(): string
    {
        return (string) $this->exportId;
    }

    public function handle(GenerateDossierExportAction $generateDossierExport): void
    {
        $generateDossierExport->handle($this->exportId);
    }

    public function failed(
        Throwable $exception,
        MarkDossierExportFailedAction $markDossierExportFailed,
    ): void {
        $export = DossierExport::query()->find($this->exportId);

        if ($export !== null) {
            $markDossierExportFailed->handle($export, $exception);
        }
    }
}
