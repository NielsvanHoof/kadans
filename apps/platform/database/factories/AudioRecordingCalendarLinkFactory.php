<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\AudioRecordingCalendarLink;
use App\Integrations\Enums\OAuthProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<AudioRecordingCalendarLink>
 */
final class AudioRecordingCalendarLinkFactory extends Factory
{
    /**
     * @var class-string<AudioRecordingCalendarLink>
     */
    #[Override]
    protected $model = AudioRecordingCalendarLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'audio_recording_id' => AudioRecording::factory(),
            'provider' => OAuthProvider::Google,
            'external_event_id' => 'evt-'.fake()->unique()->uuid(),
            'external_event_url' => 'https://calendar.google.com/event?eid='.fake()->uuid(),
            'external_file_id' => 'drive-'.fake()->uuid(),
            'linked_by_user_id' => null,
            'linked_at' => now(),
        ];
    }

    public function forProvider(OAuthProvider $provider): static
    {
        return $this->state(fn (): array => [
            'provider' => $provider,
        ]);
    }

    #[Override]
    public function configure(): static
    {
        // Inherit tenant from the parent recording when the factory did not set it.
        return $this->afterMaking(function (AudioRecordingCalendarLink $link): void {
            if ($link->tenant_id !== null) {
                return;
            }

            $recording = AudioRecording::queryWithoutTenantScope()
                ->find($link->audio_recording_id);

            if ($recording instanceof AudioRecording) {
                $link->tenant_id = $recording->tenant_id;
            }
        });
    }
}
