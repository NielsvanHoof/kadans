<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Uploads;

use App\Dossiers\Enums\AudioRecordingPermission;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\UploadPurpose;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The browser describing a file it is about to upload.
 *
 * Every value here is a claim about a file that has not been sent yet, so the
 * bounds are only wide enough to keep obvious nonsense out of the presigner —
 * ClaimUploadTicketAction is what checks the file that actually arrives.
 */
final class StoreUploadTicketRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    /**
     * A ticket grants a write into the staging prefix. Purpose selects which
     * upload permission must be held — document staff uploads and audio uploads
     * are gated separately.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return match ($this->purpose()) {
            UploadPurpose::Document => $user->hasTenantPermission(DocumentRequestPermission::Upload),
            UploadPurpose::AudioRecording => $user->hasTenantPermission(AudioRecordingPermission::Upload),
        };
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'purpose' => ['required', Rule::enum(UploadPurpose::class)],
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
        ];
    }

    public function purpose(): UploadPurpose
    {
        return $this->enum('purpose', UploadPurpose::class) ?? UploadPurpose::Document;
    }
}
