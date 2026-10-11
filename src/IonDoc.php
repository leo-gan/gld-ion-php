<?php

declare(strict_types=1);

namespace Gld\Ion;

use Gld\Ion\Wire\Writer;

/**
 * One Ion datagram. Nodes are small records. Strings, limbs, float bits,
 * timestamps, and blob bytes live in parallel lists. Containers are linked
 * edges, so a node never contains another node.
 */
final class IonDoc
{
    /** @var list<IonNode> */
    public array $nodes = [];

    /** @var list<int> */
    public array $edgeChild = [];
    /** @var list<int> */
    public array $edgeField = [];
    /** @var list<int> */
    public array $edgeNext = [];

    /** @var list<int> annotation symbol indexes */
    public array $anns = [];

    /** @var list<string> */
    public array $texts = [];

    /** @var list<SymRef> */
    public array $syms = [];

    /** @var list<int> little-endian uint32 limbs */
    public array $limbs = [];

    /**
     * Big-endian IEEE bits. Width 0 stores an empty string.
     *
     * @var list<string>
     */
    public array $floats = [];

    /** @var list<int> float widths 0, 2, 4, or 8 */
    public array $floatW = [];

    /** @var list<IonTime> */
    public array $times = [];

    public string $blobs = '';

    /** @var list<int> */
    public array $blobAt = [];

    /** @var list<int> */
    public array $blobLen = [];

    /** @var list<int> */
    public array $top = [];

    /** @var array<string, int> */
    private array $textIndex = [];
    private string $intern0 = "\1";
    private string $intern1 = "\1";
    private int $internId0 = -1;
    private int $internId1 = -1;

    /** @var list<int> symbol node cached by text index, or -1 */
    public array $symAt = [];

    public function reserve(int $n): void
    {
        // PHP arrays grow themselves. The hint exists so callers can match the arena API.
        unset($n);
    }

    public function intern(string $text): int
    {
        if ($text === $this->intern0) {
            return $this->internId0;
        }
        if ($text === $this->intern1) {
            return $this->internId1;
        }
        $key = "k\0" . $text;
        if (isset($this->textIndex[$key])) {
            $id = $this->textIndex[$key];
        } else {
            $id = count($this->texts);
            $this->texts[] = $text;
            $this->symAt[] = -1;
            $this->textIndex[$key] = $id;
        }
        $this->intern1 = $this->intern0;
        $this->internId1 = $this->internId0;
        $this->intern0 = $text;
        $this->internId0 = $id;
        return $id;
    }

    public function add(IonNode $node): int
    {
        $this->nodes[] = $node;
        return count($this->nodes) - 1;
    }

    public function add_null(int $nullType): int
    {
        $n = new IonNode(Kind::NULL);
        $n->a = $nullType;
        return $this->add($n);
    }

    public function add_bool(bool $value): int
    {
        $n = new IonNode(Kind::BOOL);
        $n->a = $value ? 1 : 0;
        return $this->add($n);
    }

    public function add_int(BigInt $value): int
    {
        $n = new IonNode(Kind::INT);
        $n->a = count($this->limbs);
        $n->b = count($value->limbs);
        $n->c = $value->neg ? 1 : 0;
        foreach ($value->limbs as $limb) {
            $this->limbs[] = $limb;
        }
        return $this->add($n);
    }

    public function add_i64(int $value): int
    {
        if ($value === 0) {
            return $this->add(new IonNode(Kind::INT));
        }
        $n = new IonNode(Kind::INT);
        if ($value < 0) {
            $n->c = 1;
        }
        if ($value === PHP_INT_MIN) {
            $lo = 0;
            $hi = 0x80000000;
        } else {
            $mag = $value < 0 ? -$value : $value;
            $lo = $mag & 0xFFFFFFFF;
            $hi = ($mag >> 32) & 0xFFFFFFFF;
        }
        $n->a = count($this->limbs);
        $this->limbs[] = $lo;
        $n->b = 1;
        if ($hi !== 0) {
            $this->limbs[] = $hi;
            $n->b = 2;
        }
        return $this->add($n);
    }

    /** $be is big-endian IEEE bits of exactly $width bytes. Width 0 is +0. */
    public function add_float(int $width, string $be): int
    {
        $n = new IonNode(Kind::FLOAT);
        $n->a = $width;
        $n->b = count($this->floats);
        $this->floats[] = $be;
        $this->floatW[] = $width;
        return $this->add($n);
    }

    public function add_decimal(BigInt $coef, bool $neg, int $exp): int
    {
        $n = new IonNode(Kind::DECIMAL);
        $n->a = count($this->limbs);
        $n->b = count($coef->limbs);
        if ($neg || $coef->neg) {
            $n->c = 1;
        }
        $n->d = $exp;
        foreach ($coef->limbs as $limb) {
            $this->limbs[] = $limb;
        }
        return $this->add($n);
    }

