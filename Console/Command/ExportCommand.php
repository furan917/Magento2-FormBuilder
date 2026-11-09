<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Console\Command;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Definition\Exporter;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem\Driver\File;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends Command
{
    private const EXIT_OK = 0;
    private const EXIT_ERROR = 1;

    public function __construct(
        private readonly FormRepositoryInterface $formRepository,
        private readonly Exporter $exporter,
        private readonly FormResource $formResource,
        private readonly File $files
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('furanform:form:export')
            ->setDescription('Export a form definition as JSON')
            ->addArgument('form', InputArgument::REQUIRED, 'Form identifier or numeric ID')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ref = (string)$input->getArgument('form');
        try {
            $form = $this->load($ref);
        } catch (NoSuchEntityException) {
            $output->writeln(sprintf('<error>Form "%s" was not found.</error>', $ref));
            return self::EXIT_ERROR;
        }

        $json = json_encode(
            $this->exporter->export($form),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            $output->writeln(sprintf('<error>Form "%s" could not be encoded as JSON.</error>', $ref));
            return self::EXIT_ERROR;
        }

        $file = $input->getOption('output');
        if ($file === null) {
            $output->writeln($json, OutputInterface::OUTPUT_RAW);
            return self::EXIT_OK;
        }
        if (!$this->write((string)$file, $json . "\n")) {
            $output->writeln(sprintf('<error>Cannot write to "%s".</error>', $file));
            return self::EXIT_ERROR;
        }
        $output->writeln(sprintf('Exported form "%s" to %s.', $ref, $file));
        return self::EXIT_OK;
    }

    private function write(string $file, string $contents): bool
    {
        try {
            $this->files->filePutContents($file, $contents);
        } catch (FileSystemException) {
            return false;
        }
        return true;
    }

    private function load(string $ref): FormInterface
    {
        $id = $this->formResource->getIdByIdentifier($ref);
        if ($id === null && ctype_digit($ref)) {
            $id = (int)$ref;
        }
        if ($id === null) {
            throw new NoSuchEntityException(__('Form "%1" does not exist.', $ref));
        }
        return $this->formRepository->getById($id);
    }
}
