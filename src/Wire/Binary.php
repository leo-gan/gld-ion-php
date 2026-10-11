<?php

declare(strict_types=1);

namespace Gld\Ion\Wire;

use Gld\Ion\BigInt;
use Gld\Ion\Catalog;
use Gld\Ion\DecodeError;
use Gld\Ion\IonDoc;
use Gld\Ion\IonTime;
use Gld\Ion\Kind;
use Gld\Ion\LocalTab;
use Gld\Ion\Utf8;

final class Binary
{
    private const VAR_LIMIT = 0x00FFFFFFFFFFFFFF;

    private int $i = 0;
    private int $depth = 0;
    private int $cacheSid0 = -1;
    private int $cacheSym0 = -1;
    private int $cacheSid1 = -1;
    private int $cacheSym1 = -1;
    private string $bpre = '';
    private string $bsuf = '';
    private int $bsym1 = -1;
    private int $bsym2 = -1;
    private string $btext = '';
    private int $btextId = -1;
    private bool $bneg = false;

    private int $n;

    public function __construct(private string $raw)
    {
        $this->n = strlen($raw);
    }

    public static function decode(string $raw, Catalog $cat): IonDoc
    {
        $doc = new IonDoc();
        $doc->reserve(strlen($raw));
        $tab = new LocalTab();
        $parser = new self($raw);
        $parser->readAll($doc, $tab, $cat);
        return $doc;
    }

    private function readAll(IonDoc $doc, LocalTab $tab, Catalog $cat): void
    {
        $n = $this->n;
        if ($n === 0) {
            return;
        }
        $ver = $this->bvm();
        while ($this->i < $n) {
            if ($ver === 11) {
                $ver = $this->read11($doc, $tab, $cat);
            } else {
                $ver = $this->read10($doc, $tab, $cat);
            }
        }
    }

    private function read10(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $n = $this->n;
        while ($this->i < $n) {
            if ($this->bpre !== '') {
                $mags = $this->drainBinMags();
                if ($mags !== []) {
                    $doc->add_top_pairs($this->bsym1, $mags, $this->bsym2, $this->btextId);
                }
                if ($this->i >= $n) {
                    break;
                }
            }
            if ($this->trySimpleLst($tab)) {
                $this->clearSid();
                continue;
            }
            if ($this->atBvm()) {
                $next = $this->bvm();
                $tab->reset_system();
                $this->clearSid();
                return $next;
            }
            $id = $this->value($doc, $tab, false);
            if ($id < 0) {
                continue;
            }
            if (Lst::isLocalTable($doc, $id)) {
                Lst::apply($doc, $id, $tab, $cat, $this->i);
                $this->clearSid();
                continue;
            }
            $doc->add_top($id);
        }
        return 10;
    }

    private function read11(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $r11 = new Ion11($this->raw);
        $r11->i = $this->i;
        $n = $this->n;
        while ($r11->i < $n) {
            $this->i = $r11->i;
            if ($this->atBvm()) {
                $next = $this->bvm();
                $tab->reset_system();
                $this->clearSid();
                $r11->clearMacros();
                if ($next !== 11) {
                    return $next;
                }
                $r11->i = $this->i;
                continue;
            }
            $id = $r11->value($doc, $tab, false, $cat);
            $this->i = $r11->i;
            if ($id >= 0 && !Lst::isLocalTable($doc, $id)) {
                $doc->add_top($id);
            }
        }
        $this->i = $r11->i;
        return 11;
    }

    private function clearSid(): void
    {
        $this->cacheSid0 = -1;
        $this->cacheSid1 = -1;
        $this->bpre = '';
    }

