<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Definition;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\FormManagementInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Model\Definition\Importer;
use Furan\FormBuilder\Api\FormWriteContext;
use Furan\FormBuilder\Model\ResourceModel\Form as FormResource;
use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ImporterTest extends TestCase
{
    private FormManagementInterface&Stub $management;
    private FormResource&Stub $resource;
    private FormRepositoryInterface&Stub $repository;

    protected function setUp(): void
    {
        $this->management = $this->createStub(FormManagementInterface::class);
        $this->resource = $this->createStub(FormResource::class);
        $this->repository = $this->createStub(FormRepositoryInterface::class);
    }

    private function importer(): Importer
    {
        $store = $this->createStub(\Magento\Store\Model\Store::class);
        $store->method('getId')->willReturn(2);
        $store->method('getCode')->willReturn('fr');
        $stores = $this->createStub(\Magento\Store\Model\StoreManagerInterface::class);
        $stores->method('getStores')->willReturn([2 => $store]);

        return new Importer($this->management, $this->resource, $this->repository, $stores);
    }

    private function definition(array $override = []): array
    {
        return $override + ['identifier' => 'contact', 'title' => 'Contact', 'schema' => ['raw' => 1], 'settings' => ['raw' => 2]];
    }

    public function testItImportsANewFormThroughTheFormManagementWithAnAllAllowedContext(): void
    {
        $form = $this->createStub(FormInterface::class);
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->once())->method('save')->with(
            null,
            [
                'identifier' => 'contact', 'title' => 'Contact', 'is_active' => true, 'store_ids' => [],
                'schema' => ['raw' => 1], 'settings' => ['raw' => 2], 'translations' => [],
            ],
            $this->callback(static fn (FormWriteContext $c): bool => $c->isAllowed('Any::resource') && $c->adminId === null)
        )->willReturn($form);

        $this->assertSame($form, $this->importer()->import($this->definition(), false));
    }

    public function testItPassesTheDefinitionValuesUntouchedSoTheWriterCanRejectThem(): void
    {
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->once())->method('save')->with(
            null,
            $this->callback(static fn (array $d): bool => $d['title'] === '  x  ' && $d['is_active'] === false && $d['identifier'] === 'Bad Id')
        )->willReturn($this->createStub(FormInterface::class));

        $this->importer()->import($this->definition([
            'identifier' => 'Bad Id', 'title' => '  x  ', 'is_active' => false,
        ]), false);
    }

    public function testItKeysTranslationsByStoreIdAndDropsUnknownStoreCodes(): void
    {
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->once())->method('save')->with(
            null,
            $this->callback(static fn (array $d): bool => $d['translations'] === [2 => ['form' => ['a' => 1]]])
        )->willReturn($this->createStub(FormInterface::class));

        $this->importer()->import($this->definition(['translations' => ['fr' => ['form' => ['a' => 1]], 'xx' => ['form' => []]]]), false);
    }

    public function testItExportsAndImportsStoreViewsByCode(): void
    {
        $fixture = $this->fixture();
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->once())->method('save')->with(
            null,
            $this->callback(static fn (array $d): bool => $d['store_ids'] === $fixture['store_ids']
                && !array_key_exists('store_codes', $d))
        )->willReturn($this->createStub(FormInterface::class));

        $this->importer()->import($fixture['definition'], false);
    }

    public function testItRefusesAnImportThatNamesAnUnknownStoreView(): void
    {
        $fixture = $this->fixture();
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->never())->method('save');

        try {
            $this->importer()->import(['store_codes' => $fixture['unknown']['store_codes']] + $fixture['definition'], false);
            $this->fail('Expected exception');
        } catch (InvalidSchemaException $e) {
            $this->assertSame([$fixture['unknown']['error']], $e->getErrors());
        }
    }

    public function testItDoesNotReadTheOldStoreIdsKey(): void
    {
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->once())->method('save')->with(
            null,
            $this->callback(static fn (array $d): bool => $d['store_ids'] === [])
        )->willReturn($this->createStub(FormInterface::class));

        $this->importer()->import($this->definition(['store_ids' => [0]]), false);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/definition-export.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testItRefusesToOverwriteAnExistingIdentifierWithoutUpdate(): void
    {
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->never())->method('save');
        $this->resource->method('getIdByIdentifier')->willReturn(5);

        try {
            $this->importer()->import($this->definition(), false);
            $this->fail('Expected exception');
        } catch (InvalidSchemaException $e) {
            $this->assertSame(['A form with identifier "contact" already exists (use --update).'], $e->getErrors());
        }
    }

    public function testItUpdatesAnExistingFormWithTheUpdateOption(): void
    {
        $this->resource->method('getIdByIdentifier')->willReturn(5);
        $existing = $this->createStub(FormInterface::class);
        $this->repository = $this->createMock(FormRepositoryInterface::class);
        $this->repository->expects($this->once())->method('getById')->with(5)->willReturn($existing);
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->once())->method('save')->with($existing)->willReturn($existing);

        $this->assertSame($existing, $this->importer()->import($this->definition(), true));
    }

    public function testItLetsTheWriterCreateAFormWhenUpdateFindsNothingToUpdate(): void
    {
        $this->management = $this->createMock(FormManagementInterface::class);
        $this->management->expects($this->once())->method('save')->with(null)->willReturn($this->createStub(FormInterface::class));

        $this->importer()->import($this->definition(), true);
    }

    public function testItDoesNotLookUpAnInvalidIdentifier(): void
    {
        $this->resource = $this->createMock(FormResource::class);
        $this->resource->expects($this->never())->method('getIdByIdentifier');
        $this->management->method('save')->willReturn($this->createStub(FormInterface::class));

        $this->importer()->import($this->definition(['identifier' => 'Bad Id']), true);
    }
}
