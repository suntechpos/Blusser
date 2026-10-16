<?php

namespace App\Modules\Access\Services;

use RuntimeException;

final class GovernanceDenied extends RuntimeException
{
    public function __construct(public readonly string $reason = 'forbidden')
    {
        // Never attach the original QueryException: its SQL bindings may contain hashes.
        parent::__construct('Operation not authorized or unavailable.');
    }
}
