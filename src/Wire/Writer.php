<?php

declare(strict_types=1);

namespace Gld\Ion\Wire;

use Gld\Ion\BigInt;
use Gld\Ion\Catalog;
use Gld\Ion\DecodeError;
use Gld\Ion\EncodeOptions;
use Gld\Ion\FloatBits;
use Gld\Ion\IonDoc;
use Gld\Ion\IonTime;
use Gld\Ion\Kind;
use Gld\Ion\LocalTab;

final class SymMap
{
    /** @var list<string> */
    public array $texts = [];
    /** @var array<string, int> */
    private array $index = [];
    private int $base;
    private string $c0 = "\1";
    private string $c1 = "\1";
    private int $s0 = 0;
    private int $s1 = 0;

    public function __construct(int $base = 10)
    {
        $this->base = $base;
    }

    public function sid(string $text): int
    {
        if ($text === $this->c0) {
            return $this->s0;
        }
        if ($text === $this->c1) {
            return $this->s1;
        }
        $sys = LocalTab::sysSid($text);
        if ($sys !== 0) {
            $this->remember($text, $sys);
            return $sys;
        }
        $key = "k\0" . $text;
        if (isset($this->index[$key])) {
            $id = $this->index[$key];
            $this->remember($text, $id);
            return $id;
        }
        $id = $this->base + count($this->texts);
        $this->texts[] = $text;
        $this->index[$key] = $id;
        $this->remember($text, $id);
        return $id;
    }

    private function remember(string $text, int $id): void
    {
        $this->c1 = $this->c0;
        $this->s1 = $this->s0;
        $this->c0 = $text;
        $this->s0 = $id;
    }
}

final class Writer
{
    private static string $fp0 = "\1";
    private static string $fp1 = "\1";
    private static string $fpS = "\1";
    private static string $fpre = '';
    private static string $fsuf = '';
    private static int $bs0 = -1;
    private static int $bs1 = -1;
    private static string $bt = "\1";
    private static bool $bneg = false;
    private static string $bpre = '';
    private static string $bsuf = '';
    public static function encodeText(IonDoc $doc, EncodeOptions $options): string
    {
        $buf = self::ivmText($options->version);
        $top = $doc->top;
        $n = count($top);
        $i = self::appendFlatText($doc, $top, $n, $buf);
        for (; $i < $n; $i++) {
            $buf .= "\n";
            self::writeText($doc, $top[$i], $buf, $options, 0);
        }
        $buf .= "\n";
        return $buf;
    }

    public static function formatValue(IonDoc $doc, int $id): string
    {
        $buf = '';
        self::writeText($doc, $id, $buf, new EncodeOptions(), 0);
        return $buf;
    }

    public static function encodeBinary(IonDoc $doc, EncodeOptions $options, Catalog $cat): string
    {
        if ($options->version === 11) {
            $buf = "\xE0\x01\x01\xEA";
            Bin11::encodeValues($doc, $buf);
            return $buf;
        }
        $base = 10;
        foreach ($options->importNames as $i => $name) {
            $ver = $options->importVersions[$i];
            $idx = $cat->exact($name, $ver);
            if ($idx >= 0) {
                $base += count($cat->tables[$idx]->texts);
            }
        }
        $sm = new SymMap($base);
        $body = '';
        $top = $doc->top;
        $n = count($top);
        $i = self::appendFlatBin($doc, $sm, $top, $n, $body);
        for (; $i < $n; $i++) {
            self::binValue($doc, $top[$i], $sm, $body);
        }
        $buf = "\xE0\x01\x00\xEA";
        self::writeLst($sm, $options, $cat, $buf);
        return $buf . $body;
    }

