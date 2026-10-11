<?php

declare(strict_types=1);

namespace Gld\Ion\Wire;

use Gld\Ion\BigInt;
use Gld\Ion\DecodeError;
use Gld\Ion\IonDoc;
use Gld\Ion\IonTime;
use Gld\Ion\Kind;

/** Ion 1.1 binary writer. Symbols are inline text. Structs use FlexSym. */
final class Bin11
{
    public static function encodeValues(IonDoc $doc, string &$buf): void
    {
        foreach ($doc->top as $id) {
            self::value($doc, $id, $buf);
        }
    }

    public static function value(IonDoc $doc, int $id, string &$buf): void
    {
        $n = $doc->nodes[$id];
        for ($i = 0; $i < $n->annN; $i++) {
            self::annotation($doc, $doc->anns[$n->ann + $i], $buf);
        }
        $k = $n->kind;
        if ($k === Kind::NULL) {
            self::nullValue($n->a, $buf);
            return;
        }
        if ($k === Kind::BOOL) {
            $buf .= $n->a === 0 ? "\x6F" : "\x6E";
            return;
        }
        if ($k === Kind::INT) {
            self::intValue($doc, $n->a, $n->b, $n->c !== 0, $buf);
            return;
        }
        if ($k === Kind::FLOAT) {
            self::floatValue($doc, $n->a, $n->b, $buf);
            return;
        }
        if ($k === Kind::DECIMAL) {
            self::decimalValue($doc, $n->a, $n->b, $n->c !== 0, $n->d, $buf);
            return;
        }
        if ($k === Kind::TIMESTAMP) {
            self::timeValue($doc, $doc->times[$n->a], $buf);
            return;
        }
        if ($k === Kind::STRING) {
            self::textOpcode($doc->texts[$n->a], 0x90, 0xF8, $buf);
            return;
        }
        if ($k === Kind::SYMBOL) {
            $s = $doc->syms[$n->a];
            if ($s->text < 0) {
                $buf .= chr(0x50 | ($s->sid & 7));
                Ion11::writeFlexUint($buf, $s->sid >> 3);
            } else {
                self::textOpcode($doc->texts[$s->text], 0xA0, 0xF9, $buf);
            }
            return;
        }
        if ($k === Kind::BLOB || $k === Kind::CLOB) {
            $raw = $doc->blob_bytes($id);
            $buf .= $k === Kind::BLOB ? "\xFE" : "\xFF";
            Ion11::writeFlexUint($buf, strlen($raw));
            $buf .= $raw;
            return;
        }
        if ($k === Kind::LIST || $k === Kind::SEXP || $k === Kind::STRUCT) {
            self::container($doc, $id, $buf);
            return;
        }
        throw new DecodeError(DecodeError::KIND_TYPE, 0);
    }

    private static function nullValue(int $typeKind, string &$buf): void
    {
        if ($typeKind === 0) {
            $buf .= "\x8E";
            return;
        }
        $code = match ($typeKind) {
            Kind::BOOL => 1,
            Kind::INT => 2,
            Kind::FLOAT => 3,
            Kind::DECIMAL => 4,
            Kind::TIMESTAMP => 5,
            Kind::STRING => 6,
            Kind::SYMBOL => 7,
            Kind::BLOB => 8,
            Kind::CLOB => 9,
            Kind::LIST => 10,
            Kind::SEXP => 11,
            Kind::STRUCT => 12,
            default => 0,
        };
        if ($code === 0) {
            $buf .= "\x8E";
            return;
        }
        $buf .= "\x8F" . chr($code);
    }

    private static function annotation(IonDoc $doc, int $sym, string &$buf): void
    {
        $s = $doc->syms[$sym];
        if ($s->text < 0) {
            $buf .= "\x58";
            Ion11::writeFlexUint($buf, $s->sid);
            return;
        }
        $raw = $doc->texts[$s->text];
        $buf .= "\x59";
        Ion11::writeFlexUint($buf, strlen($raw));
        $buf .= $raw;
    }

    private static function textOpcode(string $text, int $shortBase, int $longOp, string &$buf): void
    {
        $n = strlen($text);
        if ($n <= 15) {
            $buf .= chr($shortBase + $n);
        } else {
            $buf .= chr($longOp);
            Ion11::writeFlexUint($buf, $n);
        }
        $buf .= $text;
    }

    private static function intValue(IonDoc $doc, int $at, int $n, bool $neg, string &$buf): void
    {
        if ($n === 0 && !$neg) {
            $buf .= "\x60";
            return;
        }
        $mag = $doc->limbs_of($at, $n);
        $mag->neg = $neg && !$mag->isZero();
        if ($neg && $mag->isZero()) {
            $raw = "\x00";
        } else {
            $raw = $mag->toSignedLe();
        }
        self::putIntBytes($raw, $buf);
    }

    private static function putIntBytes(string $raw, string &$buf): void
    {
        $n = strlen($raw);
        if ($n === 0) {
            $buf .= "\x60";
            return;
        }
        if ($n <= 8) {
            $buf .= chr(0x60 + $n);
        } else {
            $buf .= "\xF5";
            Ion11::writeFlexUint($buf, $n);
        }
        $buf .= $raw;
    }

