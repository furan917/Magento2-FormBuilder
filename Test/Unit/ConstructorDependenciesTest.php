<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

class ConstructorDependenciesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const NS = 'Furan\\FormBuilder\\';

    /** Value objects, models and exceptions that are legitimately created with new. */
    private const NEW_ALLOWLIST = [
        'Model\\Submission\\ValidationResult',
        'Api\\Exception\\InvalidSchemaException',
        'Model\\Prefill\\PrefillContext',
        'Api\\Data\\FormState',
        'Api\\ActionContext',
        'Model\\Webhook\\EndpointSecret',
        'Model\\Webhook\\EndpointView',
        'Model\\Webhook\\EndpointRoute',
        'Model\\Webhook\\Endpoint',
        'Model\\Webhook\\Envelope',
        'Api\\SubmissionInput',
        'Api\\Data\\RequestPolicy',
        'Api\\Data\\SafeUrl',
        'Api\\Data\\HttpResult',
        'Model\\Webhook\\Outcome',
        'Model\\Webhook\\Decision',
        'Model\\Webhook\\DrainRun',
        'Model\\Webhook\\DrainLane',
        'Model\\Submission\\EditTarget',
    ];

    public function testItRequiresValueFormatterAsAConstructorDependencyInEveryClassThatFormatsValues(): void
    {
        $this->assertRequires('Model\\Export\\CsvExporter', 'Model\\Submission\\ValueFormatter');
        $this->assertRequires('Model\\Submission\\AnswerView', 'Model\\Submission\\ValueFormatter');
        $this->assertRequires('Model\\Email\\ValuesTable', 'Model\\Submission\\AnswerView');
        $this->assertRequires('Model\\Submission\\SummaryBuilder', 'Model\\Submission\\ValueFormatter');
        $this->assertRequires('Model\\Privacy\\Exporter', 'Model\\Submission\\ValueFormatter');
    }

    public function testItRequiresSourcesAndPersonalDataAsConstructorDependenciesOfNormalizerAndBuilderSharedConfig(): void
    {
        $this->assertRequires('Model\\Schema\\Normalizer', 'Model\\Schema\\PersonalData');
        $this->assertRequires('Model\\Schema\\Normalizer', 'Model\\Prefill\\Sources');
        $this->assertRequires('Model\\Admin\\BuilderSharedConfig', 'Model\\Prefill\\Sources');
    }

    public function testItRequiresSubmissionStatusMessageSanitizerPhpCodeDetectorAndPublicAddressAsConstructorDependencies(): void
    {
        $status = 'Model\\Source\\SubmissionStatus';
        foreach ([
            'Controller\\Adminhtml\\Submission\\SaveStatus',
            'Controller\\Adminhtml\\Submission\\MassStatus',
            'Block\\Account\\Submission',
            'Block\\Thread\\View',
        ] as $class) {
            $this->assertRequires($class, $status);
        }
        $sanitizer = 'Model\\Thread\\MessageSanitizer';
        foreach ([
            'Model\\Thread\\CustomerReply',
            'Controller\\Adminhtml\\Submission\\Reply',
        ] as $class) {
            $this->assertRequires($class, $sanitizer);
        }
        $this->assertRequires('Model\\Upload\\FileValidator', 'Model\\Upload\\PhpCodeDetector');
        $this->assertRequires('Model\\Map\\NominatimClient', 'Model\\Http\\SafeHttpClient');
        $this->assertRequires('Model\\Map\\NominatimClient', 'Model\\Http\\UrlNormalizer');
        $this->assertRequires('Model\\Config\\Backend\\SearchEndpoint', 'Model\\Http\\SafeHttpClient');
        $this->assertRequires('Model\\Config\\Backend\\SearchEndpoint', 'Model\\Http\\UrlNormalizer');
    }

    public function testItInjectsSigningKeysMiniMarkdownRepeatAnalyzerAccountLookupAndAttributionSanitizerInsteadOfCreatingThem(): void
    {
        $keys = 'Model\\Security\\SigningKeys';
        foreach (['Model\\Claim\\ClaimToken', 'Model\\Spam\\TokenService', 'Model\\Submission\\EditToken'] as $class) {
            $this->assertRequires($class, $keys);
        }
        $markdown = 'Model\\Content\\MiniMarkdown';
        foreach (['Block\\Form', 'Block\\Widget\\Form', 'Model\\Thread\\MessageRenderer'] as $class) {
            $this->assertRequires($class, $markdown);
        }
        $this->assertRequires('Model\\Validation\\PatternMatcher', 'Model\\Validation\\RepeatAnalyzer');
        $this->assertRequires('Model\\Privacy\\Eraser', 'Model\\Privacy\\AccountLookup');
        $this->assertRequires('Model\\Privacy\\Exporter', 'Model\\Privacy\\AccountLookup');
        $this->assertRequires('Model\\Config\\Backend\\Attribution', 'Model\\Map\\AttributionSanitizer');
    }

    public function testItBuildsTheProcessorWithAtMostTwentyDependencies(): void
    {
        $constructor = (new ReflectionClass(self::NS . 'Model\\Submission\\Processor'))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertLessThanOrEqual(20, $constructor->getNumberOfParameters());
    }

    public function testItLeavesNoModuleServiceClassInstantiatedWithNewInsideModelBlockOrController(): void
    {
        $offenders = [];
        foreach ($this->instantiations() as [$file, $short]) {
            if (!in_array($short, self::NEW_ALLOWLIST, true) && !str_ends_with($short, 'Exception')) {
                $offenders[] = $file . ' new ' . $short;
            }
        }
        $this->assertSame([], $offenders);
    }

    public function testItKeepsEveryConstructorAllowlistEntryInUse(): void
    {
        $used = array_unique(array_column($this->instantiations(), 1));
        $unused = [];
        foreach (self::NEW_ALLOWLIST as $short) {
            if (!is_file(self::ROOT . '/' . str_replace('\\', '/', $short) . '.php') || !in_array($short, $used, true)) {
                $unused[] = $short;
            }
        }
        $this->assertSame([], $unused);
    }

    /** @return list<array{string, string}> file (relative to the module) and module class (short name) for each `new` of a module class */
    private function instantiations(): array
    {
        $found = [];
        foreach (['Model', 'Block', 'Controller'] as $dir) {
            $files = new \RegexIterator(
                new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir)),
                '/\.php$/'
            );
            foreach ($files as $file) {
                $source = (string)file_get_contents($file->getPathname());
                $uses = $this->useMap($source);
                if (!preg_match_all('/\bnew\s+\\\\?([A-Z][A-Za-z0-9_\\\\]*)\s*\(/', $source, $m)) {
                    continue;
                }
                foreach ($m[1] as $name) {
                    $fqcn = $this->resolve($name, $uses, $source);
                    if (!str_starts_with($fqcn, self::NS)) {
                        continue;
                    }
                    $short = substr($fqcn, strlen(self::NS));
                    if (is_file(self::ROOT . '/' . str_replace('\\', '/', $short) . '.php')) {
                        $found[] = [substr($file->getPathname(), strlen(self::ROOT) + 1), $short];
                    }
                }
            }
        }
        return $found;
    }

    private function assertRequires(string $class, string $dependency): void
    {
        $constructor = (new ReflectionClass(self::NS . $class))->getConstructor();
        $this->assertNotNull($constructor, $class);
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === self::NS . $dependency) {
                $this->assertFalse($type->allowsNull() || $param->isDefaultValueAvailable(), "$class::\${$param->getName()} must be required");
                return;
            }
        }
        $this->fail("$class does not take $dependency");
    }

    /** @return array<string,string> short name => fqcn */
    private function useMap(string $source): array
    {
        $map = [];
        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $m, PREG_SET_ORDER)) {
            foreach ($m as $use) {
                $parts = explode('\\', $use[1]);
                $map[$use[2] ?? end($parts)] = $use[1];
            }
        }
        return $map;
    }

    private function resolve(string $name, array $uses, string $source): string
    {
        $first = explode('\\', $name)[0];
        if (isset($uses[$first])) {
            return $uses[$first] . substr($name, strlen($first));
        }
        $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $ns) ? $ns[1] : '';
        return $namespace . '\\' . $name;
    }
}
