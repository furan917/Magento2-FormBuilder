<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Form;

/** Rules for a form's identifier (its URL slug) and title; builder-model.js keeps a copy pinned by Test/fixtures/identifiers.json. */
class Identifier
{
    public const MAX_LENGTH = 64;
    public const PATTERN_BODY = '[a-z0-9][a-z0-9-]{0,' . (self::MAX_LENGTH - 1) . '}';
    public const MAX_TITLE_LENGTH = 255;

    public static function isValid(string $identifier): bool
    {
        return preg_match('/^' . self::PATTERN_BODY . '$/D', $identifier) === 1;
    }

    public static function isValidTitle(string $title): bool
    {
        $length = mb_strlen($title);
        return $length >= 1 && $length <= self::MAX_TITLE_LENGTH;
    }
}
