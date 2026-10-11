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

/** Ion text reader for 1.0 and the 1.1 directive forms used by this library. */
final class Text
{
    private int $i = 0;
    private int $depth = 0;
    private int $sexp = 0;
    private int $ver = 10;
    private int $bareIvm = 0;
    private bool $templ = false;
    private int $curParam0 = 0;

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
    private string $sym0 = "\1";
    private string $sym1 = "\1";
    private int $symId0 = -1;
    private int $symId1 = -1;
    private string $plain = "\1";
    private string $patPre = '';
    private string $patSuf = '';
    private int $patSym1 = -1;
    private int $patSym2 = -1;
    private string $patText = '';
    private int $patTextId = -1;

    private function __construct(private string $raw)
    {
        $this->n = strlen($raw);
    }

    public static function decode(string $raw, Catalog $cat): IonDoc
    {
        if (strlen($raw) >= 3 && $raw[0] === "\xEF" && $raw[1] === "\xBB" && $raw[2] === "\xBF") {
            throw new DecodeError(DecodeError::KIND_SYNTAX, 0);
        }
        $doc = new IonDoc();
        $doc->reserve(intdiv(strlen($raw), 2) + 8);
        $tab = new LocalTab();
        if (Utf8::isWide($raw)) {
            $utf = Utf8::fromWide($raw);
            Utf8::validate($utf, 0);
            $parser = new self($utf);
            $parser->readAll($doc, $tab, $cat);
            return $doc;
        }
        if (!Utf8::isAscii($raw)) {
            Utf8::validate($raw, 0);
        }
        $parser = new self($raw);
        $parser->readAll($doc, $tab, $cat);
        return $doc;
    }

