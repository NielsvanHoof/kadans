<?php

declare(strict_types=1);

namespace App\Dossiers\Models;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Questionnaires\Actions\NormalizeQuestionnaireItemConfigurationAction;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\DocumentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

/**
 * Questionnaire item on a dossier (file upload, text, or yes/no).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $dossier_id
 * @property QuestionnaireItemType $type
 * @property string $field_key
 * @property string $title
 * @property string|null $instructions
 * @property array<string, mixed>|null $configuration
 * @property string|null $answer_text
 * @property bool|null $answer_boolean
 * @property list<string>|null $answer_option_ids
 * @property CarbonInterface|null $answered_at
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property string|null $rejection_reason
 * @property DocumentRequestStatus $status
 * @property int $sort_order
 */
#[Fillable([
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
    'reviewed_by',
    'reviewed_at',
    'rejection_reason',
    'status',
    'sort_order',
])]
#[UseFactory(DocumentRequestFactory::class)]
final class DocumentRequest extends Model
{
    /** @use HasFactory<DocumentRequestFactory> */
    use BelongsToTenant, HasFactory, Searchable;

    /**
     * @var array<string, mixed>
     */
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
     * @return HasOne<UploadedDocument, $this>
     */
    public function uploadedDocument(): HasOne
    {
        return $this->hasOne(UploadedDocument::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected static function booted(): void
    {
        self::creating(function (self $documentRequest): void {
            $documentRequest->field_key ??= (string) Str::ulid();

            if ($documentRequest->configuration !== null) {
                return;
            }

            // Give every new field a normalized configuration, so nothing has to
            // guess defaults later when reading is_required or options.
            $documentRequest->configuration = app(
                NormalizeQuestionnaireItemConfigurationAction::class,
            )->handle($documentRequest->type, []);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionnaireItemType::class,
            'configuration' => 'array',
            'status' => DocumentRequestStatus::class,
            'answer_boolean' => 'boolean',
            'answer_option_ids' => 'array',
            'answered_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }
}
