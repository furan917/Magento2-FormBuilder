<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Cron;

use Furan\FormBuilder\Model\Cron\StatsFold;
use Furan\FormBuilder\Model\Stats\Folder;
use PHPUnit\Framework\TestCase;

class StatsFoldTest extends TestCase
{
    public function testItRunsTheFolderWithAFortySecondBudget(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->expects($this->once())->method('run')->with(40);

        (new StatsFold($folder))->execute();
    }
}
