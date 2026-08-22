<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Support\DocumentPresigner;
use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

use function is_string;
use function mb_ord;
use function mb_str_split;
use function mb_strlen;
use function mb_substr;
use function pathinfo;

/**
 * Build a short-lived signed URL for a private stored document.
 *
 * The signing itself, including the internal-vs-public endpoint split that
 * local Floci needs, lives in DocumentPresigner — shared with the presigned
 * uploads that put these documents in the bucket in the first place. What is
 * specific to downloads, and stays here, is the Content-Disposition header:
 * it is built from a fully client-controlled filename and is the one part of
 * a signed URL that an attacker could otherwise shape.
 */
final readonly class ResolveDocumentTemporaryUrlAction
{
    /**
     * Starting point for how much of the filename is kept; walked down
     * further below if the encoded header still comes out too long.
     */
    private const int MAX_FILENAME_LENGTH = 150;

    /**
     * Hard ceiling on the built Content-Disposition value itself. A fixed
     * filename-character bound isn't enough on its own: the RFC 5987
     * `filename*=UTF-8''...` form percent-encodes every non-ASCII byte, so a
     * filename of emoji or CJK characters can still expand to several times
     * MAX_FILENAME_LENGTH once encoded.
     */
    private const int MAX_CONTENT_DISPOSITION_LENGTH = 512;

    public function __construct(
        private FilesystemManager $filesystems,
        private DocumentPresigner $presigner,
    ) {}

    /**
     * The configured driver for the disk this document was stored on, or null
     * when that disk is no longer configured at all.
     *
     * `disk` is a plain string column written at upload time, so it is data,
     * not config: a row can outlive the disk it names. Config::string() throws
     * on a missing key, which turned such a row into a 500 on the download
     * route rather than a handled miss. Config::get() is what .ai/rules/app.md
     * prescribes whenever a key may legitimately be absent.
     */
    public function diskDriver(UploadedDocument $document): ?string
    {
        $driver = Config::get('filesystems.disks.'.$document->disk.'.driver');

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /**
     * S3-compatible disks support temporary URLs; local disk keeps streaming downloads in tests.
     */
    public function supportsTemporaryUrl(UploadedDocument $document): bool
    {
        return $this->diskDriver($document) === 's3';
    }

    public function handle(
        UploadedDocument $document,
        DateTimeInterface $expiresAt,
        string $disposition = 'attachment',
    ): string {
        $disk = $this->filesystems->disk($document->disk);

        throw_unless($disk instanceof FilesystemAdapter, RuntimeException::class, 'Disk does not support temporary URLs.');

        throw_if($disposition !== 'attachment' && $disposition !== 'inline', RuntimeException::class, 'Document URL disposition must be attachment or inline.');

        $overrides = [
            'ResponseContentDisposition' => $this->contentDisposition($disposition, $document->original_filename),
            'ResponseContentType' => $document->mime_type,
        ];

        // Non-null only when the internal and public S3 endpoints differ, which
        // is the local Floci setup; see DocumentPresigner for why signing for
        // the wrong host is otherwise invisible until a browser tries it.
        return $this->presigner->presignGet($disk, $document->path, $expiresAt, $overrides)
            ?? $disk->temporaryUrl($document->path, $expiresAt, $overrides);
    }

    /**
     * Build a well-formed, single Content-Disposition value from a fully
     * client-controlled filename (`getClientOriginalName()`, unvalidated).
     *
     * HeaderUtils::makeDisposition() — the same RFC 6266 builder Symfony's
     * own BinaryFileResponse uses for the (already-safe) local disk download
     * path — quoted-string-escapes the ASCII fallback, so a filename cannot
     * close the quoted parameter early and inject a second one. The real
     * filename still travels in full via the `filename*=UTF-8''...` form for
     * clients that support it.
     */
    private function contentDisposition(string $disposition, string $originalFilename): string
    {
        $filename = $this->boundedFilename($originalFilename);
        $value = HeaderUtils::makeDisposition($disposition, $filename, $this->asciiFallback($filename));

        // A fixed character bound can't predict the percent-encoded size up
        // front, so halve the filename until the built value actually fits.
        while (mb_strlen($value) > self::MAX_CONTENT_DISPOSITION_LENGTH && mb_strlen($filename) > 1) {
            $filename = mb_substr($filename, 0, (int) (mb_strlen($filename) / 2));
            $value = HeaderUtils::makeDisposition($disposition, $filename, $this->asciiFallback($filename));
        }

        return $value;
    }

    /**
     * Bounds filename length and strips path separators HeaderUtils rejects
     * outright, preserving the extension where the truncation allows it.
     */
    private function boundedFilename(string $filename): string
    {
        $filename = str_replace(['/', '\\'], '_', $filename);

        if (mb_strlen($filename) <= self::MAX_FILENAME_LENGTH) {
            return $filename;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.'.$extension : '';
        $base = mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, max(self::MAX_FILENAME_LENGTH - mb_strlen($suffix), 1));

        return $base.$suffix;
    }

    /**
     * Mirrors Symfony\Component\HttpFoundation\BinaryFileResponse's own
     * fallback derivation: replace every non-printable-ASCII byte (and any
     * literal "%", which HeaderUtils also rejects in a fallback) with "_".
     */
    private function asciiFallback(string $filename): string
    {
        $fallback = '';

        foreach (mb_str_split($filename) as $char) {
            $codepoint = mb_ord($char);
            $fallback .= ($char === '%' || $codepoint < 32 || $codepoint > 126) ? '_' : $char;
        }

        return $fallback;
    }
}
