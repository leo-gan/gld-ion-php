<?php

declare(strict_types=1);

namespace Gld\Ion;

/**
 * How encode writes one document.
 *
 * version is 10 for Ion 1.0 or 11 for Ion 1.1. binary selects the binary
 * encoding. pretty inserts newlines and indentation in text. Imports are
 * shared symbol tables the Ion 1.0 binary writer imports, in order.
 */
final class EncodeOptions
{
    /** @var list<string> */
    public array $importNames = [];

    /** @var list<int> */
    public array $importVersions = [];

    public function __construct(
        public bool $binary = false,
        public bool $pretty = false,
        public int $version = 10,
    ) {
    }

    public function add_import(string $name, int $version): void
    {
        $this->importNames[] = $name;
        $this->importVersions[] = $version;
    }
}
