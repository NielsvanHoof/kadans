import { Form, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AudioRecordingCalendarController from '@/actions/App/Dossiers/Http/Controllers/AudioRecordingCalendarController';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { AudioRecordingCalendarLink } from '@/features/audio-recordings/types';
import type { Firm } from '@/features/workspaces/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export type CalendarConnectionStatus =
    | 'ready'
    | 'missing'
    | 'needs_reconsent'
    | 'unconfigured';

export type CalendarEventSuggestion = {
    id: string;
    summary: string;
    html_link: string | null;
    starts_at: string | null;
    ends_at: string | null;
    attendee_emails: string[];
    matches_client_email: boolean;
    provider?: string;
};

type UnlinkFormProps = {
    audioRecordingId: number;
    calendarLinkId: number;
    workspace: Firm;
};

/**
 * One-click unlink; reversible by attaching again.
 */
function CalendarUnlinkForm({
    audioRecordingId,
    calendarLinkId,
    workspace,
}: UnlinkFormProps) {
    const { t } = useTranslation();

    return (
        <Form
            {...AudioRecordingCalendarController.detach.form({
                tenant: workspace,
                audioRecording: audioRecordingId,
                calendarLink: calendarLinkId,
            })}
            options={{ preserveScroll: true }}
            onSuccess={() => {
                router.reload({
                    only: ['audio_recording', 'calendar'],
                });
            }}
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    isDisabled={processing}
                >
                    {processing && <Spinner />}
                    {t('Unlink')}
                </Button>
            )}
        </Form>
    );
}

type Props = {
    audioRecordingId: number;
    transcriptionStatus: string;
    calendarLinks: AudioRecordingCalendarLink[];
    provider: string;
    connectionStatus: CalendarConnectionStatus;
    reconnectUrl: string | null;
};

/**
 * Suggest nearby calendar meetings and attach the transcript.
 * Google is the only provider today; copy stays Google-specific where accurate.
 * One recording may link to several events; already-linked rows stay visible.
 */
