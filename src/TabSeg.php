<?php

declare(strict_types=1);

namespace Gld\Ion;

final class TabSeg
{
    public function __construct(
        public int $start = 0,
        public int $count = 0,
        public int $flatAt = -1,
        public int $flatN = 0,
        public int $nameAt = -1,
    ) {
    }
}

