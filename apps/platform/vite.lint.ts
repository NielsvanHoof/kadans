import type { OxlintConfig, OxlintOverride } from 'vite-plus/lint';

/**
 * Oxlint last-match-wins and does not merge no-restricted-imports lists.
 * Every override restates the pages/ ban plus its extra patterns.
 */
const pagesAreNotLibraries = {
    group: ['@/pages/**'],
    message:
        'Pages are Inertia entry points, not libraries. Move shared code into @/features/<domain> or @/components.',
};

const sharedComponentsStayDomainAgnostic = {
    group: ['@/features/**'],
    message:
        'Shared components must stay domain-agnostic. Move domain-aware code into the feature itself.',
};

function restrictedImports(
    patterns: Array<{ group: string[]; message: string }>,
): OxlintOverride['rules'] {
    return {
        'no-restricted-imports': ['error', { patterns }],
    };
}

const importBoundaryOverrides: OxlintOverride[] = [
    {
        files: ['resources/js/**/*.test.{ts,tsx}', 'resources/js/**/tests/**'],
        rules: {
            // vi.mocked(Sentry.init) is the Vitest API; the method is not called unbound.
            'typescript/unbound-method': 'off',
        },
    },
    {
        files: ['resources/js/components/**'],
        rules: restrictedImports([
            pagesAreNotLibraries,
            sharedComponentsStayDomainAgnostic,
        ]),
    },
    {
        files: ['resources/js/components/primitives/**'],
        rules: restrictedImports([
            {
                group: ['@/pages/**', '@/features/**'],
                message: 'Shared components must stay domain-agnostic.',
            },
            {
                group: [
                    '@/components/data/**',
                    '@/components/forms/**',
                    '@/components/feedback/**',
                    '@/components/brand/**',
                    '@/components/app-shell/**',
                ],
                message:
                    'Primitives are the bottom layer: they may only use @/components/ui and each other. Composites belong in data/, forms/, or feedback/.',
            },
        ]),
    },
    {
        files: ['resources/js/features/document-requests/staff/**'],
        rules: restrictedImports([
            pagesAreNotLibraries,
            {
                group: ['@/features/document-requests/portal/**'],
                message:
                    'Staff renderers must not depend on portal renderers. Only the item-type registry wires both sides together.',
            },
        ]),
    },
    {
        files: ['resources/js/features/document-requests/portal/**'],
        rules: restrictedImports([
            pagesAreNotLibraries,
            {
                group: ['@/features/document-requests/staff/**'],
                message:
                    'Portal renderers must not depend on staff renderers. Only the item-type registry wires both sides together.',
            },
        ]),
    },
    {
        files: ['resources/js/pages/portal/**'],
        rules: restrictedImports([
            pagesAreNotLibraries,
            {
                group: [
                    '@/features/document-requests/staff/**',
                    '@/features/dossiers/**',
                    '!@/features/dossiers/types',
                    '@/components/app-shell/**',
                ],
                message:
                    'The guest portal must not pull in staff-side UI. Use @/features/portal or @/features/document-requests/portal instead.',
            },
        ]),
    },
];

export const frontendLint: OxlintConfig = {
    ignorePatterns: [
        '*',
        '!resources/',
        '!resources/**',
        'resources/css/**',
        'resources/views/**',
        'vendor/**',
        'node_modules/**',
        'public/**',
        'bootstrap/ssr/**',
        'resources/js/actions/**',
        'resources/js/components/ui/*',
        'resources/js/routes/**',
        'resources/js/wayfinder/**',
        'resources/js/types/generated/**',
        'resources/js/features/**/generated*',
    ],
    options: {
        denyWarnings: true,
        typeAware: true,
        // Codegen (Wayfinder, transformer) must run first; `npm run types:check` is the type gate.
        typeCheck: false,
    },
    rules: {
        curly: 'error',
        'typescript/consistent-type-imports': [
            'error',
            // Vitest `importOriginal<typeof import('module')>()` is the
            // documented mock factory pattern; forbidding import() types
            // would force a less readable rewrite of every vi.mock.
            { disallowTypeAnnotations: false },
        ],
        'typescript/no-explicit-any': 'error',
        'no-restricted-imports': [
            'error',
            {
                patterns: [pagesAreNotLibraries],
            },
        ],
    },
    overrides: importBoundaryOverrides,
};
