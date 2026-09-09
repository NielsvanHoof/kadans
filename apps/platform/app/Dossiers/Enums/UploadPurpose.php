<?php

declare(strict_types=1);

namespace App\Dossiers\Enums;

use App\Dossiers\Support\AudioRecordings\AudioUploadLimits;
use App\Dossiers\Support\Uploads\DocumentUploadLimits;

/**
 * What a direct-to-S3 upload ticket is for.
 *
 * The purpose is baked into the encrypted ticket at issue time and re-read at
 * claim time, so a ticket minted for a 25 MB audio recording cannot be spent
 * on a document request — and the ceiling a claim is checked against is the
 * one the ticket was issued under, not one the client gets to pick.
 */
enum UploadPurpose: string
{
    case Document = 'document';
    case AudioRecording = 'audio_recording';

    /**
     * Extensions the browser may offer and the claim must match.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::Document => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'],
            self::AudioRecording => ['mp3', 'wav', 'm4a', 'ogg', 'webm', 'mp4'],
        };
    }

    /**
     * Hard per-object ceiling, in bytes.
     *
     * These used to be enforced by PHP's upload_max_filesize on the way in.
     * Nothing in the platform enforces them now except this check against the
     * object S3 actually stored, so it is the only thing standing between a
     * client and an arbitrarily large file in the bucket.
     */
    public function maxBytes(): int
    {
        return match ($this) {
            self::Document => DocumentUploadLimits::MAX_FILE_BYTES,
            self::AudioRecording => AudioUploadLimits::MAX_FILE_BYTES,
        };
    }

    /** Human size label for validation messages. */
    public function maxSizeLabel(): string
    {
        return match ($this) {
            self::Document => DocumentUploadLimits::MAX_FILE_SIZE_LABEL,
            self::AudioRecording => AudioUploadLimits::MAX_FILE_SIZE_LABEL,
        };
    }

    /**
     * The content type to record for a file with this extension.
     *
     * Deliberately derived server-side rather than read off the stored object.
     * S3 records whatever Content-Type the browser sent with its presigned PUT,
     * and that header is not covered by the signature — only `host` is — so the
     * stored value is client-controlled. The extension, by contrast, travels
     * inside the sealed ticket and has already been checked against
     * allowedExtensions(), which makes it the one trustworthy thing to derive
     * from.
     *
     * The download and preview routes serve this value as a Content-Type, so
     * `nosniff`, the per-file CSP and Content-Disposition in
     * UploadedDocumentResponse are still the defence that matters. This just
     * stops a client from choosing the header in the first place.
     */
    public function mimeTypeForExtension(string $extension): string
    {
        return match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'webm' => 'audio/webm',
            'mp4' => 'video/mp4',
            // Unreachable while the extension has been checked against
            // allowedExtensions() first, which every caller does.
            default => 'application/octet-stream',
        };
    }

    /**
     * The rejection a user sees when they pick the wrong kind of file.
     *
     * Names the accepted formats rather than saying only that this one is
     * wrong: the person who just dropped a PDF into the recording field needs
     * to know what to reach for instead.
     */
    public function unsupportedTypeMessage(): string
    {
        return match ($this) {
            self::Document => __('That file type is not supported. Upload a PDF, JPG, PNG, WebP, Word, or Excel file.'),
            self::AudioRecording => __('That file type is not supported. Upload an MP3, WAV, M4A, OGG, WebM, or MP4 file.'),
        };
    }
}
