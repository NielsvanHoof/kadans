<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions;

use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Support\Str;

use function is_array;
use function is_string;

/**
 * Gives every questionnaire item a predictable, type-specific configuration.
 */
final class NormalizeQuestionnaireItemConfigurationAction
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function handle(
        QuestionnaireItemType $type,
        array $configuration,
    ): array {
        $normalized = [
            'is_required' => ($configuration['is_required'] ?? true) !== false,
        ];

        if ($type->storesAnswerOptionIds()) {
            $normalized['options'] = $this->options($configuration['options'] ?? []);
        }

        if (isset($configuration['visibility']) && is_array($configuration['visibility'])) {
            $normalized['visibility'] = $configuration['visibility'];
        }

        return $normalized;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function options(mixed $options): array
    {
        if (! is_array($options) || $options === []) {
            return $this->defaultOptions();
        }

        $normalizedOptions = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $id = $option['id'] ?? null;
            $label = $option['label'] ?? null;

            if (! is_string($id) || ! is_string($label)) {
                continue;
            }

            $normalizedOptions[] = [
                'id' => $id,
                'label' => mb_trim($label),
            ];
        }

        return $normalizedOptions === []
            ? $this->defaultOptions()
            : $normalizedOptions;
    }

    /** @return list<array{id: string, label: string}> */
    private function defaultOptions(): array
    {
        return [
            ['id' => (string) Str::uuid(), 'label' => 'Option 1'],
            ['id' => (string) Str::uuid(), 'label' => 'Option 2'],
        ];
    }
}
