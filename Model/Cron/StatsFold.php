<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Model\Cron;

use Furan\FormBuilder\Model\Stats\Folder;

class StatsFold
{
    private const BUDGET_SECONDS = 40;

    public function __construct(private readonly Folder $folder)
    {
    }

    public function execute(): void
    {
        $this->folder->run(self::BUDGET_SECONDS);
    }
}
