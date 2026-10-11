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

final class Message
{
    public int $id = 0;
    public string $name = '';
    public bool $has_ok = false;
    public bool $ok = false;
    public bool $has_weight = false;
    public float $weight = 0.0;
    public bool $has_note = false;
    public string $note = '';
    public bool $has_tags = false;
    public array $tags = [];
    public bool $has_when = false;
    public string $when = '';
    public bool $has_payload = false;
    public string $payload = '';

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
        $sym_id = $doc->add_symbol_text('id');
        $slot_id = $doc->nodes[$sym_id]->a;
        $val_id = $doc->add_i64($this->id);
        $doc->add_child($st, $val_id, $slot_id);
        $sym_name = $doc->add_symbol_text('name');
        $slot_name = $doc->nodes[$sym_name]->a;
        $val_name = $doc->add_string($this->name);
        $doc->add_child($st, $val_name, $slot_name);
        if ($this->has_ok) {
            $sym_ok = $doc->add_symbol_text('ok');
            $slot_ok = $doc->nodes[$sym_ok]->a;
            $val_ok = $doc->add_bool($this->ok);
            $doc->add_child($st, $val_ok, $slot_ok);
        }
        if ($this->has_weight) {
            $sym_weight = $doc->add_symbol_text('weight');
            $slot_weight = $doc->nodes[$sym_weight]->a;
            $val_weight = $doc->add_float(8, FloatBits::f64($this->weight));
            $doc->add_child($st, $val_weight, $slot_weight);
        }
        if ($this->has_note) {
            $sym_note = $doc->add_symbol_text('note');
            $slot_note = $doc->nodes[$sym_note]->a;
            $val_note = $doc->add_string($this->note);
            $doc->add_child($st, $val_note, $slot_note);
        }
        if ($this->has_tags) {
            $sym_tags = $doc->add_symbol_text('tags');
            $slot_tags = $doc->nodes[$sym_tags]->a;
            $val_tags = $doc->start_container(Kind::LIST);
            foreach ($this->tags as $item__val_tags) {
                $one__val_tags = $doc->add_string($item__val_tags);
                $doc->add_child($val_tags, $one__val_tags, -1);
            }
            $doc->add_child($st, $val_tags, $slot_tags);
        }
        if ($this->has_when) {
            $sym_when = $doc->add_symbol_text('when');
            $slot_when = $doc->nodes[$sym_when]->a;
            $val_when = $doc->adopt_text($this->when, 6);
            $doc->add_child($st, $val_when, $slot_when);
        }
        if ($this->has_payload) {
            $sym_payload = $doc->add_symbol_text('payload');
            $slot_payload = $doc->nodes[$sym_payload]->a;
            $val_payload = $doc->add_bytes(9, $this->payload);
            $doc->add_child($st, $val_payload, $slot_payload);
        }
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
        $got_id = false;
        $got_name = false;
        $count = $doc->nodes[$id]->nchild;
        for ($i = 0; $i < $count; $i++) {
            $fname = $doc->field_name($id, $i);
            $child = $doc->child_at($id, $i);
            if ($fname === 'id') {
                $out->id = $doc->as_i64($child);
                $got_id = true;
            }
            elseif ($fname === 'name') {
                $out->name = $doc->as_string($child);
                $got_name = true;
            }
            elseif ($fname === 'ok') {
                $out->ok = $doc->as_bool($child);
                $out->has_ok = true;
            }
            elseif ($fname === 'weight') {
                $out->weight = $doc->as_f64($child);
                $out->has_weight = true;
            }
            elseif ($fname === 'note') {
                $out->note = $doc->as_string($child);
                $out->has_note = true;
            }
            elseif ($fname === 'tags') {
                if ($doc->nodes[$child]->kind !== Kind::LIST) {
                    throw new DecodeError(DecodeError::KIND_TYPE, 0);
                }
                $n__out__tags_child = $doc->nodes[$child]->nchild;
                for ($j__out__tags_child = 0; $j__out__tags_child < $n__out__tags_child; $j__out__tags_child++) {
                    $el__out__tags_child = $doc->child_at($child, $j__out__tags_child);
                    $one__out__tags_child = $doc->as_string($el__out__tags_child);
                    $out->tags[] = $one__out__tags_child;
                }
                $out->has_tags = true;
            }
            elseif ($fname === 'when') {
                $out->when = $doc->ion_text($child, 6);
                $out->has_when = true;
            }
            elseif ($fname === 'payload') {
                $out->payload = $doc->as_bytes($child, 9);
                $out->has_payload = true;
            }
            else {
                throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
            }
        }
        if (!$got_id) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        if (!$got_name) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        return $out;
    }
}
