<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Lifecycle;

use App\Dossiers\Data\Lifecycle\RollOverDossierData;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Override;
use RuntimeException;

final class RollOverDossierRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    /**
     * Authorized against the source dossier, so the roll-over gate cannot be
     * skipped by posting straight to the route.
     */
    public function authorize(): bool
    {
        $dossier = $this->route('dossier');

        if (! $dossier instanceof Dossier) {
            return false;
        }

        return $this->user()?->can('rollOver', $dossier) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            // Required here, unlike StoreDossierRequest where it is optional.
            // A roll-over produces a near-identical sibling of an existing
            // dossier for the same client, so without its own reference the
            // two are indistinguishable in every list and search result.
            'reference' => [
                'required',
                'string',
                'max:255',
                Rule::unique((new Dossier)->getTable(), 'reference')
                    ->where('tenant_id', $this->tenantId()),
            ],
            'period_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
            'period_label' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'carry_answers' => ['required', 'boolean'],
        ];
    }

    /**
     * Overrides the shared map rather than extending it.
     *
     * `reference` is required here, so the shared 'Reference (optional)' label
     * would produce "Reference (optional) is required" — and an unmapped
     * `period_year` fell back to the raw column name, giving Dutch users
     * "period year moet minimaal 1900 zijn".
     *
     * @return array<string, string>
     */
    #[Override]
    public function attributes(): array
    {
        return [
            'title' => __('Title'),
            'reference' => __('Reference'),
            'period_year' => __('Period'),
            'period_label' => __('Period name'),
            'due_date' => __('Due date'),
            'carry_answers' => __('Carry the answers from the previous period'),
        ];
    }

    public function toData(): RollOverDossierData
    {
        return new RollOverDossierData(
            title: $this->string('title')->toString(),
            reference: $this->string('reference')->toString(),
            periodYear: $this->integer('period_year') === 0 ? null : $this->integer('period_year'),
            periodLabel: $this->filled('period_label') ? $this->string('period_label')->toString() : null,
            dueDate: $this->filled('due_date') ? $this->string('due_date')->toString() : null,
            carryAnswers: $this->boolean('carry_answers'),
        );
    }

    private function tenantId(): int
    {
        $tenant = Tenant::current();

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Cannot validate a roll-over without an active tenant.');

        return $tenant->id;
    }
}