    /**
     * Top-level `{ident: int, ident: "plain"}` values share one text template.
     * Stops at the first value that does not match, leaving it for the general writer.
     *
     * @param list<int> $top
     */
    private static function appendFlatText(IonDoc $doc, array $top, int $n, string &$buf): int
    {
        if ($n === 0) {
            return 0;
        }
        $nodes = $doc->nodes;
        $ec = $doc->edgeChild;
        $en = $doc->edgeNext;
        $ef = $doc->edgeField;
        $syms = $doc->syms;
        $texts = $doc->texts;
        $limbs = $doc->limbs;
        $pre = '';
        $suf = '';
        $ready = false;
        $field0 = -1;
        $field1 = -1;
        $textAt = -1;
        for ($i = 0; $i < $n; $i++) {
            $node = $nodes[$top[$i]];
            if ($node->kind !== Kind::STRUCT || $node->annN !== 0 || $node->nchild !== 2) {
                return $i;
            }
            $e0 = $node->child;
            $e1 = $en[$e0];
            $a = $nodes[$ec[$e0]];
            $b = $nodes[$ec[$e1]];
            if ($a->kind !== Kind::INT || $b->kind !== Kind::STRING || $a->b > 1 || $a->annN !== 0 || $b->annN !== 0) {
                return $i;
            }
            if ($ready) {
                if ($ef[$e0] !== $field0 || $ef[$e1] !== $field1 || $b->a !== $textAt) {
                    return $i;
                }
            } else {
                $sf0 = $syms[$ef[$e0]];
                $sf1 = $syms[$ef[$e1]];
                if ($sf0->text < 0 || $sf1->text < 0) {
                    return $i;
                }
                $tf0 = $texts[$sf0->text];
                $tf1 = $texts[$sf1->text];
                $ts = $texts[$b->a];
                if (!self::identOk($tf0) || !self::identOk($tf1)) {
                    return $i;
                }
                $len = strlen($ts);
                for ($k = 0; $k < $len; $k++) {
                    $c = ord($ts[$k]);
                    if ($c < 32 || $c === 34 || $c === 92) {
                        return $i;
                    }
                }
                $field0 = $ef[$e0];
                $field1 = $ef[$e1];
                $textAt = $b->a;
                $pre = "\n{" . $tf0 . ':';
                $suf = ',' . $tf1 . ':"' . $ts . '"}';
                $ready = true;
            }
            $mag = $a->b === 0 ? 0 : $limbs[$a->a];
            $buf .= $pre;
            if ($a->c !== 0 && $mag !== 0) {
                $buf .= '-';
            }
            $buf .= $mag;
            $buf .= $suf;
        }
        return $n;
    }

    /**
     * Top-level short binary structs with one small int and one short string.
     *
     * @param list<int> $top
     */
    private static function appendFlatBin(IonDoc $doc, SymMap $sm, array $top, int $n, string &$body): int
    {
        if ($n === 0) {
            return 0;
        }
        $nodes = $doc->nodes;
        $ec = $doc->edgeChild;
        $en = $doc->edgeNext;
        $ef = $doc->edgeField;
        $syms = $doc->syms;
        $texts = $doc->texts;
        $limbs = $doc->limbs;
        $ready = false;
        $field0 = -1;
        $field1 = -1;
        $textAt = -1;
        $nzPre = '';
        $negPre = '';
        $nzSuf = '';
        $zPkt = '';
        for ($i = 0; $i < $n; $i++) {
            $node = $nodes[$top[$i]];
            if ($node->kind !== Kind::STRUCT || $node->annN !== 0 || $node->nchild !== 2) {
                return $i;
            }
            $e0 = $node->child;
            $e1 = $en[$e0];
            $a = $nodes[$ec[$e0]];
            $b = $nodes[$ec[$e1]];
            if ($a->kind !== Kind::INT || $b->kind !== Kind::STRING || $a->b > 1 || $a->annN !== 0 || $b->annN !== 0) {
                return $i;
            }
            $mag = $a->b === 0 ? 0 : $limbs[$a->a];
            if ($mag > 255) {
                return $i;
            }
            $neg = $a->c !== 0 && $mag !== 0;
            if ($ready) {
                if ($ef[$e0] !== $field0 || $ef[$e1] !== $field1 || $b->a !== $textAt) {
                    return $i;
                }
            } else {
                $sf0 = $syms[$ef[$e0]];
                $sf1 = $syms[$ef[$e1]];
                if ($sf0->text < 0 || $sf1->text < 0) {
                    return $i;
                }
                $tf0 = $texts[$sf0->text];
                $tf1 = $texts[$sf1->text];
                $ts = $texts[$b->a];
                $ln = strlen($ts);
                if ($ln >= 14) {
                    return $i;
                }
                $sid0 = $sm->sid($tf0);
                $sid1 = $sm->sid($tf1);
                if ($sid0 > 127 || $sid1 > 127) {
                    return $i;
                }
                $tail = chr(0x80 | $sid1) . chr(0x80 | $ln) . $ts;
                $head = chr(0x80 | $sid0);
                $nzLen = 1 + 2 + 1 + 1 + $ln;
                if ($nzLen >= 14) {
                    return $i;
                }
                $field0 = $ef[$e0];
                $field1 = $ef[$e1];
                $textAt = $b->a;
                $nzPre = chr(0xD0 | $nzLen) . $head . "\x21";
                $negPre = chr(0xD0 | $nzLen) . $head . "\x31";
                $nzSuf = $tail;
                $zPkt = chr(0xD0 | (1 + 1 + 1 + 1 + $ln)) . $head . "\x20" . $tail;
                $ready = true;
            }
            if ($mag === 0) {
                $body .= $zPkt;
            } elseif ($neg) {
                $body .= $negPre . chr($mag) . $nzSuf;
            } else {
                $body .= $nzPre . chr($mag) . $nzSuf;
            }
        }
        return $n;
    }

