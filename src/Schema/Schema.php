<?php

declare(strict_types=1);

namespace Gld\Ion\Schema;

use Gld\Ion\Catalog;
use Gld\Ion\DecodeError;
use Gld\Ion\IonDoc;
use Gld\Ion\Kind;
use Gld\Ion\Wire\Text;

final class Schema
{
    public const TY_INT = 1;
    public const TY_BOOL = 2;
    public const TY_STRING = 3;
    public const TY_FLOAT = 4;
    public const TY_ION = 5;
    public const TY_BYTES = 6;
    public const TY_STRUCT = 7;
    public const TY_LIST = 8;

    /** @var list<string> */
    public array $typeName = [];
    /** @var list<int> */
    public array $typeKind = [];
    /** @var list<int> */
    public array $typeExpect = [];
    /** @var list<int> */
    public array $typeInner = [];
    /** @var list<string> */
    public array $typeRef = [];
    /** @var list<int> */
    public array $typeClosed = [];
    /** @var list<int> */
    public array $fieldAt = [];
    /** @var list<int> */
    public array $fieldN = [];
    /** @var list<string> */
    public array $fieldName = [];
    /** @var list<int> */
    public array $fieldType = [];
    /** @var list<bool> */
    public array $fieldReq = [];
    /** @var list<string> */
    public array $fieldRef = [];

    public function __construct()
    {
        $this->prim('int', self::TY_INT, 0);
        $this->prim('bool', self::TY_BOOL, 0);
        $this->prim('string', self::TY_STRING, 0);
        $this->prim('float', self::TY_FLOAT, 0);
        $this->prim('decimal', self::TY_ION, Kind::DECIMAL);
        $this->prim('timestamp', self::TY_ION, Kind::TIMESTAMP);
        $this->prim('symbol', self::TY_ION, Kind::SYMBOL);
        $this->prim('blob', self::TY_BYTES, Kind::BLOB);
        $this->prim('clob', self::TY_BYTES, Kind::CLOB);
        $this->prim('sexp', self::TY_ION, Kind::SEXP);
    }

    private function prim(string $name, int $kind, int $expect): void
    {
        $this->typeName[] = $name;
        $this->typeKind[] = $kind;
        $this->typeExpect[] = $expect;
        $this->typeInner[] = -1;
        $this->typeRef[] = '';
        $this->typeClosed[] = 0;
        $this->fieldAt[] = 0;
        $this->fieldN[] = 0;
    }

    public function find(string $name): int
    {
        foreach ($this->typeName as $i => $n) {
            if ($n === $name) {
                return $i;
            }
        }
        return -1;
    }

