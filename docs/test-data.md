# Test data

The tests are split into a vendored corpus and a few local schemas.

## ion-tests

`testdata/ion-tests` is a copy of the upstream
[amazon-ion/ion-tests](https://github.com/amazon-ion/ion-tests) data files.
Those files keep their Apache-2.0 license. `testdata/ion-tests/LICENSE` and
`NOTICE` are the upstream texts. The library's own code stays MIT.

| Directory | What a passing run means |
| --- | --- |
| `iontestdata/good` | The file decodes. |
| `iontestdata/bad` | The file raises `DecodeError`. |
| `iontestdata/good/equivs` | Each top-level group contains equivalent values. `embedded_documents` groups are parsed again before the comparison. |
| `iontestdata/good/non-equivs` | Values in a group are pairwise not equivalent. |
| `catalog` | Shared symbol tables the suite loads before each file. |

`php tests/run.php` walks every `.ion` and `.10n` file in one process. A
failure throws, and the runner reports it. The current copy has 785 files.
The expected result is a final line `ok`.

The corpus covers Ion 1.0. Ion 1.1 behavior is checked in the same runner:
a delimited list, the decimal `7d0`, the year `1947T`, `set_symbols`, a macro
with a default, and text and binary round trips at version 11. Truncated
buffers must fail.

## Schemas

| File | What it generates |
| --- | --- |
| `testdata/schema/message.json` | `Message`, a closed JSON Schema object with Ion extensions for timestamp and blob. |
| `testdata/schema/point.ion` | `Point`, an Ion Schema struct with an optional int and a symbol. |

`scripts/check-generated.sh` writes those files again and diffs them against
`tests/generated`. A drift fails the check.

The runner fills a `Message` and a `Point`, encodes them, and decodes them
back. The timestamp text and the symbol text must come back unchanged.

## Microbench

`benches/microbench.php` encodes and decodes 32 top-level structs
`{id:N,name:"item"}`, 200 times, and prints nanoseconds per call. Binary is
Ion 1.0. Text is Ion 1.0. The byte counts are part of the output so a template
change is visible.
