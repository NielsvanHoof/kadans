<?php

declare(strict_types=1);

namespace App\Integrations\Data\Google;

use Saloon\Http\Response;

use function is_array;
use function is_string;

/**
 * Google Calendar event resource fields used when reading/patching attachments.
 *
 * @phpstan-type AttachmentShape array{fileUrl: string, mimeType: string, title: string, fileId?: string}
 */
final readonly class CalendarEventResourceData
{
    /**
     * @param  list<AttachmentShape>  $attachments
     */
    public function __construct(
        public ?string $id,
        public ?string $htmlLink,
        public array $attachments,
    ) {}

    public static function fromResponse(Response $response): self
    {
        /** @var array{id?: mixed, htmlLink?: mixed, attachments?: mixed} $data */
        $data = $response->json();

        $id = $data['id'] ?? null;
        $htmlLink = $data['htmlLink'] ?? null;
        $rawAttachments = $data['attachments'] ?? [];

        $attachments = [];

        if (is_array($rawAttachments)) {
            foreach ($rawAttachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }

                $fileUrl = $attachment['fileUrl'] ?? null;
                $mimeType = $attachment['mimeType'] ?? null;
                $title = $attachment['title'] ?? null;
                $fileId = $attachment['fileId'] ?? null;

                if (! is_string($fileUrl) || $fileUrl === '') {
                    continue;
                }

                $item = [
                    'fileUrl' => $fileUrl,
                    'mimeType' => is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream',
                    'title' => is_string($title) && $title !== '' ? $title : 'attachment',
                ];

                // fileId is read-only on Google's EventAttachment; used to match our Drive file.
                if (is_string($fileId) && $fileId !== '') {
                    $item['fileId'] = $fileId;
                }

                $attachments[] = $item;
            }
        }

        return new self(
            id: is_string($id) && $id !== '' ? $id : null,
            htmlLink: is_string($htmlLink) && $htmlLink !== '' ? $htmlLink : null,
            attachments: $attachments,
        );
    }
}
