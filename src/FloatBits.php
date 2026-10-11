<?php

declare(strict_types=1);

namespace Gld\Ion;

/** IEEE bit helpers. Float payloads are big-endian byte strings. */
final class FloatBits
{
    public static function f64(float $v): string
    {
        return pack('E', $v);
    }

    public static function toFloat(int $width, string $be): float
    {
        if ($width === 0 || $be === '') {
            return 0.0;
        }
        if ($width === 8) {
            return unpack('E', $be)[1];
        }
        if ($width === 4) {
            return (float) unpack('G', $be)[1];
        }
        if ($width === 2) {
            return self::toFloat(8, self::f16ToF64($be));
        }
        throw new DecodeError(DecodeError::KIND_TYPE, 0);
    }

    public static function isNan(int $width, string $be): bool
    {
        if ($width === 0) {
            return false;
        }
        return is_nan(self::toFloat($width, $be));
    }

    public static function isNegZero(int $width, string $be): bool
    {
        if ($width === 8) {
            return $be === "\x80\x00\x00\x00\x00\x00\x00\x00";
        }
        if ($width === 4) {
            return $be === "\x80\x00\x00\x00";
        }
        if ($width === 2) {
            return $be === "\x80\x00";
        }
        return false;
    }

    public static function isNeg(int $width, string $be): bool
    {
        if ($be === '') {
            return false;
        }
        return (ord($be[0]) & 0x80) !== 0;
    }

    public static function f16ToF64(string $be): string
    {
        $h = (ord($be[0]) << 8) | ord($be[1]);
        $sign = ($h & 0x8000) !== 0;
        $exp = ($h >> 10) & 0x1F;
        $frac = $h & 0x03FF;
        if ($exp === 0) {
            if ($frac === 0) {
                return $sign ? "\x80\x00\x00\x00\x00\x00\x00\x00" : "\x00\x00\x00\x00\x00\x00\x00\x00";
            }
            $exp = 1;
            while (($frac & 0x0400) === 0) {
                $frac <<= 1;
                $exp--;
            }
            $frac &= 0x03FF;
            return self::packF64($sign, $exp + (1023 - 15), $frac << 42);
        }
        if ($exp === 31) {
            return self::packF64($sign, 0x7FF, $frac << 42);
        }
        return self::packF64($sign, $exp + (1023 - 15), $frac << 42);
    }

    public static function packF64(bool $sign, int $exp, int $frac): string
    {
        $hi = (($exp & 0x7FF) << 20) | (($frac >> 32) & 0xFFFFF);
        $lo = $frac & 0xFFFFFFFF;
        if ($sign) {
            $hi |= 0x80000000;
        }
        return pack('N', $hi) . pack('N', $lo);
    }

    public static function format(int $width, string $be): string
    {
        if ($width === 0) {
            return '0e0';
        }
        if (self::isNan($width, $be)) {
            return 'nan';
        }
        if (self::isNegZero($width, $be)) {
            return '-0e0';
        }
        $f = self::toFloat($width, $be);
        if (is_infinite($f)) {
            return $f < 0 ? '-inf' : '+inf';
        }
        if ($f == 0.0) {
            return '0e0';
        }
        $s = sprintf('%.16e', $f);
        $s = str_replace('E', 'e', $s);
        if (preg_match('/^(-?)(\d)\.(\d+)e([+-])(\d+)$/', $s, $m) === 1) {
            $sign = $m[1];
            $digits = $m[2] . $m[3];
            $digits = rtrim($digits, '0');
            if ($digits === '') {
                $digits = '0';
            }
            $exp = (int) $m[5];
            if ($m[4] === '-') {
                $exp = -$exp;
            }
            $exp += 0;
            $body = $digits[0];
            if (strlen($digits) > 1) {
                $body .= '.' . substr($digits, 1);
            }
            $es = (string) $exp;
            return $sign . $body . 'e' . $es;
        }
        return $s;
    }
}