    public function add_time(IonTime $when): int
    {
        $n = new IonNode(Kind::TIMESTAMP);
        $n->a = count($this->times);
        $this->times[] = $when;
        return $this->add($n);
    }

    public function add_string(string $text): int
    {
        $n = new IonNode(Kind::STRING);
        $n->a = $this->intern($text);
        return $this->add($n);
    }

    public function add_string_at(int $textI): int
    {
        $n = new IonNode(Kind::STRING);
        $n->a = $textI;
        return $this->add($n);
    }

    public function add_symbol_text(string $text): int
    {
        $ti = $this->intern($text);
        $cached = $this->symAt[$ti];
        if ($cached >= 0) {
            return $cached;
        }
        $n = new IonNode(Kind::SYMBOL);
        $n->a = count($this->syms);
        $this->syms[] = new SymRef($ti, 0, -1, 0);
        $id = $this->add($n);
        $this->symAt[$ti] = $id;
        return $id;
    }

    public function wrap_sym(int $sym): int
    {
        $n = new IonNode(Kind::SYMBOL);
        $n->a = $sym;
        return $this->add($n);
    }

    public function add_symbol_ref(SymRef $sym): int
    {
        $n = new IonNode(Kind::SYMBOL);
        $n->a = count($this->syms);
        $this->syms[] = $sym;
        return $this->add($n);
    }

    public function add_bytes(int $kind, string $data): int
    {
        $n = new IonNode($kind);
        $n->a = count($this->blobAt);
        $this->blobAt[] = strlen($this->blobs);
        $this->blobLen[] = strlen($data);
        $this->blobs .= $data;
        return $this->add($n);
    }

    public function start_container(int $kind): int
    {
        return $this->add(new IonNode($kind));
    }

    /**
     * Struct of one small int field and one string field.
     * $textId skips interning when the caller already interned $text.
     */
    public function add_pair_struct(int $field1, int $intValue, int $field2, string $text, int $textId = -1): int
    {
        static $ps = null;
        static $pi = null;
        static $pt = null;
        if ($ps === null) {
            $ps = new IonNode(Kind::STRUCT);
            $pi = new IonNode(Kind::INT);
            $pt = new IonNode(Kind::STRING);
        }
        $st = clone $ps;
        $iv = null;
        $intId = -1;
        if ($intValue !== 0) {
            if ($intValue < 0) {
                $mag = $intValue === PHP_INT_MIN ? null : -$intValue;
                $neg = 1;
            } else {
                $mag = $intValue;
                $neg = 0;
            }
            if ($mag === null || $mag > 0xFFFFFFFF) {
                $intId = $this->add_i64($intValue);
            } else {
                $iv = clone $pi;
                $iv->a = count($this->limbs);
                $iv->b = 1;
                $iv->c = $neg;
                $this->limbs[] = $mag;
            }
        } else {
            $iv = clone $pi;
        }
        $sv = clone $pt;
        $sv->a = $textId >= 0 ? $textId : $this->intern($text);
        $stId = count($this->nodes);
        $this->nodes[] = $st;
        if ($intId < 0) {
            $intId = count($this->nodes);
            $this->nodes[] = $iv;
        }
        $strId = count($this->nodes);
        $this->nodes[] = $sv;
        $e = count($this->edgeChild);
        $this->edgeChild[] = $intId;
        $this->edgeField[] = $field1;
        $this->edgeNext[] = $e + 1;
        $this->edgeChild[] = $strId;
        $this->edgeField[] = $field2;
        $this->edgeNext[] = -1;
        $st->child = $e;
        $st->d = $e + 1;
        $st->nchild = 2;
        return $stId;
    }

    /**
     * Append many pair structs as top-level values.
     * $textId is an interned text index.
     *
     * @param list<int> $values
     */
    public function add_top_pairs(int $field1, array $values, int $field2, int $textId): void
    {
        static $ps = null;
        static $pi = null;
        static $pt = null;
        if ($ps === null) {
            $ps = new IonNode(Kind::STRUCT);
            $pi = new IonNode(Kind::INT);
            $pt = new IonNode(Kind::STRING);
        }
        foreach ($values as $intValue) {
            $st = clone $ps;
            $iv = null;
            $intId = -1;
            if ($intValue !== 0) {
                if ($intValue < 0) {
                    $mag = $intValue === PHP_INT_MIN ? null : -$intValue;
                    $neg = 1;
                } else {
                    $mag = $intValue;
                    $neg = 0;
                }
                if ($mag === null || $mag > 0xFFFFFFFF) {
                    $intId = $this->add_i64($intValue);
                } else {
                    $iv = clone $pi;
                    $iv->a = count($this->limbs);
                    $iv->b = 1;
                    $iv->c = $neg;
                    $this->limbs[] = $mag;
                }
            } else {
                $iv = clone $pi;
            }
            $sv = clone $pt;
            $sv->a = $textId;
            $stId = count($this->nodes);
            $this->nodes[] = $st;
            if ($intId < 0) {
                $intId = count($this->nodes);
                $this->nodes[] = $iv;
            }
            $strId = count($this->nodes);
            $this->nodes[] = $sv;
            $e = count($this->edgeChild);
            $this->edgeChild[] = $intId;
            $this->edgeField[] = $field1;
            $this->edgeNext[] = $e + 1;
            $this->edgeChild[] = $strId;
            $this->edgeField[] = $field2;
            $this->edgeNext[] = -1;
            $st->child = $e;
            $st->d = $e + 1;
            $st->nchild = 2;
            $this->top[] = $stId;
        }
    }

