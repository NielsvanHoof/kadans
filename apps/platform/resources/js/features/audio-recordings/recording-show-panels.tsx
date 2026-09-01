import type { ReactNode } from 'react';
import ErrorState from '@/components/primitives/error-state';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import CalendarAttachPanel from '@/features/audio-recordings/calendar-attach-panel';
import type { RecordingShowTab } from '@/features/audio-recordings/recording-show-tab';
import TranscriptView from '@/features/audio-recordings/transcript-view/transcript-view';
import type { AudioRecordingShowProps } from '@/features/audio-recordings/types';
import { useTranslation } from '@/hooks/use-translation';

type AudioRecordingDetail = AudioRecordingShowProps['audio_recording'];

/**
 * Transcript and Calendar behind one pill strip, matching the dossier workbench.
 *
 * The Calendar count is attached meetings for the current provider. Zero is
 * omitted so a number only appears when there is something behind the tab.
 */
export default function RecordingShowPanels({
    audioRecording,
    calendar,
    currentTimeSeconds,
    tab,
    onSelectTab,
}: {
    audioRecording: AudioRecordingDetail;
    calendar: AudioRecordingShowProps['calendar'];
    currentTimeSeconds: number;
    tab: RecordingShowTab;
    onSelectTab: (tab: RecordingShowTab) => void;
}) {
    const { t } = useTranslation();

    const isProcessing =
        audioRecording.transcription_status === 'pending' ||
        audioRecording.transcription_status === 'processing';
    const isCompleted = audioRecording.transcription_status === 'completed';

    const attachedCount = audioRecording.calendar_links.filter(
        (link) => link.provider === calendar.provider,
    ).length;

    return (
        <Card className="min-w-0 gap-0 overflow-hidden py-0">
            <Tabs
                selectedKey={tab}
                onSelectionChange={(key) =>
                    onSelectTab(String(key) as RecordingShowTab)
                }
                className="gap-0"
            >
                <CardHeader className="bg-card sticky top-0 z-10 touch-manipulation border-b py-3 lg:static">
                    <ScrollArea className="w-full">
                        <TabsList aria-label={t('Recording panels')}>
                            <TabsTrigger
                                id="transcript"
                                className="gap-1.5 text-sm"
                            >
                                {t('Transcript')}
                            </TabsTrigger>
                            <TabsTrigger
                                id="calendar"
                                className="gap-1.5 text-sm"
                            >
                                {t('Calendar')}
                                <PanelCount value={attachedCount} />
                            </TabsTrigger>
                        </TabsList>
                    </ScrollArea>
                </CardHeader>

                <CardContent className="py-5">
                    <TabsContent
                        id="transcript"
                        className="scroll-mt-20 outline-none"
                    >
                        {audioRecording.transcription_status === 'failed' &&
                        audioRecording.transcription_error ? (
                            <ErrorState
                                variant="inline"
                                title={t('Transcription failed')}
                                description={audioRecording.transcription_error}
                            />
                        ) : null}

                        {isProcessing ? (
                            <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                <Spinner className="size-4 shrink-0" />
                                {t(
                                    'Transcription is still running. The transcript will be ready shortly.',
                                )}
                            </p>
                        ) : null}

                        {isCompleted ? (
                            <TranscriptView
                                transcript={audioRecording.transcript}
                                segments={audioRecording.segments}
                                currentTimeSeconds={currentTimeSeconds}
                            />
                        ) : null}
                    </TabsContent>

                    <TabsContent
                        id="calendar"
                        className="scroll-mt-20 outline-none"
                    >
                        {isCompleted ? (
                            <PanelBody
                                lead={t(
                                    'Attach this transcript to a meeting on your Google Calendar.',
                                )}
                            >
                                <CalendarAttachPanel
                                    audioRecordingId={audioRecording.id}
                                    transcriptionStatus={
                                        audioRecording.transcription_status
                                    }
                                    calendarLinks={
                                        audioRecording.calendar_links
                                    }
                                    provider={calendar.provider}
                                    connectionStatus={
                                        calendar.connection_status
                                    }
                                    reconnectUrl={calendar.reconnect_url}
                                />
                            </PanelBody>
                        ) : (
                            <p className="text-muted-foreground max-w-prose text-sm">
                                {t('Available when the transcript is ready.')}
                            </p>
                        )}
                    </TabsContent>
                </CardContent>
            </Tabs>
        </Card>
    );
}

/** Mono numeral beside a tab label; absent when there is nothing to count. */
function PanelCount({ value }: { value: number }) {
    if (value === 0) {
        return null;
    }

    return (
        <span className="font-data text-[0.6875rem] tabular-nums opacity-70">
            {value}
        </span>
    );
}

function PanelBody({
    lead,
    children,
}: {
    lead: string;
    children: ReactNode;
}): ReactNode {
    return (
        <div className="space-y-4 text-sm">
            <p className="text-muted-foreground max-w-prose text-sm">{lead}</p>
            {children}
        </div>
    );
}
