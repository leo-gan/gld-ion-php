<?php

declare(strict_types=1);

namespace Gld\Ion\Codegen;

use Gld\Ion\DecodeError;
use Gld\Ion\Schema\Schema;

final class Emit
{
    /** @return array<string, string> class name => source */
    public static function all(Schema $schema): array
    {
        $out = [];
        foreach ($schema->typeKind as $i => $kind) {
            if ($kind === Schema::TY_STRUCT && $schema->typeName[$i] !== '') {
                $name = self::sanitize($schema->typeName[$i]);
                $out[$name] = self::struct($schema, $i, $name);
            }
        }
        if ($out === []) {
            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
        }
        return $out;
    }

    private static function struct(Schema $schema, int $idx, string $name): string
    {
        $src = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Gld\\Ion\\Generated;\n\n";
        $src .= "use Gld\\Ion\\DecodeError;\nuse Gld\\Ion\\EncodeOptions;\nuse Gld\\Ion\\FloatBits;\n";
        $src .= "use Gld\\Ion\\IonDoc;\nuse Gld\\Ion\\Kind;\n";
        $src .= "use function Gld\\Ion\\decode;\nuse function Gld\\Ion\\encode;\n\n";
        $src .= "final class {$name}\n{\n";
        $n = $schema->fieldN[$idx];
        for ($f = 0; $f < $n; $f++) {
            $at = $schema->fieldAt[$idx] + $f;
            $ident = self::sanitize($schema->fieldName[$at]);
            $tid = $schema->fieldType[$at];
            if (!$schema->fieldReq[$at]) {
                $src .= "    public bool \$has_{$ident} = false;\n";
            }
            $src .= "    public " . self::phpType($schema, $tid) . " \${$ident} = " . self::defaultValue($schema, $tid) . ";\n";
        }
        $src .= "\n    public function encoded_len(EncodeOptions \$options): int\n    {\n";
        $src .= "        return strlen(\$this->encode_to(\$options));\n    }\n\n";
        $src .= "    public function encode_to(EncodeOptions \$options): string\n    {\n";
        $src .= "        return encode(\$this->to_doc(), \$options);\n    }\n\n";
        $src .= "    public function to_doc(): IonDoc\n    {\n";
        $src .= "        \$doc = new IonDoc();\n        \$doc->add_top(\$this->write_into(\$doc));\n        return \$doc;\n    }\n\n";
        $src .= "    public function write_into(IonDoc \$doc): int\n    {\n";
        $src .= "        \$st = \$doc->start_container(Kind::STRUCT);\n";
        for ($f = 0; $f < $n; $f++) {
            $at = $schema->fieldAt[$idx] + $f;
            $src .= self::writeField($schema, $schema->fieldName[$at], $schema->fieldType[$at], $schema->fieldReq[$at]);
        }
        $src .= "        return \$st;\n    }\n\n";
        $src .= "    public static function decode_from(string \$raw): self\n    {\n";
        $src .= "        \$doc = decode(\$raw);\n";
        $src .= "        if (count(\$doc->top) !== 1) {\n";
        $src .= "            throw new DecodeError(DecodeError::KIND_TYPE, 0);\n        }\n";
        $src .= "        return self::from_doc(\$doc, \$doc->top[0]);\n    }\n\n";
        $src .= "    public static function from_doc(IonDoc \$doc, int \$id): self\n    {\n";
        $src .= "        if (\$doc->nodes[\$id]->kind !== Kind::STRUCT) {\n";
        $src .= "            throw new DecodeError(DecodeError::KIND_TYPE, 0);\n        }\n";
        $src .= "        \$out = new self();\n";
        for ($f = 0; $f < $n; $f++) {
            $at = $schema->fieldAt[$idx] + $f;
            if ($schema->fieldReq[$at]) {
                $ident = self::sanitize($schema->fieldName[$at]);
                $src .= "        \$got_{$ident} = false;\n";
            }
        }
        $src .= "        \$count = \$doc->nodes[\$id]->nchild;\n";
        $src .= "        for (\$i = 0; \$i < \$count; \$i++) {\n";
        $src .= "            \$fname = \$doc->field_name(\$id, \$i);\n";
        $src .= "            \$child = \$doc->child_at(\$id, \$i);\n";
        for ($f = 0; $f < $n; $f++) {
            $at = $schema->fieldAt[$idx] + $f;
            $ion = self::phpString($schema->fieldName[$at]);
            $ident = self::sanitize($schema->fieldName[$at]);
            $kw = $f === 0 ? 'if' : 'elseif';
            $src .= "            {$kw} (\$fname === {$ion}) {\n";
            $src .= self::readField($schema, $ident, $schema->fieldType[$at], $schema->fieldReq[$at]);
            $src .= "            }\n";
        }
        if ($n === 0) {
            $src .= "            unset(\$fname, \$child);\n";
        } elseif ($schema->typeClosed[$idx] !== 0) {
            $src .= "            else {\n                throw new DecodeError(DecodeError::KIND_SCHEMA, 0);\n            }\n";
        }
        $src .= "        }\n";
        for ($f = 0; $f < $n; $f++) {
            $at = $schema->fieldAt[$idx] + $f;
            if ($schema->fieldReq[$at]) {
                $ident = self::sanitize($schema->fieldName[$at]);
                $src .= "        if (!\$got_{$ident}) {\n";
                $src .= "            throw new DecodeError(DecodeError::KIND_SCHEMA, 0);\n        }\n";
            }
        }
        $src .= "        return \$out;\n    }\n}\n";
        return $src;
    }

