# gld-ion-php design

gld-ion-php is a from-scratch Amazon Ion 1.0 and Ion 1.1 library for PHP 8.3.
The runtime never calls an Ion library written in another language. It does not
use the Ion PECL extension, GMP, or BCMath. Integers and decimals are a sign
plus little-endian `uint32` limbs. Zero has no limbs.

## Goals

The library decodes legal Ion 1.0 text and binary values and rejects the inputs
the ion-tests corpus marks as bad. Equivalence follows the Ion data model:
struct order does not matter, decimal scale does, and typed nulls stay typed.
Encoding is a semantic transcode. The caller chooses compact text or
length-prefixed binary, Ion 1.0 or the Ion 1.1 draft, and which shared symbol
tables the binary writer imports.

A second API generates PHP classes from a JSON Schema document or from an Ion
Schema `type` definition. Generated classes call the dynamic document API.
They expose `encoded_len`, `encode_to`, and `decode_from`.

## Document model

Values live in one `IonDoc` arena. A node is an `IonNode`: a kind and a handful
of integer fields. Strings, symbol text, integer limbs, float bits, timestamps,
and blob bytes sit in parallel lists. Container edges are three parallel int
arrays (`edgeChild`, `edgeField`, `edgeNext`), so a node never contains another
node.

| Kind | What the node stores |
| --- | --- |
| Null | The null's type, or `null.null` when the type is absent. |
| Int | Sign and little-endian `uint32` limbs. Zero has no limbs. |
| Decimal | Sign, coefficient limbs, and the base-10 exponent. Negative zero is a sign with an empty coefficient. |
| Float | Width 0, 2, 4, or 8, and the IEEE bits as a big-endian byte string. |
| Timestamp | Local calendar fields, offset minutes or unknown `-00:00`, and precision. |
| Symbol | Text when it is known. An unknown import stores the import name and the id inside that import. |
| Container | Head and tail edge indexes. Struct fields keep source order. |

Symbol tables are segments, not a dense array of every id. A shared import
with a huge `max_id` stores only the symbols the catalog actually defines.
The tail stays unknown. Lookup of an unknown local symbol compares equal to
symbol zero. Lookup of an unknown imported symbol compares the import name
and the import id.

PHP integers are signed 64-bit. `add_i64` stores values that fit. Wider
integers use `add_int` and `BigInt`. `PHP_INT_MIN` is two limbs.

## Ion 1.0

Text decoding accepts UTF-8 and transcodes a leading UTF-16 or UTF-32 body.
A leading UTF-8 BOM (`EF BB BF`) is rejected. The parser keeps annotations,
field order, decimal scale, and timestamp precision. It drops comments and
whitespace. A top-level `$ion_symbol_table` struct updates the local table.
A bare `$ion_1_0` or `$ion_1_1` is a version marker. The same token inside a
container, or used as an annotation, is a symbol. Text years are four digits.
`$ion_M_N` with an unknown version at the top level raises `KIND_VERSION`.

Binary decoding requires a version marker on a non-empty stream. The marker
is `E0 01 00 EA` for 1.0 and `E0 01 01 EA` for 1.1. Any other minor version
raises `KIND_VERSION`. An empty buffer decodes as an empty document. Integers,
decimals, and timestamps follow the big-endian Ion 1.0 layout. NOP padding is
skipped. A length of 15 is a typed null. A binary timestamp is UTC on the wire.
The reader converts minute-or-finer values to local time, including a date
change when the offset crosses midnight. Year, month, and day values force an
unknown offset, because a stored offset at that precision is superfluous.

The text writer emits `$ion_1_0` or `$ion_1_1`, then one value per line in
compact form, and a trailing newline. `EncodeOptions.pretty` inserts newlines
and indentation inside containers that are not on the compact two-field path.
The Ion 1.0 binary writer emits `E0 01 00 EA`, then a local symbol table when
any non-system symbol appears, then length-prefixed values. Field order,
annotations, decimal scale, and timestamp precision are preserved. The binary
timestamp writer converts local time back to UTC and shifts the calendar date
when needed. Local symbol ids start at 10, or just after the imported
`max_id` values when `add_import` is used.

