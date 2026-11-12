<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Upload;

/** File extension lists as admins and schemas give them: lower case, trimmed, without leading dots. */
class ExtensionList
{
    private const PATTERN = '/^[a-z0-9]+$/D';

    /**
     * Each plain [a-z0-9]+ extension once; anything else is dropped.
     *
     * @param array<mixed> $extensions
     * @return list<string>
     */
    public function normalise(array $extensions): array
    {
        return array_values(array_filter($this->cleaned($extensions), static fn (string $e): bool => preg_match(self::PATTERN, $e) === 1));
    }

    /**
     * The non-empty entries normalise() drops, cleaned the same way, each once.
     *
     * @param array<mixed> $extensions
     * @return list<string>
     */
    public function malformed(array $extensions): array
    {
        return array_values(array_filter($this->cleaned($extensions), static fn (string $e): bool => preg_match(self::PATTERN, $e) !== 1));
    }

    /**
     * @param array<mixed> $extensions
     * @return array<string, string>
     */
    private function cleaned(array $extensions): array
    {
        $clean = [];
        foreach ($extensions as $extension) {
            $extension = is_scalar($extension) ? strtolower(ltrim(trim((string)$extension), '.')) : '';
            if ($extension !== '') {
                $clean[$extension] = $extension;
            }
        }
        return $clean;
    }
}
