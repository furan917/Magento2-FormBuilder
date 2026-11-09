<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\FieldType;

use Furan\FormBuilder\Model\FieldType\SettingLabels;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\RendererInterface;
use PHPUnit\Framework\TestCase;

class SettingLabelsTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../../../Model/FieldType/SettingLabels.php';
    private const PACKS = __DIR__ . '/../../../../i18n';

    private ?RendererInterface $previousRenderer = null;

    protected function tearDown(): void
    {
        if ($this->previousRenderer !== null) {
            Phrase::setRenderer($this->previousRenderer);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function builtInSpecs(): array
    {
        $specs = [];
        foreach (FieldPools::builtIn()->all() as $code => $type) {
            if ($type instanceof \Furan\FormBuilder\Model\FieldType\Hidden) {
                $type = new \Furan\FormBuilder\Model\FieldType\Hidden(['static', 'query']);
            }
            $specs[$code] = $type->getSettingsSpec();
        }
        return $specs;
    }

    public function testItGivesEveryBuiltInFieldTypeSettingALabel(): void
    {
        $unlabelled = [];
        foreach ($this->builtInSpecs() as $code => $spec) {
            foreach ((new SettingLabels())->apply($spec) as $key => $entry) {
                if (!is_string($entry['label'] ?? null) || trim($entry['label']) === '') {
                    $unlabelled[] = $code . '.' . $key;
                }
            }
        }
        $this->assertSame([], $unlabelled);
    }

    public function testItKeepsALabelTheTypeSetsItself(): void
    {
        $spec = (new SettingLabels())->apply(['rows' => ['type' => 'int', 'label' => 'Custom'], 'x_unknown' => ['type' => 'int']]);
        $this->assertSame('Custom', $spec['rows']['label']);
        $this->assertArrayNotHasKey('label', $spec['x_unknown']);
    }

    public function testItTranslatesBuiltInSettingLabelsInTheAdminLocale(): void
    {
        $this->previousRenderer = (new \ReflectionClass(Phrase::class))->getStaticPropertyValue('renderer', null);
        Phrase::setRenderer(new class implements RendererInterface {
            public function render(array $source, array $arguments): string
            {
                return 'T:' . end($source);
            }
        });
        $spec = (new SettingLabels())->apply(['rows' => ['type' => 'int']]);
        $this->assertSame('T:Rows', $spec['rows']['label']);
    }

    public function testItListsEveryBuiltInSettingLabelInEveryLanguagePack(): void
    {
        preg_match_all("/__\('((?:[^'\\\\]|\\\\.)*)'\)/", (string)file_get_contents(self::SOURCE), $m);
        $phrases = array_map(stripslashes(...), $m[1]);
        $this->assertNotEmpty($phrases);
        foreach (glob(self::PACKS . '/*.csv') as $file) {
            $rows = [];
            $handle = fopen($file, 'rb');
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $rows[$row[0]] = $row[1] ?? '';
            }
            fclose($handle);
            $this->assertSame([], array_values(array_diff($phrases, array_keys($rows))), basename($file));
        }
    }
}
