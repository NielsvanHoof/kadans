<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Database\Eloquent\Relations\Relation;

final class MorphType
{
    public static function classBasename(string $type): string
    {
        return class_basename(Relation::getMorphedModel($type) ?? $type);
    }
}
