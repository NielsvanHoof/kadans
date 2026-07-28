import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import FileDropzone from '@/components/file-dropzone';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup } from '@/components/ui/field';
import { useTranslation } from '@/hooks/use-translation';

type VisitOptions = Record<string, unknown>;

/**
 * Shared file picker + progress UI for staff and portal uploads.
 * Callers supply the Wayfinder URL and visit options (forceFormData is always set).
 */
export default function DocumentFileUploadForm({
    inputId,
    hasExistingUpload,
    actionUrl,
    visitOptions = {},
    className = 'mt-2',
}: {
    inputId: string;
    hasExistingUpload: boolean;
    actionUrl: string;
    visitOptions?: VisitOptions;
    className?: string;
}) {
    const { t } = useTranslation();
    const form = useForm<{ document: File | null }>({
        document: null,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.post(actionUrl, {
            ...visitOptions,
            forceFormData: true,
            onSuccess: () => form.reset('document'),
        });
    };

    return (
        <form onSubmit={submit} className={className}>
            <FieldGroup className="gap-2">
                <FileDropzone
                    inputId={inputId}
                    label={
                        hasExistingUpload ? t('Replace file') : t('Upload file')
                    }
                    file={form.data.document}
                    onFileChange={(file) => form.setData('document', file)}
                    error={form.errors.document}
                    disabled={form.processing}
                    progressPercent={form.progress?.percentage ?? null}
                />

                <Field orientation="horizontal">
                    <Button
                        type="submit"
                        size="sm"
                        disabled={form.processing || !form.data.document}
                    >
                        {form.processing ? t('Uploading…') : t('Upload')}
                    </Button>
                </Field>
            </FieldGroup>
        </form>
    );
}
