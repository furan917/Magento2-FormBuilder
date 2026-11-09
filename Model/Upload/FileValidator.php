<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Upload;

use Furan\FormBuilder\Model\Config;

class FileValidator
{
    private const BYTES_PER_MB = 1048576;
    private const CHUNK_BYTES = 1048576;
    // Polyglots hide code after valid image or PDF bytes, so the whole file is scanned, not just its head.
    private const MARKER_PATTERN = '/<\?php|<script|<html|__halt_compiler/i';
    private const TEXT_MARKER_PATTERN = '/<\?php|<\?=|<script|<html|__halt_compiler/i';
    private const TEXT_EXTENSIONS = ['txt', 'csv'];
    private const MARKER_OVERLAP = 32;
    private const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
    ];
    // Package entry an office file must contain; the XML itself is never parsed.
    private const PACKAGE_MARKERS = [
        'docx' => '[Content_Types].xml',
        'xlsx' => '[Content_Types].xml',
        'odt' => 'mimetype',
        'ods' => 'mimetype',
    ];
    private const ODF_TYPES = [
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    public function __construct(private readonly Config $config, private readonly PhpCodeDetector $codeDetector)
    {
    }

    /** @return string[] extensions whose content can be verified */
    public static function supportedExtensions(): array
    {
        return array_keys(self::MIME_MAP);
    }

    /**
     * @param array<string, mixed> $upload
     * @param array<string, mixed> $field
     */
    public function validate(array $upload, array $field): ?string
    {
        $error = $this->checkUploadError((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE));
        if ($error !== null) {
            return $error;
        }

        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '' || !$this->isUploadedFile($tmp) || !is_file($tmp)) {
            return (string)__('The file could not be uploaded.');
        }

        $maxBytes = $this->maxBytes($field);
        if ((int)filesize($tmp) > $maxBytes || (int)($upload['size'] ?? 0) > $maxBytes) {
            return (string)__('The file is too large.');
        }

        $extension = $this->extensionOf((string)($upload['name'] ?? ''));
        if ($extension === null || !in_array($extension, $this->allowedExtensions($field), true)
            || !$this->contentMatches($tmp, $extension)
        ) {
            return (string)__('This file type is not allowed.');
        }

        return null;
    }

    public function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? $mime : '';
    }

    private function extensionOf(string $name): ?string
    {
        if (preg_match('/[\x00-\x1f\x7f\/\\\\]|\p{Cf}/u', $name) === 1 || preg_match('//u', $name) !== 1) {
            return null;
        }
        // "x.php .jpg" or a no-break space before the dot must not hide the executable part.
        $parts = array_map(
            static fn (string $part): string => (string)preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $part),
            explode('.', mb_strtolower($name))
        );
        $extension = (string)array_pop($parts);
        // "cv.php.jpg" is refused outright: some servers run the first known extension.
        if ($extension === '' || $parts === [] || array_intersect($parts, Config::BLOCKED_EXTENSIONS) !== []) {
            return null;
        }

        return $extension;
    }

    protected function isUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    private function checkUploadError(int $code): ?string
    {
        return match ($code) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => (string)__('The file is too large.'),
            UPLOAD_ERR_NO_FILE => (string)__('Please choose a file.'),
            default => (string)__('The file could not be uploaded.'),
        };
    }

    /**
     * @param array<string, mixed> $field
     */
    public function maxBytes(array $field): int
    {
        $global = $this->config->getMaxUploadMb();
        $fieldMax = (int)($field['settings']['max_size_mb'] ?? 0);
        $mb = $fieldMax > 0 ? min($fieldMax, $global) : $global;

        return $mb * self::BYTES_PER_MB;
    }

    /**
     * @return string[]
     * @param array<string, mixed> $field
     */
    private function allowedExtensions(array $field): array
    {
        $global = $this->config->getAllowedExtensions();
        $fieldList = $field['settings']['extensions'] ?? [];
        if (!is_array($fieldList) || $fieldList === []) {
            return $global;
        }
        $fieldList = array_map(static fn($e): string => strtolower((string)$e), $fieldList);

        return array_values(array_intersect($global, $fieldList));
    }

    private function contentMatches(string $tmp, string $extension): bool
    {
        if (!in_array($this->detectMime($tmp), self::MIME_MAP[$extension] ?? [], true)) {
            return false;
        }

        if (isset(self::PACKAGE_MARKERS[$extension]) && !$this->isOfficePackage($tmp, $extension)) {
            return false;
        }

        $pattern = in_array($extension, self::TEXT_EXTENSIONS, true) ? self::TEXT_MARKER_PATTERN : self::MARKER_PATTERN;

        return !$this->containsMarkup($tmp, $pattern)
            && !$this->codeDetector->containsCode($tmp);
    }

    private function isOfficePackage(string $tmp, string $extension): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            if ($zip->locateName(self::PACKAGE_MARKERS[$extension]) === false) {
                return false;
            }
            if (!isset(self::ODF_TYPES[$extension])) {
                return true;
            }
            // The ODF mimetype entry is a short plain-text string.
            return trim((string)$zip->getFromName('mimetype', 128)) === self::ODF_TYPES[$extension];
        } finally {
            $zip->close();
        }
    }

    private function containsMarkup(string $path, string $pattern): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return true;
        }
        $tail = '';
        try {
            while (!feof($handle)) {
                $buffer = $tail . (string)fread($handle, self::CHUNK_BYTES);
                if (preg_match($pattern, $buffer) === 1) {
                    return true;
                }
                $tail = substr($buffer, -self::MARKER_OVERLAP);
            }
        } finally {
            fclose($handle);
        }
        return false;
    }
}
