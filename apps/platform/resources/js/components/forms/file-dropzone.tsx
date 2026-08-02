import { Upload, X } from 'lucide-react';
import type { DragEvent } from 'react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import InputError from '@/components/primitives/input-error';
import {
    Attachment,
    AttachmentAction,
    AttachmentActions,
    AttachmentContent,
    AttachmentDescription,
    AttachmentMedia,
    AttachmentTitle,
    AttachmentTrigger,
} from '@/components/ui/attachment';
import { Field, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { fileIconForFilename } from '@/lib/file-icon-for-filename';
import { formatBytes } from '@/lib/format-bytes';
import { cn } from '@/lib/utils';

/** Default accepted extensions for document uploads. */
export const DEFAULT_FILE_ACCEPT =
    '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx';

export const DEFAULT_MAX_FILE_BYTES = 10 * 1024 * 1024;

type FileDropzoneProps = {
    inputId: string;
    label: string;
    file: File | null;
    onFileChange: (file: File | null) => void;
    accept?: string;
    acceptHint?: string;
    maxBytes?: number;
    /** Client-side validation error (e.g. from parent form.errors.document). */
    error?: string;
    disabled?: boolean;
    /** Optional upload progress 0–100 from Inertia useForm.progress. */
    progressPercent?: number | null;
    className?: string;
};

type AttachmentState = 'idle' | 'uploading' | 'error';

/**
 * Resolve Attachment visual state from validation + Inertia upload progress.
 * `idle` keeps the dashed border for a selected-but-not-yet-uploaded file.
 */
function resolveAttachmentState(
    hasError: boolean,
    progressPercent: number | null | undefined,
): AttachmentState {
    if (hasError) {
        return 'error';
    }

    if (progressPercent !== null && progressPercent !== undefined) {
        return 'uploading';
    }

    return 'idle';
}

/** Short type label for the attachment description (e.g. PDF · 1.2 MB). */
function fileTypeLabel(file: File): string {
    const extension = file.name.split('.').pop()?.toUpperCase();

    return extension && extension.length <= 5 ? extension : 'FILE';
}

/**
 * Drag-and-drop file picker with client-side size validation.
 * Empty state is a dashed browse target; a selected file becomes an Attachment window.
 */
export default function FileDropzone({
    inputId,
    label,
    file,
    onFileChange,
    accept = DEFAULT_FILE_ACCEPT,
    acceptHint,
    maxBytes = DEFAULT_MAX_FILE_BYTES,
    error,
    disabled = false,
    progressPercent = null,
    className,
}: FileDropzoneProps) {
    const { t } = useTranslation();
    const resolvedAcceptHint = acceptHint ?? t('PDF, JPG, PNG, Word, Excel');
    const hintId = useId();
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragActive, setDragActive] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);

    const displayError = error ?? localError;
    const attachmentState = resolveAttachmentState(
        Boolean(displayError),
        progressPercent,
    );

    // Object URL for image thumbnails in AttachmentMedia; revoke on change/unmount.
    useEffect(() => {
        if (!file?.type.startsWith('image/')) {
            setPreviewUrl(null);

            return;
        }

        const objectUrl = URL.createObjectURL(file);
        setPreviewUrl(objectUrl);

        return () => {
            URL.revokeObjectURL(objectUrl);
        };
    }, [file]);

    const FileTypeIcon = file ? fileIconForFilename(file.name) : null;

    const validateAndSet = useCallback(
        (nextFile: File | null): void => {
            setLocalError(null);

            if (nextFile === null) {
                onFileChange(null);
                return;
            }

            if (nextFile.size > maxBytes) {
                setLocalError(
                    t('File is too large. Maximum size is :size.', {
                        size: formatBytes(maxBytes),
                    }),
                );
                onFileChange(null);
                return;
            }

            onFileChange(nextFile);
        },
        [maxBytes, onFileChange, t],
    );

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

        const dropped = event.dataTransfer.files.item(0) ?? null;
        validateAndSet(dropped);
    }

    function openFilePicker(): void {
        if (!disabled) {
            inputRef.current?.click();
        }
    }

    function clearSelection(): void {
        validateAndSet(null);
        if (inputRef.current) {
            inputRef.current.value = '';
        }
    }

    const attachmentDescription = (() => {
        if (displayError) {
            return displayError;
        }

        if (progressPercent !== null && progressPercent !== undefined && file) {
            return t('Uploading · :percent%', {
                percent: String(progressPercent),
            });
        }

        if (file) {
            return `${fileTypeLabel(file)} · ${formatBytes(file.size)}`;
        }

        return null;
    })();

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
                accept={accept}
                disabled={disabled}
                className="sr-only"
                aria-invalid={Boolean(displayError)}
                onChange={(event) =>
                    validateAndSet(event.target.files?.[0] ?? null)
                }
            />

            {file ? (
                /*
                  Selected file: Attachment window with replace trigger + clear action.
                  Drag/drop lives on AttachmentTrigger (a real button) so the wrapper
                  stays a static layout element for a11y lint.
                */
                <div
                    className={cn(
                        'rounded-xl transition-colors',
                        dragActive && 'bg-primary/5 ring-1 ring-primary/40',
                        disabled && 'opacity-60',
                    )}
                >
                    <Attachment
                        state={attachmentState}
                        className="w-full max-w-full"
                    >
                        <AttachmentMedia
                            variant={previewUrl ? 'image' : 'icon'}
                        >
                            {attachmentState === 'uploading' ? (
                                <Spinner />
                            ) : previewUrl ? (
                                <img
                                    src={previewUrl}
                                    alt=""
                                    className="size-full object-cover"
                                />
                            ) : FileTypeIcon ? (
                                <FileTypeIcon aria-hidden />
                            ) : null}
                        </AttachmentMedia>

                        <AttachmentContent>
                            <AttachmentTitle>{file.name}</AttachmentTitle>
                            {attachmentDescription ? (
                                <AttachmentDescription>
                                    {attachmentDescription}
                                </AttachmentDescription>
                            ) : null}
                        </AttachmentContent>

                        <AttachmentActions>
                            <AttachmentAction
                                type="button"
                                isDisabled={disabled}
                                aria-label={t('Remove :name', {
                                    name: file.name,
                                })}
                                onClick={clearSelection}
                            >
                                <X />
                            </AttachmentAction>
                        </AttachmentActions>

                        {/* Full-card browse + drop target; actions sit above it (z-20). */}
                        <AttachmentTrigger
                            disabled={disabled}
                            aria-describedby={hintId}
                            aria-label={t('Replace :name', {
                                name: file.name,
                            })}
                            onClick={openFilePicker}
                            onDragOver={handleDragOver}
                            onDragLeave={handleDragLeave}
                            onDrop={handleDrop}
                        />
                    </Attachment>

                    <p
                        id={hintId}
                        className="mt-1.5 text-xs text-muted-foreground"
                    >
                        {t('Drop to replace or click to browse')}
                    </p>
                </div>
            ) : (
                /*
                  Empty state: semantic button is both drop target and browse trigger.
                */
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
                        {t('Drop a file here or click to browse')}
                    </span>
                    <span id={hintId} className="text-xs text-muted-foreground">
                        {resolvedAcceptHint} ·{' '}
                        {t('max :size', { size: formatBytes(maxBytes) })}
                    </span>
                </button>
            )}

            <InputError message={displayError ?? undefined} />
        </Field>
    );
}
