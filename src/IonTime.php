<?php

declare(strict_types=1);

namespace Gld\Ion;

/** Local-time timestamp fields. unknown means offset -00:00. */
final class IonTime
{
    public int $prec = Kind::PREC_YEAR;
    public int $off = 0;
    public bool $unknown = true;
    public int $year = 1;
    public int $month = 1;
    public int $day = 1;
    public int $hour = 0;
    public int $minute = 0;
    public int $second = 0;
    public int $fracExp = 0;
    public int $fracAt = 0;
    public int $fracLen = 0;
}
