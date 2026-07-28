<?php

declare(strict_types=1);

namespace App\Questionnaires\Models;

use App\Questionnaires\Actions\NormalizeQuestionnaireItemConfigurationAction;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Database\Factories\QuestionnaireTemplateItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One question/document line inside a reusable template.
 *
 * @property int $id
 * @property int $questionnaire_template_id
 * @property QuestionnaireItemType $type
 * @property string $field_key
 * @property string $title
 * @property string|null $instructions
 * @property array<string, mixed>|null $configuration
 * @property int $sort_order
 */
#[Fillable([
    'questionnaire_template_id',
    'type',
    'field_key',
    'title',
    'instructions',
    'configuration',
    'sort_order',
])]
#[UseFactory(QuestionnaireTemplateItemFactory::class)]
final class QuestionnaireTemplateItem extends Model
{
    /** @use HasFactory<QuestionnaireTemplateItemFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'file',
        'sort_order' => 0,
    ];

    /**
     * @return BelongsTo<QuestionnaireTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplate::class, 'questionnaire_template_id');
    }

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->field_key ??= (string) Str::ulid();

            if ($item->configuration !== null) {
                return;
            }

            // Give every new item a normalized configuration, so nothing has to
            // guess defaults later when reading is_required or options.
            $item->configuration = app(
                NormalizeQuestionnaireItemConfigurationAction::class,
            )->handle($item->type, []);
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
            'sort_order' => 'integer',
        ];
    }
}
