<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

class PatternMatcher
{
    private const MAX_LENGTH = 256;
    private const BACKTRACK_LIMIT = '100000';
    private const JS_ESCAPES = 'dDwWsSbBnrtvfcxupPk';

    public function __construct(private readonly RepeatAnalyzer $repeatAnalyzer)
    {
    }

    public function matches(string $pattern, string $value): bool
    {
        $previous = ini_set('pcre.backtrack_limit', self::BACKTRACK_LIMIT);
        try {
            return $this->run($pattern, $value) === 1;
        } finally {
            if ($previous !== false) {
                ini_set('pcre.backtrack_limit', $previous);
            }
        }
    }

    public function isSafe(string $pattern): bool
    {
        if (strlen($pattern) > self::MAX_LENGTH || str_contains($pattern, "\x01")) {
            return false;
        }
        return $this->run($pattern, '') !== false
            && $this->compilesStandalone($pattern)
            && $this->isJavascriptCompatible($pattern)
            && !$this->repeatAnalyzer->isRisky($pattern);
    }

    private function compilesStandalone(string $pattern): bool
    {
        // phpcs:disable Generic.PHP.NoSilencedErrors -- an invalid admin pattern must report false, not a warning
        return @preg_match("\x01(?:" . $pattern . ")\x01u", '') !== false
            && @preg_match("\x01" . $pattern . "\x01u", '') !== false;
        // phpcs:enable Generic.PHP.NoSilencedErrors
    }

    /**
     * @return int|false
     */
    private function run(string $pattern, string $value)
    {
        // phpcs:ignore Generic.PHP.NoSilencedErrors -- an invalid admin pattern must report false, not a warning
        return @preg_match("\x01^(?:" . $pattern . ")$\x01uD", $value);
    }

    private function isJavascriptCompatible(string $pattern): bool
    {
        $inClass = false;
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\') {
                $next = $pattern[++$i] ?? '';
                if (ctype_alpha($next) && !str_contains(self::JS_ESCAPES, $next)) {
                    return false;
                }
                if ($next === 'x' && ($pattern[$i + 1] ?? '') === '{') {
                    return false;
                }
                if (($next === 'p' || $next === 'P') && ($close = strpos($pattern, '}', $i)) !== false) {
                    $i = $close;
                }
            } elseif ($inClass) {
                if ($char === '[' && ($pattern[$i + 1] ?? '') === ':') {
                    return false;
                }
                $inClass = $char !== ']';
            } elseif ($char === '[') {
                $inClass = true;
            } elseif ($char === '(' && !$this->isAllowedGroupStart(substr($pattern, $i + 1, 3))) {
                return false;
            } elseif ($char === '{' && ($pattern[$i + 1] ?? '') === ',') {
                return false;
            } elseif (str_contains('+*?}', $char) && ($pattern[$i + 1] ?? '') === '+') {
                return false;
            }
        }
        return true;
    }

    private function isAllowedGroupStart(string $after): bool
    {
        if ($after !== '' && $after[0] === '*') {
            return false;
        }
        if ($after === '' || $after[0] !== '?') {
            return true;
        }
        $kind = substr($after, 1);
        return $kind[0] === ':' || $kind[0] === '=' || $kind[0] === '!'
            || (preg_match('/^<[=!]/', $kind) === 1)
            || (preg_match('/^<[A-Za-z_]/', $kind) === 1);
    }
}
