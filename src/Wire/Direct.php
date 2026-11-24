<?php

declare(strict_types=1);

namespace Gld\Ion\Wire;

use Gld\Ion\DecodeError;

/**
 * Ion 1.0 binary for PHP scalars, lists, and string-keyed structs.
 * No IonDoc nodes. Field names become one local symbol table, then one value.
 * Bytes are legal Ion: Binary::decode reads them, and decode() reads Writer output
 * for this same subset (bool, int, float, string, list, struct, local symbols).
 */
final class Direct
{
    private const SYS = [
        1 => '$ion',
        2 => '$ion_1_0',
        3 => '$ion_symbol_table',
        4 => 'name',
        5 => 'version',
        6 => 'imports',
        7 => 'symbols',
        8 => 'max_id',
        9 => '$ion_shared_symbol_table',
    ];

    /** @var array<string,int> */
    private static array $sid = [];

    /** @var list<string> */
    private static array $symbols = [];

    private static int $nextSid = 10;

    /** LST bytes for the last symbol list. The list is copied into every datagram. */
    private static string $lstKey = "\0";

    private static string $lstBytes = '';

    private static ?DirectParser $parser = null;

    public static function encode(mixed $value): string
    {
        self::$sid = [];
        self::$symbols = [];
        self::$nextSid = 10;
        $body = '';
        self::write($value, $body, 0);
        $key = self::$symbols === [] ? '' : implode("\0", self::$symbols);
        if ($key !== self::$lstKey) {
            self::$lstKey = $key;
            self::$lstBytes = '';
            if ($key !== '') {
                self::writeLst(self::$lstBytes);
            }
        }
        return "\xE0\x01\x00\xEA" . self::$lstBytes . $body;
    }

    public static function decode(string $raw): mixed
    {
        $parser = self::$parser ??= new DirectParser();
        return $parser->top($raw);
    }

    private static function write(mixed $value, string &$buf, int $depth): void
    {
        if ($depth > 1024) {
            throw new DecodeError(DecodeError::KIND_DEPTH, 0);
        }
        if ($value === null) {
            $buf .= "\x0F";
            return;
        }
        if (is_bool($value)) {
            $buf .= $value ? "\x11" : "\x10";
            return;
        }
        if (is_int($value)) {
            self::writeInt($buf, $value);
            return;
        }
        if (is_float($value)) {
            $buf .= "\x48" . pack('E', $value);
            return;
        }
        if (is_string($value)) {
            self::wrap($buf, 8, $value);
            return;
        }
        if (!is_array($value)) {
            throw new DecodeError(DecodeError::KIND_TYPE, 0);
        }
        $inner = '';
        if (array_is_list($value)) {
            if (self::writeFlatList($value, $inner, $depth)) {
                self::wrap($buf, 11, $inner);
                return;
            }
            foreach ($value as $item) {
                self::write($item, $inner, $depth + 1);
            }
            self::wrap($buf, 11, $inner);
            return;
        }
        foreach ($value as $key => $item) {
            $name = is_string($key) ? $key : (string) $key;
            if (!isset(self::$sid[$name])) {
                self::$sid[$name] = self::$nextSid;
                self::$symbols[] = $name;
                self::$nextSid++;
            }
            self::varUint($inner, self::$sid[$name]);
            self::write($item, $inner, $depth + 1);
        }
        self::wrap($buf, 13, $inner);
    }

