<?php

declare(strict_types=1);

namespace Gld\Ion\Wire;

use Gld\Ion\BigInt;
use Gld\Ion\Catalog;
use Gld\Ion\DecodeError;
use Gld\Ion\IonDoc;
use Gld\Ion\IonNode;
use Gld\Ion\IonTime;
use Gld\Ion\Kind;
use Gld\Ion\LocalTab;
use Gld\Ion\Utf8;

/** Ion 1.1 binary reader. Macro templates live in the caller's document. */
final class Ion11
{
    public int $i = 0;
    private bool $templ = false;
    private int $curParam0 = 0;
    private int $depth = 0;

    /** @var list<string> */
    private array $macroName = [];
    /** @var list<int> */
    private array $macroRoot = [];
    /** @var list<int> */
    private array $macroParamAt = [];
    /** @var list<int> */
    private array $macroNParam = [];
    /** @var list<int> */
    private array $paramKind = [];
    /** @var list<int> */
    private array $paramDefault = [];

    private int $n;

    public function __construct(private string $raw)
    {
        $this->n = strlen($raw);
    }

    public function readTop(IonDoc $doc, LocalTab $tab, Catalog $cat): void
    {
        $n = $this->n;
        while ($this->i < $n) {
            if ($this->bvm()) {
                $tab->reset_system();
                $this->clearMacros();
                continue;
            }
            $id = $this->value($doc, $tab, false, $cat);
            if ($id >= 0) {
                $doc->add_top($id);
            }
        }
    }

    public function clearMacros(): void
    {
        $this->macroName = [];
        $this->macroRoot = [];
        $this->macroParamAt = [];
        $this->macroNParam = [];
        $this->paramKind = [];
        $this->paramDefault = [];
    }

