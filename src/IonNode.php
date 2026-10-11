<?php

declare(strict_types=1);

namespace Gld\Ion;

final class IonNode
{
    public int $a = 0;
    public int $b = 0;
    public int $c = 0;
    public int $d = -1;
    public int $ann = -1;
    public int $annN = 0;
    public int $child = -1;
    public int $nchild = 0;

    public function __construct(public int $kind)
    {
    }
}
