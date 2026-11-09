<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Model\Webhook\EndpointInput;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EndpointInputTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalid(): array
    {
        return [
            'blank name' => [['name' => '  ']],
            'long name' => [['name' => str_repeat('a', 121)]],
            'blank url' => [['url' => '']],
            'long url' => [['url' => 'https://a.example/' . str_repeat('a', 2048)]],
            'unknown scope' => [['scope_type' => 'global']],
            'no scope ids' => [['scope_ids' => []]],
            'junk scope ids' => [['scope_ids' => ['x', -1, 0, 1.5]]],
            'no events' => [['events' => []]],
            'webhook.test is never stored' => [['events' => ['webhook.test']]],
            'selected without fields' => [['field_mode' => 'selected', 'fields' => []]],
            'selected with invalid names' => [['field_mode' => 'selected', 'fields' => ['Bad Name', '1x']]],
            'unknown field mode' => [['field_mode' => 'some']],
        ];
    }

    #[DataProvider('invalid')]
    public function testItRefusesInvalidEndpointSettings(array $override): void
    {
        $this->expectException(LocalizedException::class);
        (new EndpointInput())->normalize(WebhookFixtures::input($override));
    }

    public function testItNormalisesIdsEventsAndFields(): void
    {
        $out = (new EndpointInput())->normalize(WebhookFixtures::input([
            'scope_ids' => ['3', 2, 3, 'x'],
            'form_ids' => [9, '4'],
            'events' => ['bogus', 'submission.status_changed', 'submission.created'],
            'field_mode' => 'selected',
            'fields' => ['email', 'email', 'Bad'],
            'all_forms' => true,
        ]));

        $this->assertSame([2, 3], $out['scope_ids']);
        $this->assertSame([], $out['form_ids']);
        $this->assertSame(['submission.created', 'submission.status_changed'], $out['events']);
        $this->assertSame(['email'], $out['fields']);
    }
}
