<?php

declare(strict_types=1);

namespace Gld\Ion;

final class Catalog
{
    /** @var list<SharedTable> */
    public array $tables = [];

    public function add(SharedTable $table): void
    {
        $this->tables[] = $table;
    }

    public function exact(string $name, int $version): int
    {
        foreach ($this->tables as $i => $t) {
            if ($t->name === $name && $t->version === $version) {
                return $i;
            }
        }
        return -1;
    }

    public function greatest(string $name): int
    {
        $best = -1;
        $bestVer = -1;
        foreach ($this->tables as $i => $t) {
            if ($t->name === $name && $t->version > $bestVer) {
                $best = $i;
                $bestVer = $t->version;
            }
        }
        return $best;
    }
}

