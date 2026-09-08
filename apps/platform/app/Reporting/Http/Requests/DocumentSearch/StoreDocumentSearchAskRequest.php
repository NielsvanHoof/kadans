<?php

declare(strict_types=1);

namespace App\Reporting\Http\Requests\DocumentSearch;

use App\Dossiers\Models\DocumentRequest;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Foundation\Http\FormRequest;

final class StoreDocumentSearchAskRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('viewAny', DocumentRequest::class);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:500'],
        ];
    }
}
