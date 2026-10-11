<?php

declare(strict_types=1);

namespace Gld\Ion;

final class Resolved
{
    public function __construct(
        public bool $known = false,
        public string $text = '',
        public string $imp = '',
        public int $impSid = 0,
    ) {
    }
}

/** The current symbol address space. System symbols occupy ids 1 through 9. */