    public function add_child(int $parent, int $child, int $field): void
    {
        $idx = count($this->edgeChild);
        $this->edgeChild[] = $child;
        $this->edgeField[] = $field;
        $this->edgeNext[] = -1;
        $node = $this->nodes[$parent];
        if ($node->child < 0) {
            $node->child = $idx;
        } else {
            $this->edgeNext[$node->d] = $idx;
        }
        $node->d = $idx;
        $node->nchild++;
    }

    /** @param list<int> $anns symbol indexes */
    public function set_anns(int $id, array $anns): void
    {
        if ($anns === []) {
            return;
        }
        $node = $this->nodes[$id];
        $node->ann = count($this->anns);
        $node->annN = count($anns);
        foreach ($anns as $a) {
            $this->anns[] = $a;
        }
    }

    public function add_top(int $id): void
    {
        $this->top[] = $id;
    }

    public function child_at(int $parent, int $index): int
    {
        $edge = $this->nodes[$parent]->child;
        for ($i = 0; $i < $index; $i++) {
            $edge = $this->edgeNext[$edge];
        }
        return $this->edgeChild[$edge];
    }

    public function field_at(int $parent, int $index): int
    {
        $edge = $this->nodes[$parent]->child;
        for ($i = 0; $i < $index; $i++) {
            $edge = $this->edgeNext[$edge];
        }
        return $this->edgeField[$edge];
    }

    public function text_at(int $id): string
    {
        return $this->texts[$this->nodes[$id]->a];
    }

    public function sym_text(int $sym): string
    {
        $s = $this->syms[$sym];
        if ($s->text < 0) {
            return '';
        }
        return $this->texts[$s->text];
    }

    public function blob_bytes(int $id): string
    {
        $slot = $this->nodes[$id]->a;
        return substr($this->blobs, $this->blobAt[$slot], $this->blobLen[$slot]);
    }

    public function limbs_of(int $at, int $len): BigInt
    {
        $b = new BigInt(false);
        for ($i = 0; $i < $len; $i++) {
            $b->limbs[] = $this->limbs[$at + $i];
        }
        return $b;
    }

    public function as_i64(int $id): int
    {
        $n = $this->nodes[$id];
        if ($n->kind !== Kind::INT) {
            throw new DecodeError(DecodeError::KIND_TYPE, 0);
        }
        if ($n->b > 2) {
            throw new DecodeError(DecodeError::KIND_RANGE, 0);
        }
        if ($n->b === 0) {
            return 0;
        }
        $mag = BigInt::fromLimbs($n->c !== 0, array_slice($this->limbs, $n->a, $n->b));
        return $mag->toI64(0);
    }

    public function as_bool(int $id): bool
    {
        $n = $this->nodes[$id];
        if ($n->kind !== Kind::BOOL) {
            throw new DecodeError(DecodeError::KIND_TYPE, 0);
        }
        return $n->a !== 0;
    }

    public function as_string(int $id): string
    {
        if ($this->nodes[$id]->kind !== Kind::STRING) {
            throw new DecodeError(DecodeError::KIND_TYPE, 0);
        }
        return $this->text_at($id);
    }

    public function as_f64(int $id): float
    {
        $n = $this->nodes[$id];
        if ($n->kind !== Kind::FLOAT) {
            throw new DecodeError(DecodeError::KIND_TYPE, 0);
        }
        return FloatBits::toFloat($n->a, $this->floats[$n->b]);
    }

    public function as_bytes(int $id, int $kind): string
    {
        if ($this->nodes[$id]->kind !== $kind) {
            throw new DecodeError(DecodeError::KIND_TYPE, 0);
        }
        return $this->blob_bytes($id);
    }

    public function ion_text(int $id, int $expect): string
    {
        if ($expect !== 0 && $this->nodes[$id]->kind !== $expect) {
            throw new DecodeError(DecodeError::KIND_TYPE, 0);
        }
        return Writer::formatValue($this, $id);
    }

    public function field_name(int $id, int $index): string
    {
        $sym = $this->field_at($id, $index);
        if ($sym < 0) {
            return '';
        }
        return $this->sym_text($sym);
    }

    public function adopt_text(string $text, int $expect): int
    {
        $id = \Gld\Ion\Wire\Text::adopt($this, $text);
        if ($expect !== 0 && $this->nodes[$id]->kind !== $expect) {
            throw new DecodeError(DecodeError::KIND_TYPE, 0);
        }
        return $id;
    }
}