    public static function adopt(IonDoc $doc, string $text): int
    {
        $parser = new self($text);
        $id = $parser->parseValue($doc, new LocalTab(), new Catalog());
        if ($id < 0) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, 0);
        }
        return $id;
    }

    private function readAll(IonDoc $doc, LocalTab $tab, Catalog $cat): void
    {
        while (true) {
            if ($this->patPre !== '') {
                $this->readRepeated($doc);
            }
            $this->skipWs();
            if ($this->i >= $this->n) {
                return;
            }
            $this->bareIvm = 0;
            $c0 = ord($this->raw[$this->i]);
            if ($c0 === 123 && ($this->i + 1 >= $this->n || ord($this->raw[$this->i + 1]) !== 123)) {
                $flat = $this->tryFlatStruct($doc);
                $doc->add_top($flat >= 0 ? $flat : $this->struct($doc, $tab, $cat));
                continue;
            }
            if ($c0 === 91) {
                $doc->add_top($this->list($doc, $tab, $cat));
                continue;
            }
            $id = $this->parseValue($doc, $tab, $cat);
            if ($id < 0) {
                continue;
            }
            if ($this->bareIvm === 10 || $this->bareIvm === 11) {
                $tab->reset_system();
                $this->clearMacros();
                $this->ver = $this->bareIvm === 11 ? 11 : 10;
                continue;
            }
            if ($this->ivmNop($doc, $id)) {
                continue;
            }
            if ($this->ver === 10 && Lst::isLocalTable($doc, $id)) {
                Lst::apply($doc, $id, $tab, $cat, $this->i);
                continue;
            }
            if ($this->ver === 11 && Lst::isLocalTable($doc, $id)) {
                continue;
            }
            $doc->add_top($id);
        }
    }

    private function ivmNop(IonDoc $doc, int $id): bool
    {
        $n = $doc->nodes[$id];
        if ($n->annN !== 0 || $n->kind !== Kind::SYMBOL) {
            return false;
        }
        $s = $doc->syms[$n->a];
        if ($s->text < 0) {
            return false;
        }
        $t = $doc->texts[$s->text];
        if ($t === '$ion_1_0') {
            return true;
        }
        return $this->ver === 11 && $t === '$ion_1_1';
    }

    private function parseValue(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $this->skipWs();
        if ($this->depth === 0) {
            $iv = $this->bareIvmWord();
            if ($iv !== 0) {
                $this->bareIvm = $iv;
                return $doc->add_symbol_text($iv === 10 ? '$ion_1_0' : '$ion_1_1');
            }
        }
        if (!$this->annAhead()) {
            return $this->one($doc, $tab, $cat);
        }
        $anns = [];
        while ($this->annAhead()) {
            $quoted = ord($this->raw[$this->i]) === 39;
            $sym = $this->parseSymbol($doc, $tab, $quoted);
            $this->skipWs();
            $this->expect(58);
            $this->expect(58);
            $anns[] = $sym;
            $this->skipWs();
        }
        $id = $this->one($doc, $tab, $cat);
        if ($id < 0) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $doc->set_anns($id, $anns);
        return $id;
    }

    private function bareIvmWord(): int
    {
        $n = $this->n;
        if ($this->i >= $n) {
            return 0;
        }
        $c = ord($this->raw[$this->i]);
        if (!self::ident($c) || ($c >= 48 && $c <= 57)) {
            return 0;
        }
        $j = $this->i;
        while ($j < $n && self::ident(ord($this->raw[$j]))) {
            $j++;
        }
        $word = substr($this->raw, $this->i, $j - $this->i);
        $k = $j;
        while ($k < $n && self::ws(ord($this->raw[$k]))) {
            $k++;
        }
        if ($k + 1 < $n && ord($this->raw[$k]) === 58 && ord($this->raw[$k + 1]) === 58) {
            return 0;
        }
        $which = $this->ivmWord($word);
        if ($which === 0) {
            return 0;
        }
        $this->i = $j;
        return $which;
    }

    private function ivmWord(string $word): int
    {
        if (strlen($word) < 8 || $word[0] !== '$') {
            return 0;
        }
        if (!str_starts_with($word, '$ion_')) {
            return 0;
        }
        $k = 5;
        $major = 0;
        $saw = false;
        $n = strlen($word);
        while ($k < $n && $word[$k] >= '0' && $word[$k] <= '9') {
            $saw = true;
            $major = $major * 10 + ord($word[$k]) - 48;
            $k++;
        }
        if (!$saw || $k >= $n || $word[$k] !== '_') {
            return 0;
        }
        $k++;
        $minor = 0;
        $saw = false;
        while ($k < $n && $word[$k] >= '0' && $word[$k] <= '9') {
            $saw = true;
            $minor = $minor * 10 + ord($word[$k]) - 48;
            $k++;
        }
        if (!$saw || $k !== $n) {
            return 0;
        }
        if ($major === 1 && $minor === 0) {
            return 10;
        }
        if ($major === 1 && $minor === 1) {
            return 11;
        }
        throw new DecodeError(DecodeError::KIND_VERSION, $this->i);
    }

    private function annAhead(): bool
    {
        $n = $this->n;
        if ($this->i >= $n) {
            return false;
        }
        $c = ord($this->raw[$this->i]);
        if ($c !== 39 && $c !== 36 && !($c >= 65 && $c <= 90) && !($c >= 97 && $c <= 122) && $c !== 95) {
            return false;
        }
        $j = $this->i;
        if ($c === 39) {
            $j++;
            while ($j < $n) {
                if (ord($this->raw[$j]) === 92) {
                    $j += 2;
                    continue;
                }
                if (ord($this->raw[$j]) === 39) {
                    $j++;
                    break;
                }
                $j++;
            }
        } else {
            while ($j < $n && self::ident(ord($this->raw[$j]))) {
                $j++;
            }
            $word = substr($this->raw, $this->i, $j - $this->i);
            if ($word === 'null' || $word === 'true' || $word === 'false' || $word === 'nan') {
                return false;
            }
        }
        $k = $j;
        while ($k < $n && self::ws(ord($this->raw[$k]))) {
            $k++;
        }
        return $k + 1 < $n && ord($this->raw[$k]) === 58 && ord($this->raw[$k + 1]) === 58;
    }

    private function one(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $n = $this->n;
        if ($this->i >= $n) {
            throw new DecodeError(DecodeError::KIND_EOF, $this->i);
        }
        $c = ord($this->raw[$this->i]);
        if ($this->ver === 11 && $c === 40 && $this->i + 1 < $n && ord($this->raw[$this->i + 1]) === 58) {
            return $this->colon($doc, $tab, $cat);
        }
        if ($c === 91) {
            return $this->list($doc, $tab, $cat);
        }
        if ($c === 40) {
            return $this->sexp($doc, $tab, $cat);
        }
        if ($c === 123) {
            if ($this->i + 1 < $n && ord($this->raw[$this->i + 1]) === 123) {
                return $this->lob($doc);
            }
            return $this->struct($doc, $tab, $cat);
        }
        if ($c === 34) {
            return $this->string($doc, false);
        }
        if ($c === 39) {
            if ($this->i + 2 < $n && ord($this->raw[$this->i + 1]) === 39 && ord($this->raw[$this->i + 2]) === 39) {
                return $this->string($doc, true);
            }
            return $doc->wrap_sym($this->parseSymbol($doc, $tab, true));
        }
        if ($c === 43 || $c === 45 || ($c >= 48 && $c <= 57)) {
            return $this->number($doc);
        }
        if (self::ident($c)) {
            return $this->keywordOrSymbol($doc, $tab);
        }
        if ($this->sexp > 0 && self::op($c)) {
            return $doc->add_symbol_text($this->operator());
        }
        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
    }

    private function list(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $this->enter();
        $this->i++;
        $id = $doc->start_container(Kind::LIST);
        $this->skipWs();
        $n = $this->n;
        if ($this->i < $n && ord($this->raw[$this->i]) === 93) {
            $this->i++;
            $this->depth--;
            return $id;
        }
        while (true) {
            $child = $this->quickValue($doc, $tab, $cat);
            if ($child >= 0) {
                $doc->add_child($id, $child, -1);
            }
            $this->skipWs();
            $n = $this->n;
            if ($this->i < $n && ord($this->raw[$this->i]) === 44) {
                $this->i++;
                $this->skipWs();
                if ($this->i < $this->n && ord($this->raw[$this->i]) === 93) {
                    $this->i++;
                    break;
                }
                continue;
            }
            if ($this->i < $this->n && ord($this->raw[$this->i]) === 93) {
                $this->i++;
                break;
            }
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->depth--;
        return $id;
    }

    private function sexp(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $this->enter();
        $this->i++;
        $this->sexp++;
        $id = $doc->start_container(Kind::SEXP);
        while (true) {
            $this->skipWs();
            if ($this->i < $this->n && ord($this->raw[$this->i]) === 41) {
                $this->i++;
                break;
            }
            if ($this->i >= $this->n) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            $child = $this->parseValue($doc, $tab, $cat);
            if ($child >= 0) {
                $doc->add_child($id, $child, -1);
            }
        }
        $this->sexp--;
        $this->depth--;
        return $id;
    }

    private function struct(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $this->enter();
        $this->i++;
        $id = $doc->start_container(Kind::STRUCT);
        $this->skipWs();
        if ($this->i < $this->n && ord($this->raw[$this->i]) === 125) {
            $this->i++;
            $this->depth--;
            return $id;
        }
        while (true) {
            $field = $this->fastField($doc, $tab);
            if ($this->i < $this->n && ord($this->raw[$this->i]) === 58) {
                $this->i++;
                if ($this->i < $this->n && ord($this->raw[$this->i]) === 58) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
            } else {
                $this->skipWs();
                $this->expect(58);
                if ($this->i < $this->n && ord($this->raw[$this->i]) === 58) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
            }
            $child = $this->inlineScalar($doc);
            if ($child === -2) {
                $child = $this->quickValue($doc, $tab, $cat);
            }
            if ($child >= 0) {
                $doc->add_child($id, $child, $field);
            }
            if ($this->i >= $this->n || (ord($this->raw[$this->i]) !== 44 && ord($this->raw[$this->i]) !== 125)) {
                $this->skipWs();
            }
            if ($this->i < $this->n && ord($this->raw[$this->i]) === 44) {
                $this->i++;
                $this->skipWs();
                if ($this->i < $this->n && ord($this->raw[$this->i]) === 125) {
                    $this->i++;
                    break;
                }
                continue;
            }
            if ($this->i < $this->n && ord($this->raw[$this->i]) === 125) {
                $this->i++;
                break;
            }
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->depth--;
        return $id;
    }

    private function readRepeated(IonDoc $doc): void
    {
        $raw = $this->raw;
        $n = $this->n;
        $pre = $this->patPre;
        $suf = $this->patSuf;
        $pl = strlen($pre);
        $sl = strlen($suf);
        $i = $this->i;
        $sym1 = $this->patSym1;
        $sym2 = $this->patSym2;
        $textId = $this->patTextId;
        $vals = [];
        $ws = " \t\n\r\x0b\f";
        while (true) {
            $i += strspn($raw, $ws, $i);
            if ($i >= $n || $i + $pl >= $n || substr_compare($raw, $pre, $i, $pl) !== 0) {
                break;
            }
            $j = $i + $pl;
            $dlen = strspn($raw, '0123456789', $j);
            if ($dlen === 0 || $dlen >= 19 || ($dlen > 1 && $raw[$j] === '0')) {
                break;
            }
            $end = $j + $dlen;
            if ($end + $sl > $n || substr_compare($raw, $suf, $end, $sl) !== 0) {
                break;
            }
            $int = 0;
            for ($p = $j; $p < $end; $p++) {
                $int = $int * 10 + (ord($raw[$p]) - 48);
            }
            $i = $end + $sl;
            $vals[] = $int;
        }
        if ($vals !== []) {
            $doc->add_top_pairs($sym1, $vals, $sym2, $textId);
        }
        $this->i = $i;
    }

    /**
     * Compact `{ident:int,ident:"plain"}` with no whitespace.
     * Returns -1, and does not consume input, when the shape does not match.
     */
    private function tryFlatStruct(IonDoc $doc): int
    {
        $i = $this->i;
        $n = $this->n;
        $raw = $this->raw;
        $preLen = strlen($this->patPre);
        if ($preLen > 0 && $i + $preLen < $n && substr_compare($raw, $this->patPre, $i, $preLen) === 0) {
            $j = $i + $preLen;
            if ($j < $n) {
                $c = ord($raw[$j]);
                $int = null;
                if ($c === 48) {
                    $int = 0;
                    $j++;
                } elseif ($c >= 49 && $c <= 57) {
                    $int = 0;
                    while ($j < $n) {
                        $d = ord($raw[$j]);
                        if ($d < 48 || $d > 57) {
                            break;
                        }
                        $digit = $d - 48;
                        if ($int > 922337203685477580 || ($int === 922337203685477580 && $digit > 7)) {
                            $int = null;
                            break;
                        }
                        $int = $int * 10 + $digit;
                        $j++;
                    }
                }
                $sufLen = strlen($this->patSuf);
                if ($int !== null && $j + $sufLen <= $n && substr_compare($raw, $this->patSuf, $j, $sufLen) === 0) {
                    $this->i = $j + $sufLen;
                    return $doc->add_pair_struct($this->patSym1, $int, $this->patSym2, $this->patText, $this->patTextId);
                }
            }
        }
        if ($i >= $n || ord($raw[$i]) !== 123) {
            return -1;
        }
        $origin = $i;
        $i++;
        $c = $i < $n ? ord($raw[$i]) : 0;
        if (!(($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || $c === 95 || $c === 36)) {
            return -1;
        }
        $s1 = $i;
        $i++;
        while ($i < $n && self::ident(ord($raw[$i]))) {
            $i++;
        }
        $l1 = $i - $s1;
        if ($i >= $n || ord($raw[$i]) !== 58) {
            return -1;
        }
        $i++;
        if ($i >= $n) {
            return -1;
        }
        $intAt = $i;
        $c = ord($raw[$i]);
        if ($c === 48) {
            $int = 0;
            $i++;
        } elseif ($c >= 49 && $c <= 57) {
            $int = 0;
            while ($i < $n) {
                $d = ord($raw[$i]);
                if ($d < 48 || $d > 57) {
                    break;
                }
                $digit = $d - 48;
                if ($int > 922337203685477580 || ($int === 922337203685477580 && $digit > 7)) {
                    return -1;
                }
                $int = $int * 10 + $digit;
                $i++;
            }
        } else {
            return -1;
        }
        $intEnd = $i;
        if ($i >= $n || ord($raw[$i]) !== 44) {
            return -1;
        }
        $i++;
        $c = $i < $n ? ord($raw[$i]) : 0;
        if (!(($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || $c === 95 || $c === 36)) {
            return -1;
        }
        $s2 = $i;
        $i++;
        while ($i < $n && self::ident(ord($raw[$i]))) {
            $i++;
        }
        $l2 = $i - $s2;
        if ($i + 1 >= $n || ord($raw[$i]) !== 58 || ord($raw[$i + 1]) !== 34) {
            return -1;
        }
        $i += 2;
        $qs = $i;
        while ($i < $n) {
            $d = ord($raw[$i]);
            if ($d === 34) {
                break;
            }
            if ($d === 92 || $d < 32) {
                return -1;
            }
            $i++;
        }
        if ($i >= $n || ord($raw[$i]) !== 34 || $i + 1 >= $n || ord($raw[$i + 1]) !== 125) {
            return -1;
        }
        $sl = $i - $qs;
        $end = $i + 2;
        if (strlen($this->sym0) === $l1 && substr_compare($raw, $this->sym0, $s1, $l1) === 0) {
            $sym1 = $this->symId0;
            $name1 = $this->sym0;
        } elseif (strlen($this->sym1) === $l1 && substr_compare($raw, $this->sym1, $s1, $l1) === 0) {
            $sym1 = $this->symId1;
            $name1 = $this->sym1;
        } else {
            $name1 = substr($raw, $s1, $l1);
            if ($name1 === 'null' || $name1 === 'true' || $name1 === 'false' || $name1 === 'nan' || ($name1[0] === '$' && ctype_digit(substr($name1, 1)))) {
                return -1;
            }
            $sym1 = -1;
        }
        if ($name1 === $this->sym0 && $l1 === strlen($this->sym0) && $sym1 >= 0) {
            // already resolved
        }
        if (strlen($this->sym0) === $l2 && substr_compare($raw, $this->sym0, $s2, $l2) === 0) {
            $sym2 = $this->symId0;
        } elseif (strlen($this->sym1) === $l2 && substr_compare($raw, $this->sym1, $s2, $l2) === 0) {
            $sym2 = $this->symId1;
        } else {
            $name2 = substr($raw, $s2, $l2);
            if ($name2 === 'null' || $name2 === 'true' || $name2 === 'false' || $name2 === 'nan' || ($name2[0] === '$' && ctype_digit(substr($name2, 1)))) {
                return -1;
            }
            $sym2 = -1;
        }
        if (strlen($this->plain) === $sl && substr_compare($raw, $this->plain, $qs, $sl) === 0) {
            $text = $this->plain;
        } else {
            $text = substr($raw, $qs, $sl);
            $this->plain = $text;
        }
        $this->i = $end;
        if ($sym1 < 0) {
            $sym1 = $doc->nodes[$doc->add_symbol_text($name1)]->a;
            $this->sym1 = $this->sym0;
            $this->symId1 = $this->symId0;
            $this->sym0 = $name1;
            $this->symId0 = $sym1;
        }
        if ($sym2 < 0) {
            $name2 = $name2 ?? substr($raw, $s2, $l2);
            $sym2 = $doc->nodes[$doc->add_symbol_text($name2)]->a;
            $this->sym1 = $this->sym0;
            $this->symId1 = $this->symId0;
            $this->sym0 = $name2;
            $this->symId0 = $sym2;
        }
        $this->patPre = substr($raw, $origin, $intAt - $origin);
        $this->patSuf = substr($raw, $intEnd, $end - $intEnd);
        $this->patSym1 = $sym1;
        $this->patSym2 = $sym2;
        $this->patText = $text;
        $this->patTextId = $doc->intern($text);
        return $doc->add_pair_struct($sym1, $int, $sym2, $text, $this->patTextId);
    }

    private function enter(): void
    {
        if ($this->depth >= Kind::MAX_DEPTH) {
            throw new DecodeError(DecodeError::KIND_DEPTH, $this->i);
        }
        $this->depth++;
    }

    private function fastField(IonDoc $doc, LocalTab $tab): int
    {
        $i = $this->i;
        $n = $this->n;
        $raw = $this->raw;
        while ($i < $n) {
            $c = ord($raw[$i]);
            if ($c === 32 || $c === 10 || $c === 9 || $c === 13) {
                $i++;
                continue;
            }
            break;
        }
        if ($i >= $n) {
            $this->i = $i;
            return $this->fieldName($doc, $tab);
        }
        $c = ord($raw[$i]);
        if (!(($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || $c === 95 || $c === 36)) {
            $this->i = $i;
            return $this->fieldName($doc, $tab);
        }
        $start = $i;
        $i++;
        while ($i < $n && self::ident(ord($raw[$i]))) {
            $i++;
        }
        if ($i >= $n || ord($raw[$i]) !== 58 || ($i + 1 < $n && ord($raw[$i + 1]) === 58)) {
            $this->i = $start;
            return $this->fieldName($doc, $tab);
        }
        $len = $i - $start;
        if (strlen($this->sym0) === $len && substr_compare($raw, $this->sym0, $start, $len) === 0) {
            $this->i = $i;
            return $this->symId0;
        }
        if (strlen($this->sym1) === $len && substr_compare($raw, $this->sym1, $start, $len) === 0) {
            $this->i = $i;
            return $this->symId1;
        }
        $text = substr($raw, $start, $len);
        if ($text === 'null' || $text === 'true' || $text === 'false' || $text === 'nan' || ($text[0] === '$' && ctype_digit(substr($text, 1)))) {
            $this->i = $start;
            return $this->fieldName($doc, $tab);
        }
        $this->i = $i;
        $node = $doc->add_symbol_text($text);
        $sym = $doc->nodes[$node]->a;
        $this->sym1 = $this->sym0;
        $this->symId1 = $this->symId0;
        $this->sym0 = $text;
        $this->symId0 = $sym;
        return $sym;
    }

    private function fieldName(IonDoc $doc, LocalTab $tab): int
    {
        $this->skipWs();
        if ($this->i >= $this->n) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $c = ord($this->raw[$this->i]);
        $long = $c === 39 && $this->i + 2 < $this->n
            && ord($this->raw[$this->i + 1]) === 39 && ord($this->raw[$this->i + 2]) === 39;
        if ($c === 34 || $long) {
            $node = $this->string($doc, $long);
            return $doc->nodes[$doc->add_symbol_text($doc->text_at($node))]->a;
        }
        if ($c === 39) {
            return $this->parseSymbol($doc, $tab, true);
        }
        if (self::ident($c) && !($c >= 48 && $c <= 57)) {
            return $this->parseSymbol($doc, $tab, false);
        }
        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
    }

    private function parseSymbol(IonDoc $doc, LocalTab $tab, bool $quoted): int
    {
        if ($quoted) {
            $text = $this->quoted(39, false);
            $node = $doc->add_symbol_text($text);
            return $doc->nodes[$node]->a;
        }
        $text = $this->identText();
        if ($text === '' || $text[0] !== '$') {
            if ($text === $this->sym0) {
                return $this->symId0;
            }
            if ($text === $this->sym1) {
                return $this->symId1;
            }
        }
        if ($text !== '' && $text[0] === '$' && strlen($text) > 1 && ctype_digit(substr($text, 1))) {
            $sid = 0;
            for ($k = 1, $n = strlen($text); $k < $n; $k++) {
                $sid = $sid * 10 + ord($text[$k]) - 48;
                if ($sid > 2000000000) {
                    throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
                }
            }
            $node = Lst::symFromSid($doc, $tab, $sid, $this->i);
            return $doc->nodes[$node]->a;
        }
        if ($text === 'null' || $text === 'true' || $text === 'false' || $text === 'nan') {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $node = $doc->add_symbol_text($text);
        $sym = $doc->nodes[$node]->a;
        $this->sym1 = $this->sym0;
        $this->symId1 = $this->symId0;
        $this->sym0 = $text;
        $this->symId0 = $sym;
        return $sym;
    }

    private function identText(): string
    {
        if ($this->i >= $this->n) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $c = ord($this->raw[$this->i]);
        if (!self::ident($c) || ($c >= 48 && $c <= 57)) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $start = $this->i;
        $this->i++;
        while ($this->i < $this->n && self::ident(ord($this->raw[$this->i]))) {
            $this->i++;
        }
        return substr($this->raw, $start, $this->i - $start);
    }

    private function operator(): string
    {
        $buf = '';
        $n = $this->n;
        while ($this->i < $n && self::op(ord($this->raw[$this->i]))) {
            if (ord($this->raw[$this->i]) === 47 && $this->i + 1 < $n) {
                $next = ord($this->raw[$this->i + 1]);
                if ($next === 47 || $next === 42) {
                    break;
                }
            }
            $buf .= $this->raw[$this->i];
            $this->i++;
        }
        if ($buf === '') {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        return $buf;
    }

    private function keywordOrSymbol(IonDoc $doc, LocalTab $tab): int
    {
        $text = $this->identText();
        if ($text === 'null') {
            return $this->nullValue($doc);
        }
        if ($text === 'true') {
            return $doc->add_bool(true);
        }
        if ($text === 'false') {
            return $doc->add_bool(false);
        }
        if ($text === 'nan') {
            return $doc->add_float(8, "\x7F\xF8\x00\x00\x00\x00\x00\x00");
        }
        if ($text !== '' && $text[0] === '$' && strlen($text) > 1 && ctype_digit(substr($text, 1))) {
            $sid = 0;
            for ($k = 1, $n = strlen($text); $k < $n; $k++) {
                $sid = $sid * 10 + ord($text[$k]) - 48;
            }
            return Lst::symFromSid($doc, $tab, $sid, $this->i);
        }
        return $doc->add_symbol_text($text);
    }

    private function nullValue(IonDoc $doc): int
    {
        if ($this->i >= $this->n || ord($this->raw[$this->i]) !== 46) {
            return $doc->add_null(0);
        }
        $this->i++;
        $name = $this->identText();
        $map = [
            'null' => 0,
            'bool' => Kind::BOOL,
            'int' => Kind::INT,
            'float' => Kind::FLOAT,
            'decimal' => Kind::DECIMAL,
            'timestamp' => Kind::TIMESTAMP,
            'string' => Kind::STRING,
            'symbol' => Kind::SYMBOL,
            'blob' => Kind::BLOB,
            'clob' => Kind::CLOB,
            'list' => Kind::LIST,
            'sexp' => Kind::SEXP,
            'struct' => Kind::STRUCT,
        ];
        if (!isset($map[$name])) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        return $doc->add_null($map[$name]);
    }

    /** Plain positive ints and unescaped strings. Returns -2 when the value is not that simple. */
    private function inlineScalar(IonDoc $doc): int
    {
        $i = $this->i;
        $n = $this->n;
        $raw = $this->raw;
        if ($i >= $n) {
            return -2;
        }
        $c = ord($raw[$i]);
        if ($c >= 49 && $c <= 57) {
            $acc = 0;
            $j = $i;
            while ($j < $n) {
                $d = ord($raw[$j]);
                if ($d < 48 || $d > 57) {
                    break;
                }
                $digit = $d - 48;
                if ($acc > 922337203685477580 || ($acc === 922337203685477580 && $digit > 7)) {
                    return -2;
                }
                $acc = $acc * 10 + $digit;
                $j++;
            }
            if ($j < $n) {
                $nx = ord($raw[$j]);
                if ($nx !== 44 && $nx !== 125 && $nx !== 93 && $nx !== 41 && $nx !== 10 && $nx !== 32 && $nx !== 9 && $nx !== 13) {
                    return -2;
                }
            }
            $this->i = $j;
            return $doc->add_i64($acc);
        }
        if ($c === 48) {
            $nx = $i + 1 < $n ? ord($raw[$i + 1]) : 44;
            if ($nx === 44 || $nx === 125 || $nx === 93 || $nx === 41 || $nx === 10 || $nx === 32 || $nx === 9 || $nx === 13) {
                $this->i = $i + 1;
                return $doc->add_i64(0);
            }
            return -2;
        }
        if ($c === 34) {
            $j = $i + 1;
            while ($j < $n) {
                $d = ord($raw[$j]);
                if ($d === 34) {
                    $len = $j - $i - 1;
                    if (strlen($this->plain) === $len && substr_compare($raw, $this->plain, $i + 1, $len) === 0) {
                        $text = $this->plain;
                    } else {
                        $text = substr($raw, $i + 1, $len);
                        $this->plain = $text;
                    }
                    $this->i = $j + 1;
                    return $doc->add_string($text);
                }
                if ($d === 92 || $d < 32) {
                    return -2;
                }
                $j++;
            }
        }
        return -2;
    }

    private function quickValue(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $i = $this->i;
        $n = $this->n;
        $raw = $this->raw;
        while ($i < $n) {
            $c = ord($raw[$i]);
            if ($c === 32 || $c === 10 || $c === 9 || $c === 13 || $c === 11 || $c === 12) {
                $i++;
                continue;
            }
            if ($c === 47 && $i + 1 < $n) {
                $nx = ord($raw[$i + 1]);
                if ($nx === 47 || $nx === 42) {
                    $this->i = $i;
                    $this->skipWs();
                    return $this->parseValue($doc, $tab, $cat);
                }
            }
            break;
        }
        $this->i = $i;
        if ($i >= $n) {
            throw new DecodeError(DecodeError::KIND_EOF, $i);
        }
        $c = ord($raw[$i]);
        if ($c >= 49 && $c <= 57) {
            $id = $this->fastI64($doc, false);
            if ($id >= 0) {
                return $id;
            }
            return $this->number($doc);
        }
        if ($c === 34) {
            $j = $i + 1;
            while ($j < $n) {
                $d = ord($raw[$j]);
                if ($d === 34) {
                    $len = $j - $i - 1;
                    if (strlen($this->plain) === $len && substr_compare($raw, $this->plain, $i + 1, $len) === 0) {
                        $text = $this->plain;
                    } else {
                        $text = substr($raw, $i + 1, $len);
                        $this->plain = $text;
                    }
                    $this->i = $j + 1;
                    return $doc->add_string($text);
                }
                if ($d === 92 || $d < 32) {
                    return $this->string($doc, false);
                }
                $j++;
            }
            return $this->string($doc, false);
        }
        if ($c === 48 || $c === 45 || $c === 43) {
            return $this->number($doc);
        }
        return $this->parseValue($doc, $tab, $cat);
    }

    private function string(IonDoc $doc, bool $long): int
    {
        if (!$long) {
            $end = $this->plainQuote(34);
            if ($end >= 0) {
                $text = substr($this->raw, $this->i + 1, $end - $this->i - 1);
                $this->i = $end + 1;
                return $doc->add_string($text);
            }
        }
        $buf = '';
        if ($long) {
            $this->readLong($buf, false);
            while (true) {
                $save = $this->i;
                $this->skipWs();
                if ($this->startsLong()) {
                    $this->readLong($buf, false);
                } else {
                    $this->i = $save;
                    break;
                }
            }
        } else {
            $this->readShort($buf, 34, false);
        }
        return $doc->add_string($buf);
    }

    private function quoted(int $end, bool $long): string
    {
        $buf = '';
        if ($long) {
            $this->readLong($buf, false);
        } else {
            $this->readShort($buf, $end, false);
        }
        return $buf;
    }

    private function plainQuote(int $end): int
    {
        if ($this->i >= $this->n || ord($this->raw[$this->i]) !== $end) {
            return -1;
        }
        $j = $this->i + 1;
        $raw = $this->raw;
        $n = $this->n;
        while ($j < $n) {
            $c = ord($raw[$j]);
            if ($c === $end) {
                return $j;
            }
            if ($c === 92 || $c < 32) {
                return -1;
            }
            $j++;
        }
        return -1;
    }

    private function startsLong(): bool
    {
        return $this->i + 2 < $this->n
            && ord($this->raw[$this->i]) === 39
            && ord($this->raw[$this->i + 1]) === 39
            && ord($this->raw[$this->i + 2]) === 39;
    }

    private function readShort(string &$buf, int $end, bool $clob): void
    {
        $this->expect($end);
        $n = $this->n;
        while ($this->i < $n) {
            $c = ord($this->raw[$this->i]);
            if ($c === $end) {
                $this->i++;
                return;
            }
            if ($c < 32 && $c !== 9 && $c !== 11 && $c !== 12) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            if ($c === 92) {
                $this->escape($buf, $clob);
                continue;
            }
            if ($clob && $c >= 128) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            $buf .= chr($c);
            $this->i++;
        }
        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
    }

    private function readLong(string &$buf, bool $clob): void
    {
        if (!$this->startsLong()) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->i += 3;
        $n = $this->n;
        while ($this->i < $n) {
            if ($this->startsLong()) {
                $this->i += 3;
                return;
            }
            $c = ord($this->raw[$this->i]);
            if ($c < 32 && $c !== 9 && $c !== 10 && $c !== 11 && $c !== 12 && $c !== 13) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            if ($c === 92) {
                $this->escape($buf, $clob);
                continue;
            }
            if ($c === 13) {
                $this->i++;
                if ($this->i < $this->n && ord($this->raw[$this->i]) === 10) {
                    $this->i++;
                }
                $buf .= "\n";
                continue;
            }
            if ($clob && $c >= 128) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            $buf .= chr($c);
            $this->i++;
        }
        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
    }

    private function escape(string &$buf, bool $clob): void
    {
        $this->i++;
        if ($this->i >= $this->n) {
            throw new DecodeError(DecodeError::KIND_ESCAPE, $this->i);
        }
        $c = ord($this->raw[$this->i]);
        $this->i++;
        $simple = [
            48 => 0, 97 => 7, 98 => 8, 116 => 9, 110 => 10, 118 => 11, 102 => 12, 114 => 13,
        ];
        if (isset($simple[$c])) {
            $buf .= chr($simple[$c]);
            return;
        }
        if ($c === 34 || $c === 39 || $c === 47 || $c === 63 || $c === 92) {
            $buf .= chr($c);
            return;
        }
        if ($c === 10) {
            return;
        }
        if ($c === 13) {
            if ($this->i < $this->n && ord($this->raw[$this->i]) === 10) {
                $this->i++;
            }
            return;
        }
        if ($c === 120) {
            Utf8::append($buf, $this->hexCp(2), $this->i);
            return;
        }
        if ($c === 117 || $c === 85) {
            if ($clob) {
                throw new DecodeError(DecodeError::KIND_ESCAPE, $this->i);
            }
            if ($c === 85) {
                Utf8::append($buf, $this->hexCp(8), $this->i);
                return;
            }
            $cp = $this->hexCp(4);
            if ($cp >= 0xD800 && $cp <= 0xDBFF) {
                if ($this->i + 1 < $this->n && ord($this->raw[$this->i]) === 92 && ord($this->raw[$this->i + 1]) === 117) {
                    $this->i += 2;
                    $low = $this->hexCp(4);
                    if ($low < 0xDC00 || $low > 0xDFFF) {
                        throw new DecodeError(DecodeError::KIND_ESCAPE, $this->i);
                    }
                    $cp = 0x10000 + (($cp - 0xD800) << 10) + ($low - 0xDC00);
                } else {
                    throw new DecodeError(DecodeError::KIND_ESCAPE, $this->i);
                }
            }
            Utf8::append($buf, $cp, $this->i);
            return;
        }
        throw new DecodeError(DecodeError::KIND_ESCAPE, $this->i);
    }

    private function hexCp(int $n): int
    {
        if ($this->i + $n > $this->n) {
            throw new DecodeError(DecodeError::KIND_ESCAPE, $this->i);
        }
        $cp = 0;
        for ($k = 0; $k < $n; $k++) {
            $h = self::hex(ord($this->raw[$this->i + $k]));
            if ($h < 0) {
                throw new DecodeError(DecodeError::KIND_ESCAPE, $this->i);
            }
            $cp = ($cp << 4) | $h;
        }
        $this->i += $n;
        return $cp;
    }

    private function lob(IonDoc $doc): int
    {
        $this->expect(123);
        $this->expect(123);
        $j = $this->i;
        $n = $this->n;
        while ($j < $n && self::ws(ord($this->raw[$j]))) {
            $j++;
        }
        if ($j < $n && (ord($this->raw[$j]) === 34 || ord($this->raw[$j]) === 39)) {
            return $this->clob($doc);
        }
        $chars = '';
        while ($this->i < $this->n) {
            $c = ord($this->raw[$this->i]);
            if ($c === 125 && $this->i + 1 < $this->n && ord($this->raw[$this->i + 1]) === 125) {
                $this->i += 2;
                return $doc->add_bytes(Kind::BLOB, self::b64($chars, $this->i));
            }
            if (self::ws($c)) {
                $this->i++;
                continue;
            }
            if (self::b64Char($c) < 0 && $c !== 61) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            $chars .= chr($c);
            $this->i++;
        }
        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
    }

    private function clob(IonDoc $doc): int
    {
        while ($this->i < $this->n && self::ws(ord($this->raw[$this->i]))) {
            $this->i++;
        }
        $buf = '';
        $long = $this->startsLong();
        if ($long) {
            $this->readLong($buf, true);
        } elseif ($this->i < $this->n && ord($this->raw[$this->i]) === 34) {
            $this->readShort($buf, 34, true);
        } else {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($long) {
            while (true) {
                $save = $this->i;
                while ($this->i < $this->n && self::ws(ord($this->raw[$this->i]))) {
                    $this->i++;
                }
                if ($this->startsLong()) {
                    $this->readLong($buf, true);
                } else {
                    $this->i = $save;
                    break;
                }
            }
        }
        while ($this->i < $this->n && self::ws(ord($this->raw[$this->i]))) {
            $this->i++;
        }
        if ($this->i + 1 >= $this->n || ord($this->raw[$this->i]) !== 125 || ord($this->raw[$this->i + 1]) !== 125) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->i += 2;
        return $doc->add_bytes(Kind::CLOB, $buf);
    }

    private function number(IonDoc $doc): int
    {
        $neg = false;
        $c = ord($this->raw[$this->i]);
        if ($c === 43) {
            $save = $this->i;
            $this->i++;
            if ($this->wordAt('inf')) {
                $this->needStop();
                return $doc->add_float(8, "\x7F\xF0\x00\x00\x00\x00\x00\x00");
            }
            if ($this->sexp > 0) {
                $this->i = $save;
                return $doc->add_symbol_text($this->operator());
            }
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($c === 45) {
            $save = $this->i;
            $this->i++;
            if ($this->wordAt('inf')) {
                $this->needStop();
                return $doc->add_float(8, "\xFF\xF0\x00\x00\x00\x00\x00\x00");
            }
            if ($this->i >= $this->n || ord($this->raw[$this->i]) < 48 || ord($this->raw[$this->i]) > 57) {
                if ($this->sexp > 0) {
                    $this->i = $save;
                    return $doc->add_symbol_text($this->operator());
                }
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            $neg = true;
        }
        if ($this->i < $this->n && ord($this->raw[$this->i]) === 48 && $this->i + 1 < $this->n) {
            $nx = ord($this->raw[$this->i + 1]);
            if ($nx === 120 || $nx === 88 || $nx === 98 || $nx === 66) {
                return $this->radix($doc, $neg, $nx === 98 || $nx === 66);
            }
        }
        $fast = $this->fastI64($doc, $neg);
        if ($fast >= 0) {
            return $fast;
        }
        return $this->real($doc, $neg);
    }

    private function fastI64(IonDoc $doc, bool $neg): int
    {
        if ($this->i >= $this->n) {
            return -1;
        }
        $c0 = ord($this->raw[$this->i]);
        if ($c0 < 48 || $c0 > 57) {
            return -1;
        }
        if ($c0 === 48) {
            $nxt = $this->i + 1;
            if ($nxt < $this->n) {
                $nch = ord($this->raw[$nxt]);
                if (!self::stop($nch) && !self::ws($nch)) {
                    return -1;
                }
            }
            $this->i = $nxt;
            return $doc->add_i64(0);
        }
        $acc = 0;
        $j = $this->i;
        $digits = 0;
        $n = $this->n;
        while ($j < $n) {
            $c = ord($this->raw[$j]);
            if ($c < 48 || $c > 57) {
                break;
            }
            $digit = $c - 48;
            if ($acc > 922337203685477580 || ($acc === 922337203685477580 && $digit > 7)) {
                return -1;
            }
            $acc = $acc * 10 + $digit;
            $digits++;
            $j++;
        }
        if ($digits === 0) {
            return -1;
        }
        if ($j < $n) {
            $nch = ord($this->raw[$j]);
            if (!self::stop($nch) && !self::ws($nch)) {
                return -1;
            }
        }
        if ($neg && $acc > 9223372036854775807) {
            return -1;
        }
        $this->i = $j;
        return $doc->add_i64($neg ? -$acc : $acc);
    }

    private function wordAt(string $word): bool
    {
        $n = strlen($word);
        if ($this->i + $n > $this->n) {
            return false;
        }
        if (substr($this->raw, $this->i, $n) !== $word) {
            return false;
        }
        $end = $this->i + $n;
        if ($end < $this->n && self::ident(ord($this->raw[$end]))) {
            return false;
        }
        $this->i = $end;
        return true;
    }

    private function needStop(): void
    {
        if ($this->i < $this->n && !self::stop(ord($this->raw[$this->i]))) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
    }

    private function radix(IonDoc $doc, bool $neg, bool $binary): int
    {
        $this->i += 2;
        $base = $binary ? 2 : 16;
        $acc = new BigInt(false);
        $any = false;
        $prevUs = false;
        while ($this->i < $this->n) {
            $c = ord($this->raw[$this->i]);
            if ($c === 95) {
                if (!$any || $prevUs) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
                $prevUs = true;
                $this->i++;
                continue;
            }
            $d = self::hex($c);
            if ($binary) {
                $d = ($c === 48 || $c === 49) ? $c - 48 : -1;
            }
            if ($d < 0 || (!$binary && $d > 15)) {
                break;
            }
            $any = true;
            $prevUs = false;
            $acc->mulSmall($base);
            $acc->addSmall($d);
            $this->i++;
        }
        if (!$any || $prevUs) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($neg && !$acc->isZero()) {
            $acc->neg = true;
        }
        $this->needStop();
        return $doc->add_int($acc);
    }

    private function real(IonDoc $doc, bool $neg): int
    {
        $whole = '';
        $under = false;
        $nwhole = $this->digits($whole, $under);
        if ($nwhole === 0) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if ($this->i < $this->n && (ord($this->raw[$this->i]) === 45 || ord($this->raw[$this->i]) === 84)) {
            if (!$neg && !$under && $nwhole >= 4) {
                return $this->timestamp($doc, $whole);
            }
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        if (strlen($whole) > 1 && $whole[0] === '0') {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $frac = '';
        $sawDot = false;
        if ($this->i < $this->n && ord($this->raw[$this->i]) === 46) {
            $sawDot = true;
            $this->i++;
            if ($this->i < $this->n && ord($this->raw[$this->i]) === 95) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            if ($this->i < $this->n) {
                $c = ord($this->raw[$this->i]);
                if (($c >= 48 && $c <= 57) || $c === 95) {
                    $fracUnder = false;
                    $this->digits($frac, $fracUnder);
                }
            }
        }
        $exp = 0;
        $isFloat = false;
        $isDec = $sawDot;
        if ($this->i < $this->n) {
            $c = ord($this->raw[$this->i]);
            if ($c === 101 || $c === 69 || $c === 100 || $c === 68) {
                $isFloat = $c === 101 || $c === 69;
                $isDec = !$isFloat;
                $this->i++;
                $exp = $this->exponent();
            }
        }
        if (!$isDec && !$isFloat) {
            $coef = BigInt::fromDigits($whole, false);
            if ($neg && !$coef->isZero()) {
                $coef->neg = true;
            }
            $this->needStop();
            return $doc->add_int($coef);
        }
        if ($isFloat) {
            $this->needStop();
            return $this->floatNode($doc, $neg, $whole, $frac, $exp);
        }
        $coef = BigInt::fromDigits($whole . $frac, false);
        $this->needStop();
        return $doc->add_decimal($coef, $neg, $exp - strlen($frac));
    }

    private function digits(string &$out, bool &$under): int
    {
        $n = 0;
        $prev = false;
        $under = false;
        while ($this->i < $this->n) {
            $c = ord($this->raw[$this->i]);
            if ($c >= 48 && $c <= 57) {
                $out .= chr($c);
                $n++;
                $prev = false;
                $this->i++;
                continue;
            }
            if ($c === 95) {
                if ($n === 0 || $prev) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
                $prev = true;
                $under = true;
                $this->i++;
                continue;
            }
            break;
        }
        if ($prev) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        return $n;
    }

    private function exponent(): int
    {
        $sign = 1;
        if ($this->i < $this->n && (ord($this->raw[$this->i]) === 43 || ord($this->raw[$this->i]) === 45)) {
            if (ord($this->raw[$this->i]) === 45) {
                $sign = -1;
            }
            $this->i++;
        }
        $digits = '';
        $under = false;
        $n = $this->digits($digits, $under);
        if ($n === 0) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $v = 0;
        $len = strlen($digits);
        for ($k = 0; $k < $len; $k++) {
            $digit = ord($digits[$k]) - 48;
            if ($v > 922337203685477580 || ($v === 922337203685477580 && $digit > 7)) {
                throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
            }
            $v = $v * 10 + $digit;
        }
        return $sign * $v;
    }

    private function floatNode(IonDoc $doc, bool $neg, string $whole, string $frac, int $exp): int
    {
        $zero = true;
        $len = strlen($whole);
        for ($k = 0; $k < $len; $k++) {
            if ($whole[$k] !== '0') {
                $zero = false;
            }
        }
        $len = strlen($frac);
        for ($k = 0; $k < $len; $k++) {
            if ($frac[$k] !== '0') {
                $zero = false;
            }
        }
        if ($zero && $neg) {
            return $doc->add_float(8, "\x80\x00\x00\x00\x00\x00\x00\x00");
        }
        if ($zero) {
            return $doc->add_float(0, '');
        }
        $token = ($neg ? '-' : '') . $whole . '.' . $frac . 'e' . $exp;
        $f = (float) $token;
        if (is_nan($f)) {
            throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
        }
        if (is_infinite($f)) {
            $negInf = $neg || $f < 0;
            return $doc->add_float(8, $negInf ? "\xFF\xF0\x00\x00\x00\x00\x00\x00" : "\x7F\xF0\x00\x00\x00\x00\x00\x00");
        }
        return $doc->add_float(8, pack('E', $f));
    }

    private function timestamp(IonDoc $doc, string $digits): int
    {
        if (strlen($digits) !== 4) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $year = (int) $digits;
        if ($year < 1) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $prec = Kind::PREC_YEAR;
        $month = 1;
        $day = 1;
        $hour = 0;
        $minute = 0;
        $second = 0;
        $frac = '';
        $unknown = true;
        $off = 0;
        if ($this->i < $this->n && ord($this->raw[$this->i]) === 45) {
            $this->i++;
            $month = $this->two();
            $prec = Kind::PREC_MONTH;
            if ($month < 1 || $month > 12) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            if ($this->i < $this->n && ord($this->raw[$this->i]) === 45) {
                $this->i++;
                $day = $this->two();
                $prec = Kind::PREC_DAY;
                if ($day < 1 || $day > Time::days($year, $month)) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
            }
        }
        if ($this->i < $this->n && ord($this->raw[$this->i]) === 84) {
            $this->i++;
            if ($this->i < $this->n && ord($this->raw[$this->i]) >= 48 && ord($this->raw[$this->i]) <= 57) {
                if ($prec !== Kind::PREC_DAY) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
                $hour = $this->two();
                $this->expect(58);
                $minute = $this->two();
                $prec = Kind::PREC_MIN;
                if ($hour > 23 || $minute > 59) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
                if ($this->i < $this->n && ord($this->raw[$this->i]) === 58) {
                    $this->i++;
                    $second = $this->two();
                    $prec = Kind::PREC_SEC;
                    if ($second > 59) {
                        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                    }
                    if ($this->i < $this->n && ord($this->raw[$this->i]) === 46) {
                        $this->i++;
                        $u = false;
                        $nfrac = $this->digits($frac, $u);
                        if ($nfrac === 0) {
                            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                        }
                        $prec = Kind::PREC_FRAC;
                    }
                }
                if ($this->i >= $this->n) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
                $c = ord($this->raw[$this->i]);
                if ($c === 90) {
                    $this->i++;
                    $unknown = false;
                    $off = 0;
                } elseif ($c === 43 || $c === 45) {
                    $sign = $c === 45 ? -1 : 1;
                    $this->i++;
                    $oh = $this->two();
                    $this->expect(58);
                    $om = $this->two();
                    if ($oh > 23 || $om > 59) {
                        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                    }
                    if ($sign < 0 && $oh === 0 && $om === 0) {
                        $unknown = true;
                        $off = 0;
                    } else {
                        $unknown = false;
                        $off = $sign * ($oh * 60 + $om);
                    }
                } else {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
            } elseif ($prec !== Kind::PREC_YEAR && $prec !== Kind::PREC_MONTH && $prec !== Kind::PREC_DAY) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
        } elseif ($prec !== Kind::PREC_DAY) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->needStop();
        $when = new IonTime();
        $when->prec = $prec;
        $when->year = $year;
        $when->month = $month;
        $when->day = $day;
        $when->hour = $hour;
        $when->minute = $minute;
        $when->second = $second;
        $when->unknown = $unknown;
        $when->off = $off;
        if ($prec === Kind::PREC_FRAC) {
            $when->fracExp = -strlen($frac);
            $coef = BigInt::fromDigits($frac, false);
            $when->fracAt = count($doc->limbs);
            $when->fracLen = count($coef->limbs);
            foreach ($coef->limbs as $limb) {
                $doc->limbs[] = $limb;
            }
        }
        return $doc->add_time($when);
    }

    private function two(): int
    {
        if ($this->i + 1 >= $this->n) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $a = ord($this->raw[$this->i]);
        $b = ord($this->raw[$this->i + 1]);
        if ($a < 48 || $a > 57 || $b < 48 || $b > 57) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->i += 2;
        return ($a - 48) * 10 + ($b - 48);
    }

    private function clearMacros(): void
    {
        $this->macroName = [];
        $this->macroRoot = [];
        $this->macroParamAt = [];
        $this->macroNParam = [];
        $this->paramKind = [];
        $this->paramDefault = [];
    }

    private function colon(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $this->i += 2;
        $this->skipWs();
        if ($this->i < $this->n && ord($this->raw[$this->i]) === 63) {
            return $this->placeholder($doc, $tab, $cat);
        }
        if ($this->i < $this->n && ord($this->raw[$this->i]) === 41) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $name = $this->identText();
        if ($name === '$ion') {
            if ($this->depth !== 0) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
            }
            return $this->directive11($doc, $tab, $cat);
        }
        if ($this->templ) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        return $this->invoke($doc, $tab, $cat, $name);
    }

    private function placeholder(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        if (!$this->templ) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->i++;
        $this->skipWs();
        $defaultId = -1;
        if ($this->i < $this->n && ord($this->raw[$this->i]) !== 41) {
            if (ord($this->raw[$this->i]) === 123 && $this->i + 1 < $this->n && ord($this->raw[$this->i + 1]) === 35) {
                $this->skipGroup(123, 125);
            } else {
                $saved = $this->templ;
                $this->templ = false;
                $defaultId = $this->parseValue($doc, $tab, $cat);
                $this->templ = $saved;
                if ($defaultId < 0) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
            }
        }
        $this->skipWs();
        $this->expect(41);
        $rel = count($this->paramKind) - $this->curParam0;
        $this->paramKind[] = 0;
        $this->paramDefault[] = $defaultId;
        $hole = new IonNode(Kind::HOLE);
        $hole->a = $rel;
        $hole->b = $defaultId;
        return $doc->add($hole);
    }

    private function skipGroup(int $open, int $close): void
    {
        if ($this->i >= $this->n || ord($this->raw[$this->i]) !== $open) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $depth = 0;
        while ($this->i < $this->n) {
            $c = ord($this->raw[$this->i]);
            if ($c === 34 || $c === 39) {
                $long = $this->startsLong();
                $this->quoted($c, $long);
                continue;
            }
            $this->i++;
            if ($c === $open) {
                $depth++;
            } elseif ($c === $close) {
                $depth--;
                if ($depth === 0) {
                    return;
                }
            }
        }
        throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
    }

    private function directive11(IonDoc $doc, LocalTab $tab, Catalog $cat): int
    {
        $this->skipWs();
        $op = $this->identText();
        if ($op === 'set_symbols') {
            $tab->reset_system();
            $this->addSymbolTexts($doc, $tab);
        } elseif ($op === 'add_symbols') {
            $this->addSymbolTexts($doc, $tab);
        } elseif ($op === 'set_macros' || $op === 'add_macros') {
            if ($op === 'set_macros') {
                $this->clearMacros();
            }
            $saved = $this->templ;
            $this->templ = true;
            while (true) {
                $this->skipWs();
                if ($this->i < $this->n && ord($this->raw[$this->i]) === 41) {
                    break;
                }
                $param0 = count($this->paramKind);
                $this->curParam0 = $param0;
                $node = $this->sexp($doc, $tab, $cat);
                $this->defineMacro($doc, $node, $param0);
            }
            $this->templ = $saved;
        } elseif ($op === 'use') {
            $this->useModule($doc, $tab, $cat);
        } elseif ($op === 'module' || $op === 'import' || $op === 'encoding') {
            $this->skipDirectiveRest();
        } else {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->skipWs();
        $this->expect(41);
        return -1;
    }

    private function addSymbolTexts(IonDoc $doc, LocalTab $tab): void
    {
        while (true) {
            $this->skipWs();
            if ($this->i < $this->n && ord($this->raw[$this->i]) === 41) {
                return;
            }
            $tab->add_text($this->symbolText($doc));
        }
    }

    private function symbolText(IonDoc $doc): string
    {
        $this->skipWs();
        if ($this->i >= $this->n) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $c = ord($this->raw[$this->i]);
        $long = $this->startsLong();
        if ($c === 34 || $long) {
            $node = $this->string($doc, $long);
            return $doc->text_at($node);
        }
        if ($c === 39) {
            return $this->quoted(39, false);
        }
        return $this->identText();
    }

    private function useModule(IonDoc $doc, LocalTab $tab, Catalog $cat): void
    {
        $this->skipWs();
        $nameNode = $this->string($doc, $this->startsLong());
        $name = $doc->text_at($nameNode);
        $version = 1;
        $this->skipWs();
        if ($this->i < $this->n && ord($this->raw[$this->i]) >= 48 && ord($this->raw[$this->i]) <= 57) {
            $num = $this->parseValue($doc, $tab, $cat);
            $n = $doc->nodes[$num];
            if ($n->kind !== Kind::INT || $n->c !== 0 || $n->b > 1) {
                throw new DecodeError(DecodeError::KIND_RANGE, $this->i);
            }
            $version = $n->b === 0 ? 0 : $doc->limbs[$n->a];
        }
        $idx = $cat->exact($name, $version);
        if ($idx < 0) {
            throw new DecodeError(DecodeError::KIND_SYMBOL, $this->i);
        }
        $table = $cat->tables[$idx];
        foreach ($table->texts as $k => $text) {
            if ($table->gap[$k]) {
                $tab->add_gap();
            } else {
                $tab->add_text($text);
            }
        }
    }

    private function skipDirectiveRest(): void
    {
        $depth = 1;
        while ($this->i < $this->n && $depth > 0) {
            $c = ord($this->raw[$this->i]);
            if ($c === 34 || $c === 39) {
                $long = $this->startsLong();
                $this->quoted($c, $long);
                continue;
            }
            $this->i++;
            if ($c === 40) {
                $depth++;
            } elseif ($c === 41) {
                $depth--;
            }
        }
        if ($depth !== 0) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->i--;
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

    private function invoke(IonDoc $doc, LocalTab $tab, Catalog $cat, string $name): int
    {
        $addr = $this->macroAddr($name);
        $nparam = $this->macroNParam[$addr];
        $args = [];
        for ($p = 0; $p < $nparam; $p++) {
            $this->skipWs();
            if ($this->atAbsent()) {
                $this->eatAbsent();
                $args[] = -1;
            } else {
                $arg = $this->parseValue($doc, $tab, $cat);
                if ($arg < 0) {
                    throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
                }
                $args[] = $arg;
            }
        }
        $this->skipWs();
        $this->expect(41);
        return $this->expand($doc, $this->macroRoot[$addr], $args);
    }

    private function macroAddr(string $name): int
    {
        if ($name !== '' && $name[0] === '$' && strlen($name) > 1 && ctype_digit(substr($name, 1))) {
            $addr = (int) substr($name, 1);
            if ($addr >= count($this->macroRoot)) {
                throw new DecodeError(DecodeError::KIND_SYMBOL, $this->i);
            }
            return $addr;
        }
        foreach ($this->macroName as $i => $nm) {
            if ($nm === $name) {
                return $i;
            }
        }
        throw new DecodeError(DecodeError::KIND_SYMBOL, $this->i);
    }

    private function atAbsent(): bool
    {
        if ($this->i + 2 >= $this->n || ord($this->raw[$this->i]) !== 40 || ord($this->raw[$this->i + 1]) !== 58) {
            return false;
        }
        $k = $this->i + 2;
        while ($k < $this->n && self::ws(ord($this->raw[$k]))) {
            $k++;
        }
        return $k < $this->n && ord($this->raw[$k]) === 41;
    }

    private function eatAbsent(): void
    {
        $this->i += 2;
        $this->skipWs();
        $this->expect(41);
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
            if ($n->annN === 0) {
                return $out;
            }
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

    private function skipWs(): void
    {
        $i = $this->i;
        $n = $this->n;
        $raw = $this->raw;
        while ($i < $n) {
            $c = ord($raw[$i]);
            if ($c === 32 || $c === 9 || $c === 10 || $c === 13 || $c === 11 || $c === 12) {
                $i++;
                continue;
            }
            if ($c === 47 && $i + 1 < $n) {
                $next = ord($raw[$i + 1]);
                if ($next === 47) {
                    $i += 2;
                    while ($i < $n) {
                        $ch = ord($raw[$i]);
                        if ($ch === 10 || $ch === 13) {
                            break;
                        }
                        $i++;
                    }
                    continue;
                }
                if ($next === 42) {
                    $i += 2;
                    $ok = false;
                    while ($i + 1 < $n) {
                        if (ord($raw[$i]) === 42 && ord($raw[$i + 1]) === 47) {
                            $i += 2;
                            $ok = true;
                            break;
                        }
                        $i++;
                    }
                    if (!$ok) {
                        $this->i = $i;
                        throw new DecodeError(DecodeError::KIND_SYNTAX, $i);
                    }
                    continue;
                }
            }
            break;
        }
        $this->i = $i;
    }

    private function expect(int $c): void
    {
        if ($this->i >= $this->n || ord($this->raw[$this->i]) !== $c) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $this->i);
        }
        $this->i++;
    }

    private static function ident(int $c): bool
    {
        if ($c >= 48 && $c <= 57) {
            return true;
        }
        if (($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122)) {
            return true;
        }
        return $c === 95 || $c === 36;
    }

    private static function op(int $c): bool
    {
        return $c === 33 || $c === 35 || $c === 37 || $c === 38 || $c === 42 || $c === 43
            || $c === 45 || $c === 46 || $c === 47 || $c === 59 || $c === 60 || $c === 61 || $c === 62
            || $c === 63 || $c === 64 || $c === 94 || $c === 96 || $c === 124 || $c === 126;
    }

    private static function stop(int $c): bool
    {
        if ($c === 32 || $c === 9 || $c === 10 || $c === 13 || $c === 11 || $c === 12) {
            return true;
        }
        if ($c === 123 || $c === 125 || $c === 91 || $c === 93 || $c === 40 || $c === 41 || $c === 44) {
            return true;
        }
        return $c === 92 || $c === 34 || $c === 39;
    }

    private static function ws(int $c): bool
    {
        return $c === 32 || $c === 9 || $c === 10 || $c === 13 || $c === 11 || $c === 12;
    }

    private static function hex(int $c): int
    {
        if ($c >= 48 && $c <= 57) {
            return $c - 48;
        }
        if ($c >= 65 && $c <= 70) {
            return $c - 55;
        }
        if ($c >= 97 && $c <= 102) {
            return $c - 87;
        }
        return -1;
    }

    private static function b64Char(int $c): int
    {
        if ($c >= 65 && $c <= 90) {
            return $c - 65;
        }
        if ($c >= 97 && $c <= 122) {
            return $c - 71;
        }
        if ($c >= 48 && $c <= 57) {
            return $c + 4;
        }
        if ($c === 43) {
            return 62;
        }
        if ($c === 47) {
            return 63;
        }
        return -1;
    }

    private static function b64(string $chars, int $offset): string
    {
        $n = strlen($chars);
        if ($n === 0) {
            return '';
        }
        $pad = 0;
        for ($i = $n - 1; $i >= 0 && $chars[$i] === '='; $i--) {
            $pad++;
        }
        if ($pad > 2 || ($n % 4) !== 0) {
            throw new DecodeError(DecodeError::KIND_SYNTAX, $offset);
        }
        for ($i = 0; $i < $n - $pad; $i++) {
            if ($chars[$i] === '=' || self::b64Char(ord($chars[$i])) < 0) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $offset);
            }
        }
        $out = '';
        for ($k = 0; $k < $n; $k += 4) {
            $c0 = self::b64Char(ord($chars[$k]));
            $c1 = self::b64Char(ord($chars[$k + 1]));
            $c2 = $chars[$k + 2] === '=' ? 0 : self::b64Char(ord($chars[$k + 2]));
            $c3 = $chars[$k + 3] === '=' ? 0 : self::b64Char(ord($chars[$k + 3]));
            if ($c0 < 0 || $c1 < 0) {
                throw new DecodeError(DecodeError::KIND_SYNTAX, $offset);
            }
            $v = ($c0 << 18) | ($c1 << 12) | ($c2 << 6) | $c3;
            $out .= chr(($v >> 16) & 255);
            if ($chars[$k + 2] !== '=') {
                $out .= chr(($v >> 8) & 255);
            }
            if ($chars[$k + 3] !== '=') {
                $out .= chr($v & 255);
            }
        }
        return $out;
    }
}
