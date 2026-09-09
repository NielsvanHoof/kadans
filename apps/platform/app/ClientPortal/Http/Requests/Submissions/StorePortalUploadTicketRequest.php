<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Requests\Submissions;

use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A portal client describing a file it is about to upload.
 *
 * Deliberately narrower than the staff equivalent: the portal only ever
 * uploads documents, so there is no purpose to choose and no way to ask for
 * the larger audio ceiling.
 */
final class StorePortalUploadTicketRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        return $this->attributes->get(ResolveClientPortalGrant::REQUEST_ATTRIBUTE) instanceof ClientAccessGrant;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
        ];
    }
}
