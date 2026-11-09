<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Console\Command;

use Furan\FormBuilder\Model\Submission\Rehasher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RehashSubmissionsCommand extends Command
{
    private const DEFAULT_BATCH = 500;
    private const MAX_BATCH = 5000;

    public function __construct(private readonly Rehasher $rehasher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('furanform:submissions:rehash')
            ->setDescription('Re-fingerprint submission emails with the newest crypt key')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per batch (1-5000)', self::DEFAULT_BATCH)
            ->addOption('form-id', null, InputOption::VALUE_REQUIRED, 'Only process this form');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch = (int)$input->getOption('batch-size');
        if ($batch < 1 || $batch > self::MAX_BATCH) {
            $output->writeln(sprintf('<error>--batch-size must be between 1 and %d.</error>', self::MAX_BATCH));
            return Command::FAILURE;
        }
        $formId = $input->getOption('form-id');
        if ($formId !== null && (!ctype_digit((string)$formId) || (int)$formId < 1)) {
            $output->writeln('<error>--form-id must be a positive form ID.</error>');
            return Command::FAILURE;
        }
        $stats = $this->rehasher->run($batch, $formId === null ? null : (int)$formId);

        $output->writeln(sprintf('Scanned: %d', $stats['scanned']));
        $output->writeln(sprintf('Re-keyed: %d', $stats['rekeyed']));
        $output->writeln(sprintf('Unresolved: %d', $stats['unresolved']));
        $output->writeln(sprintf('Respondent keys rewritten: %d', $stats['respondent_keys']));
        return Command::SUCCESS;
    }
}