    private static function floatValue(IonDoc $doc, int $width, int $slot, string &$buf): void
    {
        if ($width === 0) {
            $buf .= "\x6A";
            return;
        }
        $op = 0x6D;
        $n = 8;
        if ($width === 2) {
            $op = 0x6B;
            $n = 2;
        } elseif ($width === 4) {
            $op = 0x6C;
            $n = 4;
        }
        $buf .= chr($op);
        $be = $doc->floats[$slot];
        if (strlen($be) < $n) {
            $be = str_pad($be, $n, "\0", STR_PAD_LEFT);
        }
        $buf .= strrev(substr($be, 0, $n));
    }

    private static function decimalValue(IonDoc $doc, int $at, int $n, bool $neg, int $exp, string &$buf): void
    {
        if ($n === 0 && !$neg && $exp === 0) {
            $buf .= "\x70";
            return;
        }
        $body = '';
        Ion11::writeFlexInt($body, $exp);
        if ($n > 0 || $neg) {
            $mag = $doc->limbs_of($at, $n);
            $mag->neg = $neg && !$mag->isZero();
            if ($mag->isZero() && $neg) {
                $body .= "\x00";
            } else {
                $body .= $mag->toSignedLe();
            }
        }
        $ln = strlen($body);
        if ($ln <= 15) {
            $buf .= chr(0x70 + $ln);
        } else {
            $buf .= "\xF6";
            Ion11::writeFlexUint($buf, $ln);
        }
        $buf .= $body;
    }

    private static function container(IonDoc $doc, int $id, string &$buf): void
    {
        $n = $doc->nodes[$id];
        $body = '';
        $edge = $n->child;
        for ($i = 0; $i < $n->nchild; $i++) {
            if ($n->kind === Kind::STRUCT) {
                $field = $doc->edgeField[$edge];
                $text = '';
                if ($field >= 0 && $doc->syms[$field]->text >= 0) {
                    $text = $doc->texts[$doc->syms[$field]->text];
                }
                Ion11::writeFlexInt($body, -1 - strlen($text));
                $body .= $text;
            }
            self::value($doc, $doc->edgeChild[$edge], $body);
            $edge = $doc->edgeNext[$edge];
        }
        if ($n->kind === Kind::STRUCT && $body === '') {
            $buf .= "\xD0";
            return;
        }
        $ln = strlen($body);
        if ($n->kind === Kind::STRUCT) {
            $buf .= "\xFD";
            Ion11::writeFlexUint($buf, $ln);
        } elseif ($ln <= 15) {
            $base = $n->kind === Kind::SEXP ? 0xC0 : 0xB0;
            $buf .= chr($base + $ln);
        } else {
            $buf .= $n->kind === Kind::SEXP ? "\xFB" : "\xFA";
            Ion11::writeFlexUint($buf, $ln);
        }
        $buf .= $body;
    }

    private static function timeValue(IonDoc $doc, IonTime $t, string &$buf): void
    {
        $raw = '';
        $nbits = 0;
        self::putBits($raw, $nbits, $t->year, 14);
        $length = 2;
        if ($t->prec >= Kind::PREC_MONTH) {
            $day = $t->prec >= Kind::PREC_DAY ? $t->day : 0;
            self::putBits($raw, $nbits, $t->month, 4);
            self::putBits($raw, $nbits, $day, 5);
            $length = 3;
        }
        if ($t->prec >= Kind::PREC_MIN) {
            self::putBits($raw, $nbits, $t->hour, 5);
            self::putBits($raw, $nbits, $t->minute, 6);
            $off = 4095;
            if (!$t->unknown) {
                $off = $t->off + 1440;
                if ($off < 0 || $off > 4094) {
                    throw new DecodeError(DecodeError::KIND_RANGE, 0);
                }
            }
            self::putBits($raw, $nbits, $off, 12);
            $length = 6;
        }
        if ($t->prec >= Kind::PREC_SEC) {
            self::putBits($raw, $nbits, $t->second, 6);
            $length = 7;
        }
        $frac = '';
        if ($t->prec === Kind::PREC_FRAC) {
            $scale = -$t->fracExp;
            if ($scale < 1) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, 0);
            }
            Ion11::writeFlexUint($frac, $scale);
            $mag = $doc->limbs_of($t->fracAt, $t->fracLen)->toLe();
            $frac .= $mag === '' ? "\x00" : $mag;
            $length = 7 + strlen($frac);
        }
        $buf .= "\xF7";
        Ion11::writeFlexUint($buf, $length);
        $have = strlen($raw);
        if ($have >= $length && $t->prec !== Kind::PREC_FRAC) {
            $buf .= substr($raw, 0, $length);
        } else {
            $buf .= $raw;
            if ($t->prec !== Kind::PREC_FRAC) {
                $buf .= str_repeat("\0", $length - strlen($raw));
            }
        }
        if ($t->prec === Kind::PREC_FRAC) {
            $buf .= $frac;
        }
    }

    private static function putBits(string &$raw, int &$nbits, int $value, int $width): void
    {
        for ($k = 0; $k < $width; $k++) {
            $bit = ($value >> $k) & 1;
            $pos = $nbits;
            $bi = intdiv($pos, 8);
            if (strlen($raw) <= $bi) {
                $raw .= "\0";
            }
            $raw[$bi] = chr(ord($raw[$bi]) | ($bit << ($pos % 8)));
            $nbits++;
        }
    }
}