Float text uses a trimmed scientific form. Equivalence is numeric, so a text
round trip may change the stored bits while the values still compare equal.
Binary float bits are preserved. Overflowing float text becomes signed
infinity.

## Ion 1.1 draft

The Ion 1.1 specification is still a draft. This library implements the binary
opcode map and the text forms that the draft uses for the same ideas.

| Feature | Reader | Writer |
| --- | --- | --- |
| Version marker `E0 01 01 EA` and `$ion_1_1` | Yes | Yes |
| Little-endian ints, floats, decimals | Yes | Yes |
| Inline symbol text and symbol addresses | Yes | Inline text |
| Length-prefixed and delimited containers | Yes | Length-prefixed; structs use FlexSym |
| Short-form and long-form timestamps | Yes | Long form, local time |
| `set_symbols` and `add_symbols` | Yes | Not emitted; symbols are inline |
| `set_macros` and `add_macros` | Yes | Not emitted; writers may always use literals |
| Tagged e-expressions, defaults, absent `(:)` | Yes | Not emitted |
| Binary tagless primitive arguments | Yes | Not emitted |
| `use` of a catalog module | Yes | `EncodeOptions.add_import` on Ion 1.0; inline symbols on Ion 1.1 |
| `module`, `import`, and `encoding` | Consumed at the top level | Not emitted |

A macro template is an s-expression `(name body)` stored in the caller's
document. Placeholders are hole nodes. Expansion copies containers and shares
scalar nodes. An absent argument with no default drops that field or element.
An absent argument with a default copies the default. A template that is only
a placeholder is rejected. A template may not call another macro.

An Ion version marker resets the symbol table and the macro table. In a 1.1
stream, a top-level Ion 1.0 symbol-table struct is a no-op. A delimited
container that reaches EOF without the `0xEF` terminator raises `KIND_EOF`.

Unknown symbols that have no text encode as symbol id 0 in binary and `$0` in
text. Comparison after a decode still uses the import name and import id.

## Schema and code generation

`gld-iongen-php --schema FILE --out DIR` reads one schema. JSON Schema is legal
Ion text, so the same decoder reads both files.

The JSON Schema subset is `type`, `properties`, `required`, `items`,
`additionalProperties`, `title`, `$id`, `$ref`, and `$defs`. `ionType` adds
`decimal`, `timestamp`, `symbol`, `blob`, `clob`, and `sexp`. An Ion Schema
file is a datagram of `type::{ name, type: struct, fields, content }` values.
`occurs: optional` marks a field that may be missing. `content: closed` rejects
unknown fields, as `additionalProperties: false` does for JSON Schema.

Generated classes live in `Gld\Ion\Generated`. Integers that fit in PHP `int`
use `int`. A wider integer raises `DecodeError::KIND_RANGE` through `as_i64`.
Decimals, timestamps, and symbols are stored as their Ion text so scale and
precision survive. Blobs and clobs are binary strings. Nested structs are
fields of the generated type. Lists are PHP arrays. Optional fields use a
`has_` flag.

`encode_to` builds an `IonDoc` and calls the same encoders as the dynamic API.
It returns the encoded string. `encoded_len` is the length of that encoding.
`decode_from` accepts text or binary and checks each field's type.

## Testing

`testdata/ion-tests` is a copy of the upstream ion-tests good, bad, equivs,
and non-equivs files, under the Apache-2.0 license in that directory. The
suite is `php tests/run.php`. It runs in one process. A file under `bad/` must
fail. Equivalence groups must agree. Non-equivalence groups must disagree.
Local checks cover big integers, text and binary round trips, annotations,
typed nulls, the Ion 1.0 version marker, one Ion 1.1 delimited container,
truncated buffers, macros, `set_symbols`, and generated structs.

## Packaging

The Composer package name is `leo-gan/gld-ion-php`. The PHP namespace is
`Gld\Ion`. The public facade is `Gld\Ion\decode`, `encode`, `ion_eq`, and
`docs_eq`. The generator binary is `gld-iongen-php`. Version `0.1.0` is
`composer.json` and `Gld\Ion\Version::NUMBER`. There is no conda package.