    /** Uniform lists skip per-element dispatch. Returns false when the shape is mixed. */
    private static function writeFlatList(array $value, string &$inner, int $depth): bool
    {
        if ($value === []) {
            return true;
        }
        $first = $value[0];
        if (is_float($first)) {
            foreach ($value as $item) {
                if (!is_float($item)) {
                    return false;
                }
            }
            foreach ($value as $item) {
                $inner .= "\x48" . pack('E', $item);
            }
            return true;
        }
        if (is_int($first)) {
            foreach ($value as $item) {
                if (!is_int($item)) {
                    return false;
                }
            }
            foreach ($value as $item) {
                self::writeInt($inner, $item);
            }
            return true;
        }
        if (is_bool($first)) {
            foreach ($value as $item) {
                if (!is_bool($item)) {
                    return false;
                }
            }
            foreach ($value as $item) {
                $inner .= $item ? "\x11" : "\x10";
            }
            return true;
        }
        if (is_string($first)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    return false;
                }
            }
            foreach ($value as $item) {
                self::wrap($inner, 8, $item);
            }
            return true;
        }
        if (!is_array($first) || array_is_list($first)) {
            return false;
        }
        $keys = array_keys($first);
        $nkeys = count($keys);
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row) || array_keys($row) !== $keys) {
                return false;
            }
        }
        $sidByte = [];
        foreach ($keys as $key) {
            $name = is_string($key) ? $key : (string) $key;
            if (!isset(self::$sid[$name])) {
                self::$sid[$name] = self::$nextSid;
                self::$symbols[] = $name;
                self::$nextSid++;
            }
            $sid = self::$sid[$name];
            if ($sid > 127) {
                return false;
            }
            $sidByte[] = chr(0x80 | $sid);
        }
        if ($depth >= 1024) {
            throw new DecodeError(DecodeError::KIND_DEPTH, 0);
        }
        foreach ($value as $row) {
            $body = '';
            foreach ($keys as $i => $key) {
                $body .= $sidByte[$i];
                $item = $row[$key];
                if (is_string($item)) {
                    $ln = strlen($item);
                    if ($ln < 14) {
                        $body .= chr(0x80 | $ln) . $item;
                    } else {
                        $body .= "\x8E";
                        self::varUint($body, $ln);
                        $body .= $item;
                    }
                } elseif (is_int($item)) {
                    if ($item === 0) {
                        $body .= "\x20";
                    } elseif ($item > 0 && $item < 0x100) {
                        $body .= "\x21" . chr($item);
                    } elseif ($item > 0 && $item < 0x10000) {
                        $body .= "\x22" . chr($item >> 8) . chr($item);
                    } elseif ($item > 0 && $item < 0x1000000) {
                        $body .= "\x23" . chr($item >> 16) . chr($item >> 8) . chr($item);
                    } elseif ($item < 0 && $item > -256) {
                        $body .= "\x31" . chr(-$item);
                    } else {
                        self::writeInt($body, $item);
                    }
                } elseif (is_bool($item)) {
                    $body .= $item ? "\x11" : "\x10";
                } elseif (is_float($item)) {
                    $body .= "\x48" . pack('E', $item);
                } elseif ($item === null) {
                    $body .= "\x0F";
                } else {
                    self::write($item, $body, $depth + 2);
                }
            }
            $ln = strlen($body);
            if ($ln < 14) {
                $inner .= chr(0xD0 | $ln) . $body;
            } else {
                $inner .= "\xDE";
                self::varUint($inner, $ln);
                $inner .= $body;
            }
        }
        return true;
    }

    private static function writeInt(string &$buf, int $value): void
    {
        if ($value === 0) {
            $buf .= "\x20";
            return;
        }
        if ($value === PHP_INT_MIN) {
            $buf .= "\x38\x80\x00\x00\x00\x00\x00\x00\x00";
            return;
        }
        $neg = $value < 0;
        $mag = $neg ? -$value : $value;
        $tag = $neg ? 0x30 : 0x20;
        if ($mag < 0x100) {
            $buf .= chr($tag | 1) . chr($mag);
            return;
        }
        if ($mag < 0x10000) {
            $buf .= chr($tag | 2) . chr(($mag >> 8) & 255) . chr($mag & 255);
            return;
        }
        if ($mag < 0x1000000) {
            $buf .= chr($tag | 3) . chr(($mag >> 16) & 255) . chr(($mag >> 8) & 255) . chr($mag & 255);
            return;
        }
        if ($mag < 0x100000000) {
            $buf .= chr($tag | 4)
                . chr(($mag >> 24) & 255)
                . chr(($mag >> 16) & 255)
                . chr(($mag >> 8) & 255)
                . chr($mag & 255);
            return;
        }
        $be = '';
        $x = $mag;
        while ($x > 0) {
            $be = chr($x & 255) . $be;
            $x >>= 8;
        }
        $buf .= chr($tag | strlen($be)) . $be;
    }

    /** @param list<string> $symbols is self::$symbols */
    private static function writeLst(string &$buf): void
    {
        $list = '';
        foreach (self::$symbols as $text) {
            self::wrap($list, 8, $text);
        }
        $fields = '';
        self::varUint($fields, 7);
        self::wrap($fields, 11, $list);
        $struct = '';
        self::wrap($struct, 13, $fields);
        $ann = '';
        self::varUint($ann, 1);
        self::varUint($ann, 3);
        $ann .= $struct;
        self::wrap($buf, 14, $ann);
    }

    private static function wrap(string &$buf, int $tcode, string $body): void
    {
        $n = strlen($body);
        if ($n < 14) {
            $buf .= chr(($tcode << 4) | $n) . $body;
            return;
        }
        $buf .= chr(($tcode << 4) | 14);
        self::varUint($buf, $n);
        $buf .= $body;
    }

    private static function varUint(string &$buf, int $value): void
    {
        if ($value < 128) {
            $buf .= chr(0x80 | $value);
            return;
        }
        $parts = [];
        $x = $value;
        while ($x > 0) {
            $parts[] = $x & 0x7F;
            $x >>= 7;
        }
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $b = $parts[$i];
            if ($i === 0) {
                $b |= 0x80;
            }
            $buf .= chr($b);
        }
    }
}

