import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import FileDropzone from '@/components/forms/file-dropzone';
import MultiFileDropzone from '@/components/forms/multi-file-dropzone';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup } from '@/components/ui/field';
import { useDirectUpload } from '@/hooks/use-direct-upload';
import { useTranslation } from '@/hooks/use-translation';

type VisitOptions = Record<string, unknown>;

/**
 * Shared file picker for staff and portal uploads.
 *
 * Two steps, not one: the files go straight to object storage with presigned
 * PUTs, and only then does the form submit — carrying opaque tickets rather
 * than a multipart body. That is what keeps a ten-file batch off the
 * application's request path entirely.
 *
 * `useForm` rather than Inertia's `<Form>` (which .ai/rules/js.md prefers)
 * because the submit has to wait on those uploads and abandon itself if any of
 * them fails. There is no declarative form of "await N side effects, then
 * post".
 *
 * Callers supply the Wayfinder URLs and visit options. `allowMultiple`
 * switches between a single-file replace flow and a multi-file add flow; the
 * backend always receives a `documents` array.
 */
export default function DocumentFileUploadForm({
    inputId,
    hasExistingUpload,
    allowMultiple = false,
    actionUrl,
    signUrl,
    visitOptions = {},
    className = 'mt-2',
}: {
    inputId: string;
    hasExistingUpload: boolean;
    allowMultiple?: boolean;
    actionUrl: string;
    signUrl: string;
    visitOptions?: VisitOptions;
    className?: string;
}) {
    const { t } = useTranslation();
    const [files, setFiles] = useState<File[]>([]);
    const directUpload = useDirectUpload(signUrl, 'document');
    const form = useForm<{ documents: string[] }>({ documents: [] });

    const busy = directUpload.uploading || form.processing;

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        const tickets = await directUpload.upload(files);

        // A failed upload leaves its message on the hook; there is nothing to
        // submit and no point clearing the user's selection.
        if (tickets === null) {
            return;
        }

        form.transform(() => ({
            documents: tickets.map((ticket) => ticket.ticket),
        }));

        form.post(actionUrl, {
            ...visitOptions,
            onSuccess: () => {
                setFiles([]);
                form.reset('documents');
                directUpload.reset();
            },
        });
    };

    const label = hasExistingUpload
        ? allowMultiple
            ? t('Add more files')
            : t('Replace file')
        : allowMultiple
          ? t('Upload files')
          : t('Upload file');

    // The server reports ticket failures under `documents`; the hook reports
    // everything that happened before the form was ever submitted.
    const error = directUpload.error ?? form.errors.documents;

    return (
        <form onSubmit={submit} className={className}>
            <FieldGroup className="gap-2">
                {allowMultiple ? (
                    <MultiFileDropzone
                        inputId={inputId}
                        label={label}
                        files={files}
                        onFilesChange={setFiles}
                        error={error}
                        disabled={busy}
                        progressPercent={directUpload.progressPercent}
                    />
                ) : (
                    <FileDropzone
                        inputId={inputId}
                        label={label}
                        file={files[0] ?? null}
                        onFileChange={(file) => setFiles(file ? [file] : [])}
                        error={error}
                        disabled={busy}
                        progressPercent={directUpload.progressPercent}
                    />
                )}

                <Field orientation="horizontal">
                    <Button
                        type="submit"
                        size="sm"
                        isDisabled={busy || files.length === 0}
                    >
                        {busy ? t('Uploading…') : t('Upload')}
                    </Button>
                </Field>
            </FieldGroup>
        </form>
    );
}
