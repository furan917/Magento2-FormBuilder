<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Console\Command;

use Furan\FormBuilder\Model\Webhook\SecretReencrypter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WebhookReencryptCommand extends Command
{
    private const DEFAULT_BATCH = 200;
    private const MAX_BATCH = 5000;

    public function __construct(private readonly SecretReencrypter $reencrypter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('furanform:webhooks:reencrypt')
            ->setDescription('Re-encrypt webhook endpoint secrets with the newest crypt key')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per batch (1-5000)', self::DEFAULT_BATCH);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch = (int)$input->getOption('batch-size');
        if ($batch < 1 || $batch > self::MAX_BATCH) {
            $output->writeln(sprintf('<error>--batch-size must be between 1 and %d.</error>', self::MAX_BATCH));
            return Command::FAILURE;
        }
        $stats = $this->reencrypter->run($batch);

        $output->writeln(sprintf('Scanned: %d', $stats['scanned']));
        $output->writeln(sprintf('Re-encrypted: %d', $stats['reencrypted']));
        $output->writeln(sprintf('Already on the newest key: %d', $stats['skipped']));
        if ($stats['failed'] === []) {
            return Command::SUCCESS;
        }
        $output->writeln(sprintf(
            '<error>Could not decrypt secrets of endpoint ids (left unchanged): %s</error>',
            implode(', ', $stats['failed'])
        ));
        return Command::FAILURE;
    }
}
