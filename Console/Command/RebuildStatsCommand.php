<?php

declare(strict_types=1);

namespace Furan\FormBuilder\Console\Command;

use Furan\FormBuilder\Model\Stats\Rebuilder;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Long snapshots grow InnoDB undo history: run it off-peak. */
class RebuildStatsCommand extends Command
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly Rebuilder $rebuilder,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('furanform:analytics:rebuild')
            ->setDescription('Rebuild form analytics from the stored submissions')
            ->addOption('form', null, InputOption::VALUE_REQUIRED, 'Only rebuild this form ID')
            ->addOption(
                'reset-specs',
                null,
                InputOption::VALUE_NONE,
                'Re-derive the frozen series specs from the current schema before rebuilding'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('form');
        if ($option !== null && (!ctype_digit((string)$option) || (int)$option < 1)) {
            $output->writeln('<error>--form must be a positive form ID.</error>');
            return Command::FAILURE;
        }
        if ($option !== null && !$this->formExists((int)$option)) {
            $output->writeln(sprintf('<error>Form %d does not exist.</error>', (int)$option));
            return Command::FAILURE;
        }
        $failed = false;
        foreach ($option === null ? $this->formIds() : [(int)$option] as $formId) {
            try {
                $this->rebuilder->rebuild($formId, (bool)$input->getOption('reset-specs'));
                $output->writeln(sprintf('Rebuilt form %d', $formId));
            } catch (\Throwable $e) {
                $failed = true;
                $output->writeln(sprintf('<error>Form %d: %s</error>', $formId, $e->getMessage()));
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function formExists(int $formId): bool
    {
        return $this->resource->getConnection()->fetchOne(
            'SELECT form_id FROM ' . $this->resource->getTableName('furan_formbuilder_form') . ' WHERE form_id = ?',
            [$formId]
        ) !== false;
    }

    /** @return \Generator<int> */
    private function formIds(): \Generator
    {
        $last = 0;
        do {
            $ids = $this->resource->getConnection()->fetchCol(
                'SELECT form_id FROM ' . $this->resource->getTableName('furan_formbuilder_form')
                . ' WHERE form_id > ? ORDER BY form_id LIMIT ' . self::PAGE_SIZE,
                [$last]
            );
            foreach ($ids as $id) {
                yield (int)$id;
            }
            $last = (int)end($ids);
        } while (count($ids) === self::PAGE_SIZE);
    }
}
