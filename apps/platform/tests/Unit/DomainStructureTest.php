<?php

declare(strict_types=1);

test('backend business code is organized by domain', function (): void {
    $root = dirname(__DIR__, 2);

    foreach ([
        'Accounts',
        'Audit',
        'ClientPortal',
        'Clients',
        'Dossiers',
        'DocumentProcessing',
        'Platform',
        'Questionnaires',
        'Reporting',
        'Shared',
        'Tenancy',
        'WorkspaceNotifications',
    ] as $module) {
        expect($root.'/app/'.$module)
            ->toBeDirectory();
    }

    foreach ([
        'Actions',
        'Concerns',
        'Contracts',
        'Data',
        'Enums',
        'Events',
        'Http',
        'Jobs',
        'Listeners',
        'Models',
        'Policies',
        'Queries',
        'Services',
        'Support',
        'Transitions',
    ] as $legacyDirectory) {
        expect($root.'/app/'.$legacyDirectory)->not->toBeDirectory();
    }
});

test('backend namespaces mirror module paths', function (): void {
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relativeDirectory = str_replace(
            [$root.'/app', '/'],
            ['', '\\'],
            $file->getPath(),
        );
        $expectedNamespace = 'App'.$relativeDirectory;
        $contents = (string) file_get_contents($file->getPathname());

        expect($contents)
            ->toContain('namespace '.$expectedNamespace.';');
    }
});

test('frontend business code is organized by feature domain', function (): void {
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
        'resources/js/features/document-requests/portal',
        'resources/js/features/portal',
        'resources/js/features/platform',
        'resources/js/pages/platform',
        'resources/js/features/pipelines/hooks',
        'resources/js/components/app-shell',
        'resources/js/components/brand',
        'resources/js/components/data',
        'resources/js/components/feedback',
        'resources/js/components/forms',
        'resources/js/components/primitives',
        'resources/js/components/ui',
        'resources/js/layouts/app',
        'resources/js/layouts/auth',
        'resources/js/layouts/onboarding',
        'resources/js/layouts/portal',
        'resources/js/layouts/settings',
        'resources/js/types/generated',
    ] as $directory) {
        expect($root.'/'.$directory)
            ->toBeDirectory();
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
        expect($root.'/'.$typeFile)
            ->toBeFile();
    }

    // Feature root files that must exist for document-request type wiring.
    foreach ([
        'resources/js/features/document-requests/questionnaire-item-type-registry.tsx',
        'resources/js/features/document-requests/item-types/generated.ts',
        'resources/js/features/document-requests/item-types/definitions.ts',
        'resources/js/features/document-requests/item-types/portal-registry.ts',
    ] as $registryFile) {
        expect($root.'/'.$registryFile)
            ->toBeFile();
    }

    // Legacy pre-feature layout must not come back.
    foreach ([
        'resources/js/components/clients',
        'resources/js/components/dossiers',
        'resources/js/components/questionnaires',
        'resources/js/components/portal',
        'resources/js/features/portal/document-requests',
        'resources/js/components/heading.tsx',
        'resources/js/components/data-table.tsx',
        'resources/js/components/confirm-destroy-dialog.tsx',
        'resources/js/layouts/app-layout.tsx',
        'resources/js/layouts/settings/layout.tsx',
        'resources/js/hooks/use-two-factor-auth.ts',
        'resources/js/lib/brand-shields.ts',
        'resources/js/components/brand/brand-shields.ts',
        'resources/js/components/brand/brand-shield-watermark.tsx',
        'resources/js/types/clients.ts',
        'resources/js/types/dossiers.ts',
        'resources/js/types/questionnaires.ts',
        'resources/js/types/portal.ts',
    ] as $legacyPath) {
        expect(file_exists($root.'/'.$legacyPath))->toBeFalse();
    }
});

test('controllers do not resolve dependencies through service locators', function (): void {
    $controllers = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            dirname(__DIR__, 2).'/app',
            FilesystemIterator::SKIP_DOTS,
        ),
    );

    foreach ($controllers as $controller) {
        if (! $controller->isFile()) {
            continue;
        }
        if ($controller->getExtension() !== 'php') {
            continue;
        }
        if (! str_contains((string) $controller->getPathname(), '/Http/Controllers/')) {
            continue;
        }
        $contents = file_get_contents($controller->getPathname());

        // file_get_contents() returns string|false — assert readable, then no container lookups.
        expect($contents)->toBeString()
            ->and(preg_match('/\b(?:app|resolve)\s*\(/', $contents))->toBe(0);
    }
});

test('shared frontend patterns use the intended shadcn compositions', function (): void {
    $root = dirname(__DIR__, 2);

    $confirmDialog = (string) file_get_contents(
        $root.'/resources/js/components/feedback/confirm-destroy-dialog.tsx',
    );
    $emptyState = (string) file_get_contents(
        $root.'/resources/js/components/primitives/empty-state.tsx',
    );
    $notificationBell = (string) file_get_contents(
        $root.'/resources/js/features/notifications/notification-bell.tsx',
    );
    $passwordInput = (string) file_get_contents(
        $root.'/resources/js/components/primitives/password-input.tsx',
    );
    $inputError = (string) file_get_contents(
        $root.'/resources/js/components/primitives/input-error.tsx',
    );

    expect($confirmDialog)
        ->toContain('@/components/ui/alert-dialog')
        ->not->toContain('@/components/ui/dialog')
        ->and($emptyState)->toContain('@/components/ui/empty')
        ->and($notificationBell)
        ->toContain('@/components/ui/popover')
        ->not->toContain('@/components/ui/dropdown-menu')
        ->and($passwordInput)->toContain('@/components/ui/input-group')
        ->and($inputError)->toContain('FieldError');

    $frontendFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root.'/resources/js',
            FilesystemIterator::SKIP_DOTS,
        ),
    );

    foreach ($frontendFiles as $frontendFile) {
        if (! $frontendFile->isFile()) {
            continue;
        }
        if ($frontendFile->getExtension() !== 'tsx') {
            continue;
        }
        $contents = (string) file_get_contents($frontendFile->getPathname());
        $path = $frontendFile->getPathname();
        $selectContentCount = mb_substr_count($contents, '<SelectContent');

        if ($selectContentCount > 0) {
            expect(
                preg_match_all(
                    '/<SelectContent\b[^>]*>\s*<SelectGroup\b/s',
                    $contents,
                ),
            )->toBe($selectContentCount);
        }

        if (str_contains($contents, '@/components/ui/label')) {
            expect($path)->toEndWith('/resources/js/components/ui/field.tsx');
        }

        if (str_contains($contents, '<InputError')) {
            expect($contents)->toContain('@/components/ui/field');
        }

        $containsForm = preg_match('/<(?:Form|form)\b/', $contents) === 1;
        $containsFormControl = preg_match(
            '/<(?:Input|Select|Textarea|Checkbox|PasswordInput|InputOTP|FileDropzone|AnswerTextControl)\b/',
            $contents,
        ) === 1;

        if ($containsForm && $containsFormControl) {
            expect($contents)
                ->toContain('@/components/ui/field')
                ->toContain('<Field');
        }
    }
});