    public function value(IonDoc $doc, LocalTab $tab, bool $inE, Catalog $cat): int
    {
        if ($this->i >= $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $op = ord($this->raw[$this->i]);
        $this->i++;
        if ($op === 0xEC) {
            return -1;
        }
        if ($op === 0xED) {
            $n = self::flexUint($this->raw, $this->i);
            if ($this->i + $n > $this->n) {
                throw new DecodeError(DecodeError::KIND_EOF, $this->i);
            }
            $this->i += $n;
            return -1;
        }
        if ($op === 0xEF) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($op === 0x8E) {
            return $doc->add_null(0);
        }
        if ($op === 0x8F) {
            return $this->typedNull($doc);
        }
        if ($op >= 0x60 && $op <= 0x68) {
            if ($op === 0x60) {
                return $doc->add_i64(0);
            }
            return $this->addFixed($doc, $op - 0x60);
        }
        if ($op === 0xF5) {
            $n = self::flexUint($this->raw, $this->i);
            return $this->addFixed($doc, $n);
        }
        if ($op === 0x6E) {
            return $doc->add_bool(true);
        }
        if ($op === 0x6F) {
            return $doc->add_bool(false);
        }
        if ($op === 0x6A) {
            return $doc->add_float(0, '');
        }
        if ($op >= 0x6B && $op <= 0x6D) {
            $n = 2;
            if ($op === 0x6C) {
                $n = 4;
            }
            if ($op === 0x6D) {
                $n = 8;
            }
            return $this->float($doc, $n);
        }
        if ($op >= 0x70 && $op <= 0x7F) {
            return $this->decimal($doc, $op - 0x70);
        }
        if ($op === 0xF6) {
            $n = self::flexUint($this->raw, $this->i);
            return $this->decimal($doc, $n);
        }
        if ($op >= 0x90 && $op <= 0x9F) {
            return $doc->add_string($this->text($op - 0x90));
        }
        if ($op === 0xF8) {
            $n = self::flexUint($this->raw, $this->i);
            return $doc->add_string($this->text($n));
        }
        if ($op >= 0xA0 && $op <= 0xAF) {
            return $doc->add_symbol_text($this->text($op - 0xA0));
        }
        if ($op === 0xF9) {
            $n = self::flexUint($this->raw, $this->i);
            return $doc->add_symbol_text($this->text($n));
        }
        if ($op >= 0x50 && $op <= 0x57) {
            $hi = self::flexUint($this->raw, $this->i);
            $sid = ($hi << 3) | ($op - 0x50);
            return Lst::symFromSid($doc, $tab, $sid, $this->i);
        }
        if ($op === 0x58 || $op === 0x59) {
            $this->i--;
            return $this->annotated($doc, $tab, $cat);
        }
        if ($op >= 0xB0 && $op <= 0xBF) {
            return $this->container($doc, $tab, Kind::LIST, $op - 0xB0, false, $cat);
        }
        if ($op === 0xFA) {
            $n = self::flexUint($this->raw, $this->i);
            return $this->container($doc, $tab, Kind::LIST, $n, false, $cat);
        }
        if ($op === 0xF0) {
            return $this->container($doc, $tab, Kind::LIST, -1, true, $cat);
        }
        if ($op >= 0xC0 && $op <= 0xCF) {
            return $this->container($doc, $tab, Kind::SEXP, $op - 0xC0, false, $cat);
        }
        if ($op === 0xFB) {
            $n = self::flexUint($this->raw, $this->i);
            return $this->container($doc, $tab, Kind::SEXP, $n, false, $cat);
        }
        if ($op === 0xF1) {
            return $this->container($doc, $tab, Kind::SEXP, -1, true, $cat);
        }
        if ($op === 0xD0) {
            return $doc->start_container(Kind::STRUCT);
        }
        if (($op >= 0xD2 && $op <= 0xDF) || $op === 0xFC || $op === 0xFD) {
            $n = $op - 0xD0;
            $flex = false;
            if ($op === 0xFC || $op === 0xFD) {
                $n = self::flexUint($this->raw, $this->i);
            }
            if ($op === 0xFD) {
                $flex = true;
            }
            return $this->struct($doc, $tab, $n, false, $flex, $cat);
        }
        if ($op === 0xF2 || $op === 0xF3) {
            return $this->struct($doc, $tab, -1, true, $op === 0xF3, $cat);
        }
        if ($op === 0xFE || $op === 0xFF) {
            $n = self::flexUint($this->raw, $this->i);
            $kind = $op === 0xFF ? Kind::CLOB : Kind::BLOB;
            return $this->bytes($doc, $kind, $n);
        }
        if ($op >= 0x80 && $op <= 0x8C) {
            return $this->shortTime($doc, $op);
        }
        if ($op === 0xF7) {
            return $this->longTime($doc);
        }
        if ($op === 0xE0) {
            if ($inE) {
                return -2;
            }
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($op === 0xE9 || $op === 0xEA || $op === 0xEB) {
            return $this->placeholder($doc, $tab, $op, $cat);
        }
        if ($op >= 0xE1 && $op <= 0xE8) {
            $this->directive($doc, $tab, $op, $cat);
            return -1;
        }
        if ($this->templ && ($op < 0x48 || ($op >= 0x48 && $op <= 0x4F) || $op === 0xF4)) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($op < 0x48 || ($op >= 0x48 && $op <= 0x4F) || $op === 0xF4) {
            return $this->eexp($doc, $tab, $op, $cat);
        }
        throw new DecodeError(DecodeError::KIND_VERSION, $this->i);
    }

    public static function flexUint(string $raw, int &$i): int
    {
        [$mag] = self::flexParts($raw, $i);
        return $mag;
    }

    public static function flexInt(string $raw, int &$i): int
    {
        [$mag, $width] = self::flexParts($raw, $i);
        $magBits = 7 * $width;
        $sign = 1 << ($magBits - 1);
        if (($mag & $sign) !== 0) {
            $full = (1 << $magBits) - $mag;
            return -$full;
        }
        return $mag;
    }

    public static function writeFlexUint(string &$buf, int $v): void
    {
        if ($v < 0) {
            $v = 0;
        }
        $n = 1;
        $limit = 128;
        while ($v >= $limit && $n < 8) {
            $n++;
            $limit *= 128;
        }
        self::writeFlexBits($buf, $n, $v);
    }

    public static function writeFlexInt(string &$buf, int $v): void
    {
        $n = 1;
        while ($n < 8) {
            $half = 1 << (7 * $n - 1);
            if ($v >= -$half && $v <= $half - 1) {
                break;
            }
            $n++;
        }
        $mod = (1 << (7 * $n)) - 1;
        self::writeFlexBits($buf, $n, $v & $mod);
    }

    /** @return array{0:int,1:int} magnitude bits above the stop bit, and the byte width */
    private static function flexParts(string $raw, int &$i): array
    {
        $start = $i;
        $bytes = [];
        $len = strlen($raw);
        for ($nread = 1; $nread <= 8; $nread++) {
            if ($i >= $len) {
                throw new DecodeError(DecodeError::KIND_EOF, $start);
            }
            $bytes[] = ord($raw[$i]);
            $i++;
            $low = -1;
            foreach ($bytes as $bi => $b) {
                if ($b === 0) {
                    continue;
                }
                $z = 0;
                while ((($b >> $z) & 1) === 0) {
                    $z++;
                }
                $low = $bi * 8 + $z;
                break;
            }
            if ($low < 0) {
                continue;
            }
            $width = $low + 1;
            if ($nread >= $width) {
                $count = $nread * 8 - $width;
                return [self::extract($bytes, $width, $count), $width];
            }
        }
        throw new DecodeError(DecodeError::KIND_RANGE, $start);
    }

    /** @param list<int> $bytes */
    private static function extract(array $bytes, int $from, int $count): int
    {
        $v = 0;
        for ($k = 0; $k < $count; $k++) {
            $pos = $from + $k;
            $bit = ($bytes[intdiv($pos, 8)] >> ($pos % 8)) & 1;
            $v |= $bit << $k;
        }
        return $v;
    }

    private static function writeFlexBits(string &$buf, int $n, int $pattern): void
    {
        $out = array_fill(0, $n, 0);
        $stop = $n - 1;
        $out[intdiv($stop, 8)] |= 1 << ($stop % 8);
        $magBits = 7 * $n;
        for ($b = 0; $b < $magBits; $b++) {
            if ((($pattern >> $b) & 1) === 0) {
                continue;
            }
            $pos = $n + $b;
            $out[intdiv($pos, 8)] |= 1 << ($pos % 8);
        }
        foreach ($out as $byte) {
            $buf .= chr($byte);
        }
    }

    private function bvm(): bool
    {
        if ($this->i + 3 >= $this->n) {
            return false;
        }
        if (ord($this->raw[$this->i]) !== 0xE0 || ord($this->raw[$this->i + 3]) !== 0xEA) {
            return false;
        }
        if (ord($this->raw[$this->i + 1]) !== 1) {
            return false;
        }
        $this->i += 4;
        return true;
    }

    private function text(int $n): string
    {
        if ($this->i + $n > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $start = $this->i;
        $this->i += $n;
        $s = substr($this->raw, $start, $n);
        Utf8::validate($s, $start);
        return $s;
    }

    private function typedNull(IonDoc $doc): int
    {
        if ($this->i >= $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $t = ord($this->raw[$this->i]);
        $this->i++;
        $map = [
            1 => Kind::BOOL,
            2 => Kind::INT,
            3 => Kind::FLOAT,
            4 => Kind::DECIMAL,
            5 => Kind::TIMESTAMP,
            6 => Kind::STRING,
            7 => Kind::SYMBOL,
            8 => Kind::BLOB,
            9 => Kind::CLOB,
            10 => Kind::LIST,
            11 => Kind::SEXP,
            12 => Kind::STRUCT,
        ];
        if (!isset($map[$t])) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        return $doc->add_null($map[$t]);
    }

    private function addFixed(IonDoc $doc, int $n): int
    {
        if ($n === 0) {
            return $doc->add_i64(0);
        }
        if ($n < 0 || $this->i + $n > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $bytes = substr($this->raw, $this->i, $n);
        $this->i += $n;
        if ($n <= 7) {
            $acc = 0;
            for ($k = 0; $k < $n; $k++) {
                $acc |= ord($bytes[$k]) << ($k * 8);
            }
            $sign = 1 << ($n * 8 - 1);
            if (($acc & $sign) !== 0) {
                $acc -= 1 << ($n * 8);
            }
            return $doc->add_i64($acc);
        }
        return $doc->add_int(BigInt::fromLeTwos($bytes));
    }

    private function float(IonDoc $doc, int $n): int
    {
        if ($this->i + $n > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $le = substr($this->raw, $this->i, $n);
        $this->i += $n;
        $be = strrev($le);
        return $doc->add_float($n, $be);
    }

    private function decimal(IonDoc $doc, int $n): int
    {
        if ($n === 0) {
            return $doc->add_decimal(new BigInt(false), false, 0);
        }
        $end = $this->i + $n;
        if ($end > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $exp = self::flexInt($this->raw, $this->i);
        $coef = new BigInt(false);
        $neg = false;
        if ($this->i < $end) {
            $nb = $end - $this->i;
            $bytes = substr($this->raw, $this->i, $nb);
            $this->i = $end;
            if ($nb <= 7) {
                $acc = 0;
                for ($k = 0; $k < $nb; $k++) {
                    $acc |= ord($bytes[$k]) << ($k * 8);
                }
                $sign = 1 << ($nb * 8 - 1);
                if (($acc & $sign) !== 0) {
                    $acc -= 1 << ($nb * 8);
                }
                if ($acc < 0) {
                    $neg = true;
                    $acc = -$acc;
                } else {
                    $neg = $acc === 0;
                }
                if ($acc !== 0) {
                    $coef = BigInt::fromU64($acc, false);
                }
            } else {
                $coef = BigInt::fromLeTwos($bytes);
                $neg = $coef->neg || $coef->isZero();
                $coef->neg = false;
            }
        }
        return $doc->add_decimal($coef, $neg, $exp);
    }

    private function bytes(IonDoc $doc, int $kind, int $n): int
    {
        if ($this->i + $n > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $buf = substr($this->raw, $this->i, $n);
        $this->i += $n;
        return $doc->add_bytes($kind, $buf);
    }

    private function container(IonDoc $doc, LocalTab $tab, int $kind, int $n, bool $delim, Catalog $cat): int
    {
        if ($this->depth >= Kind::MAX_DEPTH) {
            throw new DecodeError(DecodeError::KIND_DEPTH, $this->i);
        }
        $this->depth++;
        $id = $doc->start_container($kind);
        $end = $this->n;
        if (!$delim) {
            $end = $this->i + $n;
            if ($end > $this->n) {
                throw new DecodeError(DecodeError::KIND_EOF, $this->i);
            }
        }
        while ($this->i < $end) {
            if ($delim && $this->i < $this->n && ord($this->raw[$this->i]) === 0xEF) {
                $this->i++;
                $this->depth--;
                return $id;
            }
            $child = $this->value($doc, $tab, false, $cat);
            if ($child >= 0) {
                $doc->add_child($id, $child, -1);
            }
        }
        $this->depth--;
        if ($delim) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        return $id;
    }

    private function struct(IonDoc $doc, LocalTab $tab, int $n, bool $delim, bool $flex, Catalog $cat): int
    {
        if ($this->depth >= Kind::MAX_DEPTH) {
            throw new DecodeError(DecodeError::KIND_DEPTH, $this->i);
        }
        $this->depth++;
        $id = $doc->start_container(Kind::STRUCT);
        $end = $this->n;
        $mode = $flex;
        if (!$delim) {
            $end = $this->i + $n;
            if ($end > $this->n) {
                throw new DecodeError(DecodeError::KIND_EOF, $this->i);
            }
        }
        while ($this->i < $end) {
            if ($delim && ord($this->raw[$this->i]) === 0xEF) {
                $this->i++;
                $this->depth--;
                return $id;
            }
            $field = $this->fieldId($doc, $tab, $mode);
            if ($this->i < $this->n) {
                $marker = ord($this->raw[$this->i]);
                if ($marker === 0xEE || $marker === 0xEC || $marker === 0xEF) {
                    $this->i++;
                    if ($marker === 0xEE) {
                        $mode = !$mode;
                    }
                    if ($marker === 0xEF) {
                        $this->depth--;
                        return $id;
                    }
                    continue;
                }
            }
            $child = $this->value($doc, $tab, false, $cat);
            if ($child >= 0) {
                $doc->add_child($id, $child, $field);
            }
        }
        $this->depth--;
        if ($delim) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        return $id;
    }

    private function fieldId(IonDoc $doc, LocalTab $tab, bool $mode): int
    {
        if ($mode) {
            $iv = self::flexInt($this->raw, $this->i);
            if ($iv >= 0) {
                $sym = Lst::symFromSid($doc, $tab, $iv, $this->i);
                return $doc->nodes[$sym]->a;
            }
            $nbytes = -1 - $iv;
            return $doc->nodes[$doc->add_symbol_text($this->text($nbytes))]->a;
        }
        $sid = self::flexUint($this->raw, $this->i);
        $sym = Lst::symFromSid($doc, $tab, $sid, $this->i);
        return $doc->nodes[$sym]->a;
    }

    private function annotated(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $anns = [];
        while ($this->i < $this->n) {
            $op = ord($this->raw[$this->i]);
            if ($op === 0x58) {
                $this->i++;
                $sid = self::flexUint($this->raw, $this->i);
                $sym = Lst::symFromSid($doc, $tab, $sid, $this->i);
                $anns[] = $doc->nodes[$sym]->a;
            } elseif ($op === 0x59) {
                $this->i++;
                $n = self::flexUint($this->raw, $this->i);
                $sym = $doc->add_symbol_text($this->text($n));
                $anns[] = $doc->nodes[$sym]->a;
            } else {
                break;
            }
        }
        if ($anns === []) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $id = $this->value($doc, $tab, false, $cat);
        if ($id < 0) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $doc->set_anns($id, $anns);
        return $id;
    }

    private function directive(IonDoc $doc, LocalTab $tab, int $op, Catalog $cat): void
    {
        if ($op === 0xE1) {
            $tab->reset_system();
        }
        if ($op === 0xE3) {
            $this->clearMacros();
        }
        $saved = $this->templ;
        if ($op === 0xE3 || $op === 0xE4) {
            $this->templ = true;
        }
        $useName = '';
        $useVer = 1;
        $sawUse = false;
        while ($this->i < $this->n && ord($this->raw[$this->i]) !== 0xEF) {
            $param0 = count($this->paramKind);
            $this->curParam0 = $param0;
            $v = $this->value($doc, $tab, false, $cat);
            if (($op === 0xE1 || $op === 0xE2) && $v >= 0 && $doc->nodes[$v]->kind === Kind::STRING) {
                $tab->add_text($doc->text_at($v));
            }
            if (($op === 0xE3 || $op === 0xE4) && $v >= 0) {
                $this->defineMacro($doc, $v, $param0);
            }
            if ($op === 0xE5 && $v >= 0) {
                if (!$sawUse && $doc->nodes[$v]->kind === Kind::STRING) {
                    $useName = $doc->text_at($v);
                    $sawUse = true;
                } elseif ($doc->nodes[$v]->kind === Kind::INT) {
                    $useVer = $this->positiveInt($doc, $v);
                }
            }
        }
        $this->templ = $saved;
        if ($op === 0xE5) {
            $this->importNamed($tab, $cat, $useName, $useVer);
        }
        if ($this->i < $this->n && ord($this->raw[$this->i]) === 0xEF) {
            $this->i++;
        }
    }

    private function placeholder(IonDoc $doc, LocalTab $tab, int $op, Catalog $cat): int
    {
        if (!$this->templ) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $rel = count($this->paramKind) - $this->curParam0;
        $kind = 0;
        $defaultId = -1;
        if ($op === 0xEB) {
            if ($this->i >= $this->n) {
                throw new DecodeError(DecodeError::KIND_EOF, $this->i);
            }
            $kind = ord($this->raw[$this->i]);
            $this->i++;
        }
        if ($op === 0xEA) {
            $saved = $this->templ;
            $this->templ = false;
            while ($this->i < $this->n && (ord($this->raw[$this->i]) === 0xEC || ord($this->raw[$this->i]) === 0xED)) {
                $this->value($doc, $tab, false, $cat);
            }
            $defaultId = $this->value($doc, $tab, false, $cat);
            $this->templ = $saved;
            if ($defaultId < 0) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
        }
        $this->paramKind[] = $kind;
        $this->paramDefault[] = $defaultId;
        $hole = new IonNode(Kind::HOLE);
        $hole->a = $rel;
        $hole->b = $defaultId;
        return $doc->add($hole);
    }

    private function defineMacro(IonDoc $doc, int $id, int $param0): void
    {
        $n = $doc->nodes[$id];
        if ($n->kind !== Kind::SEXP || $n->nchild !== 2) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $nameId = $doc->child_at($id, 0);
        $body = $doc->child_at($id, 1);
        if ($doc->nodes[$nameId]->kind !== Kind::SYMBOL || $doc->nodes[$body]->kind === Kind::HOLE) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->macroName[] = $doc->sym_text($doc->nodes[$nameId]->a);
        $this->macroRoot[] = $body;
        $this->macroParamAt[] = $param0;
        $this->macroNParam[] = count($this->paramKind) - $param0;
    }

    private function eexp(IonDoc $doc, LocalTab $tab, int $op, Catalog $cat): int
    {
        $addr = $op;
        $limit = $this->n;
        if ($op >= 0x48 && $op <= 0x4F) {
            $hi = self::flexUint($this->raw, $this->i);
            $addr = (($hi << 3) | ($op - 0x48)) + 72;
        }
        if ($op === 0xF4) {
            $addr = self::flexUint($this->raw, $this->i);
            $nbytes = self::flexUint($this->raw, $this->i);
            $limit = $this->i + $nbytes;
            if ($limit > $this->n) {
                throw new DecodeError(DecodeError::KIND_EOF, $this->i);
            }
        }
        if ($addr < 0 || $addr >= count($this->macroRoot)) {
            throw new DecodeError(DecodeError::KIND_SYMBOL, $this->i);
        }
        $nparam = $this->macroNParam[$addr];
        $paramAt = $this->macroParamAt[$addr];
        $args = [];
        for ($p = 0; $p < $nparam; $p++) {
            while ($this->i < $limit && ord($this->raw[$this->i]) === 0xEC) {
                $this->i++;
            }
            if ($this->i >= $limit) {
                throw new DecodeError(DecodeError::KIND_EOF, $this->i);
            }
            $kind = $this->paramKind[$paramAt + $p];
            if ($kind === 0) {
                if (ord($this->raw[$this->i]) === 0xE0) {
                    $this->i++;
                    $args[] = -1;
                } else {
                    $arg = $this->value($doc, $tab, true, $cat);
                    if ($arg < 0) {
                        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                    }
                    $args[] = $arg;
                }
            } else {
                $args[] = $this->tagless($doc, $kind);
            }
        }
        if ($op === 0xF4 && $this->i !== $limit) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        return $this->expand($doc, $this->macroRoot[$addr], $args);
    }

    private function tagless(IonDoc $doc, int $code): int
    {
        if ($code === 0x60) {
            return $doc->add_i64(self::flexInt($this->raw, $this->i));
        }
        if ($code === 0x61 || $code === 0x62 || $code === 0x64 || $code === 0x68) {
            $n = 1;
            if ($code === 0x62) {
                $n = 2;
            }
            if ($code === 0x64) {
                $n = 4;
            }
            if ($code === 0x68) {
                $n = 8;
            }
            return $this->addFixed($doc, $n);
        }
        if ($code === 0xE0) {
            return $doc->add_i64(self::flexUint($this->raw, $this->i));
        }
        if ($code === 0xE1 || $code === 0xE2 || $code === 0xE4 || $code === 0xE8) {
            $n = 1;
            if ($code === 0xE2) {
                $n = 2;
            }
            if ($code === 0xE4) {
                $n = 4;
            }
            if ($code === 0xE8) {
                $n = 8;
            }
            if ($this->i + $n > $this->n) {
                throw new DecodeError(DecodeError::KIND_EOF, $this->i);
            }
            $acc = 0;
            for ($k = 0; $k < $n; $k++) {
                $acc |= ord($this->raw[$this->i + $k]) << ($k * 8);
            }
            $this->i += $n;
            return $doc->add_i64($acc);
        }
        if ($code >= 0x6B && $code <= 0x6D) {
            $n = 2;
            if ($code === 0x6C) {
                $n = 4;
            }
            if ($code === 0x6D) {
                $n = 8;
            }
            return $this->float($doc, $n);
        }
        if ($code === 0x82 || $code === 0x83 || $code === 0x84 || $code === 0x85 || $code === 0x86 || $code === 0x87) {
            return $this->shortTime($doc, $code);
        }
        if ($code === 0xF8 || $code === 0xF9 || $code === 0x90) {
            $n = self::flexUint($this->raw, $this->i);
            return $doc->add_string($this->text($n));
        }
        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
    }

    /** @param list<int> $args */
    private function expand(IonDoc $doc, int $id, array $args): int
    {
        $n = $doc->nodes[$id];
        if ($n->kind === Kind::HOLE) {
            $out = -1;
            if ($n->a < count($args) && $args[$n->a] >= 0) {
                $out = $args[$n->a];
            } elseif ($n->b >= 0) {
                $out = $this->expand($doc, $n->b, $args);
            }
            if ($out < 0) {
                return -1;
            }
            if ($n->annN > 0) {
                $src = $doc->nodes[$out];
                $copy = new IonNode($src->kind);
                $copy->a = $src->a;
                $copy->b = $src->b;
                $copy->c = $src->c;
                $copy->d = $src->d;
                $copy->child = $src->child;
                $copy->nchild = $src->nchild;
                $cloned = $doc->add($copy);
                $anns = [];
                for ($k = 0; $k < $n->annN; $k++) {
                    $anns[] = $doc->anns[$n->ann + $k];
                }
                $doc->set_anns($cloned, $anns);
                return $cloned;
            }
            return $out;
        }
        if ($n->kind === Kind::LIST || $n->kind === Kind::SEXP || $n->kind === Kind::STRUCT) {
            $nid = $doc->start_container($n->kind);
            if ($n->annN > 0) {
                $anns = [];
                for ($k = 0; $k < $n->annN; $k++) {
                    $anns[] = $doc->anns[$n->ann + $k];
                }
                $doc->set_anns($nid, $anns);
            }
            $e = $n->child;
            for ($seen = 0; $seen < $n->nchild; $seen++) {
                $child = $this->expand($doc, $doc->edgeChild[$e], $args);
                if ($child >= 0) {
                    $doc->add_child($nid, $child, $doc->edgeField[$e]);
                }
                $e = $doc->edgeNext[$e];
            }
            return $nid;
        }
        return $id;
    }

    private function shortTime(IonDoc $doc, int $op): int
    {
        $n = 1;
        if ($op !== 0x80) {
            if ($op === 0x81 || $op === 0x82) {
                $n = 2;
            } elseif ($op === 0x83) {
                $n = 4;
            } elseif ($op === 0x84 || $op === 0x88 || $op === 0x89) {
                $n = 5;
            } elseif ($op === 0x85) {
                $n = 6;
            } elseif ($op === 0x86 || $op === 0x8A) {
                $n = 7;
            } elseif ($op === 0x87 || $op === 0x8B) {
                $n = 8;
            } else {
                $n = 9;
            }
        }
        $start = $this->takeBits($n);
        $when = new IonTime();
        $when->year = 1970 + $this->field($start, 0, 7);
        $when->prec = Kind::PREC_YEAR;
        $when->unknown = true;
        $when->off = 0;
        if ($op === 0x80) {
            return $this->finishTime($doc, $when);
        }
        $when->month = $this->field($start, 7, 4);
        $when->prec = Kind::PREC_MONTH;
        if ($op === 0x81) {
            return $this->finishTime($doc, $when);
        }
        $when->day = $this->field($start, 11, 5);
        $when->prec = Kind::PREC_DAY;
        if ($op === 0x82) {
            return $this->finishTime($doc, $when);
        }
        $when->hour = $this->field($start, 16, 5);
        $when->minute = $this->field($start, 21, 6);
        $when->prec = Kind::PREC_MIN;
        $known = $op >= 0x88;
        if ($known) {
            $q = $this->field($start, 27, 7);
            if ($q === 127) {
                $when->unknown = true;
                $when->off = 0;
            } else {
                $when->unknown = false;
                $when->off = ($q - 56) * 15;
            }
        } else {
            $u = $this->field($start, 27, 1);
            if ($u === 0) {
                $when->unknown = true;
                $when->off = 0;
            } else {
                $when->unknown = false;
                $when->off = 0;
            }
        }
        if ($op === 0x83 || $op === 0x88) {
            return $this->finishTime($doc, $when);
        }
        $secAt = 28;
        $fracAt = 34;
        if ($known) {
            $secAt = 34;
            $fracAt = 40;
        }
        $when->second = $this->field($start, $secAt, 6);
        $when->prec = Kind::PREC_SEC;
        $digits = 0;
        $fbits = 0;
        if ($op === 0x85 || $op === 0x8A) {
            $digits = 3;
            $fbits = 10;
        } elseif ($op === 0x86 || $op === 0x8B) {
            $digits = 6;
            $fbits = 20;
        } elseif ($op === 0x87 || $op === 0x8C) {
            $digits = 9;
            $fbits = 30;
        }
        if ($digits !== 0) {
            $coef = $this->field($start, $fracAt, $fbits);
            $this->putFrac($doc, $when, $coef, -$digits);
        }
        return $this->finishTime($doc, $when);
    }

    private function longTime(IonDoc $doc): int
    {
        $n = self::flexUint($this->raw, $this->i);
        if ($n < 2 || $n === 4 || $n === 5) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $start = $this->takeBits($n);
        $when = new IonTime();
        $when->year = $this->field($start, 0, 14);
        $when->prec = Kind::PREC_YEAR;
        $when->unknown = true;
        $when->off = 0;
        $when->month = 1;
        $when->day = 1;
        if ($n === 2) {
            return $this->finishTime($doc, $when);
        }
        $when->month = $this->field($start, 14, 4);
        $when->prec = Kind::PREC_MONTH;
        if ($n === 3) {
            $day = $this->field($start, 18, 5);
            if ($day !== 0) {
                $when->day = $day;
                $when->prec = Kind::PREC_DAY;
            }
            return $this->finishTime($doc, $when);
        }
        $when->day = $this->field($start, 18, 5);
        $when->hour = $this->field($start, 23, 5);
        $when->minute = $this->field($start, 28, 6);
        $when->prec = Kind::PREC_MIN;
        $rawOff = $this->field($start, 34, 12);
        if ($rawOff === 4095) {
            $when->unknown = true;
            $when->off = 0;
        } else {
            $when->unknown = false;
            $when->off = $rawOff - 1440;
        }
        if ($n === 6) {
            return $this->finishTime($doc, $when);
        }
        $when->second = $this->field($start, 46, 6);
        $when->prec = Kind::PREC_SEC;
        if ($n === 7) {
            return $this->finishTime($doc, $when);
        }
        $fracAt = $start + 7;
        $saved = $this->i;
        $this->i = $fracAt;
        $scale = self::flexUint($this->raw, $this->i);
        if ($scale === 0) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $coefN = ($start + $n) - $this->i;
        if ($coefN < 0) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $coef = 0;
        for ($k = 0; $k < $coefN; $k++) {
            $coef |= ord($this->raw[$this->i + $k]) << ($k * 8);
        }
        $this->i = $saved;
        if ($scale > 0 && $coef >= 1) {
            $lim = 1;
            $p = 0;
            while ($p < $scale) {
                if ($lim > 100000000) {
                    break;
                }
                $lim *= 10;
                $p++;
            }
            if ($p === $scale && $coef >= $lim) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
        }
        $this->putFrac($doc, $when, $coef, -$scale);
        return $this->finishTime($doc, $when);
    }

    private function takeBits(int $n): int
    {
        if ($n < 0 || $this->i + $n > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $start = $this->i;
        $this->i += $n;
        return $start;
    }

    private function field(int $start, int $at, int $width): int
    {
        $v = 0;
        for ($k = 0; $k < $width; $k++) {
            $pos = $at + $k;
            $b = ord($this->raw[$start + intdiv($pos, 8)]);
            $bit = ($b >> ($pos % 8)) & 1;
            $v |= $bit << $k;
        }
        return $v;
    }

    private function finishTime(IonDoc $doc, IonTime $when): int
    {
        if ($when->month < 1 || $when->month > 12) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($when->prec >= Kind::PREC_DAY) {
            if ($when->day < 1 || $when->day > Time::days($when->year, $when->month)) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
        }
        if ($when->prec >= Kind::PREC_MIN) {
            if ($when->hour > 23 || $when->minute > 59) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
        }
        if ($when->prec >= Kind::PREC_SEC && $when->second > 59) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($when->year < 1 || $when->year > 999999999) {
            throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
        }
        return $doc->add_time($when);
    }

    private function putFrac(IonDoc $doc, IonTime $when, int $coef, int $exp): void
    {
        $when->prec = Kind::PREC_FRAC;
        $when->fracExp = $exp;
        $big = $coef === 0 ? new BigInt(false) : BigInt::fromU64($coef, false);
        $when->fracAt = count($doc->limbs);
        $when->fracLen = count($big->limbs);
        foreach ($big->limbs as $limb) {
            $doc->limbs[] = $limb;
        }
    }

    private function positiveInt(IonDoc $doc, int $id): int
    {
        $n = $doc->nodes[$id];
        if ($n->kind !== Kind::INT || $n->c !== 0 || $n->b > 1) {
            return 1;
        }
        if ($n->b === 0) {
            return 0;
        }
        return $doc->limbs[$n->a];
    }

    private function importNamed(LocalTab $tab, Catalog $cat, string $name, int $version): void
    {
        if ($name === '') {
            throw new DecodeError(DecodeError::KIND_SYMBOL, $this->i);
        }
        $idx = $cat->exact($name, $version);
        if ($idx < 0) {
            throw new DecodeError(DecodeError::KIND_SYMBOL, $this->i);
        }
        $table = $cat->tables[$idx];
        $n = count($table->texts);
        for ($k = 0; $k < $n; $k++) {
            if ($table->gap[$k]) {
                $tab->add_gap();
            } else {
                $tab->add_text($table->texts[$k]);
            }
        }
    }
}
