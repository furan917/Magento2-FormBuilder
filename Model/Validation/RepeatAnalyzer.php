<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

/**
 * @phpstan-type Atom array{type: string, src: string, alts: list<list<mixed>>, min: int, max: int|null}
 *
 * Browsers have no backtrack limit, so patterns that can backtrack catastrophically are refused.
 * Mirrored in view/adminhtml/web/js/pattern-safety.js.
 */
class RepeatAnalyzer
{
    /** @var array<string, bool> */
    private array $matchCache = [];

    public function isRisky(string $pattern): bool
    {
        $chars = preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return true;
        }
        $i = 0;
        try {
            $alternatives = $this->parse($chars, $i);
        } catch (\InvalidArgumentException) {
            return true;
        }

        return !$this->checkAlternatives($alternatives);
    }

    /**
     * @param string[] $chars
     * @return list<list<Atom>> alternatives of atoms
     */
    private function parse(array $chars, int &$i): array
    {
        $alternatives = [];
        $sequence = [];
        $count = count($chars);
        while ($i < $count) {
            $char = $chars[$i];
            if ($char === ')') {
                break;
            }
            if ($char === '|') {
                $alternatives[] = $sequence;
                $sequence = [];
                $i++;
                continue;
            }
            $atom = $this->atom($chars, $i);
            [$min, $max] = $this->quantifier($chars, $i);
            $atom['min'] = $min;
            $atom['max'] = $max;
            $sequence[] = $atom;
        }
        $alternatives[] = $sequence;

        return $alternatives;
    }

    /**
     * @param string[] $chars
     * @return Atom
     */
    private function atom(array $chars, int &$i): array
    {
        $char = $chars[$i];
        $next = $chars[$i + 1] ?? '';
        if ($char === '\\') {
            if (preg_match('/^[1-9]$/', $next) === 1 || ($next === 'k' && ($chars[$i + 2] ?? '') === '<')) {
                throw new \InvalidArgumentException('back-reference');
            }
            if ($next === 'b' || $next === 'B') {
                $i += 2;
                return ['type' => 'zero', 'src' => '', 'alts' => [], 'min' => 1, 'max' => 1];
            }
            $end = $i + 2;
            if ((($next === 'p' || $next === 'P') && ($chars[$i + 2] ?? '') === '{')
                || ($next === 'u' && ($chars[$i + 2] ?? '') === '{')
            ) {
                $end = $this->indexOf($chars, '}', $i) + 1;
            } elseif ($next === 'x') {
                $end = $i + 4;
            } elseif ($next === 'u') {
                $end = $i + 6;
            } elseif ($next === 'c') {
                $end = $i + 3;
            }
            return $this->charAtom($chars, $i, $end);
        }
        if ($char === '[') {
            $j = $i + 1;
            if (($chars[$j] ?? '') === '^') {
                $j++;
            }
            while ($j < count($chars) && $chars[$j] !== ']') {
                $j += $chars[$j] === '\\' ? 2 : 1;
            }
            return $this->charAtom($chars, $i, $j + 1);
        }
        if ($char === '(') {
            $type = 'group';
            $skip = 1;
            if ($next === '?') {
                $kind = $chars[$i + 2] ?? '';
                $after = $chars[$i + 3] ?? '';
                if ($kind === '=' || $kind === '!' || ($kind === '<' && ($after === '=' || $after === '!'))) {
                    $type = 'zero';
                    $skip = $kind === '<' ? 4 : 3;
                } elseif ($kind === '<') {
                    $skip = $this->indexOf($chars, '>', $i) + 1 - $i;
                } else {
                    $skip = 3;
                }
            }
            $i += $skip;
            $alternatives = $this->parse($chars, $i);
            $i++;
            return ['type' => $type, 'src' => '', 'alts' => $alternatives, 'min' => 1, 'max' => 1];
        }
        if ($char === '^' || $char === '$') {
            $i++;
            return ['type' => 'zero', 'src' => '', 'alts' => [], 'min' => 1, 'max' => 1];
        }
        return $this->charAtom($chars, $i, $i + 1);
    }

    /**
     * @param string[] $chars
     * @return Atom
     */
    private function charAtom(array $chars, int &$i, int $end): array
    {
        $src = implode('', array_slice($chars, $i, max(1, $end - $i)));
        $i = max($i + 1, $end);

        return ['type' => 'char', 'src' => $src, 'alts' => [], 'min' => 1, 'max' => 1];
    }

    /** @param string[] $chars */
    private function indexOf(array $chars, string $needle, int $from): int
    {
        $count = count($chars);
        for ($j = $from; $j < $count; $j++) {
            if ($chars[$j] === $needle) {
                return $j;
            }
        }
        return $count;
    }

    /**
     * @param string[] $chars
     * @return array{0: int, 1: int|null} min and max repeats; max null = unbounded
     */
    private function quantifier(array $chars, int &$i): array
    {
        $char = $chars[$i] ?? '';
        $range = [1, 1];
        if ($char === '*' || $char === '+' || $char === '?') {
            $range = $char === '*' ? [0, null] : ($char === '+' ? [1, null] : [0, 1]);
            $i++;
        } elseif ($char === '{') {
            $close = $this->indexOf($chars, '}', $i);
            $body = implode('', array_slice($chars, $i + 1, $close - $i - 1));
            if (preg_match('/^(\d+)(,(\d*))?$/D', $body, $m) !== 1) {
                return $range;
            }
            $max = isset($m[2]) ? (($m[3] ?? '') === '' ? null : (int)$m[3]) : (int)$m[1];
            $range = [(int)$m[1], $max];
            $i = $close + 1;
        } else {
            return $range;
        }
        if (($chars[$i] ?? '') === '?') {
            $i++;
        }
        return $range;
    }

    /**
     * @param list<list<Atom>> $alternatives
     */
    private function checkAlternatives(array $alternatives): bool
    {
        foreach ($alternatives as $sequence) {
            if (!$this->checkSequence($this->flatten($sequence))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Inlines plain single-alternative groups so (\d*)(\d*) is seen as \d*\d*.
     *
     * @param list<Atom> $sequence
     * @return list<Atom>
     */
    private function flatten(array $sequence): array
    {
        $out = [];
        foreach ($sequence as $atom) {
            if ($atom['type'] === 'group' && $atom['min'] === 1 && $atom['max'] === 1 && count($atom['alts']) === 1) {
                array_push($out, ...$this->flatten($atom['alts'][0]));
            } else {
                $out[] = $atom;
            }
        }
        return $out;
    }

    /**
     * @param list<Atom> $sequence
     */
    private function checkSequence(array $sequence): bool
    {
        $pending = [];
        foreach ($sequence as $atom) {
            if ($atom['alts'] !== [] && !$this->checkAlternatives($atom['alts'])) {
                return false;
            }
            if ($atom['type'] === 'zero') {
                continue;
            }
            $repeats = $atom['max'] === null || $atom['max'] > 1;
            if ($atom['type'] === 'group' && $repeats
                && (count($atom['alts']) > 1 || $this->hasAlternation($atom['alts']) || $this->hasUnbounded($atom['alts']))
            ) {
                return false;
            }
            $unbounded = $atom['max'] === null || $this->hasUnbounded($atom['alts']);
            if ($unbounded) {
                foreach ($pending as $previous) {
                    if ($this->overlaps($previous, $atom)) {
                        return false;
                    }
                }
            }
            if ($atom['min'] === 0) {
                if ($unbounded) {
                    $pending[] = $atom;
                }
            } else {
                $pending = $unbounded ? [$atom] : [];
            }
        }
        return true;
    }

    /**
     * @param list<list<Atom>> $alternatives
     */
    private function hasUnbounded(array $alternatives): bool
    {
        foreach ($alternatives as $sequence) {
            foreach ($sequence as $atom) {
                if ($atom['type'] !== 'zero' && ($atom['max'] === null || $this->hasUnbounded($atom['alts']))) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param list<list<Atom>> $alternatives
     */
    private function hasAlternation(array $alternatives): bool
    {
        foreach ($alternatives as $sequence) {
            foreach ($sequence as $atom) {
                if (count($atom['alts']) > 1 || $this->hasAlternation($atom['alts'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param Atom $atom
     * @return string[] sources of every single-character atom in the atom
     */
    private function charSources(array $atom): array
    {
        if ($atom['type'] === 'char') {
            return [$atom['src']];
        }
        $out = [];
        foreach ($atom['alts'] as $sequence) {
            foreach ($sequence as $child) {
                array_push($out, ...$this->charSources($child));
            }
        }
        return $out;
    }

    /**
     * @param Atom $left
     * @param Atom $right
     */
    private function overlaps(array $left, array $right): bool
    {
        foreach ($this->charSources($left) as $a) {
            foreach ($this->charSources($right) as $b) {
                if ($a === $b) {
                    return true;
                }
                foreach ($this->samples($a . $b) as $sample) {
                    if ($this->matchesOne($a, $sample) && $this->matchesOne($b, $sample)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** @return string[] printable ASCII, a few common non-ASCII letters, and every character the atoms name */
    private function samples(string $sources): array
    {
        $samples = ["\t", "\n", 'é', 'ß', 'Ж', '中', '😀'];
        for ($c = 0x20; $c <= 0x7E; $c++) {
            $samples[] = chr($c);
        }
        foreach (preg_split('//u', $sources, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $samples[] = $char;
        }
        return array_unique($samples);
    }

    private function matchesOne(string $source, string $sample): bool
    {
        $key = $source . "\x00" . $sample;
        // phpcs:ignore Generic.PHP.NoSilencedErrors -- an invalid admin pattern must report false, not a warning
        $this->matchCache[$key] ??= @preg_match("\x01^(?:" . $source . ")$\x01u", $sample) === 1;
        return $this->matchCache[$key];
    }
}
