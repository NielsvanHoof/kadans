<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Concerns;

use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;

use function is_array;
use function is_numeric;

/**
 * Authorize a wholesale replace of a dossier's document requests.
 *
 * The builder canvas does not send a diff: retained rows are updated and omitted
 * rows are hard-deleted along with their uploads and stored files. So each effect
 * is authorized per row the way `update` and `destroy` are individually — holding
 * the builder lock plus `create` must not become a weaker path to either one.
 */
trait AuthorizesDocumentRequestReplacement
{
    /**
     * Every existing row must be individually updatable (if the payload keeps it)
     * or deletable (if it does not).
     *
     * Cost is one policy evaluation per existing row, on every save. The ceiling is
     * the `items.max:500` validation rule: rows only enter through this endpoint, so
     * a dossier cannot hold more than one canvas' worth. Deliberately no cap of its
     * own here — refusing past a limit would lock a firm out of its own dossier, and
     * a slow save is the better failure.
     */
    protected function userMayReplaceExistingDocumentRequests(): bool
    {
        $dossier = $this->route('dossier');

        if (! $dossier instanceof Dossier) {
            return false;
        }

        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $retainedIds = $this->retainedDocumentRequestIds();

        $existing = DocumentRequest::query()
            ->whereBelongsTo($dossier)
            ->with('tenant')
            ->get();

        foreach ($existing as $documentRequest) {
            // DocumentRequestPolicy reads both relations per row; seed the one we hold.
            $documentRequest->setRelation('dossier', $dossier);

            $ability = isset($retainedIds[$documentRequest->id]) ? 'update' : 'delete';

            if (! $user->can($ability, $documentRequest)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ids the payload keeps. Read from unvalidated input, because `authorize()` runs
     * before `rules()` — anything unusable is simply not retained, so the row falls
     * under `delete` and the gate stays closed rather than open.
     *
     * @return array<int, true>
     */
    private function retainedDocumentRequestIds(): array
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return [];
        }

        $retainedIds = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;

            if (is_numeric($id) && (int) $id > 0) {
                $retainedIds[(int) $id] = true;
            }
        }

        return $retainedIds;
    }
}
