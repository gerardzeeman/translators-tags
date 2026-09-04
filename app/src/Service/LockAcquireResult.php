<?php

namespace App\Service;

class LockAcquireResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?LockConflict $conflict = null,
    ) {
    }
}
