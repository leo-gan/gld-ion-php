# Techniques

The implementation is an arena plus a few parsers. Nothing in the hot path
calls out to another Ion library.

## One arena, many lists

An `IonDoc` owns nodes, edges, strings, symbol records, limbs, float bits,
timestamps, and blob bytes. A node is a small object. Containers do not embed
children. Edges are three parallel int arrays. `add_child` appends one edge
and links it from the parent's tail, which keeps field order without a second
walk to find the end.

Sharing a scalar node is safe. Macro expansion does that: each use of a
template copies the container spine and points at the same integer or string
node. Annotations that the template adds are copied onto a fresh node so the
template itself does not change.

Depth is capped at 1024. A deeper list, s-expression, or struct raises
`DecodeError::KIND_DEPTH`.

## Symbol segments

A local table is a list of segments. The first segment is the nine system
symbols. A local declaration appends a segment whose text lives in the table.
An import appends a segment that records the shared table's real symbols and
a count for the unknown tail. The table does not allocate a string per unused
id, so a `max_id` in the hundreds of millions stays small.

`Catalog::exact` demands one name and one version. `Catalog::greatest` returns
the highest version of that name. Ion 1.0 import rules use greatest when
`max_id` is present and exact when it is not.

## Equivalence

`ion_eq` compares two nodes.

| Type | Rule |
| --- | --- |
| Annotations | Same texts, in order. |
| Struct | Same fields as a multiset. Order does not matter. |
| List and s-expression | Same values, in order. |
| Int | Same sign and the same limbs. |
| Decimal | Same sign, coefficient, and exponent. `1.0` is not `1.00`. |
| Float | Numeric value. All NaNs are equal. `-0` equals only `-0`. |
| Timestamp | Precision, offset, and every stored field. |
| Symbol | Known text, or the same unknown import. A local unknown equals symbol zero. |

## Ion 1.1 macros

A directive `(:$ion set_macros (point {x: (:?), y: (:? 0)}))` stores the struct
as a template. `(:?)` is a hole with no default. `(:? 0)` is a hole whose
default is the integer zero. `(:point 5 (:))` supplies `5` and an absent
second argument, so the expansion is `{x: 5, y: 0}`.

The binary form uses opcodes `0xE3` and `0xE4` for the directive, `0xE9` and
`0xEA` for placeholders, and `0x00` through `0x47` for a macro address. Opcode
`0xE0` inside an argument list is the absent argument. At the top level the
same byte begins a version marker.

## Repeated records

Top-level structs with one small integer and one plain string share a text or
binary template. The writer fills in the integer. The reader learns the
surrounding bytes from the first value and applies them to the following
values. Any other shape uses the general parser and writer. A miss does not
consume input.

## What the writer guarantees

Two encodes of one value with the same `EncodeOptions` produce the same bytes.
Binary output may differ from a particular input when that input used NOP
padding or different symbol ids. Ion 1.1 output prefers inline symbol text, so
it does not need a local symbol table. Ion 1.0 binary output emits one when
any non-system symbol appears.
