import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import FileDropzone from '@/components/forms/file-dropzone';
import MultiFileDropzone from '@/components/forms/multi-file-dropzone';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup } from '@/components/ui/field';
import { useTranslation } from '@/hooks/use-translation';

type VisitOptions = Record<string, unknown>;

/**
 * Shared file picker + progress UI for staff and portal uploads.
 * Callers supply the Wayfinder URL and visit options (forceFormData is always set).
 * `allowMultiple` switches between a single-file replace flow and a
 * multi-file add flow; the backend always receives a `documents` array.
 */
export default function DocumentFileUploadForm({
    inputId,
    hasExistingUpload,
    allowMultiple = false,
    actionUrl,
    visitOptions = {},
    className = 'mt-2',
}: {
    inputId: string;
    hasExistingUpload: boolean;
    allowMultiple?: boolean;
    actionUrl: string;
    visitOptions?: VisitOptions;
    className?: string;
}) {
    const { t } = useTranslation();
    const form = useForm<{ documents: File[] }>({
        documents: [],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.post(actionUrl, {
            ...visitOptions,
            forceFormData: true,
            onSuccess: () => form.reset('documents'),
        });
    };

    const label = hasExistingUpload
        ? allowMultiple
            ? t('Add more files')
            : t('Replace file')
        : allowMultiple
          ? t('Upload files')
          : t('Upload file');

    return (
        <form onSubmit={submit} className={className}>
            <FieldGroup className="gap-2">
                {allowMultiple ? (
                    <MultiFileDropzone
                        inputId={inputId}
                        label={label}
                        files={form.data.documents}
                        onFilesChange={(files) =>
                            form.setData('documents', files)
                        }
                        error={form.errors.documents}
                        disabled={form.processing}
                        progressPercent={form.progress?.percentage ?? null}
                    />
                ) : (
                    <FileDropzone
                        inputId={inputId}
                        label={label}
                        file={form.data.documents[0] ?? null}
                        onFileChange={(file) =>
                            form.setData('documents', file ? [file] : [])
                        }
                        error={form.errors.documents}
                        disabled={form.processing}
                        progressPercent={form.progress?.percentage ?? null}
                    />
                )}

                <Field orientation="horizontal">
                    <Button
                        type="submit"
                        size="sm"
                        isDisabled={
                            form.processing || form.data.documents.length === 0
                        }
                    >
                        {form.processing ? t('Uploading…') : t('Upload')}
                    </Button>
                </Field>
            </FieldGroup>
        </form>
    );
}
