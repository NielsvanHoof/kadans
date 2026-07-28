import { FileIcon, Upload, X } from 'lucide-react';
import type { DragEvent } from 'react';
import { useCallback, useId, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { Progress } from '@/components/ui/progress';
import { useTranslation } from '@/hooks/use-translation';
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

/**
 * Drag-and-drop file picker with client-side size validation.
 * Structured for single-file use today; multi-file can extend selectedFiles[] later.
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

    function handleDragOver(event: DragEvent<HTMLButtonElement>): void {
        event.preventDefault();
        if (!disabled) {
            setDragActive(true);
        }
    }

    function handleDragLeave(event: DragEvent<HTMLButtonElement>): void {
        event.preventDefault();
        setDragActive(false);
    }

    function handleDrop(event: DragEvent<HTMLButtonElement>): void {
        event.preventDefault();
        setDragActive(false);

        if (disabled) {
            return;
        }

        const dropped = event.dataTransfer.files.item(0) ?? null;
        validateAndSet(dropped);
    }

    function clearSelection(): void {
        validateAndSet(null);
        if (inputRef.current) {
            inputRef.current.value = '';
        }
    }

    const displayError = error ?? localError;

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

            {/*
              Semantic <button> is both the drop target and browse trigger.
              Clear stays outside so we never nest interactive controls.
            */}
            <button
                type="button"
                disabled={disabled}
                aria-describedby={hintId}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
                onClick={() => {
                    if (!disabled) {
                        inputRef.current?.click();
                    }
                }}
                className={cn(
                    'relative flex w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-4 py-6 text-center transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                    dragActive
                        ? 'border-primary bg-primary/5'
                        : 'border-border/70 hover:border-border hover:bg-muted/30',
                    disabled
                        ? 'cursor-not-allowed opacity-60'
                        : 'cursor-pointer',
                    file && 'py-4',
                )}
            >
                {file ? (
                    <>
                        <FileIcon
                            className="size-8 text-muted-foreground"
                            aria-hidden
                        />
                        <span className="min-w-0 space-y-0.5">
                            <span className="block truncate text-sm font-medium">
                                {file.name}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {formatBytes(file.size)}
                            </span>
                        </span>
                        <span
                            id={hintId}
                            className="text-xs text-muted-foreground"
                        >
                            {t('Drop to replace or click to browse')}
                        </span>
                    </>
                ) : (
                    <>
                        <Upload
                            className="size-8 text-muted-foreground"
                            aria-hidden
                        />
                        <span className="text-sm font-medium">
                            {t('Drop a file here or click to browse')}
                        </span>
                        <span
                            id={hintId}
                            className="text-xs text-muted-foreground"
                        >
                            {resolvedAcceptHint} ·{' '}
                            {t('max :size', { size: formatBytes(maxBytes) })}
                        </span>
                    </>
                )}
            </button>

            {file ? (
                <div className="flex">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={disabled}
                        onClick={clearSelection}
                    >
                        <X aria-hidden />
                        {t('Clear')}
                    </Button>
                </div>
            ) : null}

            {progressPercent !== null && progressPercent !== undefined ? (
                <Progress
                    value={progressPercent}
                    max={100}
                    aria-label={t('Upload progress')}
                    className="w-full"
                />
            ) : null}

            <InputError message={displayError ?? undefined} />
        </Field>
    );
}