export default function CalendarAttachPanel({
    audioRecordingId,
    transcriptionStatus,
    calendarLinks,
    provider,
    connectionStatus,
    reconnectUrl,
}: Props) {
    const { t } = useTranslation();
    const currentWorkspace = useCurrentWorkspace();
    const [events, setEvents] = useState<CalendarEventSuggestion[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const canSuggest =
        transcriptionStatus === 'completed' && connectionStatus === 'ready';

    const providerLinks = useMemo(
        () => calendarLinks.filter((link) => link.provider === provider),
        [calendarLinks, provider],
    );

    useEffect(() => {
        if (!canSuggest) {
            return;
        }

        let cancelled = false;
        setLoading(true);
        setError(null);

        const url = AudioRecordingCalendarController.suggestions.url({
            tenant: currentWorkspace,
            audioRecording: audioRecordingId,
        });

        // Plain fetch: suggestions are JSON, not an Inertia page.
        void fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                const payload = (await response.json()) as {
                    events?: CalendarEventSuggestion[];
                    message?: string;
                    needs_reconsent?: boolean;
                };

                if (cancelled) {
                    return;
                }

                if (!response.ok) {
                    setError(
                        payload.message ??
                            t('Could not load Google Calendar events.'),
                    );
                    setEvents([]);
                    return;
                }

                setEvents(payload.events ?? []);
            })
            .catch(() => {
                if (!cancelled) {
                    setError(t('Could not load Google Calendar events.'));
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [audioRecordingId, canSuggest, currentWorkspace, t]);

    if (transcriptionStatus !== 'completed') {
        return null;
    }

    return (
        <div className="space-y-4">
            {providerLinks.length > 0 ? (
                <div className="space-y-2">
                    <p className="text-sm font-medium">
                        {t('Attached meetings')}
                    </p>
                    <ul className="space-y-2">
                        {providerLinks.map((link) => (
                            <li
                                key={`${link.provider}-${link.event_id}`}
                                className="flex flex-wrap items-center gap-2 text-sm"
                            >
                                <span>
                                    {t(
                                        'Transcript attached to a calendar event.',
                                    )}
                                </span>
                                {link.html_link ? (
                                    <a
                                        className={cn(
                                            buttonVariants({
                                                variant: 'outline',
                                                size: 'sm',
                                            }),
                                        )}
                                        href={link.html_link}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {t('Open in Google Calendar')}
                                    </a>
                                ) : null}
                                {connectionStatus === 'ready' ? (
                                    <CalendarUnlinkForm
                                        audioRecordingId={audioRecordingId}
                                        calendarLinkId={link.id}
                                        workspace={currentWorkspace}
                                    />
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {(connectionStatus === 'missing' ||
                connectionStatus === 'needs_reconsent') &&
            reconnectUrl ? (
                <div className="space-y-2">
                    <p className="text-muted-foreground text-sm">
                        {connectionStatus === 'needs_reconsent'
                            ? t(
                                  'Reconnect Google to allow calendar and Drive access.',
                              )
                            : t(
                                  'Connect Google in Integrations to attach transcripts to meetings.',
                              )}
                    </p>
                    {/*
                        Plain <a>: OAuth redirect must be a full browser hop.
                    */}
                    <a
                        className={buttonVariants({ variant: 'outline' })}
                        href={reconnectUrl}
                    >
                        {t('Reconnect Google')}
                    </a>
                </div>
            ) : null}

            {connectionStatus === 'unconfigured' ? (
                <p className="text-muted-foreground text-sm">
                    {t(
                        'Google Calendar is not configured on this environment.',
                    )}
                </p>
            ) : null}

            {canSuggest ? (
                <div className="space-y-3">
                    {loading ? (
                        <p className="text-muted-foreground flex items-center gap-2 text-sm">
                            <Spinner className="size-4 shrink-0" />
                            {t('Loading meetings...')}
                        </p>
                    ) : null}

                    {error ? (
                        <p className="text-destructive text-sm">{error}</p>
                    ) : null}

                    {!loading && !error && events.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t(
                                'No meetings found in the week around this recording.',
                            )}
                        </p>
                    ) : null}

                    {events.map((event) => {
                        const linked = providerLinks.find(
                            (link) => link.event_id === event.id,
                        );
                        const alreadyLinked = linked !== undefined;

                        return (
                            <div
                                key={event.id}
                                className="border-border flex flex-col gap-2 border-b py-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="space-y-1">
                                    <p className="font-medium">
                                        {event.summary}
                                        {event.matches_client_email ? (
                                            <span className="text-muted-foreground ml-2 text-xs font-normal">
                                                {t('Matches client email')}
                                            </span>
                                        ) : null}
                                    </p>
                                    {event.starts_at ? (
                                        <p className="text-muted-foreground text-sm">
                                            {event.starts_at}
                                        </p>
                                    ) : null}
                                </div>

                                {alreadyLinked && linked ? (
                                    connectionStatus === 'ready' ? (
                                        <CalendarUnlinkForm
                                            audioRecordingId={audioRecordingId}
                                            calendarLinkId={linked.id}
                                            workspace={currentWorkspace}
                                        />
                                    ) : (
                                        <span className="text-muted-foreground text-sm">
                                            {t('Already linked')}
                                        </span>
                                    )
                                ) : (
                                    <Form
                                        {...AudioRecordingCalendarController.attach.form(
                                            {
                                                tenant: currentWorkspace,
                                                audioRecording:
                                                    audioRecordingId,
                                            },
                                        )}
                                        options={{ preserveScroll: true }}
                                        onSuccess={() => {
                                            router.reload({
                                                only: [
                                                    'audio_recording',
                                                    'calendar',
                                                ],
                                            });
                                        }}
                                    >
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="event_id"
                                                    value={event.id}
                                                />
                                                <input
                                                    type="hidden"
                                                    name="provider"
                                                    value={provider}
                                                />
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    size="sm"
                                                    isDisabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    {t('Attach transcript')}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </div>
                        );
                    })}
                </div>
            ) : null}
        </div>
    );
}
