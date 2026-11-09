<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Upload;

/**
 * Short-tag code PHP could run if an upload were included (StyleSmuggler/Blockstrap planted-file chains).
 * Walks like PHP's lexer: strings and comments may hold any byte, code may not, so compressed images pass.
 */
class PhpCodeDetector
{
    private const CHUNK_BYTES = 1048576;
    /** Longest candidate walked; anything longer is treated as code (fail closed). */
    public const MAX_WALK = 65536;

    public function containsCode(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return true;
        }
        $buffer = '';
        try {
            while (true) {
                $chunk = (string)fread($handle, self::CHUNK_BYTES);
                $buffer .= $chunk;
                $final = feof($handle) || $chunk === '';
                // Candidates near the end are walked again with more data, unless this is the last chunk.
                $limit = $final ? strlen($buffer) : strlen($buffer) - self::MAX_WALK - 8;
                if ($this->scan($buffer, max(0, $limit), $final)) {
                    return true;
                }
                if ($final) {
                    return false;
                }
                $buffer = substr($buffer, max(0, $limit));
            }
        } finally {
            fclose($handle);
        }
    }

    /** True when a candidate starting before $limit is runnable code. */
    public function scan(string $data, int $limit, bool $final): bool
    {
        $offset = 0;
        while (($at = strpos($data, '<?', $offset)) !== false && $at < $limit) {
            $offset = $at + 2;
            $next = $data[$at + 2] ?? '';
            if ($next === '=') {
                $start = $at + 3;
            } elseif ($next === ' ' || $next === "\t" || $next === "\n" || $next === "\r") {
                // Counted even with short_open_tag off: another PHP pool or a later ini change could run them.
                $start = $at + 3;
            } else {
                continue;
            }
            if ($this->walk($data, $start, $final)) {
                return true;
            }
        }
        return false;
    }

    private function walk(string $data, int $i, bool $final): bool
    {
        $length = strlen($data);
        $stop = min($length, $i + self::MAX_WALK);
        while ($i < $stop) {
            $c = $data[$i];
            $o = ord($c);
            if ($c === '?' && ($data[$i + 1] ?? '') === '>') {
                return true;
            }
            if ($o < 9 || $o === 11 || $o === 12 || ($o > 13 && $o < 32) || $o === 127) {
                return false;
            }
            if ($c === '"' || $c === '`' || $c === "'") {
                $i = $this->closeQuoted($data, $i + 1, $stop, $c);
            } elseif ($c === '/' && ($data[$i + 1] ?? '') === '*') {
                $end = strpos($data, '*/', $i + 2);
                $i = $end === false || $end >= $stop ? $stop : $end + 2;
            } elseif ($c === '#' || ($c === '/' && ($data[$i + 1] ?? '') === '/')) {
                // A line comment ends at a newline or at a closing tag, which PHP honours inside it.
                $newline = strpos($data, "\n", $i);
                $close = strpos($data, '?>', $i);
                if ($close !== false && $close < $stop && ($newline === false || $close < $newline)) {
                    return true;
                }
                $i = $newline === false || $newline >= $stop ? $stop : $newline + 1;
            } elseif ($c === '<' && substr($data, $i, 3) === '<<<') {
                $i = $this->closeHeredoc($data, $i + 3, $stop);
                if ($i < 0) {
                    return false;
                }
            } else {
                $i++;
            }
        }
        // Code running to the end of the file executes; an over-long walk fails closed.
        return $stop < $length || $final;
    }

    private function closeQuoted(string $data, int $i, int $stop, string $quote): int
    {
        while ($i < $stop) {
            $c = $data[$i];
            if ($c === '\\') {
                $i += 2;
                continue;
            }
            if ($c === $quote) {
                return $i + 1;
            }
            $i++;
        }
        return $stop;
    }

    /** @return int index after the terminator, $stop when unterminated, -1 when the opener is not valid PHP */
    private function closeHeredoc(string $data, int $i, int $stop): int
    {
        if (preg_match('/\G[ \t]*(["\']?)([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\1\r?\n/', $data, $m, 0, $i) !== 1) {
            return -1;
        }
        $label = preg_quote($m[2], '/');
        $body = $i + strlen($m[0]);
        $terminator = '/(?:^|\n)[ \t]*' . $label . '(?![A-Za-z0-9_\x80-\xff])/';
        if (preg_match($terminator, $data, $t, PREG_OFFSET_CAPTURE, $body) !== 1
            || $t[0][1] >= $stop
        ) {
            return $stop;
        }
        return $t[0][1] + strlen($t[0][0]);
    }
}
