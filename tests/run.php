<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$auto = $root . '/vendor/autoload.php';
if (is_file($auto)) {
    require $auto;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Gld\\Ion\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $rel = substr($class, strlen($prefix));
        $file = $root . '/src/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
    require $root . '/src/functions.php';
}

use Gld\Ion\BigInt;
use Gld\Ion\Catalog;
use Gld\Ion\DecodeError;
use Gld\Ion\EncodeOptions;
use Gld\Ion\IonDoc;
use Gld\Ion\Kind;
use Gld\Ion\Schema\Schema;
use Gld\Ion\SharedTable;
use Gld\Ion\SymRef;
use function Gld\Ion\decode;
use function Gld\Ion\docs_eq;
use function Gld\Ion\encode;
use function Gld\Ion\ion_eq;

$fail = 0;

function check(bool $ok, string $msg): void
{
    global $fail;
    if (!$ok) {
        fwrite(STDERR, "FAIL {$msg}\n");
        $fail++;
    }
}

function roundTrip(string $text, int $version = 10): void
{
    $cat = new Catalog();
    $doc = decode($text, $cat);
    $againText = encode($doc, new EncodeOptions(false, false, $version), $cat);
    $back = decode($againText, $cat);
    check(docs_eq($doc, $back), "text round {$text}");
    $bin = encode($doc, new EncodeOptions(true, false, $version), $cat);
    $binBack = decode($bin, $cat);
    check(docs_eq($doc, $binBack), "binary round {$text} ver {$version}");
}

$big = BigInt::fromDigits('100000000000000000000', false);
check($big->toDec() === '100000000000000000000', 'bigint dec');
$be = BigInt::fromBe("\x01\x00", false, 0);
check($be->toDec() === '256', 'bigint be');
$neg = BigInt::fromU64(99, true);
check($neg->toDec() === '-99', 'bigint neg');

roundTrip("true\n");
roundTrip("false\n");
roundTrip("0\n");
roundTrip("-42\n");
roundTrip("9223372036854775807\n");
roundTrip("-9223372036854775808\n");
roundTrip("100000000000000000000\n");
roundTrip("1.5e0\n");
roundTrip("nan\n");
roundTrip("+inf\n");
roundTrip("-inf\n");
roundTrip("-0e0\n");
roundTrip("1.0\n");
roundTrip("1.00\n");
roundTrip("-0.00\n");
roundTrip("1d3\n");
roundTrip("2023T\n");
roundTrip("2007-02-23T12:14:33.079-08:00\n");
roundTrip("2007-02-23T\n");
roundTrip("\"hi\"\n");
roundTrip("\"a\\nb\"\n");
roundTrip("foo\n");
roundTrip("\"hello\"\n");
roundTrip("{{aGVsbG8=}}\n");
roundTrip("{{ \"hi\" }}\n");
roundTrip("[1, true, \"hi\"]\n");
roundTrip("(a 1)\n");
roundTrip("{b:1,a:2}\n");
roundTrip("foo::1\n");
roundTrip("null\n");
roundTrip("null.int\n");
roundTrip("null.string\n");
roundTrip("null.struct\n");
roundTrip("\$ion_1_0\n1\n", 10);
foreach ([
    "true\n", "false\n", "0\n", "-42\n", "1.5e0\n", "-0e0\n", "nan\n",
    "1.00\n", "-0.00\n", "2023T\n", "2007-02-23T12:14:33.079-08:00\n",
    "\"hi\"\n", "foo\n", "{{aGVsbG8=}}\n", "{{ \"hi\" }}\n",
    "[1, 2, 3]\n", "(a 1)\n", "{a:7}\n", "foo::1\n", "null.int\n",
] as $sample11) {
    roundTrip($sample11, 11);
}

