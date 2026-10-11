<?php

declare(strict_types=1);

namespace Gld\Ion;

final class Utf8
{
    public static function validate(string $span, int $offset): void
    {
        $n = strlen($span);
        $i = 0;
        while ($i < $n) {
            $c = ord($span[$i]);
            if ($c < 0x80) {
                $i++;
                continue;
            }
            if ($c >= 0xC2 && $c <= 0xDF) {
                $need = 1;
                $cp = $c & 0x1F;
                $min = 0x80;
            } elseif ($c >= 0xE0 && $c <= 0xEF) {
                $need = 2;
                $cp = $c & 0x0F;
                $min = 0x800;
            } elseif ($c >= 0xF0 && $c <= 0xF4) {
                $need = 3;
                $cp = $c & 0x07;
                $min = 0x10000;
            } else {
                throw new DecodeError(DecodeError::KIND_UTF8, $offset + $i);
            }
            if ($i + $need >= $n) {
                throw new DecodeError(DecodeError::KIND_UTF8, $offset + $i);
            }
            for ($k = 0; $k < $need; $k++) {
                $i++;
                $cc = ord($span[$i]);
                if (($cc & 0xC0) !== 0x80) {
                    throw new DecodeError(DecodeError::KIND_UTF8, $offset + $i);
                }
                $cp = ($cp << 6) | ($cc & 0x3F);
            }
            if ($cp < $min || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
                throw new DecodeError(DecodeError::KIND_UTF8, $offset + $i);
            }
            $i++;
        }
    }

    public static function append(string &$buf, int $cp, int $offset): void
    {
        if ($cp < 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            throw new DecodeError(DecodeError::KIND_ESCAPE, $offset);
        }
        if ($cp < 0x80) {
            $buf .= chr($cp);
            return;
        }
        if ($cp < 0x800) {
            $buf .= chr(0xC0 | ($cp >> 6));
            $buf .= chr(0x80 | ($cp & 0x3F));
            return;
        }
        if ($cp < 0x10000) {
            $buf .= chr(0xE0 | ($cp >> 12));
            $buf .= chr(0x80 | (($cp >> 6) & 0x3F));
            $buf .= chr(0x80 | ($cp & 0x3F));
            return;
        }
        $buf .= chr(0xF0 | ($cp >> 18));
        $buf .= chr(0x80 | (($cp >> 12) & 0x3F));
        $buf .= chr(0x80 | (($cp >> 6) & 0x3F));
        $buf .= chr(0x80 | ($cp & 0x3F));
    }

    public static function isAscii(string $raw): bool
    {
        return !preg_match('/[\\x80-\\xFF]/', $raw);
    }

    public static function isWide(string $raw): bool
    {
        $n = strlen($raw);
        if ($n >= 4 && $raw[0] === "\0" && $raw[1] === "\0" && $raw[2] === "\0") {
            return true;
        }
        if ($n >= 4 && $raw[0] !== "\0" && $raw[1] === "\0" && $raw[2] === "\0" && $raw[3] === "\0") {
            return true;
        }
        if ($n >= 2 && $raw[0] === "\0" && $raw[1] !== "\0") {
            return true;
        }
        if ($n >= 2 && $raw[0] !== "\0" && $raw[1] === "\0") {
            return true;
        }
        return false;
    }

    public static function fromWide(string $raw): string
    {
        $n = strlen($raw);
        $width = 1;
        $be = true;
        if ($n >= 4 && $raw[0] === "\0" && $raw[1] === "\0" && $raw[2] === "\0") {
            $width = 4;
        } elseif ($n >= 4 && $raw[0] !== "\0" && $raw[1] === "\0" && $raw[2] === "\0" && $raw[3] === "\0") {
            $width = 4;
            $be = false;
        } elseif ($n >= 2 && $raw[0] === "\0" && $raw[1] !== "\0") {
            $width = 2;
        } elseif ($n >= 2 && $raw[0] !== "\0" && $raw[1] === "\0") {
            $width = 2;
            $be = false;
        }
        if ($width === 1) {
            return $raw;
        }
        if ($n % $width !== 0) {
            throw new DecodeError(DecodeError::KIND_UTF8, 0);
        }
        $out = '';
        $i = 0;
        while ($i < $n) {
            $cp = 0;
            for ($b = 0; $b < $width; $b++) {
                $idx = $be ? $i + $b : $i + $width - 1 - $b;
                $cp = ($cp << 8) | ord($raw[$idx]);
            }
            $advance = $width;
            if ($width === 2 && $cp >= 0xD800 && $cp <= 0xDBFF && $i + 4 <= $n) {
                $base = $i + 2;
                $low = $be
                    ? (ord($raw[$base]) << 8) | ord($raw[$base + 1])
                    : (ord($raw[$base + 1]) << 8) | ord($raw[$base]);
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $cp = 0x10000 + (($cp - 0xD800) << 10) + ($low - 0xDC00);
                    $advance = 4;
                }
            }
            self::append($out, $cp, $i);
            $i += $advance;
        }
        return $out;
    }
}
