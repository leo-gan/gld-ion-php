<?php

declare(strict_types=1);

namespace Gld\Ion;

/** Ion value kinds and timestamp precisions. Null type 0 is untyped null. */
final class Kind
{
    public const NULL = 1;
    public const BOOL = 2;
    public const INT = 3;
    public const FLOAT = 4;
    public const DECIMAL = 5;
    public const TIMESTAMP = 6;
    public const STRING = 7;
    public const SYMBOL = 8;
    public const BLOB = 9;
    public const CLOB = 10;
    public const LIST = 11;
    public const SEXP = 12;
    public const STRUCT = 13;
    public const HOLE = 14;

    public const PREC_YEAR = 0;
    public const PREC_MONTH = 1;
    public const PREC_DAY = 2;
    public const PREC_MIN = 3;
    public const PREC_SEC = 4;
    public const PREC_FRAC = 5;

    public const MAX_DEPTH = 1024;
}
