import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import FileDropzone from '@/components/forms/file-dropzone';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup } from '@/components/ui/field';
import { useDirectUpload } from '@/hooks/use-direct-upload';
import { useTranslation } from '@/hooks/use-translation';

const AUDIO_ACCEPT =
    'audio/mpeg,audio/wav,audio/mp4,audio/ogg,audio/webm,video/mp4,.mp3,.wav,.m4a,.ogg,.webm,.mp4';
const AUDIO_MAX_BYTES = 25 * 1024 * 1024;

type VisitOptions = Record<string, unknown>;

/**
 * Staff audio upload for a dossier. Mirrors DocumentFileUploadForm's shape —
 * always a fresh recording (no replace semantics, unlike document requests) —
 * including the two-step flow: presigned PUT to object storage first, then a
 * form submit carrying only the ticket.
 */
export default function AudioRecordingUploadForm({
    inputId,
    actionUrl,
    signUrl,
    visitOptions = {},
    className = 'mt-2',
}: {
    inputId: string;
    actionUrl: string;
    signUrl: string;
    visitOptions?: VisitOptions;
    className?: string;
}) {
    const { t } = useTranslation();
    const [file, setFile] = useState<File | null>(null);
    const directUpload = useDirectUpload(signUrl, 'audio_recording');
    const form = useForm<{ audio: string }>({ audio: '' });

    const busy = directUpload.uploading || form.processing;

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (file === null) {
            return;
        }

        const tickets = await directUpload.upload([file]);

        if (tickets === null || tickets.length === 0) {
            return;
        }

        form.transform(() => ({ audio: tickets[0].ticket }));

        form.post(actionUrl, {
            ...visitOptions,
            onSuccess: () => {
                setFile(null);
                form.reset('audio');
                directUpload.reset();
            },
        });
    };

    return (
        <form onSubmit={submit} className={className}>
            <FieldGroup className="gap-2">
                <FileDropzone
                    inputId={inputId}
                    label={t('Upload recording')}
                    accept={AUDIO_ACCEPT}
                    acceptHint={t('MP3, WAV, M4A, OGG, MP4')}
                    maxBytes={AUDIO_MAX_BYTES}
                    file={file}
                    onFileChange={setFile}
                    error={directUpload.error ?? form.errors.audio}
                    disabled={busy}
                    progressPercent={directUpload.progressPercent}
                />

                <Field orientation="horizontal">
                    <Button type="submit" size="sm" isDisabled={busy || !file}>
                        {busy ? t('Uploading…') : t('Upload')}
                    </Button>
                </Field>
            </FieldGroup>
        </form>
    );
}
