<?php

declare(strict_types=1);

namespace App\Questionnaires\Enums;

enum QuestionnaireItemType: string
{
    case File = 'file';
    case Text = 'text';
    case Textarea = 'textarea';
    case Date = 'date';
    case Number = 'number';
    case Boolean = 'boolean';
    case Choice = 'choice';
    case MultiSelect = 'multi_select';

    /**
     * Yes/no answers have no stored option rows, so visibility rules match them
     * against these two fixed identifiers instead. Mirrored in
     * resources/js/features/document-requests/item-types/generated.ts
     * (via `php artisan questionnaire:generate-item-types`).
     */
    public const string BooleanYesOptionId = 'true';

    public const string BooleanNoOptionId = 'false';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether answers for this type are stored in answer_text.
     */
    public function storesAnswerText(): bool
    {
        return match ($this) {
            self::Text, self::Textarea, self::Date, self::Number => true,
            self::File, self::Boolean, self::Choice, self::MultiSelect => false,
        };
    }

    /** Whether answers for this type are stored as selected option identifiers. */
    public function storesAnswerOptionIds(): bool
    {
        return match ($this) {
            self::Choice, self::MultiSelect => true,
            self::File, self::Text, self::Textarea, self::Date, self::Number, self::Boolean => false,
        };
    }

    /**
     * Whether an answer to this type survives a roll-over into the next period.
     *
     * Everything except an uploaded file is a stable fact the client restated
     * verbatim every year (legal form, shareholders, bank details, KYC
     * answers). A file is filed *for* a period — last year's annual report is
     * not this year's — so a File item is always rolled over empty.
     *
     * Not mirrored into resources/js/.../item-types/generated.ts: rolling over
     * is a staff decision taken once for the whole dossier, so no frontend
     * registry branches on it.
     */
    public function carriesAnswerAcrossPeriods(): bool
    {
        return match ($this) {
            self::Text, self::Textarea, self::Date, self::Number,
            self::Boolean, self::Choice, self::MultiSelect => true,
            self::File => false,
        };
    }

    /**
     * Whether an answer to this type can drive another field's visibility rule.
     * Only types with a fixed, comparable set of answers qualify.
     */
    public function canDriveVisibility(): bool
    {
        return match ($this) {
            self::Boolean, self::Choice, self::MultiSelect => true,
            self::File, self::Text, self::Textarea, self::Date, self::Number => false,
        };
    }
}
