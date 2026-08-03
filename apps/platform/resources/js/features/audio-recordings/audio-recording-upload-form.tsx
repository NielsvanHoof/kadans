import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import FileDropzone from '@/components/forms/file-dropzone';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup } from '@/components/ui/field';
import { useTranslation } from '@/hooks/use-translation';

const AUDIO_ACCEPT =
    'audio/mpeg,audio/wav,audio/mp4,audio/ogg,audio/webm,video/mp4,.mp3,.wav,.m4a,.ogg,.webm,.mp4';
const AUDIO_MAX_BYTES = 25 * 1024 * 1024;

type VisitOptions = Record<string, unknown>;

/**
 * Staff audio upload for a dossier. Mirrors DocumentFileUploadForm's shape —
 * always a fresh recording (no replace semantics, unlike document requests).
 */
export default function AudioRecordingUploadForm({
    inputId,
    actionUrl,
    visitOptions = {},
    className = 'mt-2',
}: {
    inputId: string;
    actionUrl: string;
    visitOptions?: VisitOptions;
    className?: string;
}) {
    const { t } = useTranslation();
    const form = useForm<{ audio: File | null }>({
        audio: null,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.post(actionUrl, {
            ...visitOptions,
            forceFormData: true,
            onSuccess: () => form.reset('audio'),
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
                    file={form.data.audio}
                    onFileChange={(file) => form.setData('audio', file)}
                    error={form.errors.audio}
                    disabled={form.processing}
                    progressPercent={form.progress?.percentage ?? null}
                />

                <Field orientation="horizontal">
                    <Button
                        type="submit"
                        size="sm"
                        isDisabled={form.processing || !form.data.audio}
                    >
                        {form.processing ? t('Uploading…') : t('Upload')}
                    </Button>
                </Field>
            </FieldGroup>
        </form>
    );
}
