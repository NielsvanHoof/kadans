<?php

declare(strict_types=1);

use App\Dossiers\Models\DocumentRequest;

/*
|--------------------------------------------------------------------------
| Ordering parity (PHP side)
|--------------------------------------------------------------------------
|
| DocumentRequest::isOrderedBefore() is mirrored in TypeScript by
| isOrderedBefore() in resources/js/features/document-requests/types.ts, because
| the builder must offer exactly the visibility sources the backend will accept.
|
| Both run against tests/fixtures/ordering-parity.json. Add a case there and it
| is asserted on both sides; change one implementation and its side fails.
|
*/

/**
 * @return list<array{name: string, field: array{id: int, sort_order: int}, other: array{id: int, sort_order: int}, expected: bool}>
 */
function orderingParityCases(): array
{
    $path = __DIR__.'/../../fixtures/ordering-parity.json';

    /** @var array{cases: list<array{name: string, field: array{id: int, sort_order: int}, other: array{id: int, sort_order: int}, expected: bool}>} $fixture */
    $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $fixture['cases'];
}

test('isOrderedBefore matches the shared parity table', function (): void {
    $cases = orderingParityCases();

    expect($cases)->not->toBeEmpty();

    foreach ($cases as $case) {
        $field = new DocumentRequest;
        $field->id = $case['field']['id'];
        $field->sort_order = $case['field']['sort_order'];

        $other = new DocumentRequest;
        $other->id = $case['other']['id'];
        $other->sort_order = $case['other']['sort_order'];

        expect($field->isOrderedBefore($other))->toBe(
            $case['expected'],
            "Ordering case [{$case['name']}] disagrees with the shared parity table.",
        );
    }
});