$cat = new Catalog();
$marker = decode("\$ion_1_0\n1\n", $cat);
$encoded = encode($marker, new EncodeOptions(false, false, 10), $cat);
check(str_starts_with($encoded, "\$ion_1_0\n"), 'text ivm');
$bin = encode($marker, new EncodeOptions(true, false, 10), $cat);
check(substr($bin, 0, 4) === "\xE0\x01\x00\xEA", 'binary ivm 1.0');
$bin11 = encode($marker, new EncodeOptions(true, false, 11), $cat);
check(substr($bin11, 0, 4) === "\xE0\x01\x01\xEA", 'binary ivm 1.1');

$one = new IonDoc();
$one->add_top($one->add_decimal(BigInt::fromU64(7, false), false, 0));
$raw7 = encode($one, new EncodeOptions(true, false, 11), new Catalog());
check(bin2hex($raw7) === 'e00101ea720107', '1.1 decimal 7d0 ' . bin2hex($raw7));
$year = new IonDoc();
$when = new \Gld\Ion\IonTime();
$when->year = 1947;
$when->prec = Kind::PREC_YEAR;
$when->unknown = true;
$year->add_top($year->add_time($when));
$rawY = encode($year, new EncodeOptions(true, false, 11), new Catalog());
check(bin2hex($rawY) === 'e00101eaf7059b07', '1.1 1947T ' . bin2hex($rawY));

$delim = "\xE0\x01\x01\xEA\xF0\x61\x01\xEF";
$delimDoc = decode($delim, new Catalog());
check(count($delimDoc->top) === 1 && $delimDoc->nodes[$delimDoc->top[0]]->kind === Kind::LIST, '1.1 delimited list');
check($delimDoc->nodes[$delimDoc->top[0]]->nchild === 1, '1.1 delimited child');
$delimBack = encode($delimDoc, new EncodeOptions(true, false, 11), new Catalog());
check(docs_eq($delimDoc, decode($delimBack, new Catalog())), '1.1 delimited round');

$nop = decode("\xE0\x01\x00\xEA\x00\x20", new Catalog());
check(count($nop->top) === 1 && $nop->nodes[$nop->top[0]]->kind === Kind::INT, 'nop pad');

$sym = decode("\$ion_1_0\n\$ion_symbol_table::{symbols:[\"foo\"]}\n\$10\n", new Catalog());
check(count($sym->top) === 1 && $sym->sym_text($sym->nodes[$sym->top[0]]->a) === 'foo', 'symbol table');

$pairs = new IonDoc();
$idSym = $pairs->nodes[$pairs->add_symbol_text('id')]->a;
$nameSym = $pairs->nodes[$pairs->add_symbol_text('name')]->a;
for ($i = 0; $i < 32; $i++) {
    $pairs->add_top($pairs->add_pair_struct($idSym, $i, $nameSym, 'item'));
}
$pairBin = encode($pairs, new EncodeOptions(true, false, 10), new Catalog());
$pairText = encode($pairs, new EncodeOptions(false, false, 10), new Catalog());
check(strlen($pairBin) === 332 && strlen($pairText) === 639, 'pair payload size ' . strlen($pairBin) . ' ' . strlen($pairText));
check(docs_eq($pairs, decode($pairBin, new Catalog())), 'pair binary round');
check(docs_eq($pairs, decode($pairText, new Catalog())), 'pair text round');

$ann = decode("foo::bar::1\n", new Catalog());
check($ann->nodes[$ann->top[0]]->annN === 2, 'annotations');

$a = decode("{b:1,a:2}\n", new Catalog());
$b = decode("{a:2,b:1}\n", new Catalog());
check(docs_eq($a, $b), 'struct order');
$d1 = decode("1.0\n", new Catalog());
$d2 = decode("1.00\n", new Catalog());
check(!docs_eq($d1, $d2), 'decimal scale');
check($d1->nodes[$d1->top[0]]->d === -1 && $d2->nodes[$d2->top[0]]->d === -2, 'decimal exponents');
$n1 = decode("null.int\n", new Catalog());
$n2 = decode("null.string\n", new Catalog());
check(!docs_eq($n1, $n2), 'typed nulls');
$nz = decode("-0.00\n", new Catalog());
check($nz->nodes[$nz->top[0]]->kind === Kind::DECIMAL && $nz->nodes[$nz->top[0]]->c === 1 && $nz->nodes[$nz->top[0]]->d === -2, 'neg zero decimal');

