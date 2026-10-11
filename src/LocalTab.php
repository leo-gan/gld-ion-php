<?php

declare(strict_types=1);

namespace Gld\Ion;

final class LocalTab
{
    public const SYS_MAX = 9;

    /** @var list<string> */
    public array $text = [''];

    /** @var list<bool> */
    public array $known = [false];

    /** @var list<string> */
    public array $names = [];

    /** @var list<TabSeg> */
    public array $segs = [];

    public int $maxId = self::SYS_MAX;

    public function __construct()
    {
        $this->segs[] = new TabSeg(1, self::SYS_MAX, 1, 0, -1);
    }

    public static function sysName(int $sid): string
    {
        return match ($sid) {
            1 => '$ion',
            2 => '$ion_1_0',
            3 => '$ion_symbol_table',
            4 => 'name',
            5 => 'version',
            6 => 'imports',
            7 => 'symbols',
            8 => 'max_id',
            9 => '$ion_shared_symbol_table',
            default => '',
        };
    }

    public static function sysSid(string $text): int
    {
        return match ($text) {
            '$ion' => 1,
            '$ion_1_0' => 2,
            '$ion_symbol_table' => 3,
            'name' => 4,
            'version' => 5,
            'imports' => 6,
            'symbols' => 7,
            'max_id' => 8,
            '$ion_shared_symbol_table' => 9,
            default => 0,
        };
    }

    public function reset_system(): void
    {
        if (count($this->segs) > 1) {
            $this->segs = [$this->segs[0]];
        }
        $this->text = [''];
        $this->known = [false];
        $this->names = [];
        $this->maxId = self::SYS_MAX;
        $this->segs[0]->count = self::SYS_MAX;
        $this->segs[0]->flatN = 0;
    }

    public function add_text(string $text): int
    {
        return $this->addFlat($text, true);
    }

    public function add_gap(): int
    {
        return $this->addFlat('', false);
    }

    private function addFlat(string $text, bool $isKnown): int
    {
        $lastI = count($this->segs) - 1;
        $last = $this->segs[$lastI];
        if ($last->nameAt < 0 && $last->start + $last->count === $this->maxId + 1 && $lastI > 0) {
            $this->text[] = $text;
            $this->known[] = $isKnown;
            $last->count++;
            $last->flatN++;
            $this->maxId++;
            return $this->maxId;
        }
        $seg = new TabSeg($this->maxId + 1, 1, count($this->text), 1, -1);
        $this->text[] = $text;
        $this->known[] = $isKnown;
        $this->segs[] = $seg;
        $this->maxId++;
        return $this->maxId;
    }

    public function sid_of(string $text): int
    {
        $sys = self::sysSid($text);
        if ($sys !== 0) {
            return $sys;
        }
        foreach ($this->segs as $seg) {
            for ($i = 0; $i < $seg->flatN; $i++) {
                $at = $seg->flatAt + $i;
                if ($this->known[$at] && $this->text[$at] === $text) {
                    return $seg->start + $i;
                }
            }
        }
        return 0;
    }

    public function lookup(int $sid, int $offset): Resolved
    {
        if ($sid < 0 || $sid > $this->maxId) {
            throw new DecodeError(DecodeError::KIND_SYMBOL, $offset);
        }
        if ($sid === 0) {
            return new Resolved();
        }
        if ($sid <= self::SYS_MAX) {
            return new Resolved(true, self::sysName($sid), '', 0);
        }
        foreach ($this->segs as $seg) {
            if ($sid >= $seg->start && $sid < $seg->start + $seg->count) {
                $off = $sid - $seg->start;
                if ($seg->nameAt < 0) {
                    $at = $seg->flatAt + $off;
                    if ($this->known[$at]) {
                        return new Resolved(true, $this->text[$at], '', 0);
                    }
                    return new Resolved();
                }
                if ($off < $seg->flatN && $this->known[$seg->flatAt + $off]) {
                    return new Resolved(true, $this->text[$seg->flatAt + $off], '', 0);
                }
                return new Resolved(false, '', $this->names[$seg->nameAt], $off + 1);
            }
        }
        throw new DecodeError(DecodeError::KIND_SYMBOL, $offset);
    }

    public function import_table(SharedTable $table, int $maxId, int $offset): void
    {
        if ($maxId < 0) {
            throw new DecodeError(DecodeError::KIND_SYMBOL, $offset);
        }
        $prefix = count($table->texts);
        if ($prefix > $maxId) {
            $prefix = $maxId;
        }
        $seg = new TabSeg($this->maxId + 1, $maxId, -1, 0, count($this->names));
        $this->names[] = $table->name;
        if ($prefix > 0) {
            $seg->flatAt = count($this->text);
            $seg->flatN = $prefix;
            for ($i = 0; $i < $prefix; $i++) {
                if ($table->gap[$i]) {
                    $this->text[] = '';
                    $this->known[] = false;
                } else {
                    $this->text[] = $table->texts[$i];
                    $this->known[] = true;
                }
            }
        }
        $this->segs[] = $seg;
        $this->maxId += $maxId;
    }
}
