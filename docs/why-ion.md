# Why Ion

Amazon Ion is a typed superset of JSON. A document can be text, which a person
can read, or binary, which is smaller and keeps the same values. One model
covers both.

JSON has objects, arrays, strings, numbers, booleans, and null. Ion adds
integers with arbitrary precision, decimals that remember their scale,
timestamps with a precision and a local offset, symbols, blobs, clobs,
s-expressions, annotations, and typed nulls. `1`, `1.0`, and `1.00` are three
different decimals. `null.int` is not the same value as `null.string`.

---

## Text and binary

Text Ion looks like JSON with extra tokens. Field names may be identifiers.
`//` and `/* */` are comments. A binary stream starts with a four-byte version
marker.

| Marker | Version |
| --- | --- |
| `E0 01 00 EA` | Ion 1.0 binary |
| `E0 01 01 EA` | Ion 1.1 binary |
| `$ion_1_0` | Ion 1.0 text |
| `$ion_1_1` | Ion 1.1 text |

Ion 1.0 binary is big-endian. The type and a short length share one byte.
Ion 1.1 binary is little-endian. An opcode says what follows, and symbol text
can sit inline so a symbol table is optional.

This library decodes both versions and encodes either one. The caller chooses
text or binary and the version. The encoder keeps annotations, field order,
decimal scale, and timestamp precision. It drops comments and whitespace.

---

## Symbols and shared tables

A symbol is not a string. The text `"foo"` and the symbol `foo` compare as
different types. Ion 1.0 binary stores symbols as integer ids. Ids 1 through 9
are the system symbols. A local symbol table at the top of a stream names the
rest and may import a shared table from a catalog.

Ion 1.1 replaces that struct with directives such as `set_symbols` and `use`.
The system module still occupies ids 1 through 9.

| Id | Text |
| --- | --- |
| 1 | `$ion` |
| 2 | `$ion_1_0` |
| 3 | `$ion_symbol_table` |
| 4 | `name` |
| 5 | `version` |
| 6 | `imports` |
| 7 | `symbols` |
| 8 | `max_id` |
| 9 | `$ion_shared_symbol_table` |

Unknown imported symbols stay unknown. They compare equal only when the import
name and the id inside that import match.

---

## Why a schema is optional

Ion can carry data with no schema. The dynamic `IonDoc` API is enough for that.
When a service does have a shape, JSON Schema or Ion Schema describes it, and
`gld-iongen-php` emits a PHP class. The class still encodes through the same
runtime, so the bytes are ordinary Ion.
