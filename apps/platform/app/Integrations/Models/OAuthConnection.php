<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Accounts\Models\User;
use App\Integrations\Enums\OAuthProvider;
use Carbon\CarbonImmutable;
use Database\Factories\OAuthConnectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A linked third-party identity for a Qompose user (Microsoft, later Google…).
 *
 * Stores encrypted tokens so calendar / Graph clients can reuse the same row.
 *
 * @property int $id
 * @property int $user_id
 * @property OAuthProvider $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $token_expires_at
 * @property list<string>|null $scopes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 *
 * @method static OAuthConnectionFactory factory($count = null, $state = [])
 * @method static Builder<static>|OAuthConnection newModelQuery()
 * @method static Builder<static>|OAuthConnection newQuery()
 * @method static Builder<static>|OAuthConnection query()
 * @method static Builder<static>|OAuthConnection whereAccessToken($value)
 * @method static Builder<static>|OAuthConnection whereCreatedAt($value)
 * @method static Builder<static>|OAuthConnection whereEmail($value)
 * @method static Builder<static>|OAuthConnection whereId($value)
 * @method static Builder<static>|OAuthConnection whereProvider($value)
 * @method static Builder<static>|OAuthConnection whereProviderUserId($value)
 * @method static Builder<static>|OAuthConnection whereRefreshToken($value)
 * @method static Builder<static>|OAuthConnection whereScopes($value)
 * @method static Builder<static>|OAuthConnection whereTokenExpiresAt($value)
 * @method static Builder<static>|OAuthConnection whereUpdatedAt($value)
 * @method static Builder<static>|OAuthConnection whereUserId($value)
 *
 * @mixin \Eloquent
 */
final class OAuthConnection extends Model
{
    /** @use HasFactory<OAuthConnectionFactory> */
    use HasFactory;

    /**
     * Avoid Eloquent turning OAuth into o_auth.
     */
    #[Override]
    protected $table = 'oauth_connections';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'token_expires_at',
        'scopes',
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'provider' => OAuthProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }
}