    private static function ivmText(int $version): string
    {
        return $version === 11 ? '$ion_1_1' : '$ion_1_0';
    }

    private static function writeText(IonDoc $doc, int $id, string &$buf, EncodeOptions $options, int $depth): void
    {
        $n = $doc->nodes[$id];
        for ($a = 0; $a < $n->annN; $a++) {
            $s = $doc->syms[$doc->anns[$n->ann + $a]];
            if ($s->text < 0) {
                $buf .= '$0';
            } else {
                self::symText($buf, $doc->texts[$s->text]);
            }
            $buf .= '::';
        }
        $k = $n->kind;
        if ($k === Kind::NULL) {
            $buf .= 'null';
            if ($n->a !== 0) {
                $buf .= '.' . self::nullName($n->a);
            }
            return;
        }
        if ($k === Kind::BOOL) {
            $buf .= $n->a !== 0 ? 'true' : 'false';
            return;
        }
        if ($k === Kind::INT) {
            if ($n->b <= 1) {
                $mag = $n->b === 0 ? 0 : $doc->limbs[$n->a];
                if ($n->c !== 0 && $mag !== 0) {
                    $buf .= '-';
                }
                $buf .= (string) $mag;
                return;
            }
            $mag = $doc->limbs_of($n->a, $n->b);
            $mag->neg = $n->c !== 0 && !$mag->isZero();
            $buf .= $mag->toDec();
            return;
        }
        if ($k === Kind::FLOAT) {
            $buf .= FloatBits::format($n->a, $doc->floats[$n->b]);
            return;
        }
        if ($k === Kind::DECIMAL) {
            self::decText($doc, $id, $buf);
            return;
        }
        if ($k === Kind::TIMESTAMP) {
            self::timeText($doc, $id, $buf);
            return;
        }
        if ($k === Kind::STRING) {
            $text = $doc->texts[$n->a];
            $len = strlen($text);
            $plain = true;
            for ($i = 0; $i < $len; $i++) {
                $c = ord($text[$i]);
                if ($c < 32 || $c === 34 || $c === 92) {
                    $plain = false;
                    break;
                }
            }
            if ($plain) {
                $buf .= '"' . $text . '"';
                return;
            }
            self::strText($buf, $text);
            return;
        }
        if ($k === Kind::SYMBOL) {
            $s = $doc->syms[$n->a];
            if ($s->text < 0) {
                $buf .= '$0';
            } else {
                self::symText($buf, $doc->texts[$s->text]);
            }
            return;
        }
        if ($k === Kind::BLOB || $k === Kind::CLOB) {
            self::lobText($doc, $id, $buf);
            return;
        }
        if ($k === Kind::LIST || $k === Kind::SEXP) {
            $open = $k === Kind::SEXP ? '(' : '[';
            $close = $k === Kind::SEXP ? ')' : ']';
            $buf .= $open;
            $edge = $n->child;
            for ($i = 0; $i < $n->nchild; $i++) {
                if ($i > 0) {
                    if ($k === Kind::LIST) {
                        $buf .= ',';
                    } elseif (!$options->pretty) {
                        $buf .= ' ';
                    }
                }
                if ($options->pretty) {
                    self::nl($buf, $depth + 1);
                }
                self::writeText($doc, $doc->edgeChild[$edge], $buf, $options, $depth + 1);
                $edge = $doc->edgeNext[$edge];
            }
            if ($n->nchild > 0 && $options->pretty) {
                self::nl($buf, $depth);
            }
            $buf .= $close;
            return;
        }
        if ($k === Kind::STRUCT) {
            if ($n->annN === 0 && $n->nchild === 2 && self::flatTextStruct($doc, $n, $buf)) {
                return;
            }
            $buf .= '{';
            $edge = $n->child;
            for ($i = 0; $i < $n->nchild; $i++) {
                if ($i > 0) {
                    $buf .= ',';
                }
                if ($options->pretty) {
                    self::nl($buf, $depth + 1);
                }
                $f = $doc->syms[$doc->edgeField[$edge]];
                if ($f->text < 0) {
                    $buf .= '$0';
                } else {
                    self::symText($buf, $doc->texts[$f->text]);
                }
                $buf .= ':';
                self::writeText($doc, $doc->edgeChild[$edge], $buf, $options, $depth + 1);
                $edge = $doc->edgeNext[$edge];
            }
            if ($n->nchild > 0 && $options->pretty) {
                self::nl($buf, $depth);
            }
            $buf .= '}';
            return;
        }
        throw new DecodeError(DecodeError::KIND_TYPE, 0);
    }

