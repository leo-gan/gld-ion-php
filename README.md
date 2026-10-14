# gld-ion-php

A from-scratch Amazon Ion implementation for PHP 8.3.
The runtime and the code generator are written in PHP. They do not wrap, link,
or vendor ion-c, ion-java, ion-rust, the Ion PECL extension, or any other
C, C++, or Rust Ion library. They do not depend on an `amazon-ion` Composer package.

This repository is a standalone library. It is not part of any other project.

Documentation: [Why Ion](https://leo-gan.github.io/gld-ion-php/why-ion/),
[Instructions](https://leo-gan.github.io/gld-ion-php/instructions/),
[Examples](https://leo-gan.github.io/gld-ion-php/examples/),
[Techniques](https://leo-gan.github.io/gld-ion-php/techniques/),
[Test data](https://leo-gan.github.io/gld-ion-php/test-data/).

## Install

From a git checkout:

```bash
git clone https://github.com/leo-gan/gld-ion-php.git
cd gld-ion-php
composer install
php tests/run.php
```

Composer package name: `leo-gan/gld-ion-php`. PHP namespace: `Gld\Ion`.
Generator binary: `gld-iongen-php`.

After release `0.2.0` is on Packagist:

```bash
composer require leo-gan/gld-ion-php
```

Registration and the release workflow: [docs/publishing.md](docs/publishing.md).

Requires **PHP 8.3** or newer. `bcmath` and `gmp` are not required. Integers
and decimals use a small limb array inside the library.

## Tests

```bash
php tests/run.php
php benches/microbench.php
php examples/encode_value.php
```

`php tests/run.php` prints `ok` when the local checks and the vendored ion-tests
corpus pass. The design notes are in `DESIGN.md`. The Material site is built
with `mkdocs build --strict`.

## What it reads and writes

| Surface | Behavior |
| --- | --- |
| Ion 1.0 text and binary | Decode legal values, including annotations, typed nulls, symbol tables, NOP padding, and version markers. Encode compact text or length-prefixed binary. |
| Ion 1.1 draft | Little-endian values, inline symbols, delimited containers, directives, and template macros. |
| Numbers | Arbitrary-precision integers and decimals. Decimal scale is kept, so `1.0` and `1.00` stay distinct. |
| Timestamps | Instant, local offset, and precision from year through fractional seconds. |
| Schema | JSON Schema objects and Ion Schema `type` definitions. `gld-iongen-php` emits classes with `encoded_len`, `encode_to`, and `decode_from`. |

The encoder drops comments and whitespace. Two encodes of the same value match
when the options match. A catalog supplies shared symbol tables. Unknown
imported symbols stay unknown and compare by import name and import id.

## Layout

`src/functions.php` is the public facade (`decode`, `encode`). `src/IonDoc.php`
holds the document arena. `src/Wire` holds the text parser, the Ion 1.0 binary
parser, the Ion 1.1 reader and writer, and the encoders. `src/Schema` and
`src/Codegen` turn a schema into PHP.
