<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Schema;

use Furan\FormBuilder\Api\Exception\InvalidSchemaException;
use Furan\FormBuilder\Model\Schema\SettingsNormalizer;
use Furan\FormBuilder\Model\Schema\SettingValues;
use Furan\FormBuilder\Model\Settings\ContributorPool;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Test\Unit\Helper\ArraySettingsContributor;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use PHPUnit\Framework\TestCase;

class SettingsContributorsTest extends TestCase
{
    private function normalizer(?ContributorPool $pool = null, bool $redirectAllowed = true): SettingsNormalizer
    {
        $validator = $this->createStub(RedirectValidator::class);
        $validator->method('isAllowed')->willReturn($redirectAllowed);
        return new SettingsNormalizer(
            $validator,
            FieldPools::builtIn(),
            new SettingValues($validator),
            $pool ?? ArraySettingsContributor::pool(new ArraySettingsContributor()),
            \Furan\FormBuilder\Test\Unit\Helper\ConditionNormalizers::builtIn()
        );
    }

    private function schema(): array
    {
        return ['version' => 1, 'pages' => []];
    }

    public function testItStoresSettingsDeclaredByAContributorUnderItsNamespace(): void
    {
        $result = $this->normalizer()->normalize(
            ['ext' => ['acme_crm' => ['list_id' => 'vip', 'sync' => false, 'mode' => 'b']]],
            $this->schema()
        );

        $this->assertSame(['acme_crm' => ['list_id' => 'vip', 'sync' => false, 'mode' => 'b']], $result['ext']);
    }

    public function testItDropsKeysAContributorDidNotDeclare(): void
    {
        $result = $this->normalizer()->normalize(
            ['ext' => ['acme_crm' => ['list_id' => 'vip', 'secret' => 'x'], 'other' => ['a' => 1]], 'bogus' => 1],
            $this->schema()
        );

        $this->assertSame(['list_id', 'sync', 'mode'], array_keys($result['ext']['acme_crm']));
        $this->assertSame(['acme_crm'], array_keys($result['ext']));
    }

    public function testItAppliesContributorDefaultsWhenAValueIsMissing(): void
    {
        $result = $this->normalizer()->normalize(['ext' => 'junk'], $this->schema());

        $this->assertSame(['list_id' => 'main', 'sync' => true, 'mode' => 'a'], $result['ext']['acme_crm']);
    }

    public function testItValidatesContributorValuesWithTheSameRulesAsFieldSettings(): void
    {
        $spec = [
            'count' => ['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 10, 'label' => 'Count'],
            'tags' => ['type' => 'list', 'default' => [], 'min_items' => 1, 'label' => 'Tags'],
            'url' => ['type' => 'string', 'default' => '', 'format' => 'url', 'label' => 'Url'],
        ];
        $pool = ArraySettingsContributor::pool(new ArraySettingsContributor('acme_crm', $spec));

        $result = $this->normalizer($pool)->normalize(
            ['ext' => ['acme_crm' => ['count' => '99', 'tags' => ['a', ''], 'url' => '/ok']]],
            $this->schema()
        );
        $this->assertSame(['count' => 10, 'tags' => ['a'], 'url' => '/ok'], $result['ext']['acme_crm']);

        try {
            $this->normalizer($pool, false)->normalize(['ext' => ['acme_crm' => ['url' => 'https://evil.test']]], $this->schema());
            $this->fail('Expected an invalid settings error');
        } catch (InvalidSchemaException $e) {
            $errors = array_values(array_filter($e->getErrors(), static fn (string $m): bool => str_contains($m, 'Acme CRM')));
            $this->assertCount(2, $errors);
            $this->assertStringContainsString('"Tags"', $errors[0]);
            $this->assertStringContainsString('"Url"', $errors[1]);
        }
    }

    public function testItKeepsStoredSettingsOfAContributorThatIsNoLongerRegistered(): void
    {
        $stored = ['ext' => ['gone_ext' => ['token_id' => 7], 'acme_crm' => ['list_id' => 'old']]];
        $raw = ['ext' => ['gone_ext' => ['token_id' => 99], 'acme_crm' => ['list_id' => 'new']]];

        $result = $this->normalizer()->normalize($raw, $this->schema(), $stored);

        $this->assertSame(['token_id' => 7], $result['ext']['gone_ext']);
        $this->assertSame('new', $result['ext']['acme_crm']['list_id']);

        $noStored = $this->normalizer()->normalize($raw, $this->schema());
        $this->assertArrayNotHasKey('gone_ext', $noStored['ext']);
    }

    public function testItOmitsTheExtensionKeyWithoutContributors(): void
    {
        $result = $this->normalizer(ArraySettingsContributor::pool())->normalize(['ext' => ['x' => ['a' => 1]]], $this->schema());

        $this->assertArrayNotHasKey('ext', $result);
    }
}
