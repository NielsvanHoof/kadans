import { Upload, X } from 'lucide-react';
import type { DragEvent } from 'react';
import { useCallback, useId, useRef, useState } from 'react';
import {
    fileTypeLabel,
    validateFileSize,
} from '@/components/forms/validate-upload-file';
import InputError from '@/components/primitives/input-error';
import {
    Attachment,
    AttachmentAction,
    AttachmentActions,
    AttachmentContent,
    AttachmentDescription,
    AttachmentGroup,
    AttachmentMedia,
    AttachmentTitle,
} from '@/components/ui/attachment';
import { Field, FieldLabel } from '@/components/ui/field';
import { Progress, ProgressValue } from '@/components/ui/progress';
import { useTranslation } from '@/hooks/use-translation';
import { fileIconForFilename } from '@/lib/file-icon-for-filename';
import { formatBytes } from '@/lib/format-bytes';
import { cn } from '@/lib/utils';
import { DEFAULT_FILE_ACCEPT, DEFAULT_MAX_FILE_BYTES } from './file-dropzone';

export const DEFAULT_MAX_FILES = 10;

type MultiFileDropzoneProps = {
    inputId: string;
    label: string;
    files: File[];
    onFilesChange: (files: File[]) => void;
    accept?: string;
    acceptHint?: string;
    maxBytes?: number;
    maxFiles?: number;
    /** Client-side validation error (e.g. from parent form.errors.documents). */
    error?: string;
    disabled?: boolean;
    /** Optional aggregate upload progress 0–100 from Inertia useForm.progress. */
    progressPercent?: number | null;
    className?: string;
};

/**
 * Drag-and-drop picker for several files at once. Selected files accumulate
 * (drop/browse again to add more) instead of replacing one another, up to
 * `maxFiles`. Each file gets its own remove action.
 */
export default function MultiFileDropzone({
    inputId,
    label,
    files,
    onFilesChange,
    accept = DEFAULT_FILE_ACCEPT,
    acceptHint,
    maxBytes = DEFAULT_MAX_FILE_BYTES,
    maxFiles = DEFAULT_MAX_FILES,
    error,
    disabled = false,
    progressPercent = null,
    className,
}: MultiFileDropzoneProps) {
    const { t } = useTranslation();
    const resolvedAcceptHint = acceptHint ?? t('PDF, JPG, PNG, Word, Excel');
    const hintId = useId();
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragActive, setDragActive] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);

    const displayError = error ?? localError;
    const isUploading =
        progressPercent !== null && progressPercent !== undefined;
    const atCapacity = files.length >= maxFiles;

    const addFiles = useCallback(
        (candidates: File[]): void => {
            setLocalError(null);

            if (candidates.length === 0) {
                return;
            }

            const room = maxFiles - files.length;

            if (room <= 0) {
                setLocalError(
                    t('You can add up to :count files.', { count: maxFiles }),
                );
                return;
            }

            const accepted: File[] = [];

            for (const candidate of candidates.slice(0, room)) {
                const sizeError = validateFileSize(candidate, maxBytes, t);

                if (sizeError) {
                    setLocalError(sizeError);
                    continue;
                }

                accepted.push(candidate);
            }

            if (accepted.length > 0) {
                onFilesChange([...files, ...accepted]);
            }

            if (candidates.length > room) {
                setLocalError(
                    t('You can add up to :count files.', { count: maxFiles }),
                );
            }
        },
        [files, maxBytes, maxFiles, onFilesChange, t],
    );

    function removeFile(index: number): void {
        setLocalError(null);
        onFilesChange(files.filter((_, fileIndex) => fileIndex !== index));
    }

    function handleDragOver(event: DragEvent<HTMLElement>): void {
        event.preventDefault();
        if (!disabled) {
            setDragActive(true);
        }
    }

    function handleDragLeave(event: DragEvent<HTMLElement>): void {
        event.preventDefault();
        setDragActive(false);
    }

    function handleDrop(event: DragEvent<HTMLElement>): void {
        event.preventDefault();
        setDragActive(false);

        if (disabled) {
            return;
        }

        addFiles(Array.from(event.dataTransfer.files));
    }

    function openFilePicker(): void {
        if (!disabled && !atCapacity) {
            inputRef.current?.click();
        }
    }

    return (
        <Field
            className={className}
            data-disabled={disabled}
            data-invalid={Boolean(displayError)}
        >
            <FieldLabel htmlFor={inputId}>{label}</FieldLabel>

            <input
                ref={inputRef}
                id={inputId}
                type="file"
                multiple
                accept={accept}
                disabled={disabled}
                className="sr-only"
                aria-invalid={Boolean(displayError)}
                onChange={(event) => {
                    addFiles(Array.from(event.target.files ?? []));
                    event.target.value = '';
                }}
            />

            {files.length > 0 ? (
                <AttachmentGroup>
                    {files.map((file, index) => {
                        const FileTypeIcon = fileIconForFilename(file.name);

                        return (
                            <Attachment
                                // biome-ignore lint/suspicious/noArrayIndexKey: files have no stable identity before upload
                                key={index}
                                state={
                                    isUploading
                                        ? 'uploading'
                                        : displayError
                                          ? 'error'
                                          : 'idle'
                                }
                                orientation="vertical"
                            >
                                <AttachmentMedia>
                                    <FileTypeIcon aria-hidden />
                                </AttachmentMedia>
                                <AttachmentContent>
                                    <AttachmentTitle>
                                        {file.name}
                                    </AttachmentTitle>
                                    <AttachmentDescription>
                                        {fileTypeLabel(file)} ·{' '}
                                        {formatBytes(file.size)}
                                    </AttachmentDescription>
                                </AttachmentContent>
                                <AttachmentActions>
                                    <AttachmentAction
                                        type="button"
                                        isDisabled={disabled}
                                        aria-label={t('Remove :name', {
                                            name: file.name,
                                        })}
                                        onClick={() => removeFile(index)}
                                    >
                                        <X />
                                    </AttachmentAction>
                                </AttachmentActions>
                            </Attachment>
                        );
                    })}
                </AttachmentGroup>
            ) : null}

            {/* Inertia reports one aggregate percentage for the whole request,
                so the bar belongs to the batch rather than to each file. */}
            {isUploading ? (
                <Progress
                    value={progressPercent ?? 0}
                    aria-label={t('Uploading…')}
                >
                    <ProgressValue />
                </Progress>
            ) : null}

            {!atCapacity && (
                <button
                    type="button"
                    disabled={disabled}
                    aria-describedby={hintId}
                    onDragOver={handleDragOver}
                    onDragLeave={handleDragLeave}
                    onDrop={handleDrop}
                    onClick={openFilePicker}
                    className={cn(
                        'relative flex w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-4 py-6 text-center transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                        files.length > 0 && 'py-3',
                        dragActive
                            ? 'border-primary bg-primary/5'
                            : 'border-border/70 hover:border-border hover:bg-muted/30',
                        disabled
                            ? 'cursor-not-allowed opacity-60'
                            : 'cursor-pointer',
                    )}
                >
                    <Upload
                        className="size-8 text-muted-foreground"
                        aria-hidden
                    />
                    <span className="text-sm font-medium">
                        {files.length > 0
                            ? t('Drop more files here or click to add')
                            : t('Drop files here or click to browse')}
                    </span>
                    <span id={hintId} className="text-xs text-muted-foreground">
                        {resolvedAcceptHint} ·{' '}
                        {t('max :size per file', {
                            size: formatBytes(maxBytes),
                        })}
                    </span>
                </button>
            )}

            <InputError message={displayError ?? undefined} />
        </Field>
    );
}
