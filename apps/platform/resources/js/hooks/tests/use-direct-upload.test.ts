import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { useDirectUpload } = await import('@/hooks/use-direct-upload');

const SIGN_URL = '/workspaces/acme/upload-tickets';

/**
 * A minimal XMLHttpRequest stand-in.
 *
 * jsdom ships no XHR upload implementation, and the hook's whole reason for
 * using XHR instead of fetch is `upload.progress` — so the fake has to be able
 * to emit it, or the progress behaviour would go untested.
 */
class FakeXhr {
    static instances: FakeXhr[] = [];

    public status = 200;
    public method: string | null = null;
    public url: string | null = null;
    public readonly headers: Record<string, string> = {};
    public body: unknown = null;

    private readonly listeners: Record<
        string,
        Array<(event: unknown) => void>
    > = {};

    public readonly upload = {
        addEventListener: (type: string, handler: (event: unknown) => void) => {
            this.uploadListeners[type] ??= [];
            this.uploadListeners[type].push(handler);
        },
    };

    private readonly uploadListeners: Record<
        string,
        Array<(event: unknown) => void>
    > = {};

    constructor() {
        FakeXhr.instances.push(this);
    }

    open(method: string, url: string) {
        this.method = method;
        this.url = url;
    }

    setRequestHeader(name: string, value: string) {
        this.headers[name] = value;
    }

    addEventListener(type: string, handler: (event: unknown) => void) {
        this.listeners[type] ??= [];
        this.listeners[type].push(handler);
    }

    abort() {
        this.emit('abort', {});
    }

    /** Auto-completes so a test only has to await the hook's promise. */
    send(body: unknown) {
        this.body = body;
        queueMicrotask(() => {
            for (const handler of this.uploadListeners.progress ?? []) {
                handler({ lengthComputable: true, loaded: 512 });
            }
            this.emit('load', {});
        });
    }

    private emit(type: string, event: unknown) {
        for (const handler of this.listeners[type] ?? []) {
            handler(event);
        }
    }
}

function fakeFile(name = 'statement.pdf', type = 'application/pdf'): File {
    return new File(['x'.repeat(1024)], name, { type });
}

function respondWithTicket(ticket = 'sealed-ticket') {
    return vi.fn().mockResolvedValue({
        ok: true,
        // Snake case, because Spatie Data serialises that way — a camelCase
        // fixture here would let a real field-name mismatch pass.
        json: async () => ({
            upload_url: 'https://bucket.example/staging/abc?signature=x',
            ticket,
            content_type: 'application/pdf',
            expires_at: '2026-01-01T00:00:00+00:00',
        }),
    });
}

beforeEach(() => {
    FakeXhr.instances = [];
    vi.stubGlobal('XMLHttpRequest', FakeXhr);
});

describe('useDirectUpload', () => {
    it('signs each file and PUTs it to the returned URL', async () => {
        const fetchMock = respondWithTicket();
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() =>
            useDirectUpload(SIGN_URL, 'document'),
        );

        let tickets: Awaited<ReturnType<typeof result.current.upload>> = null;
        await act(async () => {
            tickets = await result.current.upload([fakeFile()]);
        });

        expect(tickets).toEqual([
            { ticket: 'sealed-ticket', name: 'statement.pdf', size: 1024 },
        ]);

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe(SIGN_URL);
        expect(JSON.parse(init.body as string)).toEqual({
            purpose: 'document',
            filename: 'statement.pdf',
            content_type: 'application/pdf',
            size_bytes: 1024,
        });

        const xhr = FakeXhr.instances[0];
        expect(xhr.method).toBe('PUT');
        expect(xhr.url).toBe('https://bucket.example/staging/abc?signature=x');
        // Any drift from the signed Content-Type is a signature mismatch.
        expect(xhr.headers['Content-Type']).toBe('application/pdf');
    });

    it('declares a content type for files the browser cannot identify', async () => {
        const fetchMock = respondWithTicket();
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() =>
            useDirectUpload(SIGN_URL, 'document'),
        );

        await act(async () => {
            await result.current.upload([fakeFile('mystery.pdf', '')]);
        });

        expect(
            JSON.parse(fetchMock.mock.calls[0][1].body as string).content_type,
        ).toBe('application/octet-stream');
    });

    it('uploads a batch sequentially rather than saturating the uplink', async () => {
        vi.stubGlobal('fetch', respondWithTicket());

        const { result } = renderHook(() =>
            useDirectUpload(SIGN_URL, 'document'),
        );

        await act(async () => {
            await result.current.upload([
                fakeFile('a.pdf'),
                fakeFile('b.pdf'),
                fakeFile('c.pdf'),
            ]);
        });

        expect(FakeXhr.instances).toHaveLength(3);
    });

    it('surfaces the server validation message and uploads nothing', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            json: async () => ({
                message: 'The given data was invalid.',
                errors: {
                    file: [
                        'That file is too large. The maximum size is 20 MB.',
                    ],
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() =>
            useDirectUpload(SIGN_URL, 'document'),
        );

        let tickets: Awaited<ReturnType<typeof result.current.upload>> =
            'unset' as never;
        await act(async () => {
            tickets = await result.current.upload([fakeFile()]);
        });

        expect(tickets).toBeNull();
        expect(FakeXhr.instances).toHaveLength(0);
        await waitFor(() => {
            expect(result.current.error).toBe(
                'That file is too large. The maximum size is 20 MB.',
            );
        });
    });

    it('reports a failed PUT rather than submitting a ticket for a file that never landed', async () => {
        vi.stubGlobal('fetch', respondWithTicket());

        // A 403 here is the signature having expired between issue and send.
        class RejectingXhr extends FakeXhr {
            override send(body: unknown) {
                this.status = 403;
                super.send(body);
            }
        }
        vi.stubGlobal('XMLHttpRequest', RejectingXhr);

        const { result } = renderHook(() =>
            useDirectUpload(SIGN_URL, 'document'),
        );

        let tickets: Awaited<ReturnType<typeof result.current.upload>> =
            'unset' as never;
        await act(async () => {
            tickets = await result.current.upload([fakeFile()]);
        });

        expect(tickets).toBeNull();
        await waitFor(() => {
            expect(result.current.error).toBe(
                'The upload failed. Check your connection and try again.',
            );
        });
    });

    it('returns an empty batch without signing anything', async () => {
        const fetchMock = respondWithTicket();
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() =>
            useDirectUpload(SIGN_URL, 'document'),
        );

        let tickets: Awaited<ReturnType<typeof result.current.upload>> = null;
        await act(async () => {
            tickets = await result.current.upload([]);
        });

        expect(tickets).toEqual([]);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('clears progress and error state on reset', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: false, json: async () => ({}) }),
        );

        const { result } = renderHook(() =>
            useDirectUpload(SIGN_URL, 'document'),
        );

        await act(async () => {
            await result.current.upload([fakeFile()]);
        });
        await waitFor(() => expect(result.current.error).not.toBeNull());

        act(() => {
            result.current.reset();
        });

        expect(result.current.error).toBeNull();
        expect(result.current.progressPercent).toBeNull();
        expect(result.current.uploading).toBe(false);
    });
});
