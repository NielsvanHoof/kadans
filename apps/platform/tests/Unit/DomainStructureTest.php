<?php

declare(strict_types=1);

test('backend business code is organized by domain', function () {
    $root = dirname(__DIR__, 2);

    foreach ([
        'app/Actions/Clients',
        'app/Actions/Dossiers',
        'app/Actions/Dossiers/Builder',
        'app/Actions/Dossiers/DocumentRequests',
        'app/Actions/Portal',
        'app/Actions/Platform',
        'app/Actions/Questionnaires',
        'app/Actions/Tenancy',
        'app/Http/Controllers/Clients',
        'app/Http/Controllers/Dossiers',
        'app/Http/Controllers/Portal',
        'app/Http/Controllers/Platform',
        'app/Http/Controllers/Questionnaires',
        'app/Http/Requests/Clients',
        'app/Http/Requests/Dossiers',
        'app/Http/Requests/Dossiers/Builder',
        'app/Http/Requests/Dossiers/DocumentRequests',
        'app/Http/Requests/Portal',
        'app/Http/Requests/Platform',
        'app/Http/Requests/Questionnaires',
        'app/Queries/Clients',
        'app/Queries/Dossiers',
        'app/Queries/Portal',
        'app/Queries/Platform',
        'app/Queries/Questionnaires',
        'app/Queries/Reporting',
        'app/Queries/Tenancy',
    ] as $directory) {
        expect(is_dir($root.'/'.$directory))->toBeTrue();
    }

    foreach ([
        'app/Actions/Workspace',
        'app/Http/Controllers/Workspace',
        'app/Http/Requests/Workspace',
        'app/Queries/Workspace',
    ] as $legacyDirectory) {
        expect(glob($root.'/'.$legacyDirectory.'/*.php') ?: [])->toBeEmpty();
    }
});

test('frontend business code is organized by feature domain', function () {
    $root = dirname(__DIR__, 2);

    foreach ([
        'resources/js/pages/clients',
        'resources/js/pages/dossiers',
        'resources/js/pages/questionnaires',
        'resources/js/pages/portal',
        'resources/js/features/clients',
        'resources/js/features/clients/list',
        'resources/js/features/clients/manage',
        'resources/js/features/dossiers',
        'resources/js/features/dossiers/permissions',
        'resources/js/features/dossiers/status',
        'resources/js/features/dossiers/stage',
        'resources/js/features/dossiers/show',
        'resources/js/features/dossiers/list',
        'resources/js/features/dossiers/create',
        'resources/js/features/dossiers/edit',
        'resources/js/features/dossiers/follow-up',
        'resources/js/features/document-requests/staff',
        'resources/js/features/document-requests/staff/builder',
        'resources/js/features/document-requests/staff/review',
        'resources/js/features/document-requests/item-types',
        'resources/js/features/document-requests/status',
        'resources/js/features/invitations',
        'resources/js/features/questionnaires',
        'resources/js/features/questionnaires/list',
        'resources/js/features/questionnaires/show',
        'resources/js/features/security/passkeys',
        'resources/js/features/security/two-factor',
        'resources/js/features/portal',
        'resources/js/features/portal/document-requests',
        'resources/js/features/platform',
        'resources/js/pages/platform',
        'resources/js/components/app-shell',
        'resources/js/components/ui',
    ] as $directory) {
        expect(is_dir($root.'/'.$directory))->toBeTrue();
    }

    // Domain types are co-located with their feature, not in the global barrel.
    foreach ([
        'resources/js/features/clients/types.ts',
        'resources/js/features/dossiers/types.ts',
        'resources/js/features/document-requests/types.ts',
        'resources/js/features/invitations/types.ts',
        'resources/js/features/questionnaires/types.ts',
        'resources/js/features/portal/types.ts',
    ] as $typeFile) {
        expect(is_file($root.'/'.$typeFile))->toBeTrue();
    }

    // Legacy pre-feature layout must not come back.
    foreach ([
        'resources/js/components/clients',
        'resources/js/components/dossiers',
        'resources/js/components/questionnaires',
        'resources/js/components/portal',
        'resources/js/features/document-requests/portal',
        'resources/js/features/document-requests/questionnaire-item-type-registry.tsx',
        'resources/js/types/clients.ts',
        'resources/js/types/dossiers.ts',
        'resources/js/types/questionnaires.ts',
        'resources/js/types/portal.ts',
    ] as $legacyPath) {
        expect(file_exists($root.'/'.$legacyPath))->toBeFalse();
    }
});

test('controllers do not resolve dependencies through service locators', function () {
    $controllers = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            dirname(__DIR__, 2).'/app/Http/Controllers',
            FilesystemIterator::SKIP_DOTS,
        ),
    );

    foreach ($controllers as $controller) {
        if (! $controller->isFile() || $controller->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($controller->getPathname());

        expect($contents)->not->toBeFalse()
            ->and(preg_match('/\b(?:app|resolve)\s*\(/', (string) $contents))->toBe(0);
    }
});

test('shared frontend patterns use the intended shadcn compositions', function () {
    $root = dirname(__DIR__, 2);

    $confirmDialog = (string) file_get_contents(
        $root.'/resources/js/components/confirm-destroy-dialog.tsx',
    );
    $emptyState = (string) file_get_contents(
        $root.'/resources/js/components/empty-state.tsx',
    );
    $notificationBell = (string) file_get_contents(
        $root.'/resources/js/features/notifications/notification-bell.tsx',
    );
    $passwordInput = (string) file_get_contents(
        $root.'/resources/js/components/password-input.tsx',
    );
    $inputError = (string) file_get_contents(
        $root.'/resources/js/components/input-error.tsx',
    );

    expect($confirmDialog)
        ->toContain("@/components/ui/alert-dialog")
        ->not->toContain("@/components/ui/dialog")
        ->and($emptyState)->toContain("@/components/ui/empty")
        ->and($notificationBell)
        ->toContain("@/components/ui/popover")
        ->not->toContain("@/components/ui/dropdown-menu")
        ->and($passwordInput)->toContain("@/components/ui/input-group")
        ->and($inputError)->toContain('FieldError');

    $frontendFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root.'/resources/js',
            FilesystemIterator::SKIP_DOTS,
        ),
    );

    foreach ($frontendFiles as $frontendFile) {
        if (! $frontendFile->isFile() || $frontendFile->getExtension() !== 'tsx') {
            continue;
        }

        $contents = (string) file_get_contents($frontendFile->getPathname());
        $path = $frontendFile->getPathname();
        $selectContentCount = substr_count($contents, '<SelectContent');

        if ($selectContentCount > 0) {
            expect(
                preg_match_all(
                    '/<SelectContent\b[^>]*>\s*<SelectGroup\b/s',
                    $contents,
                ),
                $path,
            )->toBe($selectContentCount);
        }

        if (str_contains($contents, '@/components/ui/label')) {
            expect($path)->toEndWith('/resources/js/components/ui/field.tsx');
        }

        if (str_contains($contents, '<InputError')) {
            expect($contents, $path)->toContain('@/components/ui/field');
        }

        $containsForm = preg_match('/<(?:Form|form)\b/', $contents) === 1;
        $containsFormControl = preg_match(
            '/<(?:Input|Select|Textarea|Checkbox|PasswordInput|InputOTP|FileDropzone|AnswerTextControl)\b/',
            $contents,
        ) === 1;

        if ($containsForm && $containsFormControl) {
            expect($contents, $path)
                ->toContain('@/components/ui/field')
                ->toContain('<Field');
        }
    }
});
