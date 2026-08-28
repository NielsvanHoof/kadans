<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Builder;

use App\Dossiers\Http\Requests\Concerns\AuthorizesDocumentRequestReplacement;
use App\Dossiers\Http\Requests\Concerns\AuthorizesDossierBuilderMutation;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

use function is_array;
use function is_numeric;
use function is_string;

/**
 * Full canvas replace for the dossier form builder (draft Save).
 */
final class SyncDocumentRequestsRequest extends FormRequest
{
    use AuthorizesDocumentRequestReplacement;
    use AuthorizesDossierBuilderMutation;
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        return $this->userHoldsBuilderLockForMutation()
            && $this->userMayReplaceExistingDocumentRequests();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Empty array is allowed — clears the form.
            'items' => ['present', 'array', 'max:500'],
            // Null / omitted / non-positive → create. Positive → update existing.
            'items.*.id' => ['nullable', 'integer'],
            'items.*.field_key' => ['required', 'ulid', 'distinct'],
            'items.*.type' => ['required', Rule::enum(QuestionnaireItemType::class)],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.instructions' => ['nullable', 'string', 'max:2000'],
            'items.*.configuration' => ['nullable', 'array'],
            'items.*.configuration.is_required' => ['sometimes', 'boolean'],
            'items.*.configuration.allow_multiple' => ['sometimes', 'boolean'],
            'items.*.configuration.options' => ['sometimes', 'array', 'max:50'],
            'items.*.configuration.options.*.id' => ['required_with:items.*.configuration.options', 'uuid', 'distinct'],
            'items.*.configuration.options.*.label' => ['required_with:items.*.configuration.options', 'string', 'max:255'],
            'items.*.configuration.visibility' => ['nullable', 'array'],
            'items.*.configuration.visibility.source_field_key' => [
                'required_with:items.*.configuration.visibility',
                'ulid',
            ],
            'items.*.configuration.visibility.option_ids' => [
                'required_with:items.*.configuration.visibility',
                'array',
                'min:1',
                'max:50',
            ],
            'items.*.configuration.visibility.option_ids.*' => ['required', 'string', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dossier = $this->route('dossier');

            if (! $dossier instanceof Dossier) {
                return;
            }

            /** @var list<array<string, mixed>> $items */
            $items = array_values($this->input('items', []));

            $this->validateExistingIdsBelongToDossier($validator, $dossier, $items);
            $this->validateVisibilityAgainstPayloadOrder($validator, $items);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function validateExistingIdsBelongToDossier(
        Validator $validator,
        Dossier $dossier,
        array $items,
    ): void {
        $positiveIds = [];

        foreach ($items as $index => $item) {
            $id = $item['id'] ?? null;
            if (! is_numeric($id)) {
                continue;
            }
            if ((int) $id <= 0) {
                continue;
            }

            $positiveIds[(int) $id] = $index;
        }

        if ($positiveIds === []) {
            return;
        }

        $ownedQuery = DocumentRequest::query()->whereBelongsTo($dossier);
        $ownedQuery->getQuery()->whereIn('id', array_keys($positiveIds));
        $ownedIds = $ownedQuery
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $ownedLookup = array_fill_keys($ownedIds, true);

        foreach ($positiveIds as $id => $index) {
            if (! isset($ownedLookup[$id])) {
                $validator->errors()->add(
                    "items.{$index}.id",
                    __('The selected field could not be found.'),
                );
            }
        }
    }

    /**
     * Visibility sources must appear earlier in the saved canvas payload.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function validateVisibilityAgainstPayloadOrder(
        Validator $validator,
        array $items,
    ): void {
        /** @var array<string, int> $fieldKeyIndexes */
        $fieldKeyIndexes = [];

        foreach ($items as $index => $item) {
            $fieldKey = $item['field_key'] ?? null;

            if (is_string($fieldKey) && $fieldKey !== '') {
                $fieldKeyIndexes[$fieldKey] = $index;
            }
        }

        foreach ($items as $index => $item) {
            $configuration = $item['configuration'] ?? null;

            if (! is_array($configuration)) {
                continue;
            }

            $visibility = $configuration['visibility'] ?? null;

            if (! is_array($visibility)) {
                continue;
            }

            $sourceFieldKey = $visibility['source_field_key'] ?? null;
            if (! is_string($sourceFieldKey)) {
                continue;
            }
            if ($sourceFieldKey === '') {
                continue;
            }

            $sourceIndex = $fieldKeyIndexes[$sourceFieldKey] ?? null;

            if ($sourceIndex === null) {
                $validator->errors()->add(
                    "items.{$index}.configuration.visibility.source_field_key",
                    __('The selected field could not be found.'),
                );

                continue;
            }

            if ($sourceIndex >= $index) {
                $validator->errors()->add(
                    "items.{$index}.configuration.visibility.source_field_key",
                    __('Visibility can only depend on an earlier field.'),
                );

                continue;
            }

            $sourceType = $items[$sourceIndex]['type'] ?? null;

            if (! is_string($sourceType)) {
                continue;
            }

            $type = QuestionnaireItemType::tryFrom($sourceType);

            if (! $type instanceof QuestionnaireItemType || ! $type->canDriveVisibility()) {
                $validator->errors()->add(
                    "items.{$index}.configuration.visibility.source_field_key",
                    __('Visibility can only depend on a yes/no or choice field.'),
                );
            }
        }
    }
}
