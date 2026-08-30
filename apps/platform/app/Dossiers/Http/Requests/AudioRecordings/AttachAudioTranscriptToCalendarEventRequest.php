<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\AudioRecordings;

use App\Accounts\Models\User;
use App\Dossiers\Models\AudioRecording;
use App\Integrations\Enums\OAuthProvider;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach a completed transcript to a calendar event.
 */
final class AttachAudioTranscriptToCalendarEventRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $audioRecording = $this->route('audioRecording');

        return $audioRecording instanceof AudioRecording
            && ($this->user()?->can('view', $audioRecording) ?? false);
    }

    public function authenticatedUser(): User
    {
        $user = $this->user();

        abort_unless($user instanceof User, Response::HTTP_FORBIDDEN);

        return $user;
    }

    /**
     * Calendar provider to attach against (defaults to Google until more exist).
     */
    public function calendarProvider(): OAuthProvider
    {
        $value = $this->string('provider')->toString();

        if ($value === '') {
            return OAuthProvider::Google;
        }

        return OAuthProvider::from($value);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:255'],
            // Optional today; Google is the only implemented calendar provider.
            'provider' => ['sometimes', 'nullable', 'string', Rule::enum(OAuthProvider::class)],
        ];
    }
}
