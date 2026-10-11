<?php

declare(strict_types=1);

namespace Gld\Ion;

/** A decode or encode failure. `field` is 0 when the failure is not tied to one schema field. */
final class DecodeError extends \RuntimeException
{
    public const KIND_EOF = 1;
    public const KIND_SYNTAX = 2;
    public const KIND_RANGE = 3;
    public const KIND_UTF8 = 4;
    public const KIND_ESCAPE = 5;
    public const KIND_TYPE = 6;
    public const KIND_DEPTH = 7;
    public const KIND_SYMBOL = 8;
    public const KIND_SCHEMA = 9;
    public const KIND_VERSION = 10;

    public function __construct(
        public readonly int $kind,
        public readonly int $offset,
        public readonly int $field = 0,
    ) {
        parent::__construct("DecodeError(kind={$kind}, offset={$offset}, field={$field})");
    }
}
