<?php

declare(strict_types=1);

namespace Gld\Ion;

final class Eq
{
    public static function sym(IonDoc $a, int $sa, IonDoc $b, int $sb): bool
    {
        $x = $a->syms[$sa];
        $y = $b->syms[$sb];
        if ($x->text >= 0 && $y->text >= 0) {
            return $a->texts[$x->text] === $b->texts[$y->text];
        }
        if ($x->text >= 0 || $y->text >= 0) {
            return false;
        }
        $xImp = $x->impName >= 0;
        $yImp = $y->impName >= 0;
        if ($xImp || $yImp) {
            if (!($xImp && $yImp)) {
                return false;
            }
            return $a->texts[$x->impName] === $b->texts[$y->impName] && $x->impSid === $y->impSid;
        }
        return true;
    }

    public static function ion(IonDoc $a, int $ai, IonDoc $b, int $bi): bool
    {
        if (!self::anns($a, $ai, $b, $bi)) {
            return false;
        }
        $ak = $a->nodes[$ai]->kind;
        $bk = $b->nodes[$bi]->kind;
        if ($ak !== $bk) {
            return false;
        }
        $an = $a->nodes[$ai];
        $bn = $b->nodes[$bi];
        if ($ak === Kind::NULL) {
            return $an->a === $bn->a;
        }
        if ($ak === Kind::BOOL) {
            return $an->a === $bn->a;
        }
        if ($ak === Kind::INT) {
            return $an->c === $bn->c && self::limbs($a, $an->a, $an->b, $b, $bn->a, $bn->b);
        }
        if ($ak === Kind::FLOAT) {
            return self::floats($a, $ai, $b, $bi);
        }
        if ($ak === Kind::DECIMAL) {
            return $an->c === $bn->c && $an->d === $bn->d && self::limbs($a, $an->a, $an->b, $b, $bn->a, $bn->b);
        }
        if ($ak === Kind::TIMESTAMP) {
            return self::times($a, $ai, $b, $bi);
        }
        if ($ak === Kind::STRING) {
            return $a->texts[$an->a] === $b->texts[$bn->a];
        }
        if ($ak === Kind::SYMBOL) {
            return self::sym($a, $an->a, $b, $bn->a);
        }
        if ($ak === Kind::BLOB || $ak === Kind::CLOB) {
            return $a->blob_bytes($ai) === $b->blob_bytes($bi);
        }
        if ($ak === Kind::LIST || $ak === Kind::SEXP) {
            if ($an->nchild !== $bn->nchild) {
                return false;
            }
            $ea = $an->child;
            $eb = $bn->child;
            for ($i = 0; $i < $an->nchild; $i++) {
                if (!self::ion($a, $a->edgeChild[$ea], $b, $b->edgeChild[$eb])) {
                    return false;
                }
                $ea = $a->edgeNext[$ea];
                $eb = $b->edgeNext[$eb];
            }
            return true;
        }
        if ($ak === Kind::STRUCT) {
            return self::structs($a, $ai, $b, $bi);
        }
        return false;
    }

    public static function docs(IonDoc $a, IonDoc $b): bool
    {
        if (count($a->top) !== count($b->top)) {
            return false;
        }
        foreach ($a->top as $i => $id) {
            if (!self::ion($a, $id, $b, $b->top[$i])) {
                return false;
            }
        }
        return true;
    }

    private static function anns(IonDoc $a, int $ai, IonDoc $b, int $bi): bool
    {
        $an = $a->nodes[$ai];
        $bn = $b->nodes[$bi];
        if ($an->annN !== $bn->annN) {
            return false;
        }
        for ($i = 0; $i < $an->annN; $i++) {
            if (!self::sym($a, $a->anns[$an->ann + $i], $b, $b->anns[$bn->ann + $i])) {
                return false;
            }
        }
        return true;
    }

    private static function limbs(IonDoc $a, int $at, int $n, IonDoc $b, int $bt, int $bn): bool
    {
        if ($n !== $bn) {
            return false;
        }
        for ($i = 0; $i < $n; $i++) {
            if ($a->limbs[$at + $i] !== $b->limbs[$bt + $i]) {
                return false;
            }
        }
        return true;
    }

    private static function floats(IonDoc $a, int $ai, IonDoc $b, int $bi): bool
    {
        $aw = $a->nodes[$ai]->a;
        $bw = $b->nodes[$bi]->a;
        $ab = $a->floats[$a->nodes[$ai]->b];
        $bb = $b->floats[$b->nodes[$bi]->b];
        $aNan = FloatBits::isNan($aw, $ab);
        $bNan = FloatBits::isNan($bw, $bb);
        if ($aNan || $bNan) {
            return $aNan && $bNan;
        }
        $aNeg = FloatBits::isNegZero($aw, $ab);
        $bNeg = FloatBits::isNegZero($bw, $bb);
        if ($aNeg || $bNeg) {
            return $aNeg && $bNeg;
        }
        return FloatBits::toFloat($aw, $ab) == FloatBits::toFloat($bw, $bb);
    }

    private static function times(IonDoc $a, int $ai, IonDoc $b, int $bi): bool
    {
        $x = $a->times[$a->nodes[$ai]->a];
        $y = $b->times[$b->nodes[$bi]->a];
        if ($x->prec !== $y->prec || $x->unknown !== $y->unknown) {
            return false;
        }
        if (!$x->unknown && $x->off !== $y->off) {
            return false;
        }
        if ($x->year !== $y->year || $x->month !== $y->month || $x->day !== $y->day) {
            return false;
        }
        if ($x->hour !== $y->hour || $x->minute !== $y->minute || $x->second !== $y->second) {
            return false;
        }
        if ($x->fracExp !== $y->fracExp) {
            return false;
        }
        return self::limbs($a, $x->fracAt, $x->fracLen, $b, $y->fracAt, $y->fracLen);
    }

    private static function structs(IonDoc $a, int $ai, IonDoc $b, int $bi): bool
    {
        $n = $a->nodes[$ai]->nchild;
        if ($n !== $b->nodes[$bi]->nchild) {
            return false;
        }
        $aChild = [];
        $aField = [];
        $bChild = [];
        $bField = [];
        $e = $a->nodes[$ai]->child;
        for ($i = 0; $i < $n; $i++) {
            $aChild[] = $a->edgeChild[$e];
            $aField[] = $a->edgeField[$e];
            $e = $a->edgeNext[$e];
        }
        $e = $b->nodes[$bi]->child;
        for ($i = 0; $i < $n; $i++) {
            $bChild[] = $b->edgeChild[$e];
            $bField[] = $b->edgeField[$e];
            $e = $b->edgeNext[$e];
        }
        $used = array_fill(0, $n, false);
        for ($i = 0; $i < $n; $i++) {
            $found = false;
            for ($j = 0; $j < $n; $j++) {
                if (!$used[$j] && self::sym($a, $aField[$i], $b, $bField[$j]) && self::ion($a, $aChild[$i], $b, $bChild[$j])) {
                    $used[$j] = true;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }
}