    private static function writeField(Schema $schema, string $ionName, int $tid, bool $required): string
    {
        $ident = self::sanitize($ionName);
        $pad = '        ';
        $body = '';
        if (!$required) {
            $body .= $pad . "if (\$this->has_{$ident}) {\n";
            $pad = '            ';
        }
        $body .= $pad . "\$sym_{$ident} = \$doc->add_symbol_text(" . self::phpString($ionName) . ");\n";
        $body .= $pad . "\$slot_{$ident} = \$doc->nodes[\$sym_{$ident}]->a;\n";
        $body .= self::writeValue($schema, $tid, "\$this->{$ident}", "\$val_{$ident}", $pad);
        $body .= $pad . "\$doc->add_child(\$st, \$val_{$ident}, \$slot_{$ident});\n";
        if (!$required) {
            $body .= "        }\n";
        }
        return $body;
    }

    private static function writeValue(Schema $schema, int $tid, string $expr, string $dest, string $pad): string
    {
        $kind = $schema->typeKind[$tid];
        if ($kind === Schema::TY_INT) {
            return $pad . "{$dest} = \$doc->add_i64({$expr});\n";
        }
        if ($kind === Schema::TY_BOOL) {
            return $pad . "{$dest} = \$doc->add_bool({$expr});\n";
        }
        if ($kind === Schema::TY_STRING) {
            return $pad . "{$dest} = \$doc->add_string({$expr});\n";
        }
        if ($kind === Schema::TY_FLOAT) {
            return $pad . "{$dest} = \$doc->add_float(8, FloatBits::f64({$expr}));\n";
        }
        if ($kind === Schema::TY_ION) {
            return $pad . "{$dest} = \$doc->adopt_text({$expr}, " . $schema->typeExpect[$tid] . ");\n";
        }
        if ($kind === Schema::TY_BYTES) {
            return $pad . "{$dest} = \$doc->add_bytes(" . $schema->typeExpect[$tid] . ", {$expr});\n";
        }
        if ($kind === Schema::TY_STRUCT) {
            return $pad . "{$dest} = {$expr}->write_into(\$doc);\n";
        }
        if ($kind === Schema::TY_LIST) {
            $inner = $schema->typeInner[$tid];
            $slug = self::slug($dest);
            $line = $pad . "{$dest} = \$doc->start_container(Kind::LIST);\n";
            $line .= $pad . "foreach ({$expr} as \$item_{$slug}) {\n";
            $line .= self::writeValue($schema, $inner, "\$item_{$slug}", "\$one_{$slug}", $pad . '    ');
            $line .= $pad . "    \$doc->add_child({$dest}, \$one_{$slug}, -1);\n";
            $line .= $pad . "}\n";
            return $line;
        }
        throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
    }

    private static function readField(Schema $schema, string $ident, int $tid, bool $required): string
    {
        $body = self::readValue($schema, $tid, '$child', "\$out->{$ident}", '                ');
        if (!$required) {
            $body .= "                \$out->has_{$ident} = true;\n";
        } else {
            $body .= "                \$got_{$ident} = true;\n";
        }
        return $body;
    }

