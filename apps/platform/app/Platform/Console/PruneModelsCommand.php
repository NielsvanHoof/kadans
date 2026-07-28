<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Illuminate\Database\Console\PruneCommand;

final class PruneModelsCommand extends PruneCommand
{
    /**
     * @return array<string>
     */
    protected function getPath(): array
    {
        $configuredPaths = $this->option('path');

        if ($configuredPaths !== []) {
            $resolvedPaths = [];

            foreach ($configuredPaths as $configuredPath) {
                if ($configuredPath !== null) {
                    $resolvedPaths[] = base_path($configuredPath);
                }
            }

            return $resolvedPaths;
        }

        $moduleModelPaths = glob(app_path('*/Models'), GLOB_ONLYDIR);

        return $moduleModelPaths === false ? [] : $moduleModelPaths;
    }
}
