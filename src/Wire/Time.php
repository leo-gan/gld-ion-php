<?php

declare(strict_types=1);

namespace Gld\Ion\Wire;

use Gld\Ion\DecodeError;
use Gld\Ion\IonTime;
use Gld\Ion\Kind;

final class Time
{
    public static function days(int $year, int $month): int
    {
        if ($month === 2) {
            $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
            return $leap ? 29 : 28;
        }
        if ($month === 4 || $month === 6 || $month === 9 || $month === 11) {
            return 30;
        }
        return 31;
    }

    public static function shiftDays(IonTime $when, int $days): void
    {
        $left = $days;
        while ($left > 0) {
            $dim = self::days($when->year, $when->month);
            $room = $dim - $when->day;
            if ($left <= $room) {
                $when->day += $left;
                return;
            }
            $left -= $room + 1;
            $when->day = 1;
            $when->month++;
            if ($when->month === 13) {
                $when->month = 1;
                $when->year++;
                if ($when->year > 999999999) {
                    throw new DecodeError(DecodeError::KIND_RANGE, 0);
                }
            }
        }
        while ($left < 0) {
            if ($when->day - 1 >= -$left) {
                $when->day += $left;
                return;
            }
            $left += $when->day;
            $when->month--;
            if ($when->month === 0) {
                $when->month = 12;
                $when->year--;
                if ($when->year < 1) {
                    throw new DecodeError(DecodeError::KIND_RANGE, 0);
                }
            }
            $when->day = self::days($when->year, $when->month);
        }
    }

    /** Add minutes to a minute-or-finer timestamp. Coarser values are left alone. */
    public static function applyOffset(IonTime $when, int $minutes): void
    {
        if ($when->prec < Kind::PREC_MIN) {
            return;
        }
        $total = $when->hour * 60 + $when->minute + $minutes;
        $days = 0;
        while ($total < 0) {
            $total += 1440;
            $days--;
        }
        while ($total >= 1440) {
            $total -= 1440;
            $days++;
        }
        $when->hour = intdiv($total, 60);
        $when->minute = $total % 60;
        if ($days !== 0) {
            self::shiftDays($when, $days);
        }
    }
}
