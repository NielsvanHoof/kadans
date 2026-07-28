<?php

declare(strict_types=1);

namespace App\Dossiers\Enums;

enum DocumentRequestStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
