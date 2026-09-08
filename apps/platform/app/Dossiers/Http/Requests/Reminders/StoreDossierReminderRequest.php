<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Reminders;

use App\Dossiers\Models\Dossier;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Foundation\Http\FormRequest;

final class StoreDossierReminderRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $dossier = $this->route('dossier');

        return $dossier instanceof Dossier
            && ($this->user()?->can('sendReminder', $dossier) ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
