<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Requests;

use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

final class StoreClientAccessGrantRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    /**
     * Staff who can create dossiers may also issue client portal access grants.
     */
    public function authorize(): bool
    {
        $dossier = $this->route('dossier');
        $user = $this->user();

        return $user !== null
            && $dossier instanceof Dossier
            && $user->can('update', $dossier)
            && $user->can('create', ClientAccessGrant::class);
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            // Default true in the controller when omitted — checkbox posts "1" / absent.
            'send_invite' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalize checkbox / boolean input before validation runs.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        if ($this->has('send_invite')) {
            $this->merge([
                'send_invite' => $this->boolean('send_invite'),
            ]);
        }
    }
}