$ts = decode("2007-02-23T12:14:33.079-08:00\n", new Catalog());
$when = $ts->times[$ts->nodes[$ts->top[0]]->a];
check($when->hour === 12 && $when->off === -480 && $when->unknown === false, 'timestamp fields');

$macro = decode("\$ion_1_1\n(:\$ion set_macros (point {x: (:?), y: (:? 0)}))\n(:point 5 (:))\n", new Catalog());
check(count($macro->top) === 1 && $macro->nodes[$macro->top[0]]->kind === Kind::STRUCT, 'macro struct');
check($macro->nodes[$macro->top[0]]->nchild === 2, 'macro fields');
$y = $macro->child_at($macro->top[0], 1);
check($macro->nodes[$y]->kind === Kind::INT && $macro->nodes[$y]->b === 0, 'macro default');

$symbols = decode("\$ion_1_1\n(:\$ion set_symbols \"foo\" \"bar\")\nfoo\n", new Catalog());
check($symbols->sym_text($symbols->nodes[$symbols->top[0]]->a) === 'foo', '1.1 set_symbols');

$bad = false;
try {
    decode("\xE0\x01\x00\xEA\x21", new Catalog());
} catch (DecodeError $e) {
    $bad = $e->kind === DecodeError::KIND_EOF;
}
check($bad, 'truncated binary int');

$bad = false;
try {
    decode("\xE0\x01\x01\xEA\xF0", new Catalog());
} catch (DecodeError $e) {
    $bad = true;
}
check($bad, 'truncated 1.1 delimited');

$bad = false;
try {
    decode("\"abc", new Catalog());
} catch (DecodeError $e) {
    $bad = true;
}
check($bad, 'truncated text string');

$bad = false;
try {
    decode("\xE0\x01\x02\xEA", new Catalog());
} catch (DecodeError $e) {
    $bad = $e->kind === DecodeError::KIND_VERSION;
}
check($bad, 'bad version');

$doc = new IonDoc();
$st = $doc->start_container(Kind::STRUCT);
$field = $doc->add_symbol_text('n');
$doc->add_child($st, $doc->add_i64(150), $doc->nodes[$field]->a);
$doc->add_top($st);
$blob = $doc->add_bytes(Kind::BLOB, "\x01\x02");
unset($blob);
check(ion_eq($doc, $st, decode(encode($doc, new EncodeOptions(true, false, 10)), new Catalog()), decode(encode($doc, new EncodeOptions(true, false, 10)), new Catalog())->top[0]), 'built struct');

$message = Schema::parseFile($root . '/testdata/schema/message.json');
check($message->find('Message') >= 0, 'message schema');
$point = Schema::parseFile($root . '/testdata/schema/point.ion');
check($point->find('Point') >= 0, 'point schema');
$rejected = false;
try {
    Schema::parseFile($root . '/testdata/invalid-schema/reject.ion');
} catch (DecodeError $e) {
    $rejected = true;
}
check($rejected, 'reject schema');

$gen = $root . '/tests/generated';
if (!is_dir($gen)) {
    mkdir($gen, 0777, true);
}
passthru(PHP_BINARY . ' ' . escapeshellarg($root . '/bin/gld-iongen-php') . ' --schema ' . escapeshellarg($root . '/testdata/schema/message.json') . ' --out ' . escapeshellarg($gen), $code);
check($code === 0, 'generate message');
passthru(PHP_BINARY . ' ' . escapeshellarg($root . '/bin/gld-iongen-php') . ' --schema ' . escapeshellarg($root . '/testdata/schema/point.ion') . ' --out ' . escapeshellarg($gen), $code);
check($code === 0, 'generate point');
require $gen . '/Message.php';
require $gen . '/Point.php';

