<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Requests\Access;

use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

/**
 * Staff issuing a client their standing portal link.
 */
final class StoreClientPortalLinkRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $client = $this->route('client');
        $user = $this->user();

        return $user !== null
            && $client instanceof Client
            && $user->can('view', $client)
            && $user->can('create', ClientAccessGrant::class);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Its own ceiling, lower than a dossier link's 90. One standing link
            // opens the client's whole book, so the window it is worth stealing
            // in is deliberately shorter.
            'expires_in_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.Config::integer('portal.client_grant_max_ttl_days'),
            ],
            'send_invite' => ['sometimes', 'boolean'],
        ];
    }
}
