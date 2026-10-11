<?php

declare(strict_types=1);

namespace Gld\Ion\Generated;

use Gld\Ion\DecodeError;
use Gld\Ion\EncodeOptions;
use Gld\Ion\FloatBits;
use Gld\Ion\IonDoc;
use Gld\Ion\Kind;
use function Gld\Ion\decode;
use function Gld\Ion\encode;

final class Point
{
    public int $x = 0;
    public bool $has_y = false;
    public int $y = 0;
    public string $label = '';

    public function encoded_len(EncodeOptions $options): int
    {
        return strlen($this->encode_to($options));
    }

    public function encode_to(EncodeOptions $options): string
    {
        return encode($this->to_doc(), $options);
    }

    public function to_doc(): IonDoc
    {
        $doc = new IonDoc();
        $doc->add_top($this->write_into($doc));
        return $doc;
    }

    public function write_into(IonDoc $doc): int
    {
        $st = $doc->start_container(Kind::STRUCT);
        $sym_x = $doc->add_symbol_text('x');
        $slot_x = $doc->nodes[$sym_x]->a;
        $val_x = $doc->add_i64($this->x);
        $doc->add_child($st, $val_x, $slot_x);
        if ($this->has_y) {
            $sym_y = $doc->add_symbol_text('y');
            $slot_y = $doc->nodes[$sym_y]->a;
            $val_y = $doc->add_i64($this->y);
            $doc->add_child($st, $val_y, $slot_y);
        }
        $sym_label = $doc->add_symbol_text('label');
        $slot_label = $doc->nodes[$sym_label]->a;
        $val_label = $doc->adopt_text($this->label, 8);
        $doc->add_child($st, $val_label, $slot_label);
        return $st;
    }

    public static function decode_from(string $raw): self
    {
        $doc = decode($raw);
        if (count($doc->top) !== 1) {
            throw new DecodeError(DecodeError::KIND_TYPE, 0);
        }
        return self::from_doc($doc, $doc->top[0]);
    }

    public static function from_doc(IonDoc $doc, int $id): self
    {
        if ($doc->nodes[$id]->kind !== Kind::STRUCT) {
            throw new DecodeError(DecodeError::KIND_TYPE, 0);
        }
        $out = new self();
        $got_x = false;
        $got_label = false;
        $count = $doc->nodes[$id]->nchild;
        for ($i = 0; $i < $count; $i++) {
            $fname = $doc->field_name($id, $i);
            $child = $doc->child_at($id, $i);
            if ($fname === 'x') {
                $out->x = $doc->as_i64($child);
                $got_x = true;
            }
            elseif ($fname === 'y') {
                $out->y = $doc->as_i64($child);
                $out->has_y = true;
            }
            elseif ($fname === 'label') {
                $out->label = $doc->ion_text($child, 8);
                $got_label = true;
            }
            else {
                throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
            }
        }
        if (!$got_x) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        if (!$got_label) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        return $out;
    }
}