$msg = new Gld\Ion\Generated\Message();
$msg->id = 7;
$msg->name = 'ada';
$msg->has_ok = true;
$msg->ok = true;
$msg->has_weight = true;
$msg->weight = 1.5;
$msg->has_note = true;
$msg->note = 'hi';
$msg->has_tags = true;
$msg->tags = ['a', 'b'];
$msg->has_when = true;
$msg->when = '2023T';
$msg->has_payload = true;
$msg->payload = "\x01\x02";
$opt = new EncodeOptions(true, false, 10);
$buf = $msg->encode_to($opt);
check($msg->encoded_len($opt) === strlen($buf), 'encoded_len');
$back = Gld\Ion\Generated\Message::decode_from($buf);
check($back->id === 7 && $back->name === 'ada' && $back->ok === true, 'message scalars');
check($back->has_weight && $back->weight === 1.5, 'message weight');
check($back->note === 'hi' && $back->tags === ['a', 'b'], 'message text');
check($back->when === '2023T', 'message when ' . $back->when);
check($back->payload === "\x01\x02", 'message blob');

$pt = new Gld\Ion\Generated\Point();
$pt->x = 3;
$pt->label = 'foo';
$buf = $pt->encode_to(new EncodeOptions(true, false, 11));
$got = Gld\Ion\Generated\Point::decode_from($buf);
check($got->x === 3 && $got->has_y === false && $got->label === 'foo', 'point ' . $got->label);

$closed = false;
try {
    Gld\Ion\Generated\Point::decode_from(encode(decode("{x:1,label:foo,z:1}\n"), new EncodeOptions(true, false, 10)));
} catch (DecodeError $e) {
    $closed = $e->kind === DecodeError::KIND_SCHEMA;
}
check($closed, 'closed schema');

directValueChecks();

// ion-tests corpus
$catPath = $root . '/testdata/ion-tests/catalog/catalog.ion';
$catDoc = decode((string) file_get_contents($catPath), new Catalog());
$catalog = loadCatalog($catDoc);
$base = $root . '/testdata/ion-tests/iontestdata';
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
$corpusFail = 0;
$corpusN = 0;
foreach ($iter as $file) {
    $path = $file->getPathname();
    if (!str_ends_with($path, '.ion') && !str_ends_with($path, '.10n')) {
        continue;
    }
    $corpusN++;
    $expectBad = str_contains($path, '/bad/');
    $equiv = str_contains($path, '/equivs/');
    $noneq = str_contains($path, '/non-equivs/');
    $ok = false;
    try {
        $data = (string) file_get_contents($path);
        $doc = str_ends_with($path, '.10n') ? \Gld\Ion\Wire\Binary::decode($data, $catalog) : \Gld\Ion\Wire\Text::decode($data, $catalog);
        if ($expectBad) {
            $ok = false;
        } elseif ($equiv || $noneq) {
            $ok = $doc->top !== [];
            foreach ($doc->top as $id) {
                if (!seqOk($doc, $id, $equiv, $catalog)) {
                    $ok = false;
                    break;
                }
            }
        } else {
            $ok = true;
        }
    } catch (DecodeError $e) {
        $ok = $expectBad;
    } catch (Throwable $e) {
        $ok = false;
        fwrite(STDERR, 'EXC ' . $path . ' ' . $e->getMessage() . "\n");
    }
    if (!$ok) {
        $corpusFail++;
        if ($corpusFail <= 40) {
            fwrite(STDERR, "CORPUS FAIL {$path}\n");
        }
    }
}
check($corpusFail === 0, "ion-tests {$corpusFail}/{$corpusN}");

