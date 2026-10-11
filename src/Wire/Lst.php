<?php

declare(strict_types=1);

namespace Gld\Ion\Wire;

use Gld\Ion\Catalog;
use Gld\Ion\DecodeError;
use Gld\Ion\IonDoc;
use Gld\Ion\Kind;
use Gld\Ion\LocalTab;
use Gld\Ion\SharedTable;
use Gld\Ion\SymRef;

final class Lst
{
    public static function symFromSid(IonDoc $doc, LocalTab $tab, int $sid, int $offset): int
    {
        if ($sid < 0) {
            throw new DecodeError(DecodeError::KIND_SYMBOL, $offset);
        }
        $got = $tab->lookup($sid, $offset);
        if ($sid === 0 || (!$got->known && $got->imp === '')) {
            return $doc->add_symbol_ref(new SymRef(-1, 0, -1, 0));
        }
        if ($got->known) {
            $node = $doc->add_symbol_text($got->text);
            $doc->syms[$doc->nodes[$node]->a]->sid = $sid;
            return $node;
        }
        $imp = $doc->intern($got->imp);
        return $doc->add_symbol_ref(new SymRef(-1, $sid, $imp, $got->impSid));
    }

    public static function isLocalTable(IonDoc $doc, int $id): bool
    {
        $n = $doc->nodes[$id];
        if ($n->annN === 0) {
            return false;
        }
        $s = $doc->syms[$doc->anns[$n->ann]];
        if ($s->text < 0) {
            return false;
        }
        if ($doc->texts[$s->text] !== '$ion_symbol_table') {
            return false;
        }
        if ($n->kind === Kind::STRUCT) {
            return true;
        }
        return $n->kind === Kind::NULL && $n->a === Kind::STRUCT;
    }

    public static function apply(IonDoc $doc, int $id, LocalTab $tab, Catalog $cat, int $offset): void
    {
        $n = $doc->nodes[$id];
        if ($n->kind === Kind::NULL) {
            $tab->reset_system();
            return;
        }
        $imp = self::field($doc, $id, 'imports');
        if (self::countField($doc, $id, 'imports') > 1 || self::countField($doc, $id, 'symbols') > 1) {
            throw new DecodeError(DecodeError::KIND_SYMBOL, $offset);
        }
        $extend = $imp >= 0 && $doc->nodes[$imp]->kind === Kind::SYMBOL && self::symTextOf($doc, $imp) === '$ion_symbol_table';
        if (!$extend) {
            if ($imp >= 0 && $doc->nodes[$imp]->kind === Kind::LIST) {
                $tab->reset_system();
                $count = $doc->nodes[$imp]->nchild;
                for ($i = 0; $i < $count; $i++) {
                    $el = $doc->child_at($imp, $i);
                    if ($doc->nodes[$el]->kind === Kind::STRUCT) {
                        self::importOne($doc, $el, $tab, $cat, $offset);
                    }
                }
            } elseif ($imp < 0 || $doc->nodes[$imp]->kind === Kind::NULL) {
                $tab->reset_system();
            } else {
                throw new DecodeError(DecodeError::KIND_SYMBOL, $offset);
            }
        }
        $syms = self::field($doc, $id, 'symbols');
        if ($syms >= 0 && $doc->nodes[$syms]->kind === Kind::LIST) {
            $count = $doc->nodes[$syms]->nchild;
            for ($i = 0; $i < $count; $i++) {
                $el = $doc->child_at($syms, $i);
                if ($doc->nodes[$el]->kind === Kind::STRING) {
                    $tab->add_text($doc->text_at($el));
                } else {
                    $tab->add_gap();
                }
            }
        }
    }

    private static function importOne(IonDoc $doc, int $id, LocalTab $tab, Catalog $cat, int $offset): void
    {
        $nameId = self::field($doc, $id, 'name');
        if ($nameId < 0 || $doc->nodes[$nameId]->kind !== Kind::STRING) {
            return;
        }
        $name = $doc->text_at($nameId);
        if ($name === '' || $name === '$ion') {
            return;
        }
        $version = 1;
        $verId = self::field($doc, $id, 'version');
        if ($verId >= 0 && $doc->nodes[$verId]->kind === Kind::INT) {
            $v = self::i64($doc, $verId, $offset);
            if ($v >= 1) {
                $version = $v;
            }
        }
        $hasMax = false;
        $maxId = 0;
        $maxAt = self::field($doc, $id, 'max_id');
        if ($maxAt >= 0 && $doc->nodes[$maxAt]->kind === Kind::INT) {
            $m = self::i64($doc, $maxAt, $offset);
            if ($m >= 0) {
                $hasMax = true;
                $maxId = $m;
            }
        }
        if ($hasMax && $maxId === 0) {
            return;
        }
        $idx = $cat->exact($name, $version);
        if ($idx < 0) {
            if (!$hasMax) {
                throw new DecodeError(DecodeError::KIND_SYMBOL, $offset);
            }
            $idx = $cat->greatest($name);
        }
        if ($idx < 0) {
            $tab->import_table(new SharedTable($name, $version), $maxId, $offset);
            return;
        }
        if (!$hasMax) {
            $maxId = count($cat->tables[$idx]->texts);
        }
        $tab->import_table($cat->tables[$idx], $maxId, $offset);
    }

    private static function i64(IonDoc $doc, int $id, int $offset): int
    {
        $n = $doc->nodes[$id];
        if ($n->kind !== Kind::INT) {
            throw new DecodeError(DecodeError::KIND_TYPE, $offset);
        }
        if ($n->b > 2) {
            throw new DecodeError(DecodeError::KIND_RANGE, $offset);
        }
        $mag = 0;
        if ($n->b > 0) {
            $mag = $doc->limbs[$n->a];
        }
        if ($n->b > 1) {
            $mag += $doc->limbs[$n->a + 1] * 4294967296;
        }
        if ($n->c !== 0) {
            if ($mag > 9223372036854775808) {
                throw new DecodeError(DecodeError::KIND_RANGE, $offset);
            }
            return -$mag;
        }
        if ($mag > 9223372036854775807) {
            throw new DecodeError(DecodeError::KIND_RANGE, $offset);
        }
        return $mag;
    }

    private static function countField(IonDoc $doc, int $id, string $name): int
    {
        $n = 0;
        $count = $doc->nodes[$id]->nchild;
        for ($i = 0; $i < $count; $i++) {
            $sym = $doc->field_at($id, $i);
            $s = $doc->syms[$sym];
            if ($s->text >= 0 && $doc->texts[$s->text] === $name) {
                $n++;
            }
        }
        return $n;
    }

    private static function field(IonDoc $doc, int $id, string $name): int
    {
        $count = $doc->nodes[$id]->nchild;
        for ($i = 0; $i < $count; $i++) {
            $sym = $doc->field_at($id, $i);
            $s = $doc->syms[$sym];
            if ($s->text >= 0 && $doc->texts[$s->text] === $name) {
                return $doc->child_at($id, $i);
            }
        }
        return -1;
    }

    private static function symTextOf(IonDoc $doc, int $id): string
    {
        $n = $doc->nodes[$id];
        if ($n->kind !== Kind::SYMBOL) {
            return '';
        }
        return $doc->sym_text($n->a);
    }
}
