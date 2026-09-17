import { useCallback, useRef, useState } from 'react';
import { useTranslation } from '@/hooks/use-translation';

/**
 * A file that has been PUT to object storage and is waiting to be claimed.
 * `ticket` is opaque: it is submitted verbatim with the form.
 */
export type UploadedTicket = {
    ticket: string;
    name: string;
    size: number;
};

/*
 * The generated ambient type, not a hand-written mirror of it: Spatie Data
 * serialises with a snake_case output mapper (config/data.php), so a local
 * camelCase copy compiles perfectly and reads undefined at runtime.
 */
type TicketResponse = App.Dossiers.Data.Uploads.UploadTicketData;

type DirectUploadState = {
    /** 0–100 across the whole batch, or null when idle. */
    progressPercent: number | null;
    uploading: boolean;
    error: string | null;
};

/** Matches the PHP UploadPurpose enum; decides which ceiling applies. */
export type UploadPurpose = 'document' | 'audio_recording';

export type UploadTicketRequestData = Record<string, string | number>;

/**
 * Upload files straight to object storage, bypassing the application.
 *
 * The application signs a PUT and the browser sends the bytes to S3 itself, so
 * request-body limits (PHP's post_max_size, the edge's own cap) never apply and
 * a 200 MB batch costs the app nothing but a few signatures. The form then
 * submits tickets — small opaque strings — instead of a multipart body.
 *
 * `signUrl` is passed in rather than imported so this stays domain-agnostic:
 * staff and the client portal sign against different endpoints with different
 * authorization, and neither belongs in a top-level hook.
 *
 * Progress is tracked with XMLHttpRequest because fetch() still cannot report
 * upload progress in any browser — a progress bar is the whole reason a user
 * tolerates a slow upload, so this is not a detail worth simplifying away.
 */
export function useDirectUpload(
    signUrl: string,
    purpose: UploadPurpose,
    ticketRequestData?: UploadTicketRequestData,
) {
    const { t } = useTranslation();
    const [state, setState] = useState<DirectUploadState>({
        progressPercent: null,
        uploading: false,
        error: null,
    });

    // Tracks the in-flight requests so an unmount or a reset can abort them
    // instead of leaving a large PUT running against a page that is gone.
    const inFlight = useRef<XMLHttpRequest[]>([]);

    const reset = useCallback(() => {
        for (const request of inFlight.current) {
            request.abort();
        }
        inFlight.current = [];
        setState({ progressPercent: null, uploading: false, error: null });
    }, []);

    const upload = useCallback(
        async (files: File[]): Promise<UploadedTicket[] | null> => {
            if (files.length === 0) {
                return [];
            }

            setState({ progressPercent: 0, uploading: true, error: null });

            const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
            const sentBytes = Array.from({ length: files.length }, () => 0);

            const reportProgress = () => {
                const sent = sentBytes.reduce((sum, bytes) => sum + bytes, 0);
                setState((current) => ({
                    ...current,
                    progressPercent:
                        totalBytes === 0
                            ? 100
                            : Math.round((sent / totalBytes) * 100),
                }));
            };

            try {
                const tickets: UploadedTicket[] = [];

                /*
                 * Sequential, not parallel. Ten concurrent 20 MB PUTs saturate
                 * a domestic uplink and make every one of them slower, and the
                 * progress bar becomes meaningless. The files are also claimed
                 * in order server-side, so there is nothing to gain.
                 */
                for (const [index, file] of files.entries()) {
                    const signed = await signUpload(
                        signUrl,
                        purpose,
                        file,
                        ticketRequestData,
                    );

                    await putToStorage(
                        signed,
                        file,
                        (bytes) => {
                            sentBytes[index] = bytes;
                            reportProgress();
                        },
                        inFlight.current,
                    );

                    sentBytes[index] = file.size;
                    reportProgress();

                    tickets.push({
                        ticket: signed.ticket,
                        name: file.name,
                        size: file.size,
                    });
                }

                setState({
                    progressPercent: null,
                    uploading: false,
                    error: null,
                });

                return tickets;
            } catch (error) {
                setState({
                    progressPercent: null,
                    uploading: false,
                    error:
                        error instanceof UploadError
                            ? error.message
                            : t(
                                  'The upload failed. Check your connection and try again.',
                              ),
                });

                return null;
            } finally {
                inFlight.current = [];
            }
        },
        [purpose, signUrl, t, ticketRequestData],
    );

    return { upload, reset, ...state };
}

/** Carries a server-supplied message through to the form's error slot. */
class UploadError extends Error {}

async function signUpload(
    signUrl: string,
    purpose: UploadPurpose,
    file: File,
    ticketRequestData?: UploadTicketRequestData,
): Promise<TicketResponse> {
    const response = await window.fetch(signUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            ...ticketRequestData,
            purpose,
            filename: file.name,
            // Browsers leave this empty for types they do not recognise. The
            // server does not trust this value — it derives the stored mime
            // type from the filename — but S3 records whatever arrives, so
            // send something rather than an empty header.
            content_type: file.type || 'application/octet-stream',
            size_bytes: file.size,
        }),
    });

    if (!response.ok) {
        throw new UploadError(await validationMessage(response));
    }

    return (await response.json()) as TicketResponse;
}

/** Pull Laravel's first validation message out of a 422, if there is one. */
async function validationMessage(response: Response): Promise<string> {
    try {
        const body = (await response.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        const first = body.errors
            ? Object.values(body.errors)[0]?.[0]
            : undefined;

        return first ?? body.message ?? '';
    } catch {
        return '';
    }
}

function putToStorage(
    signed: TicketResponse,
    file: File,
    onProgress: (bytes: number) => void,
    inFlight: XMLHttpRequest[],
): Promise<void> {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        inFlight.push(request);

        request.open('PUT', signed.upload_url, true);
        request.setRequestHeader('Content-Type', signed.content_type);

        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded);
            }
        });

        request.addEventListener('load', () => {
            if (request.status >= 200 && request.status < 300) {
                resolve();
            } else {
                reject(
                    new Error(
                        `Storage rejected the upload (${request.status})`,
                    ),
                );
            }
        });

        request.addEventListener('error', () =>
            reject(new Error('Network error during upload')),
        );
        request.addEventListener('abort', () =>
            reject(new Error('Upload aborted')),
        );

        request.send(file);
    });
}
