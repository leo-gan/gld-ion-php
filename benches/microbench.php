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
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
    require $root . '/src/functions.php';
}

use Gld\Ion\Catalog;
use Gld\Ion\EncodeOptions;
use Gld\Ion\IonDoc;
use Gld\Ion\Kind;
use function Gld\Ion\decode;

function sample(): IonDoc
{
    $doc = new IonDoc();
    for ($i = 0; $i < 32; $i++) {
        $st = $doc->start_container(Kind::STRUCT);
        $name = $doc->add_symbol_text('id');
        $doc->add_child($st, $doc->add_i64($i), $doc->nodes[$name]->a);
        $label = $doc->add_symbol_text('name');
        $doc->add_child($st, $doc->add_string('item'), $doc->nodes[$label]->a);
        $doc->add_top($st);
    }
    return $doc;
}

$doc = sample();
$cat = new Catalog();
$opt = new EncodeOptions(true, false, 10);
$textOpt = new EncodeOptions(false, false, 10);
$n = 200;
for ($i = 0; $i < 20; $i++) {
    \Gld\Ion\encode($doc, $opt, $cat);
    \Gld\Ion\encode($doc, $textOpt, $cat);
}
$t0 = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    \Gld\Ion\encode($doc, $opt, $cat);
}
$binNs = intdiv(hrtime(true) - $t0, $n);
$t0 = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    \Gld\Ion\encode($doc, $textOpt, $cat);
}
$textNs = intdiv(hrtime(true) - $t0, $n);
$raw = \Gld\Ion\encode($doc, $opt, $cat);
$text = \Gld\Ion\encode($doc, $textOpt, $cat);
for ($i = 0; $i < 20; $i++) {
    \Gld\Ion\Wire\Binary::decode($raw, $cat);
    \Gld\Ion\Wire\Text::decode($text, $cat);
}
$t0 = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    \Gld\Ion\Wire\Binary::decode($raw, $cat);
}
$decBin = intdiv(hrtime(true) - $t0, $n);
$t0 = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    \Gld\Ion\Wire\Text::decode($text, $cat);
}
$decText = intdiv(hrtime(true) - $t0, $n);
echo "binary_encode_ns {$binNs}\n";
echo "text_encode_ns {$textNs}\n";
echo "binary_decode_ns {$decBin}\n";
echo "text_decode_ns {$decText}\n";
echo "binary_bytes " . strlen($raw) . "\n";
echo "text_bytes " . strlen($text) . "\n";
