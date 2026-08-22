<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Everything the browser needs to upload one file straight to S3.
 *
 * `ticket` is an opaque encrypted blob naming the object key, the tenant and
 * the purpose. The browser hands it back with the form submit and never sees
 * inside it — which is the point: if the client could name the key, it could
 * name someone else's.
 */
#[TypeScript]
final class UploadTicketData extends Data
{
    public function __construct(
        /** Presigned PUT URL. Single object, single use, short-lived. */
        public readonly string $uploadUrl,
        /** Opaque ticket to submit alongside the form. */
        public readonly string $ticket,
        /** The exact Content-Type the PUT must send; it is part of the signature. */
        public readonly string $contentType,
        public readonly string $expiresAt,
    ) {}
}
