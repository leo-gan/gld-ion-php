<?php

declare(strict_types=1);

namespace Gld\Ion;

use Gld\Ion\Wire\Binary;
use Gld\Ion\Wire\Text;
use Gld\Ion\Wire\Writer;

function decode(string $raw, ?Catalog $cat = null): IonDoc
{
    $cat ??= new Catalog();
    if (strlen($raw) >= 4
        && ord($raw[0]) === 0xE0
        && ord($raw[1]) === 0x01
        && ord($raw[3]) === 0xEA
    ) {
        return Binary::decode($raw, $cat);
    }
    return Text::decode($raw, $cat);
}

function encode(IonDoc $doc, ?EncodeOptions $options = null, ?Catalog $cat = null): string
{
    $options ??= new EncodeOptions();
    $cat ??= new Catalog();
    if ($options->binary) {
        return Writer::encodeBinary($doc, $options, $cat);
    }
    return Writer::encodeText($doc, $options);
}

function ion_eq(IonDoc $a, int $ai, IonDoc $b, int $bi): bool
{
    return Eq::ion($a, $ai, $b, $bi);
}

function docs_eq(IonDoc $a, IonDoc $b): bool
{
    return Eq::docs($a, $b);
}