/** Cursor over one Ion 1.0 datagram. */
final class DirectParser
{
    /** Distinct from int 0 and null so a symbol table is not a value. */
    private static ?object $skip = null;

    private string $raw = '';

    private int $n = 0;

    private int $i = 0;

    private int $depth = 0;

    /** @var array<int,string> */
    private array $syms = [];

    /** Header before the single user value, reused when the next datagram matches it. */
    private static int $hotAt = 0;

    private static string $hot = '';

    /** @var array<int,string> */
    private static array $hotSyms = [];

    public function __construct()
    {
    }

    private function skipMark(): object
    {
        return self::$skip ??= new \stdClass();
    }

    public function top(string $raw): mixed
    {
        $this->raw = $raw;
        $this->n = strlen($raw);
        $this->depth = 0;
        $at = self::$hotAt;
        if ($at > 4 && $at < $this->n && substr_compare($raw, self::$hot, 0, $at) === 0) {
            $this->syms = self::$hotSyms;
            $this->i = $at;
            $value = $this->readValue();
            if ($value !== $this->skipMark() && $this->i === $this->n) {
                return $value;
            }
        }
        $this->i = 0;
        $this->resetSyms();
        $values = [];
        $markAt = 0;
        $markSyms = [];
        while ($this->i < $this->n) {
            if ($this->atBvm()) {
                $minor = ord($this->raw[$this->i + 2]);
                $this->i += 4;
                if ($minor !== 0) {
                    throw new DecodeError(DecodeError::KIND_VERSION, $this->i - 4);
                }
                $this->resetSyms();
                continue;
            }
            $pendingAt = $this->i;
            $pendingSyms = $this->syms;
            $value = $this->readValue();
            if ($value === $this->skipMark()) {
                continue;
            }
            if ($values === []) {
                $markAt = $pendingAt;
                $markSyms = $pendingSyms;
            }
            $values[] = $value;
        }
        $count = count($values);
        if ($count === 1) {
            self::$hotAt = $markAt;
            self::$hot = substr($raw, 0, $markAt);
            self::$hotSyms = $markSyms;
            return $values[0];
        }
        self::$hotAt = 0;
        if ($count === 0) {
            return null;
        }
        return $values;
    }

    private function resetSyms(): void
    {
        $this->syms = [
            1 => '$ion',
            2 => '$ion_1_0',
            3 => '$ion_symbol_table',
            4 => 'name',
            5 => 'version',
            6 => 'imports',
            7 => 'symbols',
            8 => 'max_id',
            9 => '$ion_shared_symbol_table',
        ];
    }

