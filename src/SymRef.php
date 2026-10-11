<?php

declare(strict_types=1);

namespace Gld\Ion;

final class SymRef
{
    public function __construct(
        public int $text = -1,
        public int $sid = 0,
        public int $impName = -1,
        public int $impSid = 0,
    ) {
    }
}
