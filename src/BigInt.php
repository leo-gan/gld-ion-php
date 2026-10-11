<?php

declare(strict_types=1);

namespace Gld\Ion;

/**
 * Sign-and-magnitude integer. Limbs are little-endian base 2^32.
 * An empty limb list is zero. Negative zero is not representable here;
 * decimal negative zero keeps its sign on the node, not on the coefficient.
 */
final class BigInt
{
    /** @var list<int> */
    public array $limbs = [];

    public function __construct(public bool $neg = false)
    {
    }

    public static function fromLimbs(bool $neg, array $limbs): self
    {
        $b = new self(false);
        $b->limbs = array_values($limbs);
        $b->trim();
        $b->neg = $neg && $b->limbs !== [];
        return $b;
    }

    public function isZero(): bool
    {
        return $this->limbs === [];
    }

    public function trim(): void
    {
        $n = count($this->limbs);
        while ($n > 0 && $this->limbs[$n - 1] === 0) {
            array_pop($this->limbs);
            $n--;
        }
        if ($this->limbs === []) {
            $this->neg = false;
        }
    }

    public function cmpMag(self $other): int
    {
        $na = count($this->limbs);
        $nb = count($other->limbs);
        if ($na !== $nb) {
            return $na < $nb ? -1 : 1;
        }
        for ($i = $na - 1; $i >= 0; $i--) {
            if ($this->limbs[$i] < $other->limbs[$i]) {
                return -1;
            }
            if ($this->limbs[$i] > $other->limbs[$i]) {
                return 1;
            }
        }
        return 0;
    }

    public function mulSmall(int $m): void
    {
        if ($m === 0 || $this->limbs === []) {
            $this->limbs = [];
            $this->neg = false;
            return;
        }
        $carry = 0;
        $n = count($this->limbs);
        for ($i = 0; $i < $n; $i++) {
            $cur = $this->limbs[$i] * $m + $carry;
            $this->limbs[$i] = $cur & 0xFFFFFFFF;
            $carry = intdiv($cur, 0x100000000);
        }
        if ($carry !== 0) {
            $this->limbs[] = $carry;
        }
    }

    public function addSmall(int $m): void
    {
        $carry = $m;
        $i = 0;
        while ($carry !== 0) {
            if ($i === count($this->limbs)) {
                $this->limbs[] = $carry;
                return;
            }
            $cur = $this->limbs[$i] + $carry;
            $this->limbs[$i] = $cur & 0xFFFFFFFF;
            $carry = intdiv($cur, 0x100000000);
            $i++;
        }
    }

    public function toDec(): string
    {
        if ($this->limbs === []) {
            return '0';
        }
        $scratch = $this->limbs;
        $digits = '';
        while ($scratch !== []) {
            $rem = 0;
            for ($j = count($scratch) - 1; $j >= 0; $j--) {
                $cur = $rem * 4294967296 + $scratch[$j];
                $scratch[$j] = intdiv($cur, 10);
                $rem = $cur % 10;
            }
            $digits .= chr(48 + $rem);
            while ($scratch !== [] && $scratch[count($scratch) - 1] === 0) {
                array_pop($scratch);
            }
        }
        $out = $this->neg ? '-' : '';
        for ($k = strlen($digits) - 1; $k >= 0; $k--) {
            $out .= $digits[$k];
        }
        return $out;
    }

    /** Unsigned big-endian magnitude with no leading zero byte. Zero is empty. */
    public function toBe(): string
    {
        if ($this->limbs === []) {
            return '';
        }
        $out = '';
        $started = false;
        for ($i = count($this->limbs) - 1; $i >= 0; $i--) {
            $limb = $this->limbs[$i];
            for ($shift = 24; $shift >= 0; $shift -= 8) {
                $b = ($limb >> $shift) & 0xFF;
                if ($started || $b !== 0) {
                    $started = true;
                    $out .= chr($b);
                }
            }
        }
        return $out;
    }

    /** Little-endian magnitude with no trailing zero byte. Zero is empty. */
    public function toLe(): string
    {
        if ($this->limbs === []) {
            return '';
        }
        $out = '';
        foreach ($this->limbs as $limb) {
            $out .= chr($limb & 0xFF);
            $out .= chr(($limb >> 8) & 0xFF);
            $out .= chr(($limb >> 16) & 0xFF);
            $out .= chr(($limb >> 24) & 0xFF);
        }
        $n = strlen($out);
        while ($n > 1 && $out[$n - 1] === "\0") {
            $n--;
        }
        if ($n === 1 && $out[0] === "\0") {
            return '';
        }
        return substr($out, 0, $n);
    }