    private function atBvm(): bool
    {
        return $this->i + 3 < $this->n
            && ord($this->raw[$this->i]) === 0xE0
            && ord($this->raw[$this->i + 1]) === 0x01
            && ord($this->raw[$this->i + 3]) === 0xEA;
    }

    private function readValue(): mixed
    {
        while (true) {
            if ($this->i >= $this->n) {
                throw new DecodeError(DecodeError::KIND_EOF, $this->i);
            }
            $td = ord($this->raw[$this->i]);
            $this->i++;
            $type = $td >> 4;
            $ln = $td & 15;
            if ($type === 0 && $ln !== 15) {
                $this->skip($this->lenOf($ln));
                continue;
            }
            if ($ln === 15) {
                return null;
            }
            return match ($type) {
                1 => $this->readBool($ln),
                2 => $this->readInt($ln, false),
                3 => $this->readInt($ln, true),
                4 => $this->readFloat($ln),
                7 => $this->symText($this->readMag($this->lenOf($ln))),
                8 => $this->take($this->lenOf($ln)),
                11 => $this->readList($this->lenOf($ln)),
                13 => $this->readStruct($this->lenOf($ln)),
                14 => $this->readAnn($this->lenOf($ln)),
                default => throw new DecodeError(DecodeError::KIND_TYPE, $this->i - 1),
            };
        }
    }

    private function readBool(int $ln): bool
    {
        if ($ln === 0) {
            return false;
        }
        if ($ln === 1) {
            return true;
        }
        throw new DecodeError(DecodeError::KIND_TYPE, $this->i - 1);
    }

    private function readInt(int $ln, bool $neg): int
    {
        $len = $this->lenOf($ln);
        if ($len === 0) {
            return 0;
        }
        if ($len > 8) {
            throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
        }
        if ($len === 8) {
            $be = $this->take(8);
            if ($be === "\x80\x00\x00\x00\x00\x00\x00\x00") {
                if (!$neg) {
                    throw new DecodeError(DecodeError::KIND_RANGE, $this->i - 8);
                }
                return PHP_INT_MIN;
            }
            if ((ord($be[0]) & 0x80) !== 0) {
                throw new DecodeError(DecodeError::KIND_RANGE, $this->i - 8);
            }
            $hi = unpack('N', substr($be, 0, 4))[1];
            $lo = unpack('N', substr($be, 4, 4))[1];
            $mag = $hi * 4294967296 + $lo;
            return $neg ? -$mag : $mag;
        }
        $mag = $this->readMag($len);
        return $neg ? -$mag : $mag;
    }

