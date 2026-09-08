<?php

declare(strict_types=1);

namespace App\Dossiers\Models;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Questionnaires\Actions\Templates\NormalizeQuestionnaireItemConfigurationAction;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Shared\Concerns\InvalidatesTenantCaches;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Override;

/**
 * Questionnaire item on a dossier (file upload, text, or yes/no).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $dossier_id
 * @property string $title
 * @property string|null $instructions
 * @property DocumentRequestStatus $status
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property QuestionnaireItemType $type
 * @property string|null $answer_text
 * @property bool|null $answer_boolean
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $carried_forward_at
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $rejection_reason
 * @property string $field_key
 * @property array<string, mixed>|null $configuration
 * @property list<string>|null $answer_option_ids
 * @property-read Dossier|null $dossier
 * @property-read User|null $reviewedBy
 * @property-read Tenant $tenant
 * @property-read Collection<int, UploadedDocument> $uploadedDocuments
 * @property-read int|null $uploaded_documents_count
 *
 * @method static DocumentRequestFactory factory($count = null, $state = [])
 * @method static Builder<static>|DocumentRequest newModelQuery()
 * @method static Builder<static>|DocumentRequest newQuery()
 * @method static Builder<static>|DocumentRequest query()
 * @method static Builder<static>|DocumentRequest whereAnswerBoolean($value)
 * @method static Builder<static>|DocumentRequest whereAnswerOptionIds($value)
 * @method static Builder<static>|DocumentRequest whereAnswerText($value)
 * @method static Builder<static>|DocumentRequest whereAnsweredAt($value)
 * @method static Builder<static>|DocumentRequest whereCarriedForwardAt($value)
 * @method static Builder<static>|DocumentRequest whereConfiguration($value)
 * @method static Builder<static>|DocumentRequest whereCreatedAt($value)
 * @method static Builder<static>|DocumentRequest whereDossierId($value)
 * @method static Builder<static>|DocumentRequest whereFieldKey($value)
 * @method static Builder<static>|DocumentRequest whereId($value)
 * @method static Builder<static>|DocumentRequest whereInstructions($value)
 * @method static Builder<static>|DocumentRequest whereRejectionReason($value)
 * @method static Builder<static>|DocumentRequest whereReviewedAt($value)
 * @method static Builder<static>|DocumentRequest whereReviewedBy($value)
 * @method static Builder<static>|DocumentRequest whereSortOrder($value)
 * @method static Builder<static>|DocumentRequest whereStatus($value)
 * @method static Builder<static>|DocumentRequest whereTenantId($value)
 * @method static Builder<static>|DocumentRequest whereTitle($value)
 * @method static Builder<static>|DocumentRequest whereType($value)
 * @method static Builder<static>|DocumentRequest whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
final class DocumentRequest extends TenantOwnedModel
{
    /** @use HasFactory<DocumentRequestFactory> */
    use HasFactory, InvalidatesTenantCaches, Searchable;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'dossier_id',
        'type',
        'field_key',
        'title',
        'instructions',
        'configuration',
        'answer_text',
        'answer_boolean',
        'answer_option_ids',
        'answered_at',
        'carried_forward_at',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'status',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'type' => 'file',
        'status' => 'pending',
        'sort_order' => 0,
    ];

    /**
     * Columns Scout's database engine searches with LIKE.
     *
     * @return array{id: int, title: string, instructions: string}
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'instructions' => $this->instructions ?? '',
        ];
    }

    /**
     * Whether this item accepts more than one uploaded file.
     *
     * `configuration` is a free-form JSON column, so every reader had to spell
     * out the same `(… ?? false) === true` defaulting. That is five
     * character-identical expressions across requests, actions, transitions and
     * a portal adapter, each free to drift — and the key is absent entirely on
     * non-File items, so the default is load-bearing rather than cosmetic.
     *
     * NormalizeQuestionnaireItemConfigurationAction owns the write side and
     * still reads the raw array: it normalises a payload into configuration
     * before any model exists.
     */
    public function allowsMultipleUploads(): bool
    {
        return ($this->configuration['allow_multiple'] ?? false) === true;
    }

    /**
     * Whether an answer to this item is required.
     *
     * Note the default is the inverse of allow_multiple's: an item is required
     * unless it says otherwise, which is why this cannot share one helper.
     */
    public function isRequired(): bool
    {
        return ($this->configuration['is_required'] ?? true) !== false;
    }

    /**
     * Whether this item sits before $other on the canvas.
     *
     * Two items can briefly share a sort_order while a reorder is in flight, so the
     * id breaks the tie. This is the one definition of "earlier" used by visibility
     * rules, keeping validation and rendering in agreement.
     */
    public function isOrderedBefore(self $other): bool
    {
        if ($this->sort_order !== $other->sort_order) {
            return $this->sort_order < $other->sort_order;
        }

        return $this->id < $other->id;
    }

    /**
     * @return BelongsTo<Dossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    /**
     * @return HasMany<UploadedDocument, $this>
     */
    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(UploadedDocument::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    #[Override]
    protected static function booted(): void
    {
        self::creating(function (self $documentRequest): void {
            $documentRequest->field_key ??= (string) Str::ulid();

            if ($documentRequest->configuration !== null) {
                return;
            }

            // Give every new field a normalized configuration, so nothing has to
            // guess defaults later when reading is_required or options.
            $documentRequest->configuration = resolve(
                NormalizeQuestionnaireItemConfigurationAction::class,
            )->handle($documentRequest->type, []);
        });
    }

    /**
     * status drives the submitted / outstanding counts and the dossier-level
     * awaiting_client and needs_review rollups; answered_at drives the weekly
     * throughput trend. Title, instructions, sort_order and configuration are
     * written often and feed nothing cached.
     *
     * @return list<string>
     */
    protected static function cacheInvalidatingAttributes(): array
    {
        return ['status', 'answered_at'];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => QuestionnaireItemType::class,
            'configuration' => 'array',
            'status' => DocumentRequestStatus::class,
            'answer_boolean' => 'boolean',
            'answer_option_ids' => 'array',
            'answered_at' => 'datetime',
            'carried_forward_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }
}