if ($fail !== 0) {
    fwrite(STDERR, "{$fail} checks failed\n");
    exit(1);
}
fwrite(STDOUT, "ok\n");
exit(0);

function directValueChecks(): void
{
    $samples = [
        null,
        true,
        false,
        0,
        1,
        -1,
        255,
        256,
        -256,
        16777216,
        PHP_INT_MAX,
        PHP_INT_MIN,
        0.0,
        -0.0,
        1.5,
        -2.25,
        '',
        'hi',
        str_repeat('abc', 20),
        [],
        [0, 1, -4, 'z'],
        ['f_bool' => true, 'f_int32' => 7, 'f_int64' => 99, 'f_float64' => 1.25, 'f_string' => 'ada', 'f_bool_2' => false, 'f_int32_2' => -3, 'f_string_2' => ''],
        ['id' => 'd1', 'items' => [['sku' => 'a', 'qty' => 2, 'price_minor' => 50], ['sku' => 'bb', 'qty' => 0, 'price_minor' => -1]]],
    ];
    foreach ($samples as $i => $sample) {
        $raw = \Gld\Ion\encode_value($sample);
        check(directSame($sample, \Gld\Ion\decode_value($raw)), "direct round-trip {$i}");
        $doc = decode($raw, new Catalog());
        check(count($doc->top) === 1, "direct wire top {$i}");
        if (count($doc->top) === 1) {
            check(directSame($sample, directFromDoc($doc, $doc->top[0])), "direct wire decode {$i}");
        }
        $built = new IonDoc();
        $built->add_top(directPut($built, $sample));
        $legacy = encode($built, new EncodeOptions(true, false, 10), new Catalog());
        check(directSame($sample, \Gld\Ion\decode_value($legacy)), "direct reads writer {$i}");
    }
}

function directSame(mixed $a, mixed $b): bool
{
    if (is_array($a) || is_array($b)) {
        if (!is_array($a) || !is_array($b) || count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b) || !directSame($v, $b[$k])) {
                return false;
            }
        }
        return true;
    }
    if (is_float($a) || is_float($b)) {
        if (!is_float($a) || !is_float($b)) {
            return false;
        }
        if ($a === 0.0 && $b === 0.0) {
            return ((string) $a === (string) $b) || (pack('E', $a) === pack('E', $b));
        }
        return $a === $b;
    }
    return $a === $b;
}

function directFromDoc(IonDoc $doc, int $id): mixed
{
    $kind = $doc->nodes[$id]->kind;
    if ($kind === Kind::NULL) {
        return null;
    }
    if ($kind === Kind::BOOL) {
        return $doc->as_bool($id);
    }
    if ($kind === Kind::INT) {
        return $doc->as_i64($id);
    }
    if ($kind === Kind::FLOAT) {
        return $doc->as_f64($id);
    }
    if ($kind === Kind::STRING) {
        return $doc->as_string($id);
    }
    if ($kind === Kind::LIST) {
        $n = $doc->nodes[$id]->nchild;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = directFromDoc($doc, $doc->child_at($id, $i));
        }
        return $out;
    }
    if ($kind === Kind::STRUCT) {
        $n = $doc->nodes[$id]->nchild;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[$doc->field_name($id, $i)] = directFromDoc($doc, $doc->child_at($id, $i));
        }
        return $out;
    }
    throw new RuntimeException('direct kind ' . $kind);
}

