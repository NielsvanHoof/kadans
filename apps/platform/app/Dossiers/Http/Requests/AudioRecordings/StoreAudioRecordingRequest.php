<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\AudioRecordings;

use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

final class StoreAudioRecordingRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    /**
     * Staff who can upload onto an open dossier.
     */
    public function authorize(): bool
    {
        $dossier = $this->route('dossier');

        if (! $dossier instanceof Dossier) {
            return false;
        }

        return $this->user()?->can('create', [AudioRecording::class, $dossier]) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        // One opaque upload ticket. The recording itself is already in S3;
        // ClaimUploadTicketAction checks its real type and size there.
        return [
            'audio' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    #[Override]
    public function messages(): array
    {
        return [
            'audio.required' => __('Choose an audio file to upload.'),
        ];
    }
}