    public static function parseFile(string $path): self
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        $doc = Text::decode($data, new Catalog());
        return self::fromDoc($doc);
    }

    public static function fromDoc(IonDoc $doc): self
    {
        $schema = new self();
        if ($doc->top === []) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        $first = $doc->top[0];
        if (self::isJson($doc, $first)) {
            self::jsonRoot($doc, $schema, $first, 'Document');
        } else {
            foreach ($doc->top as $id) {
                self::registerIon($doc, $schema, $id);
            }
            foreach ($doc->top as $id) {
                self::fillIon($doc, $schema, $id);
            }
        }
        self::resolve($schema);
        return $schema;
    }

    private static function isJson(IonDoc $doc, int $id): bool
    {
        if ($doc->nodes[$id]->kind !== Kind::STRUCT) {
            return false;
        }
        if ($doc->nodes[$id]->annN !== 0) {
            return false;
        }
        return self::findField($doc, $id, 'properties') >= 0
            || self::findField($doc, $id, '$defs') >= 0
            || self::findField($doc, $id, 'definitions') >= 0;
    }

    private static function registerIon(IonDoc $doc, self $schema, int $id): void
    {
        if ($doc->nodes[$id]->kind !== Kind::STRUCT) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        $name = self::typeNameOf($doc, $id, '');
        if ($name === '') {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        if ($schema->find($name) < 0) {
            self::alloc($schema, $name, self::TY_STRUCT, 0, -1);
        }
    }

    private static function fillIon(IonDoc $doc, self $schema, int $id): void
    {
        $name = self::typeNameOf($doc, $id, '');
        $idx = $schema->find($name);
        if ($idx < 0) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        self::fillStruct($doc, $schema, $idx, $id, $name);
    }

    private static function jsonRoot(IonDoc $doc, self $schema, int $id, string $fallback): void
    {
        $defs = self::findField($doc, $id, '$defs');
        if ($defs < 0) {
            $defs = self::findField($doc, $id, 'definitions');
        }
        if ($defs >= 0) {
            $n = $doc->nodes[$defs]->nchild;
            for ($i = 0; $i < $n; $i++) {
                $nm = $doc->field_name($defs, $i);
                if ($schema->find($nm) < 0) {
                    self::alloc($schema, $nm, self::TY_STRUCT, 0, -1);
                }
            }
            for ($i = 0; $i < $n; $i++) {
                $child = $doc->child_at($defs, $i);
                $nm = $doc->field_name($defs, $i);
                $idx = $schema->find($nm);
                self::fillStruct($doc, $schema, $idx, $child, $nm);
            }
        }
        $name = self::typeNameOf($doc, $id, $fallback);
        $idx = $schema->find($name);
        if ($idx < 0) {
            $idx = self::alloc($schema, $name, self::TY_STRUCT, 0, -1);
        }
        self::fillStruct($doc, $schema, $idx, $id, $name);
    }

    private static function fillStruct(IonDoc $doc, self $schema, int $idx, int $id, string $parent): void
    {
        if ($schema->fieldN[$idx] !== 0) {
            return;
        }
        $closed = 0;
        $addl = self::findField($doc, $id, 'additionalProperties');
        if ($addl >= 0 && $doc->nodes[$addl]->kind === Kind::BOOL && $doc->nodes[$addl]->a === 0) {
            $closed = 1;
        }
        $content = self::findField($doc, $id, 'content');
        if ($content >= 0 && self::nodeText($doc, $content) === 'closed') {
            $closed = 1;
        }
        $schema->typeClosed[$idx] = $closed;
        $fields = self::findField($doc, $id, 'fields');
        if ($fields < 0) {
            $fields = self::findField($doc, $id, 'properties');
        }
        $required = self::findField($doc, $id, 'required');
        $at = count($schema->fieldName);
        $n = 0;
        if ($fields >= 0 && $doc->nodes[$fields]->kind === Kind::STRUCT) {
            $count = $doc->nodes[$fields]->nchild;
            for ($i = 0; $i < $count; $i++) {
                $fname = $doc->field_name($fields, $i);
                $child = $doc->child_at($fields, $i);
                $req = true;
                if ($required >= 0) {
                    $req = self::listHas($doc, $required, $fname);
                } elseif (self::occursOptional($doc, $child)) {
                    $req = false;
                }
                $tid = self::valueType($doc, $schema, $parent, $fname, $child);
                $schema->fieldName[] = $fname;
                $schema->fieldType[] = $tid;
                $schema->fieldReq[] = $req;
                $schema->fieldRef[] = $tid < 0 ? self::refName($doc, $child) : '';
                $n++;
            }
        }
        $schema->fieldAt[$idx] = $at;
        $schema->fieldN[$idx] = $n;
    }

    private static function valueType(IonDoc $doc, self $schema, string $parent, string $hint, int $id): int
    {
        if ($id < 0) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        $kind = $doc->nodes[$id]->kind;
        if ($kind === Kind::SYMBOL || $kind === Kind::STRING) {
            return self::lookup($schema, self::nodeText($doc, $id));
        }
        if ($kind !== Kind::STRUCT) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        $link = self::findField($doc, $id, '$ref');
        if ($link >= 0) {
            return self::lookup($schema, self::last(self::nodeText($doc, $link)));
        }
        $label = '';
        $ionT = self::findField($doc, $id, 'ionType');
        if ($ionT >= 0) {
            $label = self::nodeText($doc, $ionT);
        } else {
            $ty = self::findField($doc, $id, 'type');
            if ($ty >= 0) {
                $label = self::nodeText($doc, $ty);
            }
        }
        if ($label === 'object' || $label === 'struct' || self::findField($doc, $id, 'properties') >= 0 || self::findField($doc, $id, 'fields') >= 0) {
            $nm = self::typeNameOf($doc, $id, $parent . '_' . $hint);
            $idx = $schema->find($nm);
            if ($idx < 0) {
                $idx = self::alloc($schema, $nm, self::TY_STRUCT, 0, -1);
            }
            self::fillStruct($doc, $schema, $idx, $id, $nm);
            return $idx;
        }
        if ($label === 'array' || $label === 'list') {
            $elem = self::findField($doc, $id, 'items');
            if ($elem < 0) {
                $elem = self::findField($doc, $id, 'element');
            }
            if ($elem < 0) {
                throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
            }
            $inner = self::valueType($doc, $schema, $parent, $hint . '_item', $elem);
            $listId = self::alloc($schema, '', self::TY_LIST, 0, $inner);
            if ($inner < 0) {
                $schema->typeRef[$listId] = self::refName($doc, $elem);
            }
            return $listId;
        }
        if ($label === 'sexp') {
            return 9;
        }
        if ($label === '') {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        return self::lookup($schema, $label);
    }

    private static function lookup(self $schema, string $name): int
    {
        if ($name === 'integer') {
            return 0;
        }
        if ($name === 'boolean') {
            return 1;
        }
        if ($name === 'number') {
            return 3;
        }
        return $schema->find($name);
    }

    private static function refName(IonDoc $doc, int $id): string
    {
        $kind = $doc->nodes[$id]->kind;
        if ($kind === Kind::SYMBOL || $kind === Kind::STRING) {
            return self::nodeText($doc, $id);
        }
        $link = self::findField($doc, $id, '$ref');
        if ($link >= 0) {
            return self::last(self::nodeText($doc, $link));
        }
        $ty = self::findField($doc, $id, 'type');
        if ($ty >= 0) {
            return self::nodeText($doc, $ty);
        }
        $elem = self::findField($doc, $id, 'element');
        if ($elem < 0) {
            $elem = self::findField($doc, $id, 'items');
        }
        if ($elem >= 0) {
            return self::refName($doc, $elem);
        }
        return '';
    }

    private static function occursOptional(IonDoc $doc, int $id): bool
    {
        if ($doc->nodes[$id]->kind !== Kind::STRUCT) {
            return false;
        }
        $occ = self::findField($doc, $id, 'occurs');
        return $occ >= 0 && self::nodeText($doc, $occ) === 'optional';
    }

    private static function resolve(self $schema): void
    {
        foreach ($schema->fieldType as $i => $tid) {
            if ($tid < 0) {
                $idx = $schema->find($schema->fieldRef[$i]);
                if ($idx < 0) {
                    throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
                }
                $schema->fieldType[$i] = $idx;
            }
        }
        foreach ($schema->typeKind as $i => $kind) {
            if ($kind === self::TY_LIST && $schema->typeInner[$i] < 0) {
                $idx = $schema->find($schema->typeRef[$i]);
                if ($idx < 0) {
                    throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
                }
                $schema->typeInner[$i] = $idx;
            }
        }
    }

    private static function alloc(self $schema, string $name, int $kind, int $expect, int $inner): int
    {
        $schema->typeName[] = $name;
        $schema->typeKind[] = $kind;
        $schema->typeExpect[] = $expect;
        $schema->typeInner[] = $inner;
        $schema->typeRef[] = '';
        $schema->typeClosed[] = 0;
        $schema->fieldAt[] = 0;
        $schema->fieldN[] = 0;
        return count($schema->typeName) - 1;
    }

    private static function typeNameOf(IonDoc $doc, int $id, string $fallback): string
    {
        foreach (['title', 'name'] as $key) {
            $at = self::findField($doc, $id, $key);
            if ($at >= 0) {
                $text = self::nodeText($doc, $at);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        $idField = self::findField($doc, $id, '$id');
        if ($idField >= 0) {
            return self::last(self::nodeText($doc, $idField));
        }
        return $fallback;
    }

    private static function last(string $text): string
    {
        $pos = strrpos($text, '/');
        if ($pos === false) {
            return $text;
        }
        return substr($text, $pos + 1);
    }

    private static function findField(IonDoc $doc, int $id, string $name): int
    {
        if ($id < 0 || $doc->nodes[$id]->kind !== Kind::STRUCT) {
            return -1;
        }
        $n = $doc->nodes[$id]->nchild;
        for ($i = 0; $i < $n; $i++) {
            if ($doc->field_name($id, $i) === $name) {
                return $doc->child_at($id, $i);
            }
        }
        return -1;
    }

    private static function nodeText(IonDoc $doc, int $id): string
    {
        $kind = $doc->nodes[$id]->kind;
        if ($kind === Kind::STRING) {
            return $doc->text_at($id);
        }
        if ($kind === Kind::SYMBOL) {
            return $doc->sym_text($doc->nodes[$id]->a);
        }
        return '';
    }

    private static function listHas(IonDoc $doc, int $id, string $name): bool
    {
        if ($doc->nodes[$id]->kind !== Kind::LIST) {
            return false;
        }
        $n = $doc->nodes[$id]->nchild;
        for ($i = 0; $i < $n; $i++) {
            if (self::nodeText($doc, $doc->child_at($id, $i)) === $name) {
                return true;
            }
        }
        return false;
    }
}
