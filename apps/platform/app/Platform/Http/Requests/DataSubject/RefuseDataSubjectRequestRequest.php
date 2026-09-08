<?php

declare(strict_types=1);

namespace App\Platform\Http\Requests;

use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Foundation\Http\FormRequest;

final class RefuseDataSubjectRequestRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    /**
     * Authorized in the controller against the bound request model, which the
     * policy needs in order to check the request is still open.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required, not optional: a refusal that records no reason is not an
            // answer the firm can defend later.
            'refusal_reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
