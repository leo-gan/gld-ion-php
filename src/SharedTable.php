<?php

declare(strict_types=1);

namespace Gld\Ion;

final class SharedTable
{
    /** @var list<string> symbol id 1 is texts[0] */
    public array $texts = [];

    /** @var list<bool> */
    public array $gap = [];

    public function __construct(public string $name, public int $version)
    {
    }

    public function add(string $text, bool $isGap): void
    {
        $this->texts[] = $text;
        $this->gap[] = $isGap;
    }
}

