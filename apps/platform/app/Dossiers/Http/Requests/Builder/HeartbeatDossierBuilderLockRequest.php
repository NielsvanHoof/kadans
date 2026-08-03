<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Builder;

use App\Dossiers\Http\Requests\Concerns\AuthorizesDossierBuilderMutation;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Keep the builder lock alive while the holder stays on the page.
 */
final class HeartbeatDossierBuilderLockRequest extends FormRequest
{
    use AuthorizesDossierBuilderMutation;
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        return $this->userCanManageBuilderLock();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:26'],
        ];
    }
}
