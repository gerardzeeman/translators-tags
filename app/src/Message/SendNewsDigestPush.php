<?php

namespace App\Message;

final class SendNewsDigestPush
{
    public function __construct(
        public readonly int $newsDigestId,
    ) {
    }
}
