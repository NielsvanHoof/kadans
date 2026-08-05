import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    DocumentRequest,
    QuestionnaireItemConfiguration,
} from '@/features/document-requests/types';
import type { ApplyTemplateOption } from '@/features/questionnaires/types';

const syncDocumentRequests = vi.fn();

// The hook's collaborators reach for Inertia page props / HTTP; neither is under
// test here. Translation is identity so assertions read as the source strings.
vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock(
    '@/features/document-requests/staff/builder/use-questionnaire-builder-actions',
    () => ({
        useQuestionnaireBuilderActions: () => ({ syncDocumentRequests }),
    }),
);

let ulidCounter = 0;
vi.mock('@/lib/ulid', () => ({
    createUlid: () => `ULID_${++ulidCounter}`,
}));

const { useQuestionnaireBuilderState } =
    await import('@/features/document-requests/staff/builder/use-questionnaire-builder-state');

function item(
    overrides: Partial<DocumentRequest> & Pick<DocumentRequest, 'id'>,
): DocumentRequest {
    return {
        type: 'text',
        field_key: `key-${overrides.id}`,
        title: `Item ${overrides.id}`,
        instructions: null,
        configuration: { is_required: true },
        status: 'pending',
        answer_text: null,
        answer_boolean: null,
        answer_option_ids: null,
        answered_at: null,
        reviewed_at: null,
        reviewed_by_name: null,
        rejection_reason: null,
        sort_order: 0,
        is_visible: true,
        is_applicable: true,
        uploaded_documents: [],
        ...overrides,
    } as DocumentRequest;
}

function setup(documentRequests: DocumentRequest[], canEdit = true) {
    return renderHook(() =>
        useQuestionnaireBuilderState({
            dossierId: 1,
            documentRequests,
            canEdit,
        }),
    );
}

beforeEach(() => {
    ulidCounter = 0;
    syncDocumentRequests.mockReset();
});