    public function toI64(int $offset): int
    {
        if (count($this->limbs) > 2) {
            throw new DecodeError(DecodeError::KIND_RANGE, $offset);
        }
        $lo = $this->limbs[0] ?? 0;
        $hi = $this->limbs[1] ?? 0;
        if (!$this->neg) {
            if ($hi > 0x7FFFFFFF) {
                throw new DecodeError(DecodeError::KIND_RANGE, $offset);
            }
            return ($hi << 32) | $lo;
        }
        if ($hi > 0x80000000 || ($hi === 0x80000000 && $lo !== 0)) {
            throw new DecodeError(DecodeError::KIND_RANGE, $offset);
        }
        if ($hi === 0x80000000 && $lo === 0) {
            return PHP_INT_MIN;
        }
        return -(($hi << 32) | $lo);
    }

    public static function fromU64(int $v, bool $neg): self
    {
        if ($v === 0) {
            return new self(false);
        }
        $b = new self(false);
        $b->limbs[] = $v & 0xFFFFFFFF;
        $hi = ($v >> 32) & 0xFFFFFFFF;
        if ($hi !== 0) {
            $b->limbs[] = $hi;
        }
        $b->neg = $neg;
        return $b;
    }

    public static function fromI64(int $value): self
    {
        if ($value === 0) {
            return new self(false);
        }
        if ($value === PHP_INT_MIN) {
            $b = new self(true);
            $b->limbs = [0, 0x80000000];
            return $b;
        }
        $neg = $value < 0;
        $mag = $neg ? -$value : $value;
        return self::fromU64($mag, $neg);
    }

    public static function fromDigits(string $digits, bool $neg): self
    {
        $acc = new self(false);
        $seen = false;
        $n = strlen($digits);
        for ($i = 0; $i < $n; $i++) {
            $d = ord($digits[$i]) - 48;
            if (!$seen && $d === 0) {
                continue;
            }
            $seen = true;
            $acc->mulSmall(10);
            $acc->addSmall($d);
        }
        if ($acc->limbs !== []) {
            $acc->neg = $neg;
        }
        return $acc;
    }

    public static function fromBe(string $raw, bool $neg, int $offset): self
    {
        $start = 0;
        $n = strlen($raw);
        while ($start < $n && $raw[$start] === "\0") {
            $start++;
        }
        if ($start === $n) {
            return new self(false);
        }
        if ($n - $start > 1048576) {
            throw new DecodeError(DecodeError::KIND_RANGE, $offset);
        }
        $limbs = [];
        $pos = $n;
        while ($pos > $start) {
            $take = $pos - $start;
            if ($take > 4) {
                $take = 4;
            }
            $chunk = 0;
            $base = $pos - $take;
            for ($k = 0; $k < $take; $k++) {
                $chunk = ($chunk << 8) | ord($raw[$base + $k]);
            }
            $limbs[] = $chunk;
            $pos -= $take;
        }
        return self::fromLimbs($neg, $limbs);
    }

    /** Little-endian two's-complement bytes. Returns magnitude and the sign. */
    public static function fromLeTwos(string $raw): self
    {
        $n = strlen($raw);
        if ($n === 0) {
            return new self(false);
        }
        $neg = (ord($raw[$n - 1]) & 0x80) !== 0;
        $mag = $raw;
        if ($neg) {
            $out = '';
            $carry = 1;
            for ($i = 0; $i < $n; $i++) {
                $inv = (ord($raw[$i]) ^ 0xFF) + $carry;
                $out .= chr($inv & 0xFF);
                $carry = $inv >> 8;
            }
            $mag = $out;
        }
        $limbs = [];
        for ($i = 0; $i < $n; $i += 4) {
            $chunk = 0;
            $take = min(4, $n - $i);
            for ($k = 0; $k < $take; $k++) {
                $chunk |= ord($mag[$i + $k]) << (8 * $k);
            }
            $limbs[] = $chunk;
        }
        return self::fromLimbs($neg, $limbs);
    }

    /** Two's-complement little-endian encoding, minimal width, sign bit correct. */
    public function toSignedLe(): string
    {
        $mag = $this->toLe();
        if ($mag === '' && !$this->neg) {
            return '';
        }
        $width = strlen($mag);
        if ($width === 0) {
            $width = 1;
        }
        while (true) {
            $raw = self::twos($mag, $width, $this->neg);
            $high = ord($raw[$width - 1]) & 128;
            if ($this->neg && $high !== 0) {
                return $raw;
            }
            if (!$this->neg && $high === 0) {
                return $raw;
            }
            $width++;
        }
    }

    public static function twos(string $mag, int $width, bool $neg): string
    {
        if (!$neg) {
            $out = $mag;
            if (strlen($out) < $width) {
                $out .= str_repeat("\0", $width - strlen($out));
            }
            return substr($out, 0, $width);
        }
        $out = '';
        $carry = 1;
        for ($i = 0; $i < $width; $i++) {
            $b = $i < strlen($mag) ? ord($mag[$i]) : 0;
            $inv = ($b ^ 255) + $carry;
            $out .= chr($inv & 255);
            $carry = $inv >> 8;
        }
        return $out;
    }
}
