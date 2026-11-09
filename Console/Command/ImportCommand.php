<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Console\Command;

use Furan\FormBuilder\Model\Definition\Importer;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCommand extends Command
{
    private const EXIT_OK = 0;
    private const EXIT_ERROR = 1;
    private const MAX_BYTES = 2097152;

    public function __construct(
        private readonly Importer $importer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('furanform:form:import')
            ->setDescription('Import a form definition from a JSON file')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the JSON definition')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Overwrite the form with the same identifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = [];
        $definition = $this->readDefinition((string)$input->getArgument('file'), $errors);
        if ($definition !== null) {
            try {
                $form = $this->importer->import($definition, (bool)$input->getOption('update'));
                $output->writeln(sprintf('Imported form "%s" (ID %s).', $form->getIdentifier(), $form->getId()));
                return self::EXIT_OK;
            } catch (InvalidSchemaException $e) {
                $errors = $e->getErrors();
            } catch (LocalizedException $e) {
                $errors = [$e->getMessage()];
            }
        }
        foreach ($errors as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }
        return self::EXIT_ERROR;
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed>|null
     */
    private function readDefinition(string $path, array &$errors): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            $errors[] = sprintf('File "%s" does not exist or is not readable.', $path);
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            $errors[] = 'The file is larger than 2 MB.';
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            $errors[] = 'The file does not contain a valid JSON object.';
            return null;
        }
        return $data;
    }
}
