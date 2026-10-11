# Instructions

These steps assume PHP 8.3 or newer. The Composer package name is
`leo-gan/gld-ion-php`. The namespace is `Gld\Ion`. GMP and BCMath are not
required.

## Install from a checkout

```bash
git clone https://github.com/leo-gan/gld-ion-php.git
cd gld-ion-php
composer install
php tests/run.php
```

`composer.json` requires `php: >=8.3`. A local `.env` file may sit beside the
checkout. It is gitignored. The test runner does not read it. Do not commit it.

| Command | What it runs |
| --- | --- |
| `php tests/run.php` | Unit checks, generated classes, then every file in the vendored ion-tests corpus. |
| `php bin/gld-iongen-php --schema FILE --out DIR` | Writes one PHP class per struct. |
| `scripts/check-generated.sh` | Regenerates into a temp directory and diffs `tests/generated`. |
| `php benches/microbench.php` | Prints nanoseconds per encode and decode of a 32-struct document. |

## Decode and encode

```php
use Gld\Ion\Catalog;
use Gld\Ion\EncodeOptions;
use function Gld\Ion\decode;
use function Gld\Ion\encode;

$cat = new Catalog();
$doc = decode("{a:1}\n", $cat);
$raw = encode($doc, new EncodeOptions(true, false, 10), $cat);
echo strlen($raw), "\n";
```

`EncodeOptions` fields:

| Field | Meaning |
| --- | --- |
| `binary` | When true, `encode` writes binary Ion. Otherwise it writes UTF-8 text. |
| `pretty` | Indent text. Binary ignores it. |
| `version` | `10` for Ion 1.0, `11` for the Ion 1.1 draft. |
| `add_import(name, version)` | Shared table the Ion 1.0 binary writer imports. |

Four bytes `E0 01 ?? EA` select the binary decoder. Any other byte string is
text. The text decoder accepts UTF-8 and transcodes UTF-16 or UTF-32 when a
wide encoding is obvious from the first bytes. A leading UTF-8 BOM is rejected.
A non-empty binary stream must start with a version marker.

## Generate PHP

A JSON Schema file:

```bash
php bin/gld-iongen-php --schema testdata/schema/message.json --out tests/generated
```

An Ion Schema file is Ion text:

```bash
php bin/gld-iongen-php --schema testdata/schema/point.ion --out tests/generated
```

The generator writes one `.php` file per struct in the namespace
`Gld\Ion\Generated`. Load Composer's autoload, or include the generated file
after the library, before calling `encode_to` or `decode_from`.

## Document the package

```bash
pip install -r requirements-docs.txt
mkdocs build --strict
```

The site is Material for MkDocs with an indigo primary color and a teal accent.