    private static function nullName(int $kind): string
    {
        return match ($kind) {
            Kind::BOOL => 'bool',
            Kind::INT => 'int',
            Kind::FLOAT => 'float',
            Kind::DECIMAL => 'decimal',
            Kind::TIMESTAMP => 'timestamp',
            Kind::STRING => 'string',
            Kind::SYMBOL => 'symbol',
            Kind::BLOB => 'blob',
            Kind::CLOB => 'clob',
            Kind::LIST => 'list',
            Kind::SEXP => 'sexp',
            Kind::STRUCT => 'struct',
            default => 'null',
        };
    }

    private static function flatTextStruct(IonDoc $doc, \Gld\Ion\IonNode $n, string &$buf): bool
    {
        $e0 = $n->child;
        $e1 = $doc->edgeNext[$e0];
        if ($e1 < 0) {
            return false;
        }
        $n0 = $doc->nodes[$doc->edgeChild[$e0]];
        $n1 = $doc->nodes[$doc->edgeChild[$e1]];
        if ($n0->annN !== 0 || $n1->annN !== 0 || $n0->kind !== Kind::INT || $n1->kind !== Kind::STRING || $n0->b > 1) {
            return false;
        }
        $f0 = $doc->syms[$doc->edgeField[$e0]];
        $f1 = $doc->syms[$doc->edgeField[$e1]];
        if ($f0->text < 0 || $f1->text < 0) {
            return false;
        }
        $t0 = $doc->texts[$f0->text];
        $t1 = $doc->texts[$f1->text];
        $s = $doc->texts[$n1->a];
        $mag = $n0->b === 0 ? 0 : $doc->limbs[$n0->a];
        if ($t0 === self::$fp0 && $t1 === self::$fp1 && $s === self::$fpS) {
            $buf .= self::$fpre;
            if ($n0->c !== 0 && $mag !== 0) {
                $buf .= '-';
            }
            $buf .= $mag;
            $buf .= self::$fsuf;
            return true;
        }
        if (!self::identOk($t0) || !self::identOk($t1)) {
            return false;
        }
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            if ($c < 32 || $c === 34 || $c === 92) {
                return false;
            }
        }
        self::$fp0 = $t0;
        self::$fp1 = $t1;
        self::$fpS = $s;
        self::$fpre = '{' . $t0 . ':';
        self::$fsuf = ',' . $t1 . ':"' . $s . '"}';
        $buf .= self::$fpre;
        if ($n0->c !== 0 && $mag !== 0) {
            $buf .= '-';
        }
        $buf .= $mag;
        $buf .= self::$fsuf;
        return true;
    }

    private static function flatBinStruct(IonDoc $doc, int $id, SymMap $sm, string &$buf): bool
    {
        $n = $doc->nodes[$id];
        if ($n->nchild !== 2) {
            return false;
        }
        $e0 = $n->child;
        $e1 = $doc->edgeNext[$e0];
        $n0 = $doc->nodes[$doc->edgeChild[$e0]];
        $n1 = $doc->nodes[$doc->edgeChild[$e1]];
        if ($n0->annN !== 0 || $n1->annN !== 0 || $n0->kind !== Kind::INT || $n1->kind !== Kind::STRING) {
            return false;
        }
        if ($n0->b > 1) {
            return false;
        }
        $mag = $n0->b === 0 ? 0 : $doc->limbs[$n0->a];
        $neg = $n0->c !== 0 && $mag !== 0;
        if ($mag > 255) {
            return false;
        }
        $text = $doc->texts[$n1->a];
        $ln = strlen($text);
        if ($ln >= 14) {
            return false;
        }
        $f0 = $doc->syms[$doc->edgeField[$e0]];
        $f1 = $doc->syms[$doc->edgeField[$e1]];
        if ($f0->text < 0 || $f1->text < 0) {
            return false;
        }
        $sid0 = $sm->sid($doc->texts[$f0->text]);
        $sid1 = $sm->sid($doc->texts[$f1->text]);
        if ($sid0 > 127 || $sid1 > 127) {
            return false;
        }
        if ($mag !== 0 && $sid0 === self::$bs0 && $sid1 === self::$bs1 && $text === self::$bt && $neg === self::$bneg) {
            $buf .= self::$bpre;
            $buf .= chr($mag);
            $buf .= self::$bsuf;
            return true;
        }
        $body = 1 + ($mag === 0 && !$neg ? 1 : 2) + 1 + 1 + $ln;
        if ($body >= 14) {
            return false;
        }
        if ($mag === 0 && !$neg) {
            $buf .= chr(0xD0 | $body) . chr(0x80 | $sid0) . "\x20" . chr(0x80 | $sid1) . chr(0x80 | $ln) . $text;
            return true;
        }
        self::$bs0 = $sid0;
        self::$bs1 = $sid1;
        self::$bt = $text;
        self::$bneg = $neg;
        self::$bpre = chr(0xD0 | $body) . chr(0x80 | $sid0) . chr($neg ? 0x31 : 0x21);
        self::$bsuf = chr(0x80 | $sid1) . chr(0x80 | $ln) . $text;
        $buf .= self::$bpre;
        $buf .= chr($mag);
        $buf .= self::$bsuf;
        return true;
    }

    private static function nl(string &$buf, int $depth): void
    {
        $buf .= "\n" . str_repeat('  ', $depth);
    }

    private static function identOk(string $text): bool
    {
        $n = strlen($text);
        if ($n === 0) {
            return false;
        }
        $c0 = ord($text[0]);
        if ($c0 >= 48 && $c0 <= 57) {
            return false;
        }
        if (!(($c0 >= 65 && $c0 <= 90) || ($c0 >= 97 && $c0 <= 122) || $c0 === 95 || $c0 === 36)) {
            return false;
        }
        for ($i = 1; $i < $n; $i++) {
            $c = ord($text[$i]);
            $ok = ($c >= 48 && $c <= 57) || ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || $c === 95 || $c === 36;
            if (!$ok) {
                return false;
            }
        }
        return $text !== 'null' && $text !== 'true' && $text !== 'nan' && $text !== 'false';
    }

    private static function symText(string &$buf, string $text): void
    {
        if (self::identOk($text)) {
            $buf .= $text;
        } else {
            self::quote($buf, $text);
        }
    }

    private static function quote(string &$buf, string $text): void
    {
        $buf .= "'";
        $n = strlen($text);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($text[$i]);
            if ($c === 39 || $c === 92) {
                $buf .= '\\' . chr($c);
            } elseif ($c < 32) {
                $buf .= '\\x' . self::hexByte($c);
            } else {
                $buf .= chr($c);
            }
        }
        $buf .= "'";
    }

    private static function strText(string &$buf, string $text): void
    {
        $buf .= '"';
        $n = strlen($text);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($text[$i]);
            if ($c === 34 || $c === 92) {
                $buf .= '\\' . chr($c);
            } elseif ($c === 10) {
                $buf .= '\\n';
            } elseif ($c < 32) {
                $buf .= '\\x' . self::hexByte($c);
            } else {
                $buf .= chr($c);
            }
        }
        $buf .= '"';
    }

    private static function hexByte(int $c): string
    {
        return strtolower(sprintf('%02x', $c));
    }

    private static function decText(IonDoc $doc, int $id, string &$buf): void
    {
        $n = $doc->nodes[$id];
        $digits = $doc->limbs_of($n->a, $n->b)->toDec();
        if ($n->c !== 0) {
            $buf .= '-';
        }
        if ($n->d >= 0) {
            $buf .= $digits . 'd' . $n->d;
            return;
        }
        $frac = -$n->d;
        if ($digits === '0') {
            $buf .= '0.' . str_repeat('0', $frac);
            return;
        }
        if ($frac >= strlen($digits)) {
            $buf .= '0.' . str_repeat('0', $frac - strlen($digits)) . $digits;
            return;
        }
        $cut = strlen($digits) - $frac;
        $buf .= substr($digits, 0, $cut) . '.' . substr($digits, $cut);
    }

    private static function timeText(IonDoc $doc, int $id, string &$buf): void
    {
        $t = $doc->times[$doc->nodes[$id]->a];
        $buf .= sprintf('%04d', $t->year);
        if ($t->prec === Kind::PREC_YEAR) {
            $buf .= 'T';
            return;
        }
        $buf .= sprintf('-%02d', $t->month);
        if ($t->prec === Kind::PREC_MONTH) {
            $buf .= 'T';
            return;
        }
        $buf .= sprintf('-%02d', $t->day);
        if ($t->prec === Kind::PREC_DAY) {
            return;
        }
        $buf .= sprintf('T%02d:%02d', $t->hour, $t->minute);
        if ($t->prec >= Kind::PREC_SEC) {
            $buf .= sprintf(':%02d', $t->second);
        }
        if ($t->prec === Kind::PREC_FRAC) {
            $digits = $doc->limbs_of($t->fracAt, $t->fracLen)->toDec();
            $need = -$t->fracExp;
            $buf .= '.';
            if (strlen($digits) < $need) {
                $buf .= str_repeat('0', $need - strlen($digits));
            }
            $buf .= $digits;
        }
        if ($t->unknown) {
            $buf .= '-00:00';
            return;
        }
        if ($t->off === 0) {
            $buf .= 'Z';
            return;
        }
        $off = $t->off;
        if ($off < 0) {
            $buf .= '-';
            $off = -$off;
        } else {
            $buf .= '+';
        }
        $buf .= sprintf('%02d:%02d', intdiv($off, 60), $off % 60);
    }

    private static function lobText(IonDoc $doc, int $id, string &$buf): void
    {
        $n = $doc->nodes[$id];
        $buf .= '{{';
        $raw = $doc->blob_bytes($id);
        if ($n->kind === Kind::CLOB) {
            $buf .= '"';
            $len = strlen($raw);
            for ($i = 0; $i < $len; $i++) {
                $c = ord($raw[$i]);
                if ($c === 34 || $c === 92) {
                    $buf .= '\\' . chr($c);
                } elseif ($c >= 32 && $c < 127) {
                    $buf .= chr($c);
                } else {
                    $buf .= '\\x' . self::hexByte($c);
                }
            }
            $buf .= '"';
        } else {
            $buf .= self::b64($raw);
        }
        $buf .= '}}';
    }

    private static function b64(string $raw): string
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $out = '';
        $n = strlen($raw);
        for ($i = 0; $i < $n; $i += 3) {
            $b0 = ord($raw[$i]);
            $b1 = $i + 1 < $n ? ord($raw[$i + 1]) : 0;
            $b2 = $i + 2 < $n ? ord($raw[$i + 2]) : 0;
            $have = 1 + ($i + 1 < $n ? 1 : 0) + ($i + 2 < $n ? 1 : 0);
            $v = ($b0 << 16) | ($b1 << 8) | $b2;
            $out .= $alpha[($v >> 18) & 63];
            $out .= $alpha[($v >> 12) & 63];
            $out .= $have > 1 ? $alpha[($v >> 6) & 63] : '=';
            $out .= $have > 2 ? $alpha[$v & 63] : '=';
        }
        return $out;
    }

    private static function binValue(IonDoc $doc, int $id, SymMap $sm, string &$buf): void
    {
        $n = $doc->nodes[$id];
        if ($n->annN === 0) {
            self::emitCore($doc, $id, $sm, $buf);
            return;
        }
        $bare = '';
        self::emitCore($doc, $id, $sm, $bare);
        $ann = '';
        for ($a = 0; $a < $n->annN; $a++) {
            $s = $doc->syms[$doc->anns[$n->ann + $a]];
            $sid = $s->text >= 0 ? $sm->sid($doc->texts[$s->text]) : 0;
            self::varUint($ann, $sid);
        }
        $wrapped = '';
        self::varUint($wrapped, strlen($ann));
        $wrapped .= $ann . $bare;
        self::td($buf, 14, $wrapped);
    }

    private static function emitCore(IonDoc $doc, int $id, SymMap $sm, string &$buf): void
    {
        $n = $doc->nodes[$id];
        $k = $n->kind;
        if ($k === Kind::NULL && $n->a === 0) {
            $buf .= "\x0F";
            return;
        }
        if ($k === Kind::NULL) {
            $code = match ($n->a) {
                Kind::BOOL => 0x1F,
                Kind::INT => 0x2F,
                Kind::FLOAT => 0x4F,
                Kind::DECIMAL => 0x5F,
                Kind::TIMESTAMP => 0x6F,
                Kind::SYMBOL => 0x7F,
                Kind::STRING => 0x8F,
                Kind::CLOB => 0x9F,
                Kind::BLOB => 0xAF,
                Kind::LIST => 0xBF,
                Kind::SEXP => 0xCF,
                Kind::STRUCT => 0xDF,
                default => 0x0F,
            };
            $buf .= chr($code);
            return;
        }
        if ($k === Kind::BOOL) {
            $buf .= $n->a === 0 ? "\x10" : "\x11";
            return;
        }
        if ($k === Kind::INT) {
            if ($n->b === 0) {
                $buf .= "\x20";
                return;
            }
            if ($n->b === 1) {
                $mag = $doc->limbs[$n->a];
                $t = ($n->c !== 0 && $mag !== 0) ? 3 : 2;
                if ($mag < 256) {
                    $buf .= chr(($t << 4) | ($mag === 0 ? 0 : 1));
                    if ($mag !== 0) {
                        $buf .= chr($mag);
                    }
                    return;
                }
                if ($mag < 65536) {
                    $buf .= chr(($t << 4) | 2) . chr(($mag >> 8) & 255) . chr($mag & 255);
                    return;
                }
                if ($mag < 16777216) {
                    $buf .= chr(($t << 4) | 3) . chr(($mag >> 16) & 255) . chr(($mag >> 8) & 255) . chr($mag & 255);
                    return;
                }
                $buf .= chr(($t << 4) | 4)
                    . chr(($mag >> 24) & 255)
                    . chr(($mag >> 16) & 255)
                    . chr(($mag >> 8) & 255)
                    . chr($mag & 255);
                return;
            }
            $mag = $doc->limbs_of($n->a, $n->b);
            self::td($buf, $n->c !== 0 && !$mag->isZero() ? 3 : 2, $mag->toBe());
            return;
        }
        if ($k === Kind::FLOAT) {
            if ($n->a === 0) {
                $buf .= "\x40";
                return;
            }
            self::td($buf, 4, $doc->floats[$n->b]);
            return;
        }
        if ($k === Kind::DECIMAL) {
            $tmp = '';
            self::varInt($tmp, $n->d, false);
            $mag = $doc->limbs_of($n->a, $n->b);
            if ($n->c !== 0 || $n->b > 0) {
                $be = $mag->toBe();
                if ($n->c !== 0) {
                    if ($be === '') {
                        $be = "\x80";
                    } else {
                        $be[0] = chr(ord($be[0]) | 0x80);
                    }
                }
                $tmp .= $be;
            }
            self::td($buf, 5, $tmp);
            return;
        }
        if ($k === Kind::STRING) {
            $text = $doc->texts[$n->a];
            $ln = strlen($text);
            if ($ln < 14) {
                $buf .= chr(0x80 | $ln) . $text;
                return;
            }
            self::td($buf, 8, $text);
            return;
        }
        if ($k === Kind::BLOB || $k === Kind::CLOB) {
            self::td($buf, $k === Kind::CLOB ? 9 : 10, $doc->blob_bytes($id));
            return;
        }
        if ($k === Kind::SYMBOL) {
            $s = $doc->syms[$n->a];
            $sid = $s->text >= 0 ? $sm->sid($doc->texts[$s->text]) : 0;
            if ($sid === 0) {
                $buf .= "\x70";
                return;
            }
            $tmp = '';
            $x = $sid;
            $be = '';
            if ($x === 0) {
                $be = "\0";
            }
            while ($x > 0) {
                $tmp .= chr($x & 255);
                $x >>= 8;
            }
            for ($i = strlen($tmp) - 1; $i >= 0; $i--) {
                $be .= $tmp[$i];
            }
            self::td($buf, 7, $be);
            return;
        }
        if ($k === Kind::TIMESTAMP) {
            self::binTime($doc, $id, $buf);
            return;
        }
        if ($k === Kind::LIST || $k === Kind::SEXP || $k === Kind::STRUCT) {
            if ($k === Kind::STRUCT && $n->annN === 0 && self::flatBinStruct($doc, $id, $sm, $buf)) {
                return;
            }
            $tcode = match ($k) {
                Kind::SEXP => 12,
                Kind::STRUCT => 13,
                default => 11,
            };
            $mark = strlen($buf);
            $buf .= "\0";
            $edge = $n->child;
            for ($i = 0; $i < $n->nchild; $i++) {
                if ($k === Kind::STRUCT) {
                    $f = $doc->syms[$doc->edgeField[$edge]];
                    $sid = $f->text >= 0 ? $sm->sid($doc->texts[$f->text]) : 0;
                    self::varUint($buf, $sid);
                }
                self::binValue($doc, $doc->edgeChild[$edge], $sm, $buf);
                $edge = $doc->edgeNext[$edge];
            }
            self::seal($buf, $mark, $tcode);
            return;
        }
        throw new DecodeError(DecodeError::KIND_TYPE, 0);
    }

    private static function binTime(IonDoc $doc, int $id, string &$buf): void
    {
        $t = $doc->times[$doc->nodes[$id]->a];
        $tmp = '';
        if ($t->prec < Kind::PREC_MIN || $t->unknown) {
            self::varInt($tmp, 0, true);
        } else {
            self::varInt($tmp, $t->off, false);
        }
        $year = $t->year;
        $month = $t->month;
        $day = $t->day;
        $hour = $t->hour;
        $minute = $t->minute;
        if ($t->prec >= Kind::PREC_MIN && !$t->unknown && $t->off !== 0) {
            $total = $hour * 60 + $minute - $t->off;
            $days = 0;
            while ($total < 0) {
                $total += 1440;
                $days--;
            }
            while ($total >= 1440) {
                $total -= 1440;
                $days++;
            }
            $hour = intdiv($total, 60);
            $minute = $total % 60;
            if ($days !== 0) {
                $when = new IonTime();
                $when->year = $year;
                $when->month = $month;
                $when->day = $day;
                $when->hour = $hour;
                $when->minute = $minute;
                Time::shiftDays($when, $days);
                $year = $when->year;
                $month = $when->month;
                $day = $when->day;
            }
        }
        self::varUint($tmp, $year);
        if ($t->prec >= Kind::PREC_MONTH) {
            self::varUint($tmp, $month);
        }
        if ($t->prec >= Kind::PREC_DAY) {
            self::varUint($tmp, $day);
        }
        if ($t->prec >= Kind::PREC_MIN) {
            self::varUint($tmp, $hour);
            self::varUint($tmp, $minute);
        }
        if ($t->prec >= Kind::PREC_SEC) {
            self::varUint($tmp, $t->second);
        }
        if ($t->prec === Kind::PREC_FRAC) {
            self::varInt($tmp, $t->fracExp, false);
            $tmp .= $doc->limbs_of($t->fracAt, $t->fracLen)->toBe();
        }
        self::td($buf, 6, $tmp);
    }

    private static function writeLst(SymMap $sm, EncodeOptions $options, Catalog $cat, string &$buf): void
    {
        if ($sm->texts === [] && $options->importNames === []) {
            return;
        }
        if ($options->importNames === []) {
            self::writeSimpleLst($sm, $buf);
            return;
        }
        $fields = '';
        $items = '';
        foreach ($options->importNames as $i => $name) {
            $item = '';
            self::varUint($item, 4);
            self::td($item, 8, $name);
            self::varUint($item, 5);
            self::putUInt($item, 2, $options->importVersions[$i]);
            $maxId = 0;
            $idx = $cat->exact($name, $options->importVersions[$i]);
            if ($idx >= 0) {
                $maxId = count($cat->tables[$idx]->texts);
            }
            self::varUint($item, 8);
            self::putUInt($item, 2, $maxId);
            self::td($items, 13, $item);
        }
        self::varUint($fields, 6);
        self::td($fields, 11, $items);
        if ($sm->texts !== []) {
            self::varUint($fields, 7);
            $syms = '';
            foreach ($sm->texts as $text) {
                self::td($syms, 8, $text);
            }
            self::td($fields, 11, $syms);
        }
        $structed = '';
        self::td($structed, 13, $fields);
        $ann = '';
        self::varUint($ann, 1);
        self::varUint($ann, 3);
        $ann .= $structed;
        self::td($buf, 14, $ann);
    }

    private static function writeSimpleLst(SymMap $sm, string &$buf): void
    {
        $mark = strlen($buf);
        $buf .= "\0";
        self::varUint($buf, 1);
        self::varUint($buf, 3);
        $smark = strlen($buf);
        $buf .= "\0";
        self::varUint($buf, 7);
        $lmark = strlen($buf);
        $buf .= "\0";
        foreach ($sm->texts as $text) {
            self::td($buf, 8, $text);
        }
        self::seal($buf, $lmark, 11);
        self::seal($buf, $smark, 13);
        self::seal($buf, $mark, 14);
    }

    private static function putUInt(string &$buf, int $tcode, int $value): void
    {
        if ($value === 0) {
            self::td($buf, $tcode, "\0");
            return;
        }
        $be = '';
        $x = $value;
        $tmp = '';
        while ($x > 0) {
            $tmp .= chr($x & 255);
            $x >>= 8;
        }
        for ($i = strlen($tmp) - 1; $i >= 0; $i--) {
            $be .= $tmp[$i];
        }
        self::td($buf, $tcode, $be);
    }

    public static function varUint(string &$buf, int $value): void
    {
        if ($value < 0) {
            $value = 0;
        }
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

    public static function varInt(string &$buf, int $v, bool $neg0): void
    {
        $neg = $v < 0 || $neg0;
        $mag = $v < 0 ? -$v : $v;
        if ($mag < 64) {
            $b = 0x80 | $mag;
            if ($neg) {
                $b |= 0x40;
            }
            $buf .= chr($b);
            return;
        }
        $lows = [];
        while ($mag >= 64) {
            $lows[] = $mag & 0x7F;
            $mag >>= 7;
        }
        $first = $mag;
        if ($neg) {
            $first |= 0x40;
        }
        $buf .= chr($first);
        for ($i = count($lows) - 1; $i >= 0; $i--) {
            $b = $lows[$i];
            if ($i === 0) {
                $b |= 0x80;
            }
            $buf .= chr($b);
        }
    }

    public static function td(string &$buf, int $t, string $body): void
    {
        $n = strlen($body);
        if ($n < 14) {
            $buf .= chr(($t << 4) | $n);
        } else {
            $buf .= chr(($t << 4) | 14);
            self::varUint($buf, $n);
        }
        $buf .= $body;
    }

    private static function seal(string &$buf, int $mark, int $tcode): void
    {
        $ln = strlen($buf) - ($mark + 1);
        if ($ln < 14) {
            $buf[$mark] = chr(($tcode << 4) | $ln);
            return;
        }
        $tail = substr($buf, $mark + 1);
        $buf = substr($buf, 0, $mark);
        $buf .= chr(($tcode << 4) | 14);
        self::varUint($buf, $ln);
        $buf .= $tail;
    }
}
