# Examples

The dynamic API and the generated API write the same Ion. Use the dynamic API
when the shape is not known ahead of time. Use a generated class when a schema
already names the fields.

## Dynamic document

`examples/encode_value.php` decodes one struct and writes it twice.

```php
use Gld\Ion\Catalog;
use Gld\Ion\EncodeOptions;
use function Gld\Ion\decode;
use function Gld\Ion\encode;

$cat = new Catalog();
$doc = decode("{a:1,b:\"hi\"}\n", $cat);
$text = encode($doc, new EncodeOptions(false, false, 10), $cat);
$binary = encode($doc, new EncodeOptions(true, false, 11), $cat);
echo "text bytes ", strlen($text), "\n";
echo "ion 1.1 binary bytes ", strlen($binary), "\n";
```

Run it from a checkout:

```bash
php examples/encode_value.php
```

Ion 1.0 text starts with `$ion_1_0`. Ion 1.1 binary starts with `E0 01 01 EA`
and stores the field name `a` as inline symbol text, so the stream has no
symbol table.

## Build a value without parsing

```php
use Gld\Ion\IonDoc;
use Gld\Ion\Kind;

$doc = new IonDoc();
$st = $doc->start_container(Kind::STRUCT);
$field = $doc->add_symbol_text("n");
$doc->add_child($st, $doc->add_i64(150), $doc->nodes[$field]->a);
$doc->add_top($st);
```

`Kind::STRUCT` is the struct kind. `add_i64` accepts any PHP `int`. Wider
integers go through `add_int` and a `BigInt`. `add_decimal` keeps the
coefficient and the exponent, including a negative zero. `add_time` keeps
precision and offset.

## Generated class

`testdata/schema/message.json` describes a closed object. After the generator
runs, `tests/generated/Message.php` has the fields below.

| Schema field | PHP field | Required |
| --- | --- | --- |
| `id` | `int` | yes |
| `name` | `string` | yes |
| `ok` | `bool` | no |
| `weight` | `float` | no |
| `note` | `string` | no |
| `tags` | `array` of strings | no |
| `when` | `string` holding Ion timestamp text | no |
| `payload` | `string` of blob bytes | no |

```php
use Gld\Ion\EncodeOptions;
use Gld\Ion\Generated\Message;

$msg = new Message();
$msg->id = 7;
$msg->name = "ada";
$msg->has_when = true;
$msg->when = "2023T";
$raw = $msg->encode_to(new EncodeOptions(true, false, 10));
$back = Message::decode_from($raw);
```

Optional fields use a `has_` flag. A missing flag omits the field. A closed
schema raises `DecodeError::KIND_SCHEMA` when a required field is absent or an
unknown field is present. `encoded_len` is `strlen` of `encode_to`.

`testdata/schema/point.ion` is Ion Schema. `label` is a symbol, so the
generated `string` holds Ion symbol text such as `foo`, not a quoted string.
`encode_to` with version `11` writes an Ion 1.1 binary struct.
