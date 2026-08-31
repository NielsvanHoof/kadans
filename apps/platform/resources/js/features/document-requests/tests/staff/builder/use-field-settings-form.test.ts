import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { DocumentRequest } from '@/features/document-requests/types';
import { mockUseTranslation } from '@/test/mocks';

// Identity translation so assertions match the source strings in findValidationError.
vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { useFieldSettingsForm } =
    await import('@/features/document-requests/staff/builder/use-field-settings-form');

/** Minimal DocumentRequest fixture — only fields the settings form reads. */
function field(
    overrides: Partial<DocumentRequest> & Pick<DocumentRequest, 'id'> = {
        id: 1,
    },
): DocumentRequest {
    return {
        type: 'text',
        field_key: `key-${overrides.id}`,
        title: 'Label',
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

/**
 * Stable documentRequest reference — creating a new object inside the
 * renderHook callback would re-fire the reset effect every render (OOM).
 */
function setup(
    documentRequest: DocumentRequest,
    onSave: (input: unknown) => Promise<boolean>,
    canEdit = true,
) {
    return renderHook(() =>
        useFieldSettingsForm(documentRequest, onSave, canEdit),
    );
}

beforeEach(() => {
    vi.clearAllMocks();
});

describe('useFieldSettingsForm', () => {
    it('is not dirty when the draft matches the selected field', () => {
        const onSave = vi.fn().mockResolvedValue(true);
        const { result } = setup(field({ id: 1, title: 'Label' }), onSave);

        expect(result.current.isDirty).toBe(false);
        expect(result.current.error).toBeNull();
    });

    it('marks dirty when the title diverges from the saved field', () => {
        const onSave = vi.fn().mockResolvedValue(true);
        const { result } = setup(field({ id: 1 }), onSave);

        act(() => {
            result.current.setTitle('Changed');
        });

        expect(result.current.isDirty).toBe(true);
    });

    it('resets the draft when a different field is selected', () => {
        const onSave = vi.fn().mockResolvedValue(true);
        const first = field({ id: 1, title: 'One' });
        const second = field({ id: 2, title: 'Two' });
        const { result, rerender } = renderHook(
            ({ documentRequest }) =>
                useFieldSettingsForm(documentRequest, onSave, true),
            { initialProps: { documentRequest: first } },
        );

        act(() => {
            result.current.setTitle('Unsaved');
        });
        expect(result.current.isDirty).toBe(true);

        rerender({ documentRequest: second });

        expect(result.current.title).toBe('Two');
        expect(result.current.isDirty).toBe(false);
        expect(result.current.error).toBeNull();
    });

    it('does not call onSave when canEdit is false', async () => {
        const onSave = vi.fn().mockResolvedValue(true);
        const { result } = setup(field({ id: 1 }), onSave, false);

        await act(async () => {
            result.current.saveDraft();
        });

        expect(onSave).not.toHaveBeenCalled();
    });

    it('blocks save and sets an error when the label is blank', async () => {
        const onSave = vi.fn().mockResolvedValue(true);
        const { result } = setup(field({ id: 1 }), onSave);

        act(() => {
            result.current.setTitle('   ');
        });

        await act(async () => {
            result.current.saveDraft();
        });

        expect(onSave).not.toHaveBeenCalled();
        expect(result.current.error).toBe('Enter a field label.');
    });

    it('requires at least two options for choice fields', async () => {
        const onSave = vi.fn().mockResolvedValue(true);
        const { result } = setup(
            field({
                id: 1,
                type: 'choice',
                configuration: {
                    is_required: true,
                    options: [{ id: 'a', label: 'Only one' }],
                },
            }),
            onSave,
        );

        await act(async () => {
            result.current.saveDraft();
        });

        expect(onSave).not.toHaveBeenCalled();
        expect(result.current.error).toBe('Add at least two options.');
    });

    it('trims title and instructions before calling onSave', async () => {
        const onSave = vi.fn().mockResolvedValue(true);
        const { result } = setup(field({ id: 1 }), onSave);

        act(() => {
            result.current.setTitle('  Hello  ');
            result.current.setInstructions('  Note  ');
        });

        await act(async () => {
            result.current.saveDraft();
        });

        expect(onSave).toHaveBeenCalledWith({
            type: 'text',
            title: 'Hello',
            instructions: 'Note',
            configuration: { is_required: true },
        });
        expect(result.current.error).toBeNull();
    });

    it('surfaces a save failure as an error message', async () => {
        const onSave = vi.fn().mockResolvedValue(false);
        const { result } = setup(field({ id: 1 }), onSave);

        await act(async () => {
            result.current.saveDraft();
        });

        expect(result.current.error).toBe(
            'Could not save. Check the fields and try again.',
        );
    });

    it('changeType keeps options when switching to a choice type and persists', async () => {
        const onSave = vi.fn().mockResolvedValue(true);
        const { result } = setup(
            field({
                id: 1,
                type: 'text',
                configuration: { is_required: true },
            }),
            onSave,
        );

        const fallback = [
            { id: 'yes', label: 'Yes' },
            { id: 'no', label: 'No' },
        ];

        await act(async () => {
            result.current.changeType('choice', fallback);
        });

        expect(result.current.type).toBe('choice');
        expect(result.current.configuration.options).toEqual(fallback);
        expect(onSave).toHaveBeenCalledWith(
            expect.objectContaining({
                type: 'choice',
                configuration: expect.objectContaining({
                    is_required: true,
                    options: fallback,
                }),
            }),
        );
    });

    it('updateConfiguration can persist immediately', async () => {
        const onSave = vi.fn().mockResolvedValue(true);
        const { result } = setup(field({ id: 1 }), onSave);

        await act(async () => {
            result.current.updateConfiguration({ is_required: false }, true);
        });

        expect(onSave).toHaveBeenCalledWith(
            expect.objectContaining({
                configuration: { is_required: false },
            }),
        );
    });
});
