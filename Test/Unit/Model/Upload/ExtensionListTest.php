<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Upload;

use Furan\FormBuilder\Model\Upload\ExtensionList;
use PHPUnit\Framework\TestCase;

class ExtensionListTest extends TestCase
{
    public function testItKeepsEachPlainExtensionOnceInLowerCaseWithoutDots(): void
    {
        $this->assertSame(
            ['pdf', 'jpg'],
            (new ExtensionList())->normalise([' .PDF', 'jpg', 'pdf', '', '..', ['x'], null, 'p df', 'php.jpg'])
        );
    }

    public function testItNamesTheMalformedEntriesItDrops(): void
    {
        $this->assertSame(
            ['p df', 'php.jpg', 'x<'],
            (new ExtensionList())->malformed([' .PDF', 'jpg', '', '..', ['x'], null, 'p df', 'php.jpg', 'X<', 'p df'])
        );
    }
}
