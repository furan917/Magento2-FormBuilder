<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Upload;

use Furan\FormBuilder\Model\Upload\FilesNormalizer;
use PHPUnit\Framework\TestCase;

class FilesNormalizerTest extends TestCase
{
    public function testItNormalisesPerFieldAndTransposedPhpFileArraysIntoPerFieldUploads(): void
    {
        $expected = ['cv' => ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/x',
            'error' => 0, 'size' => 12]];
        $normalizer = new FilesNormalizer();

        $perField = ['cv' => ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/x',
            'error' => '0', 'size' => '12']];
        $this->assertSame($expected, $normalizer->normalize($perField));

        $transposed = ['name' => ['cv' => 'a.pdf'], 'type' => ['cv' => 'application/pdf'],
            'tmp_name' => ['cv' => '/tmp/x'], 'error' => ['cv' => 0], 'size' => ['cv' => 12]];
        $this->assertSame($expected, $normalizer->normalize($transposed));
    }

    public function testItDropsMalformedEntries(): void
    {
        $normalizer = new FilesNormalizer();
        $this->assertSame([], $normalizer->normalize('nope'));
        $this->assertSame([], $normalizer->normalize([
            0 => ['name' => 'a', 'tmp_name' => '/t', 'error' => 0],
            'multi' => ['name' => ['a', 'b'], 'tmp_name' => ['/a', '/b'], 'error' => [0, 0], 'size' => [1, 2]],
            'partial' => ['name' => 'a'],
        ]));
    }
}
