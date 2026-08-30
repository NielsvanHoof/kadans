<?php

declare(strict_types=1);

namespace App\Dossiers\Models;

use App\Accounts\Models\User;
use App\Integrations\Enums\OAuthProvider;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\AudioRecordingCalendarLinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A calendar event linked to an audio transcript (Google / Microsoft).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $audio_recording_id
 * @property OAuthProvider $provider
 * @property string $external_event_id
 * @property string|null $external_event_url
 * @property string|null $external_file_id
 * @property int|null $linked_by_user_id
 * @property CarbonImmutable $linked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AudioRecording $audioRecording
 * @property-read User|null $linkedBy
 * @property-read Tenant $tenant
 *
 * @method static AudioRecordingCalendarLinkFactory factory($count = null, $state = [])
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> query()
 *
 * @mixin \Eloquent
 */
final class AudioRecordingCalendarLink extends TenantOwnedModel
{
    /** @use HasFactory<AudioRecordingCalendarLinkFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'audio_recording_id',
        'provider',
        'external_event_id',
        'external_event_url',
        'external_file_id',
        'linked_by_user_id',
        'linked_at',
    ];

    /**
     * @return BelongsTo<AudioRecording, $this>
     */
    public function audioRecording(): BelongsTo
    {
        return $this->belongsTo(AudioRecording::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'provider' => OAuthProvider::class,
            'linked_at' => 'datetime',
        ];
    }
}
