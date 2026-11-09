<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

/**
 * Shared regexes. JS_* are the raw sources used verbatim by ffb-core.js; the PHP constants wrap them.
 * Whitespace is spelled out because PHP \s is ASCII-only while JS \s also matches NBSP and friends.
 */
class Patterns
{
    // A leading hyphen is refused: a sendmail transport can read such an address as an option.
    public const JS_EMAIL = '^[^\t\n\x0B\f\r @-][^\t\n\x0B\f\r @]*@[^\t\n\x0B\f\r @]+\.[^\t\n\x0B\f\r @]+$';
    public const JS_URL = '^https?:\/\/[^\t\n\x0B\f\r \/$.?#][^\t\n\x0B\f\r ]*$';
    public const JS_PHONE = '^\+?[0-9\t\n\x0B\f\r ().-]{6,20}$';
    public const JS_ALPHA = '^[\p{L}\t\n\x0B\f\r \'-]+$';
    public const JS_ALPHANUMERIC = '^[\p{L}\p{N}\t\n\x0B\f\r ]+$';
    public const JS_NUMERIC = '^-?\d+(\.\d+)?$';
    public const JS_INTEGER = '^-?\d+$';
    public const JS_DATE = '^\d{4}-\d{2}-\d{2}$';
    public const JS_DATETIME = '^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d$';
    public const JS_COLOR = '^#[0-9a-f]{6}$';
    public const JS_TIME = '^([01]\d|2[0-3]):[0-5]\d$';
    public const JS_MONTH = '^\d{4}-(0[1-9]|1[0-2])$';

    public const EMAIL = '/' . self::JS_EMAIL . '/uD';
    public const URL = '/' . self::JS_URL . '/uiD';
    public const PHONE = '/' . self::JS_PHONE . '/uD';
    public const ALPHA = '/' . self::JS_ALPHA . '/uD';
    public const ALPHANUMERIC = '/' . self::JS_ALPHANUMERIC . '/uD';
    public const NUMERIC = '/' . self::JS_NUMERIC . '/D';
    public const INTEGER = '/' . self::JS_INTEGER . '/D';
    public const DATE = '/' . self::JS_DATE . '/D';
    public const DATETIME = '/' . self::JS_DATETIME . '/D';
    public const COLOR = '/' . self::JS_COLOR . '/iD';
    public const TIME = '/' . self::JS_TIME . '/D';
    public const MONTH = '/' . self::JS_MONTH . '/D';
}