describe('useQuestionnaireBuilderState', () => {
    it('starts clean with the first item selected', () => {
        const { result } = setup([item({ id: 1 }), item({ id: 2 })]);

        expect(result.current.isDirty).toBe(false);
        expect(result.current.selectedId).toBe(1);
    });

    describe('adding', () => {
        it('inserts after the selection and resequences sort_order', () => {
            const { result } = setup([
                item({ id: 1, sort_order: 0 }),
                item({ id: 2, sort_order: 1 }),
            ]);

            act(() => result.current.addAt('text'));

            const orders = result.current.items.map((i) => i.sort_order);
            expect(orders).toEqual([0, 1, 2]);
            // Inserted directly after the selected first item.
            expect(result.current.items[1].id).toBe(result.current.selectedId);
            expect(result.current.items.map((i) => i.id)[0]).toBe(1);
            expect(result.current.isDirty).toBe(true);
        });

        it('gives new drafts negative ids so they cannot collide with server ids', () => {
            const { result } = setup([item({ id: 1 })]);

            act(() => result.current.addAt('text'));

            const created = result.current.items[1];
            expect(created.id).toBeLessThan(0);
        });
    });

    describe('deleting', () => {
        it('resequences and moves the selection off the removed item', () => {
            const { result } = setup([
                item({ id: 1, sort_order: 0 }),
                item({ id: 2, sort_order: 1 }),
                item({ id: 3, sort_order: 2 }),
            ]);

            act(() => {
                result.current.setSelectedId(2);
            });
            act(() => {
                void result.current.handleDestroy(2);
            });

            expect(result.current.items.map((i) => i.id)).toEqual([1, 3]);
            expect(result.current.items.map((i) => i.sort_order)).toEqual([
                0, 1,
            ]);
            expect(result.current.selectedId).toBe(1);
        });

        it('keeps the selection when a different item is removed', () => {
            const { result } = setup([item({ id: 1 }), item({ id: 2 })]);

            act(() => {
                void result.current.handleDestroy(2);
            });

            expect(result.current.selectedId).toBe(1);
        });

        it('clears the selection when the last item goes', () => {
            const { result } = setup([item({ id: 1 })]);

            act(() => {
                void result.current.handleDestroy(1);
            });

            expect(result.current.items).toEqual([]);
            expect(result.current.selectedId).toBeNull();
        });
    });

    describe('applying a template', () => {
        /**
         * The risky part: template items carry visibility rules that point at
         * *template* field keys. Every item gets a fresh ULID on apply, so those
         * rules have to be remapped or they silently reference a key that is not
         * in the dossier — a field that can then never become visible.
         */
        const template: ApplyTemplateOption = {
            id: 10,
            name: 'Onboarding',
            category_label: 'General',
            items_count: 2,
            is_system: false,
            items: [
                {
                    type: 'boolean',
                    field_key: 'tmpl-source',
                    title: 'Do you own property?',
                    instructions: null,
                    configuration: { is_required: true },
                    sort_order: 0,
                },
                {
                    type: 'file',
                    field_key: 'tmpl-dependent',
                    title: 'Deed of ownership',
                    instructions: null,
                    configuration: {
                        is_required: true,
                        visibility: {
                            source_field_key: 'tmpl-source',
                            option_ids: ['true'],
                        },
                    },
                    sort_order: 1,
                },
            ],
        } as ApplyTemplateOption;

        it('remaps a visibility source to the newly generated field key', () => {
            const { result } = setup([item({ id: 1 })]);

            act(() => result.current.applyTemplate(template));

            const [, source, dependent] = result.current.items;
            const visibility = (
                dependent.configuration as QuestionnaireItemConfiguration
            ).visibility;

            expect(visibility?.source_field_key).toBe(source.field_key);
            // The template's own key must not survive into the dossier.
            expect(visibility?.source_field_key).not.toBe('tmpl-source');
            expect(visibility?.option_ids).toEqual(['true']);
        });

        it('gives every applied item a fresh field key', () => {
            const { result } = setup([item({ id: 1 })]);

            act(() => result.current.applyTemplate(template));

            const keys = result.current.items.slice(1).map((i) => i.field_key);
            expect(new Set(keys).size).toBe(keys.length);
            expect(keys).not.toContain('tmpl-source');
            expect(keys).not.toContain('tmpl-dependent');
        });

        it('appends after existing items and resequences the whole list', () => {
            const { result } = setup([
                item({ id: 1, sort_order: 0 }),
                item({ id: 2, sort_order: 1 }),
            ]);

            act(() => result.current.applyTemplate(template));

            expect(result.current.items.map((i) => i.sort_order)).toEqual([
                0, 1, 2, 3,
            ]);
            expect(result.current.items[0].id).toBe(1);
        });

        it('ignores an empty template', () => {
            const { result } = setup([item({ id: 1 })]);

            act(() =>
                result.current.applyTemplate({
                    ...template,
                    items: [],
                } as ApplyTemplateOption),
            );

            expect(result.current.items).toHaveLength(1);
            expect(result.current.isDirty).toBe(false);
        });
    });

    describe('saving', () => {
        it('keeps the selection on the same field_key once ids become real', async () => {
            const draft = item({ id: -1, field_key: 'stable-key' });
            const { result } = setup([item({ id: 1 }), draft]);

            act(() => {
                result.current.setSelectedId(-1);
            });

            syncDocumentRequests.mockResolvedValue([
                item({ id: 1 }),
                item({ id: 42, field_key: 'stable-key' }),
            ]);

            await act(async () => {
                await result.current.saveDraft();
            });

            // The temp id is gone; selection followed the field_key to the real row.
            expect(result.current.selectedId).toBe(42);
            expect(result.current.isDirty).toBe(false);
            expect(result.current.saveStatus).toBe('saved');
        });

        it('stays dirty and reports an error when the sync fails', async () => {
            const { result } = setup([item({ id: 1 })]);

            act(() => result.current.addAt('text'));
            syncDocumentRequests.mockResolvedValue(null);

            await act(async () => {
                await result.current.saveDraft();
            });

            expect(result.current.saveStatus).toBe('error');
            expect(result.current.isDirty).toBe(true);
        });
    });

    describe('read-only mode', () => {
        it('refuses every mutation when the lock is not held', async () => {
            const { result } = setup([item({ id: 1 }), item({ id: 2 })], false);

            act(() => result.current.addAt('text'));
            act(() =>
                result.current.applyTemplate({
                    id: 1,
                    name: 't',
                    category_label: 'c',
                    items_count: 1,
                    is_system: false,
                    items: [
                        {
                            type: 'text',
                            field_key: 'k',
                            title: 'T',
                            instructions: null,
                            configuration: { is_required: true },
                            sort_order: 0,
                        },
                    ],
                } as ApplyTemplateOption),
            );

            let destroyed = true;
            let reordered = true;
            await act(async () => {
                destroyed = await result.current.handleDestroy(1);
                reordered = result.current.handleReorder([2, 1]);
            });

            expect(result.current.items).toHaveLength(2);
            expect(destroyed).toBe(false);
            expect(reordered).toBe(false);
            expect(result.current.isDirty).toBe(false);
            expect(syncDocumentRequests).not.toHaveBeenCalled();
        });
    });
});
