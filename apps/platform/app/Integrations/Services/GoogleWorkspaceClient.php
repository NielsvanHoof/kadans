<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Data\CalendarEventData;
use App\Integrations\Data\Google\CalendarEventItemData;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Http\Connectors\Google\GoogleConnector;
use App\Integrations\Http\Connectors\Google\Requests\Calendar\GetEventRequest;
use App\Integrations\Http\Connectors\Google\Requests\Calendar\ListPrimaryEventsRequest;
use App\Integrations\Http\Connectors\Google\Requests\Calendar\PatchEventAttachmentsRequest;
use App\Integrations\Http\Connectors\Google\Requests\Drive\UpdateDriveFileMetadataRequest;
use App\Integrations\Http\Connectors\Google\Requests\Drive\UploadPlainTextMediaRequest;
use Carbon\CarbonInterface;
use Saloon\Exceptions\Request\RequestException;

use function in_array;
use function mb_strtolower;

/**
 * Google Calendar + Drive API driver used by dossier calendar features.
 */
final readonly class GoogleWorkspaceClient
{
    /**
     * @return list<CalendarEventData>
     *
     * @throws RequestException
     */
    public function listPrimaryEvents(
        GoogleConnector $connector,
        CarbonInterface $timeMin,
        CarbonInterface $timeMax,
        ?string $clientEmail = null,
    ): array {
        $request = new ListPrimaryEventsRequest($timeMin, $timeMax);
        $response = $connector->send($request);
        $response->throw();

        $list = $request->createDtoFromResponse($response);
        $normalizedClient = $clientEmail === null ? null : mb_strtolower(mb_trim($clientEmail));

        $events = array_map(
            function (CalendarEventItemData $item) use ($normalizedClient): CalendarEventData {
                $matches = $normalizedClient !== null
                    && in_array($normalizedClient, $item->attendeeEmails, true);

                return new CalendarEventData(
                    id: $item->id,
                    summary: $item->summary ?? __('(No title)'),
                    htmlLink: $item->htmlLink,
                    startsAt: $item->startsAt,
                    endsAt: $item->endsAt,
                    attendeeEmails: $item->attendeeEmails,
                    matchesClientEmail: $matches,
                    provider: OAuthProvider::Google,
                );
            },
            $list->items,
        );

        // Client-email matches first, then chronological by start.
        usort(
            $events,
            static function (CalendarEventData $left, CalendarEventData $right): int {
                if ($left->matchesClientEmail !== $right->matchesClientEmail) {
                    return $left->matchesClientEmail ? -1 : 1;
                }

                return strcmp((string) $left->startsAt, (string) $right->startsAt);
            },
        );

        return $events;
    }

    /**
     * Upload transcript text to Drive and attach it to a calendar event.
     *
     * @return array{event_id: string, html_link: string|null, drive_file_id: string}
     *
     * @throws RequestException
     */
    public function attachTranscriptToEvent(
        GoogleConnector $connector,
        string $eventId,
        string $transcript,
        string $filename,
    ): array {
        $uploadRequest = new UploadPlainTextMediaRequest($transcript);
        $uploadResponse = $connector->send($uploadRequest);
        $uploadResponse->throw();
        $uploaded = $uploadRequest->createDtoFromResponse($uploadResponse);

        $metaRequest = new UpdateDriveFileMetadataRequest($uploaded->id, $filename);
        $metaResponse = $connector->send($metaRequest);
        $metaResponse->throw();
        $file = $metaRequest->createDtoFromResponse($metaResponse);

        $webViewLink = $file->webViewLink
            ?? 'https://drive.google.com/file/d/'.$file->id.'/view';
        $mimeType = $file->mimeType ?? 'text/plain';
        $title = $file->name ?? $filename;

        $getRequest = new GetEventRequest($eventId);
        $getResponse = $connector->send($getRequest);
        $getResponse->throw();
        $event = $getRequest->createDtoFromResponse($getResponse);

        $attachments = $event->attachments;
        $attachments[] = [
            'fileUrl' => $webViewLink,
            'mimeType' => $mimeType,
            'title' => $title,
        ];

        $patchRequest = new PatchEventAttachmentsRequest($eventId, $attachments);
        $patchResponse = $connector->send($patchRequest);
        $patchResponse->throw();
        $patched = $patchRequest->createDtoFromResponse($patchResponse);

        return [
            'event_id' => $eventId,
            'html_link' => $patched->htmlLink ?? $event->htmlLink,
            'drive_file_id' => $file->id,
        ];
    }
}
