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
use function Gld\Ion\decode;
use function Gld\Ion\encode;

$cat = new Catalog();
$doc = decode("{a:1,b:\"hi\"}\n", $cat);
$text = encode($doc, new EncodeOptions(false, false, 10), $cat);
$binary = encode($doc, new EncodeOptions(true, false, 11), $cat);
echo "text bytes " . strlen($text) . "\n";
echo "ion 1.1 binary bytes " . strlen($binary) . "\n";
