<?php

declare(strict_types=1);

return [
    'disk' => env('DOSSIER_EXPORT_DISK', env('FILESYSTEM_DISK', 'local')),
    'expires_in_minutes' => (int) env('DOSSIER_EXPORT_EXPIRES_MINUTES', 60),
];