    private static function readValue(Schema $schema, int $tid, string $expr, string $dest, string $pad): string
    {
        $kind = $schema->typeKind[$tid];
        if ($kind === Schema::TY_INT) {
            return $pad . "{$dest} = \$doc->as_i64({$expr});\n";
        }
        if ($kind === Schema::TY_BOOL) {
            return $pad . "{$dest} = \$doc->as_bool({$expr});\n";
        }
        if ($kind === Schema::TY_STRING) {
            return $pad . "{$dest} = \$doc->as_string({$expr});\n";
        }
        if ($kind === Schema::TY_FLOAT) {
            return $pad . "{$dest} = \$doc->as_f64({$expr});\n";
        }
        if ($kind === Schema::TY_ION) {
            return $pad . "{$dest} = \$doc->ion_text({$expr}, " . $schema->typeExpect[$tid] . ");\n";
        }
        if ($kind === Schema::TY_BYTES) {
            return $pad . "{$dest} = \$doc->as_bytes({$expr}, " . $schema->typeExpect[$tid] . ");\n";
        }
        if ($kind === Schema::TY_STRUCT) {
            $nm = self::sanitize($schema->typeName[$tid]);
            return $pad . "{$dest} = {$nm}::from_doc(\$doc, {$expr});\n";
        }
        if ($kind === Schema::TY_LIST) {
            $inner = $schema->typeInner[$tid];
            $slug = self::slug($dest . $expr);
            $line = $pad . "if (\$doc->nodes[{$expr}]->kind !== Kind::LIST) {\n";
            $line .= $pad . "    throw new DecodeError(DecodeError::KIND_TYPE, 0);\n";
            $line .= $pad . "}\n";
            $line .= $pad . "\$n_{$slug} = \$doc->nodes[{$expr}]->nchild;\n";
            $line .= $pad . "for (\$j_{$slug} = 0; \$j_{$slug} < \$n_{$slug}; \$j_{$slug}++) {\n";
            $line .= $pad . "    \$el_{$slug} = \$doc->child_at({$expr}, \$j_{$slug});\n";
            $tmp = "\$one_{$slug}";
            $line .= self::readValue($schema, $inner, "\$el_{$slug}", $tmp, $pad . '    ');
            $line .= $pad . "    {$dest}[] = {$tmp};\n";
            $line .= $pad . "}\n";
            return $line;
        }
        throw new DecodeError(DecodeError::KIND_SCHEMA, 0);
    }

    private static function phpType(Schema $schema, int $tid): string
    {
        return match ($schema->typeKind[$tid]) {
            Schema::TY_INT => 'int',
            Schema::TY_BOOL => 'bool',
            Schema::TY_STRING, Schema::TY_ION, Schema::TY_BYTES => 'string',
            Schema::TY_FLOAT => 'float',
            Schema::TY_STRUCT => self::sanitize($schema->typeName[$tid]),
            Schema::TY_LIST => 'array',
            default => throw new DecodeError(DecodeError::KIND_SCHEMA, 0),
        };
    }

    private static function defaultValue(Schema $schema, int $tid): string
    {
        return match ($schema->typeKind[$tid]) {
            Schema::TY_INT => '0',
            Schema::TY_BOOL => 'false',
            Schema::TY_STRING, Schema::TY_ION, Schema::TY_BYTES => "''",
            Schema::TY_FLOAT => '0.0',
            Schema::TY_STRUCT => 'new ' . self::sanitize($schema->typeName[$tid]) . '()',
            Schema::TY_LIST => '[]',
            default => throw new DecodeError(DecodeError::KIND_SCHEMA, 0),
        };
    }

    private static function slug(string $text): string
    {
        $out = preg_replace('/[^A-Za-z0-9_]/', '_', $text) ?? 'v';
        if ($out === '' || ($out[0] >= '0' && $out[0] <= '9')) {
            $out = 'v_' . $out;
        }
        return $out;
    }

    public static function sanitize(string $name): string
    {
        $out = '';
        $n = strlen($name);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($name[$i]);
            $ok = ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || $c === 95;
            if ($i > 0 && $c >= 48 && $c <= 57) {
                $ok = true;
            }
            $out .= $ok ? chr($c) : '_';
        }
        if ($out === '') {
            $out = 'Value';
        }
        if ($out[0] >= '0' && $out[0] <= '9') {
            $out = 'T_' . $out;
        }
        $reserved = [
            'class', 'function', 'new', 'list', 'array', 'string', 'int', 'float', 'bool', 'null',
            'true', 'false', 'namespace', 'use', 'return', 'if', 'else', 'foreach', 'for', 'while',
            'switch', 'case', 'default', 'public', 'private', 'protected', 'static', 'self', 'parent',
            'echo', 'isset', 'empty', 'unset', 'clone', 'try', 'catch', 'finally', 'throw', 'interface',
            'trait', 'extends', 'implements', 'abstract', 'final', 'const', 'var', 'global', 'match',
            'enum', 'readonly', 'fn', 'yield', 'and', 'or', 'xor', 'as', 'break', 'continue', 'declare',
            'do', 'elseif', 'endif', 'goto', 'instanceof', 'print', 'exit', 'die', 'require', 'include',
        ];
        if (in_array(strtolower($out), $reserved, true)) {
            $out .= '_';
        }
        return $out;
    }

    private static function phpString(string $text): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $text) . "'";
    }
}