    private function readMag(int $len): int
    {
        $mag = 0;
        $end = $this->i + $len;
        if ($end > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $raw = $this->raw;
        $i = $this->i;
        while ($i < $end) {
            $mag = ($mag << 8) | ord($raw[$i]);
            $i++;
        }
        $this->i = $i;
        return $mag;
    }

    private function readFloat(int $ln): float
    {
        $len = $this->lenOf($ln);
        if ($len === 0) {
            return 0.0;
        }
        if ($len === 4) {
            return unpack('G', $this->take(4))[1];
        }
        if ($len === 8) {
            return unpack('E', $this->take(8))[1];
        }
        throw new DecodeError(DecodeError::KIND_TYPE, $this->i - 1);
    }

    /** @return list<mixed> */
    private function readList(int $len): array
    {
        if ($this->depth > 1024) {
            throw new DecodeError(DecodeError::KIND_DEPTH, $this->i);
        }
        $end = $this->i + $len;
        if ($end > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $this->depth++;
        $out = [];
        while ($this->i < $end) {
            $item = $this->readValue();
            if ($item !== $this->skipMark()) {
                $out[] = $item;
            }
        }
        if ($this->i !== $end) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->depth--;
        return $out;
    }

    /** @return array<string,mixed> */
    private function readStruct(int $len): array
    {
        if ($this->depth > 1024) {
            throw new DecodeError(DecodeError::KIND_DEPTH, $this->i);
        }
        $end = $this->i + $len;
        if ($end > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $this->depth++;
        $out = [];
        $raw = $this->raw;
        $syms = $this->syms;
        while ($this->i < $end) {
            $b = ord($raw[$this->i]);
            if (($b & 0x80) !== 0) {
                $sid = $b & 0x7F;
                $this->i++;
            } else {
                $sid = $this->varUint();
            }
            $name = $syms[$sid] ?? "\0";
            if ($name === "\0") {
                throw new DecodeError(DecodeError::KIND_SYMBOL, $this->i);
            }
            if ($this->i >= $end) {
                throw new DecodeError(DecodeError::KIND_EOF, $this->i);
            }
            $td = ord($raw[$this->i]);
            $type = $td >> 4;
            $ln = $td & 15;
            if ($type === 1 && $ln < 2) {
                $this->i++;
                $out[$name] = $ln === 1;
                continue;
            }
            if (($type === 2 || $type === 3) && $ln < 8) {
                $this->i++;
                if ($ln === 0) {
                    $out[$name] = 0;
                    continue;
                }
                $mag = 0;
                $stop = $this->i + $ln;
                while ($this->i < $stop) {
                    $mag = ($mag << 8) | ord($raw[$this->i]);
                    $this->i++;
                }
                $out[$name] = $type === 3 ? -$mag : $mag;
                continue;
            }
            if ($type === 4 && $ln === 8) {
                $this->i++;
                $out[$name] = unpack('E', substr($raw, $this->i, 8))[1];
                $this->i += 8;
                continue;
            }
            if ($type === 8 && $ln < 14) {
                $this->i++;
                $out[$name] = $ln === 0 ? '' : substr($raw, $this->i, $ln);
                $this->i += $ln;
                continue;
            }
            if ($type === 8 && $ln === 14) {
                $this->i++;
                $slen = $this->varUint();
                $out[$name] = substr($raw, $this->i, $slen);
                $this->i += $slen;
                continue;
            }
            $item = $this->readValue();
            if ($item === $this->skipMark()) {
                continue;
            }
            $out[$name] = $item;
        }
        if ($this->i !== $end) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->depth--;
        return $out;
    }

    private function readAnn(int $len): mixed
    {
        $end = $this->i + $len;
        if ($end > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $alen = $this->varUint();
        $annEnd = $this->i + $alen;
        if ($annEnd > $end) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $table = false;
        while ($this->i < $annEnd) {
            if ($this->varUint() === 3) {
                $table = true;
            }
        }
        $value = $this->readValue();
        if ($this->i !== $end) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($table) {
            $this->applyTable($value);
            return $this->skipMark();
        }
        return $value;
    }

    private function applyTable(mixed $value): void
    {
        if (!is_array($value) || array_is_list($value)) {
            return;
        }
        $symbols = $value['symbols'] ?? null;
        if (!is_array($symbols)) {
            return;
        }
        foreach ($symbols as $text) {
            if (!is_string($text)) {
                throw new DecodeError(DecodeError::KIND_SYMBOL, $this->i);
            }
            $this->syms[] = $text;
        }
    }

    private function symText(int $sid): string
    {
        if ($sid === 0) {
            return '';
        }
        if (!isset($this->syms[$sid])) {
            throw new DecodeError(DecodeError::KIND_SYMBOL, $this->i);
        }
        return $this->syms[$sid];
    }

    private function lenOf(int $ln): int
    {
        if ($ln < 14) {
            return $ln;
        }
        return $this->varUint();
    }

    private function varUint(): int
    {
        $n = 0;
        $raw = $this->raw;
        $i = $this->i;
        $limit = $this->n;
        $guard = 0;
        while ($i < $limit && $guard < 10) {
            $b = ord($raw[$i]);
            $i++;
            $n = ($n << 7) | ($b & 0x7F);
            $guard++;
            if (($b & 0x80) !== 0) {
                $this->i = $i;
                return $n;
            }
        }
        throw new DecodeError(DecodeError::KIND_EOF, $this->i);
    }

    private function take(int $len): string
    {
        if ($len < 0 || $this->i + $len > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $s = substr($this->raw, $this->i, $len);
        $this->i += $len;
        return $s;
    }

    private function skip(int $len): void
    {
        if ($len < 0 || $this->i + $len > $this->n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $this->i += $len;
    }
}