function directPut(IonDoc $doc, mixed $value): int
{
    if ($value === null) {
        return $doc->add_null(0);
    }
    if (is_bool($value)) {
        return $doc->add_bool($value);
    }
    if (is_int($value)) {
        return $doc->add_i64($value);
    }
    if (is_float($value)) {
        return $doc->add_float(8, \Gld\Ion\FloatBits::f64($value));
    }
    if (is_string($value)) {
        return $doc->add_string($value);
    }
    if (!is_array($value)) {
        throw new RuntimeException('direct put');
    }
    if (array_is_list($value)) {
        $list = $doc->start_container(Kind::LIST);
        foreach ($value as $item) {
            $doc->add_child($list, directPut($doc, $item), -1);
        }
        return $list;
    }
    $st = $doc->start_container(Kind::STRUCT);
    foreach ($value as $key => $item) {
        $sym = $doc->add_symbol_text((string) $key);
        $doc->add_child($st, directPut($doc, $item), $doc->nodes[$sym]->a);
    }
    return $st;
}

function loadCatalog(IonDoc $doc): Catalog
{
    $cat = new Catalog();
    foreach ($doc->top as $id) {
        $n = $doc->nodes[$id];
        if ($n->annN === 0 || $n->kind !== Kind::STRUCT) {
            continue;
        }
        if ($doc->sym_text($doc->anns[$n->ann]) !== '$ion_shared_symbol_table') {
            continue;
        }
        $nameId = fieldOf($doc, $id, 'name');
        if ($nameId < 0 || $doc->nodes[$nameId]->kind !== Kind::STRING) {
            continue;
        }
        $version = 1;
        $ver = fieldOf($doc, $id, 'version');
        if ($ver >= 0 && $doc->nodes[$ver]->kind === Kind::INT) {
            $version = $doc->as_i64($ver);
        }
        $table = new SharedTable($doc->text_at($nameId), $version);
        $syms = fieldOf($doc, $id, 'symbols');
        if ($syms >= 0 && $doc->nodes[$syms]->kind === Kind::LIST) {
            $count = $doc->nodes[$syms]->nchild;
            for ($k = 0; $k < $count; $k++) {
                $el = $doc->child_at($syms, $k);
                if ($doc->nodes[$el]->kind === Kind::STRING) {
                    $table->add($doc->text_at($el), false);
                } else {
                    $table->add('', true);
                }
            }
        }
        $cat->add($table);
    }
    return $cat;
}

function fieldOf(IonDoc $doc, int $id, string $name): int
{
    $n = $doc->nodes[$id]->nchild;
    for ($i = 0; $i < $n; $i++) {
        if ($doc->field_name($id, $i) === $name) {
            return $doc->child_at($id, $i);
        }
    }
    return -1;
}

function seqOk(IonDoc $doc, int $id, bool $wantEq, Catalog $cat): bool
{
    $n = $doc->nodes[$id];
    if ($n->kind !== Kind::LIST && $n->kind !== Kind::SEXP) {
        return false;
    }
    if ($n->nchild < 2) {
        return false;
    }
    $embedded = $n->annN !== 0 && $doc->sym_text($doc->anns[$n->ann]) === 'embedded_documents';
    if ($embedded) {
        $first = null;
        for ($i = 0; $i < $n->nchild; $i++) {
            $el = $doc->child_at($id, $i);
            if ($doc->nodes[$el]->kind !== Kind::STRING) {
                return false;
            }
            $got = decode($doc->text_at($el), $cat);
            if ($i === 0) {
                $first = $got;
            } else {
                $eq = docs_eq($first, $got);
                if ($wantEq && !$eq) {
                    return false;
                }
                if (!$wantEq && $eq) {
                    return false;
                }
            }
        }
        return true;
    }
    for ($i = 1; $i < $n->nchild; $i++) {
        $eq = ion_eq($doc, $doc->child_at($id, 0), $doc, $doc->child_at($id, $i));
        if ($wantEq && !$eq) {
            return false;
        }
        if (!$wantEq && $eq) {
            return false;
        }
    }
    if (!$wantEq) {
        for ($a = 0; $a < $n->nchild; $a++) {
            for ($b = $a + 1; $b < $n->nchild; $b++) {
                if (ion_eq($doc, $doc->child_at($id, $a), $doc, $doc->child_at($id, $b))) {
                    return false;
                }
            }
        }
    }
    return true;
}