    private function b(): int
    {
        if ($this->i >= $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        return ord($this->raw[$this->i++]);
    }

    private function varUint(): int
    {
        $b0 = $this->b();
        if (($b0 & 0x80) !== 0) {
            return $b0 & 0x7F;
        }
        $acc = $b0 & 0x7F;
        for ($k = 1; $k < 10; $k++) {
            $b = $this->b();
            if ($acc > intdiv(self::VAR_LIMIT, 128)) {
                throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
            }
            $acc = ($acc << 7) | ($b & 0x7F);
            if (($b & 0x80) !== 0) {
                return $acc;
            }
        }
        throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
    }

    /** @param bool $neg0 set when the varint is negative zero */
    private function varInt(bool &$neg0): int
    {
        $neg0 = false;
        $b = $this->b();
        $neg = ($b & 0x40) !== 0;
        $acc = $b & 0x3F;
        $guard = 0;
        while (($b & 0x80) === 0) {
            $guard++;
            if ($guard > 10) {
                throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
            }
            $b = $this->b();
            if ($acc > intdiv(self::VAR_LIMIT, 128)) {
                throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
            }
            $acc = ($acc << 7) | ($b & 0x7F);
        }
        if ($neg && $acc === 0) {
            $neg0 = true;
            return 0;
        }
        return $neg ? -$acc : $acc;
    }

    private function take(int $n): string
    {
        if ($n < 0 || $this->i + $n > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $start = $this->i;
        $this->i += $n;
        return substr($this->raw, $start, $n);
    }

    private function reprLen(int $ln): int
    {
        return $ln === 14 ? $this->varUint() : $ln;
    }

    private function atBvm(): bool
    {
        if ($this->i + 3 >= $this->n) {
            return false;
        }
        return ord($this->raw[$this->i]) === 0xE0
            && ord($this->raw[$this->i + 1]) === 0x01
            && ord($this->raw[$this->i + 3]) === 0xEA;
    }

    private function bvm(): int
    {
        if ($this->i + 3 >= $this->n) {
            throw new DecodeError(DecodeError::KIND_VERSION, $this->i);
        }
        if (ord($this->raw[$this->i]) !== 0xE0 || ord($this->raw[$this->i + 3]) !== 0xEA || ord($this->raw[$this->i + 1]) !== 0x01) {
            throw new DecodeError(DecodeError::KIND_VERSION, $this->i);
        }
        $minor = ord($this->raw[$this->i + 2]);
        $this->i += 4;
        if ($minor === 0) {
            return 10;
        }
        if ($minor === 1) {
            return 11;
        }
        throw new DecodeError(DecodeError::KIND_VERSION, $this->i - 4);
    }

    private function value(IonDoc $doc, LocalTab $tab, bool $inAnn): int
    {
        if ($this->depth > Kind::MAX_DEPTH) {
            throw new DecodeError(DecodeError::KIND_DEPTH, $this->i);
        }
        $n = $this->n;
        if ($this->i < $n) {
            $td0 = ord($this->raw[$this->i]);
            $t0 = $td0 >> 4;
            $ln0 = $td0 & 15;
            if ($t0 === 2 && $ln0 === 0) {
                $this->i++;
                return $doc->add_i64(0);
            }
            if ($t0 === 2 && $ln0 === 1 && $this->i + 1 < $n) {
                $one = ord($this->raw[$this->i + 1]);
                $this->i += 2;
                return $doc->add_i64($one);
            }
            if ($t0 === 8 && $ln0 < 14) {
                $start = $this->i + 1;
                if ($start + $ln0 > $n) {
                    throw new DecodeError(DecodeError::KIND_EOF, $this->i);
                }
                $span = $ln0 === 0 ? '' : substr($this->raw, $start, $ln0);
                $this->i = $start + $ln0;
                return $this->stringNode($doc, $span);
            }
            if ($t0 === 13 && $ln0 > 1 && $ln0 < 14) {
                $flat = $this->replayBinStruct($doc);
                if ($flat >= 0) {
                    return $flat;
                }
                $flat = $this->tryFlatBinStruct($doc, $tab, $ln0);
                if ($flat >= 0) {
                    return $flat;
                }
            }
            if ($t0 >= 11 && $t0 <= 13) {
                $this->i++;
                return $this->container($doc, $tab, $t0, $ln0);
            }
        }
        $td = $this->b();
        $t = $td >> 4;
        $ln = $td & 0x0F;
        if ($t === 0) {
            if ($ln === 15) {
                return $doc->add_null(0);
            }
            $skip = $ln === 14 ? $this->varUint() : $ln;
            $this->take($skip);
            return -1;
        }
        if ($t === 15) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($t === 14) {
            return $this->annotation($doc, $tab, $ln, $inAnn);
        }
        if ($t === 1) {
            if ($ln === 0) {
                return $doc->add_bool(false);
            }
            if ($ln === 1) {
                return $doc->add_bool(true);
            }
            if ($ln === 15) {
                return $doc->add_null(Kind::BOOL);
            }
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($t === 2 || $t === 3) {
            if ($ln === 15) {
                return $doc->add_null(Kind::INT);
            }
            if ($t === 3 && $ln === 0) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            $size = $this->reprLen($ln);
            if ($size === 0) {
                return $doc->add_i64(0);
            }
            $span = $this->take($size);
            $mag = BigInt::fromBe($span, false, $this->i);
            if ($t === 3) {
                if ($mag->isZero()) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
                $mag->neg = true;
            }
            return $doc->add_int($mag);
        }
        if ($t === 4) {
            if ($ln === 15) {
                return $doc->add_null(Kind::FLOAT);
            }
            if ($ln === 0) {
                return $doc->add_float(0, '');
            }
            if ($ln !== 4 && $ln !== 8) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            return $doc->add_float($ln, $this->take($ln));
        }
        if ($t === 5) {
            if ($ln === 15) {
                return $doc->add_null(Kind::DECIMAL);
            }
            if ($ln === 0) {
                return $doc->add_decimal(new BigInt(false), false, 0);
            }
            $size = $this->reprLen($ln);
            return $this->decimal($doc, $this->i + $size);
        }
        if ($t === 6) {
            if ($ln === 15) {
                return $doc->add_null(Kind::TIMESTAMP);
            }
            if ($ln < 2) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            $size = $this->reprLen($ln);
            return $this->time($doc, $this->i + $size);
        }
        if ($t === 7) {
            if ($ln === 15) {
                return $doc->add_null(Kind::SYMBOL);
            }
            if ($ln === 0) {
                return Lst::symFromSid($doc, $tab, 0, $this->i);
            }
            $size = $this->reprLen($ln);
            $sid = $this->uint($size);
            return Lst::symFromSid($doc, $tab, $sid, $this->i);
        }
        if ($t === 8 || $t === 9 || $t === 10) {
            $kind = Kind::STRING;
            $nullK = Kind::STRING;
            if ($t === 9) {
                $kind = Kind::CLOB;
                $nullK = Kind::CLOB;
            }
            if ($t === 10) {
                $kind = Kind::BLOB;
                $nullK = Kind::BLOB;
            }
            if ($ln === 15) {
                return $doc->add_null($nullK);
            }
            $size = $this->reprLen($ln);
            $span = $this->take($size);
            if ($t === 8) {
                return $this->stringNode($doc, $span);
            }
            return $doc->add_bytes($kind, $span);
        }
        if ($t === 11 || $t === 12 || $t === 13) {
            return $this->container($doc, $tab, $t, $ln);
        }
        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
    }

    private function stringNode(IonDoc $doc, string $span): int
    {
        $ascii = true;
        $n = strlen($span);
        for ($k = 0; $k < $n; $k++) {
            if (ord($span[$k]) >= 128) {
                $ascii = false;
                break;
            }
        }
        if (!$ascii) {
            Utf8::validate($span, $this->i);
        }
        return $doc->add_string($span);
    }

    private function uint(int $n): int
    {
        $span = $this->take($n);
        $acc = 0;
        $len = strlen($span);
        for ($k = 0; $k < $len; $k++) {
            if ($acc > (PHP_INT_MAX >> 8)) {
                throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
            }
            $acc = ($acc << 8) | ord($span[$k]);
        }
        return $acc;
    }

    private function decimal(IonDoc $doc, int $end): int
    {
        $neg0 = false;
        $exp = $this->varInt($neg0);
        if ($this->i > $end) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $left = $end - $this->i;
        $negz = false;
        $coef = $this->signedMag($left, $negz);
        if ($this->i !== $end) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $neg = $coef->neg || $negz;
        $coef->neg = false;
        return $doc->add_decimal($coef, $neg, $exp);
    }

    private function signedMag(int $n, bool &$neg0): BigInt
    {
        $neg0 = false;
        if ($n === 0) {
            return new BigInt(false);
        }
        $span = $this->take($n);
        $first = ord($span[0]);
        $neg = ($first & 0x80) !== 0;
        $buf = chr($first & 0x7F) . substr($span, 1);
        $mag = BigInt::fromBe($buf, false, $this->i);
        if ($neg && $mag->isZero()) {
            $neg0 = true;
            return $mag;
        }
        if ($neg) {
            $mag->neg = true;
        }
        return $mag;
    }

    private function time(IonDoc $doc, int $end): int
    {
        $neg0 = false;
        $off = $this->varInt($neg0);
        if ($this->i >= $end) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $year = $this->varUint();
        if ($year < 1 || $year > 999999999) {
            throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
        }
        $when = new IonTime();
        $when->year = $year;
        $when->prec = Kind::PREC_YEAR;
        $when->unknown = $neg0;
        $when->off = $off;
        if ($this->i < $end) {
            $when->month = $this->varUint();
            $when->prec = Kind::PREC_MONTH;
            if ($when->month < 1 || $when->month > 12) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
        }
        if ($this->i < $end) {
            $when->day = $this->varUint();
            $when->prec = Kind::PREC_DAY;
            if ($when->month < 1 || $when->day < 1 || $when->day > Time::days($when->year, $when->month)) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
        }
        if ($this->i < $end) {
            $when->hour = $this->varUint();
            if ($this->i >= $end) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            $when->minute = $this->varUint();
            $when->prec = Kind::PREC_MIN;
            if ($when->hour > 23 || $when->minute > 59) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
        }
        if ($this->i < $end) {
            $when->second = $this->varUint();
            $when->prec = Kind::PREC_SEC;
            if ($when->second > 59) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
        }
        if ($this->i < $end) {
            $fneg = false;
            $fexp = $this->varInt($fneg);
            $coef = new BigInt(false);
            if ($this->i < $end) {
                $nz = false;
                $coef = $this->signedMag($end - $this->i, $nz);
                if ($coef->neg && !$nz) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
                if ($nz) {
                    $coef = new BigInt(false);
                }
            } elseif ($this->i !== $end) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            $drop = $coef->isZero() && $fexp >= 0;
            if (!$drop && self::fracGeOne($coef, $fexp)) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            if (!$drop) {
                if ($fexp >= 0 && !$coef->isZero()) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
                $when->prec = Kind::PREC_FRAC;
                $when->fracExp = $fexp;
                $when->fracAt = count($doc->limbs);
                $when->fracLen = count($coef->limbs);
                foreach ($coef->limbs as $limb) {
                    $doc->limbs[] = $limb;
                }
            }
        }
        if ($this->i !== $end) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($when->prec < Kind::PREC_MIN) {
            $when->unknown = true;
            $when->off = 0;
        } elseif (!$when->unknown) {
            Time::applyOffset($when, $when->off);
        }
        return $doc->add_time($when);
    }

    private static function fracGeOne(BigInt $coef, int $exp): bool
    {
        if ($coef->isZero()) {
            return false;
        }
        if ($exp >= 0) {
            return true;
        }
        $limit = new BigInt(false);
        $limit->addSmall(1);
        $n = -$exp;
        for ($k = 0; $k < $n; $k++) {
            $limit->mulSmall(10);
        }
        return $coef->cmpMag($limit) >= 0;
    }

    /** @return list<int> signed magnitudes matched by the learned struct template */
    private function drainBinMags(): array
    {
        $pl = strlen($this->bpre);
        if ($pl === 0) {
            return [];
        }
        $sl = strlen($this->bsuf);
        $need = $pl + 1 + $sl;
        $raw = $this->raw;
        $n = $this->n;
        $i = $this->i;
        $neg = $this->bneg;
        $out = [];
        while ($i + $need <= $n
            && substr_compare($raw, $this->bpre, $i, $pl) === 0
            && substr_compare($raw, $this->bsuf, $i + $pl + 1, $sl) === 0
        ) {
            $mag = ord($raw[$i + $pl]);
            if ($neg && $mag === 0) {
                break;
            }
            $out[] = $neg ? -$mag : $mag;
            $i += $need;
        }
        $this->i = $i;
        return $out;
    }

    /**
     * Length-prefixed `$ion_symbol_table` whose only field is a list of short ASCII strings.
     * Leaves the cursor unchanged when the shape does not match.
     */
    private function trySimpleLst(LocalTab $tab): bool
    {
        $i = $this->i;
        $n = $this->n;
        $raw = $this->raw;
        if ($i >= $n) {
            return false;
        }
        $td = ord($raw[$i]);
        if (($td >> 4) !== 14) {
            return false;
        }
        $ln = $td & 15;
        if ($ln < 3 || $ln >= 14 || $i + 1 + $ln > $n) {
            return false;
        }
        $end = $i + 1 + $ln;
        $p = $i + 1;
        $al = ord($raw[$p]);
        if (($al & 0x80) === 0 || ($al & 0x7F) !== 1) {
            return false;
        }
        $p++;
        if ($p >= $end || ord($raw[$p]) !== 0x83) {
            return false;
        }
        $p++;
        if ($p >= $end) {
            return false;
        }
        $sd = ord($raw[$p]);
        if (($sd >> 4) !== 13) {
            return false;
        }
        $sln = $sd & 15;
        if ($sln === 15 || $sln >= 14) {
            return false;
        }
        $p++;
        if ($p + $sln !== $end) {
            return false;
        }
        if ($sln === 0) {
            $tab->reset_system();
            $this->i = $end;
            return true;
        }
        $fd = ord($raw[$p]);
        if (($fd & 0x80) === 0 || ($fd & 0x7F) !== 7) {
            return false;
        }
        $p++;
        if ($p >= $end) {
            return false;
        }
        $ld = ord($raw[$p]);
        if (($ld >> 4) !== 11) {
            return false;
        }
        $lln = $ld & 15;
        if ($lln >= 14) {
            return false;
        }
        $p++;
        if ($p + $lln !== $end) {
            return false;
        }
        $texts = [];
        $lend = $p + $lln;
        while ($p < $lend) {
            $ed = ord($raw[$p]);
            if (($ed >> 4) !== 8) {
                return false;
            }
            $el = $ed & 15;
            if ($el >= 14) {
                return false;
            }
            $p++;
            if ($p + $el > $lend) {
                return false;
            }
            if ($el === 0) {
                $texts[] = '';
            } else {
                $span = substr($raw, $p, $el);
                for ($k = 0; $k < $el; $k++) {
                    if (ord($span[$k]) >= 128) {
                        return false;
                    }
                }
                $texts[] = $span;
            }
            $p += $el;
        }
        $tab->reset_system();
        foreach ($texts as $text) {
            $tab->add_text($text);
        }
        $this->i = $end;
        return true;
    }

    private function replayBinStruct(IonDoc $doc): int
    {
        $pl = strlen($this->bpre);
        if ($pl === 0) {
            return -1;
        }
        $sl = strlen($this->bsuf);
        $i = $this->i;
        if ($i + $pl + 1 + $sl > $this->n) {
            return -1;
        }
        $raw = $this->raw;
        if (substr_compare($raw, $this->bpre, $i, $pl) !== 0 || substr_compare($raw, $this->bsuf, $i + $pl + 1, $sl) !== 0) {
            return -1;
        }
        $mag = ord($raw[$i + $pl]);
        if ($this->bneg && $mag === 0) {
            return -1;
        }
        $this->i = $i + $pl + 1 + $sl;
        return $doc->add_pair_struct($this->bsym1, $this->bneg ? -$mag : $mag, $this->bsym2, $this->btext, $this->btextId);
    }

    /** `{sid: small-int, sid: short-ascii}` with single-byte symbol ids. -1 leaves the cursor alone. */
    private function tryFlatBinStruct(IonDoc $doc, LocalTab $tab, int $ln): int
    {
        $start = $this->i + 1;
        $end = $start + $ln;
        if ($end > $this->n) {
            return -1;
        }
        $raw = $this->raw;
        $origin = $this->i;
        $b = ord($raw[$start]);
        if (($b & 0x80) === 0) {
            return -1;
        }
        $sid1 = $b & 0x7F;
        $p = $start + 1;
        $tb = ord($raw[$p]);
        if ($tb === 0x20) {
            $int = 0;
            $p++;
        } elseif (($tb === 0x21 || $tb === 0x31) && $p + 1 < $end) {
            $int = ord($raw[$p + 1]);
            if ($tb === 0x31 && $int === 0) {
                return -1;
            }
            $p += 2;
        } else {
            return -1;
        }
        if ($p >= $end) {
            return -1;
        }
        $b2 = ord($raw[$p]);
        if (($b2 & 0x80) === 0) {
            return -1;
        }
        $sid2 = $b2 & 0x7F;
        $p++;
        if ($p >= $end) {
            return -1;
        }
        $sb = ord($raw[$p]);
        if (($sb >> 4) !== 8) {
            return -1;
        }
        $sl = $sb & 15;
        if ($sl >= 14 || $p + 1 + $sl !== $end) {
            return -1;
        }
        $text = $sl === 0 ? '' : substr($raw, $p + 1, $sl);
        for ($k = 0; $k < $sl; $k++) {
            if (ord($text[$k]) >= 128) {
                return -1;
            }
        }
        $this->i = $end;
        $neg = $tb === 0x31;
        $slot1 = $this->cachedSlot($doc, $tab, $sid1);
        $slot2 = $this->cachedSlot($doc, $tab, $sid2);
        $textId = $doc->intern($text);
        if ($tb === 0x21 || $tb === 0x31) {
            $this->bpre = substr($raw, $origin, 3);
            $this->bsuf = substr($raw, $origin + 4, $end - $origin - 4);
            $this->bneg = $neg;
            $this->bsym1 = $slot1;
            $this->bsym2 = $slot2;
            $this->btext = $text;
            $this->btextId = $textId;
        }
        return $doc->add_pair_struct($slot1, $neg ? -$int : $int, $slot2, $text, $textId);
    }

    private function cachedSlot(IonDoc $doc, LocalTab $tab, int $sid): int
    {
        if ($sid === $this->cacheSid0) {
            return $this->cacheSym0;
        }
        if ($sid === $this->cacheSid1) {
            return $this->cacheSym1;
        }
        $sym = Lst::symFromSid($doc, $tab, $sid, $this->i);
        $slot = $doc->nodes[$sym]->a;
        $this->cacheSid1 = $this->cacheSid0;
        $this->cacheSym1 = $this->cacheSym0;
        $this->cacheSid0 = $sid;
        $this->cacheSym0 = $slot;
        return $slot;
    }

    /** Small ints and short strings skip the general value parser. */
    private function quickBin(IonDoc $doc, LocalTab $tab, int $end): int
    {
        if ($this->i >= $end) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $td = ord($this->raw[$this->i]);
        $ty = $td >> 4;
        $ln = $td & 15;
        if ($ty === 2 && $ln === 0) {
            $this->i++;
            return $doc->add_i64(0);
        }
        if ($ty === 2 && $ln === 1 && $this->i + 1 < $end) {
            $one = ord($this->raw[$this->i + 1]);
            $this->i += 2;
            return $doc->add_i64($one);
        }
        if ($ty === 8 && $ln < 14 && $this->i + 1 + $ln <= $end) {
            $start = $this->i + 1;
            $span = $ln === 0 ? '' : substr($this->raw, $start, $ln);
            $this->i = $start + $ln;
            return $this->stringNode($doc, $span);
        }
        return $this->value($doc, $tab, false);
    }

    private function container(IonDoc $doc, LocalTab $tab, int $t, int $ln): int
    {
        $kind = Kind::LIST;
        $nullK = Kind::LIST;
        if ($t === 12) {
            $kind = Kind::SEXP;
            $nullK = Kind::SEXP;
        }
        if ($t === 13) {
            $kind = Kind::STRUCT;
            $nullK = Kind::STRUCT;
        }
        if ($ln === 15) {
            return $doc->add_null($nullK);
        }
        if ($ln === 0) {
            return $doc->start_container($kind);
        }
        $size = $ln;
        if ($ln === 14 || ($t === 13 && $ln === 1)) {
            $size = $this->varUint();
            if ($t === 13 && $ln === 1 && $size === 0) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
        }
        $end = $this->i + $size;
        if ($end > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $this->depth++;
        $id = $doc->start_container($kind);
        $raw = $this->raw;
        while ($this->i < $end) {
            if ($t === 13) {
                $b = ord($raw[$this->i]);
                if (($b & 0x80) !== 0) {
                    $this->i++;
                    $sid = $b & 0x7F;
                } else {
                    $sid = $this->varUint();
                }
                $child = $this->quickBin($doc, $tab, $end);
                if ($child < 0) {
                    continue;
                }
                if ($sid === $this->cacheSid0) {
                    $slot = $this->cacheSym0;
                } elseif ($sid === $this->cacheSid1) {
                    $slot = $this->cacheSym1;
                } else {
                    $sym = Lst::symFromSid($doc, $tab, $sid, $this->i);
                    $slot = $doc->nodes[$sym]->a;
                    $this->cacheSid1 = $this->cacheSid0;
                    $this->cacheSym1 = $this->cacheSym0;
                    $this->cacheSid0 = $sid;
                    $this->cacheSym0 = $slot;
                }
                $doc->add_child($id, $child, $slot);
            } else {
                $child = $this->quickBin($doc, $tab, $end);
                if ($child < 0) {
                    continue;
                }
                $doc->add_child($id, $child, -1);
            }
        }
        $this->depth--;
        if ($this->i !== $end) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        return $id;
    }

    private function annotation(IonDoc $doc, LocalTab $tab, int $ln, bool $inAnn): int
    {
        if ($inAnn || $ln < 3 || $ln === 15) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $size = $this->reprLen($ln);
        $end = $this->i + $size;
        if ($end > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $alen = $this->varUint();
        $annotEnd = $this->i + $alen;
        if ($annotEnd > $end || $alen <= 0) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $anns = [];
        while ($this->i < $annotEnd) {
            $sid = $this->varUint();
            $sym = Lst::symFromSid($doc, $tab, $sid, $this->i);
            $anns[] = $doc->nodes[$sym]->a;
        }
        if ($this->i !== $annotEnd || $anns === []) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $id = $this->value($doc, $tab, true);
        if ($id < 0 || $this->i !== $end) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $doc->set_anns($id, $anns);
        return $id;
    }
}
