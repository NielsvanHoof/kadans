<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\AudioRecordings;

use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
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
        return [
            'audio' => [
                'required',
                File::types(['mp3', 'wav', 'm4a', 'ogg', 'webm', 'mp4'])
                    ->max('25mb'),
            ],
        ];
    }

    /** @return array<string, string> */
    #[Override]
    public function messages(): array
    {
        return [
            'audio.required' => __('Choose an audio file to upload.'),
            'audio.mimes' => __('That file type is not supported. Upload an MP3, WAV, M4A, OGG, WebM, or MP4 file.'),
            'audio.max' => __('That file is too large. The maximum size is 25 MB.'),
            'audio.uploaded' => __('The file failed to upload. Check your connection and try again.'),
        ];
    }
}
