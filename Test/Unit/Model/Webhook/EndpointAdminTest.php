<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Furan\FormBuilder\Api\Data\SafeUrl;
use Furan\FormBuilder\Model\Http\UnsafeUrlException;
use Furan\FormBuilder\Model\Webhook\EndpointAdmin;
use Furan\FormBuilder\Model\Webhook\EndpointInputException;
use Furan\FormBuilder\Model\Webhook\UrlPolicy;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Notification\NotifierInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class EndpointAdminTest extends TestCase
{
    private WebhookFixtures $fx;
    private UrlPolicy&Stub $policy;
    private NotifierInterface&Stub $notifier;
    private EndpointAdmin $admin;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?NotifierInterface $notifier = null): void
    {
        $this->fx = new WebhookFixtures($this->createStub(...));
        $this->policy = $this->createStub(UrlPolicy::class);
        $this->policy->method('validateForSave')->willReturnCallback(
            static function (string $url): SafeUrl {
                if (str_contains($url, 'internal')) {
                    throw new UnsafeUrlException('address_not_public');
                }
                return new SafeUrl('https', 'crm.example.com', 443, '/canonical?k=1', false);
            }
        );
        $this->notifier = $notifier ?? $this->createStub(NotifierInterface::class);
        $this->admin = new EndpointAdmin($this->fx->repository, $this->policy, $this->fx->audit, $this->notifier, new \Furan\FormBuilder\Model\Webhook\EndpointInput());
    }

    /** @return list<array<string, mixed>> audit rows written */
    private function auditRows(): array
    {
        $rows = [];
        foreach ($this->fx->inserts as [$table, $data]) {
            if ($table === 'furan_formbuilder_webhook_audit') {
                $rows[] = $data;
            }
        }
        return $rows;
    }

    public function testItSavesAnEndpointWhoseUrlPassesThePolicy(): void
    {
        $secret = $this->admin->save(WebhookFixtures::input(['url' => 'https://CRM.example.com/x']), null, true, 3);

        $this->assertNotNull($secret);
        $this->assertSame(7, $secret->endpointId());
        $this->assertSame('https://crm.example.com/canonical?k=1', $this->fx->endpointInsert()['url']);
    }

    public function testItRejectsAnEndpointUrlThatFailsThePolicy(): void
    {
        try {
            $this->admin->save(WebhookFixtures::input(['url' => 'https://internal.example.com/x']), null, true, 3);
            $this->fail('A URL that fails the policy must be refused');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('public address', $e->getMessage());
        }
        $this->assertNull($this->fx->endpointInsert());
    }

    /** @return array<string, mixed>|null the bind of the last UPDATE of the endpoint table */
    private function endpointUpdate(): ?array
    {
        foreach (array_reverse($this->fx->updates) as [$table, $bind]) {
            if ($table === 'furan_formbuilder_webhook') {
                return $bind;
            }
        }
        return null;
    }

    public function testItKeepsTheStoredPersonalDataChoiceForAdminsWithoutThePersonalPermission(): void
    {
        $this->fx->rows = [WebhookFixtures::row(['include_personal' => '1', 'url' => 'https://crm.example.com/canonical?k=1'])];

        $this->admin->save(WebhookFixtures::input(['include_personal' => false, 'events' => ['submission.created']]), 7, false, 3);
        $this->assertSame(1, $this->endpointUpdate()['include_personal']);

        $this->admin->save(WebhookFixtures::input(['include_personal' => true]), null, false, 3);
        $this->assertSame(0, $this->fx->endpointInsert()['include_personal']);
    }

    public function testItRecordsAnAuditEntryAndNotifiesAdminsWhenTheUrlChangesOrPersonalDataIsEnabled(): void
    {
        $this->fx->rows = [WebhookFixtures::row(['url' => 'https://old.example.org/in?token=abc'])];
        $notices = [];
        $this->notifier->method('addMajor')->willReturnCallback(
            function (string $title, string $description) use (&$notices): NotifierInterface {
                $notices[] = $title . ' | ' . $description;
                return $this->notifier;
            }
        );

        $this->admin->save(WebhookFixtures::input(['include_personal' => true, 'debug_responses' => true]), 7, true, 3);

        $audit = array_map(static fn (array $r): string => $r['action'] . ':' . $r['detail'], $this->auditRows());
        $this->assertSame(['url_changed:crm.example.com', 'personal_on:', 'debug_on:'], $audit);
        $this->assertSame(3, $this->auditRows()[0]['admin_user_id']);
        $this->assertCount(2, $notices);
        foreach ($notices as $notice) {
            $this->assertStringContainsString('CRM', $notice);
            $this->assertStringContainsString('crm.example.com', $notice);
            $this->assertStringNotContainsString('canonical', $notice);
            $this->assertStringNotContainsString('k=1', $notice);
            $this->assertStringNotContainsString('abc', $notice);
        }
    }

    public function testItStaysQuietWhenNothingSensitiveChanges(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $this->fixture(notifier: $notifier);
        $this->fx->rows = [WebhookFixtures::row(['url' => 'https://crm.example.com/canonical?k=1'])];
        $notifier->expects($this->never())->method('addMajor');

        $this->admin->save(WebhookFixtures::input(['name' => 'Renamed']), 7, true, 3);

        $this->assertSame([], $this->auditRows());
    }

    public function testItNotifiesAndAuditsWhenAnEndpointIsCreated(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $this->fixture(notifier: $notifier);
        $notifier->expects($this->once())->method('addMajor')
            ->with($this->stringContains('CRM'), $this->stringContains('crm.example.com'));

        $this->admin->save(WebhookFixtures::input(['include_personal' => true]), null, true, 3);

        $actions = array_column($this->auditRows(), 'action');
        $this->assertSame(['created', 'personal_on'], $actions);
    }

    public function testItSoftDeletesAnEndpointForTheBatchedPurge(): void
    {
        $this->admin->delete(7, 3);

        $bind = $this->endpointUpdate();
        $this->assertSame(0, $bind['is_active']);
        $this->assertSame(gmdate('Y-m-d H:i:s', WebhookFixtures::NOW), $bind['purge_requested_at']);
        $this->assertSame(['deleted'], array_column($this->auditRows(), 'action'));
        $this->assertSame(3, $this->auditRows()[0]['admin_user_id']);

    }

    public function testItReportsAMissingEndpointOnDelete(): void
    {
        $this->fx->updateAffects = 0;

        $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);
        $this->admin->delete(99, 3);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function receiverChanges(): array
    {
        return [
            'url' => [['url' => 'https://other.example.com/x']],
            'scope type' => [['scope_type' => 'website']],
            'scope ids' => [['scope_ids' => [1, 2, 3]]],
            'all forms' => [['all_forms' => true]],
            'form ids' => [['form_ids' => [5]]],
            'field mode' => [['field_mode' => 'selected', 'fields' => ['email']]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('receiverChanges')]
    public function testItRefusesToChangeTheReceiverOfAPersonalDataEndpointWithoutThePersonalPermission(array $change): void
    {
        $this->fx->rows = [WebhookFixtures::row(['include_personal' => '1', 'url' => 'https://crm.example.com/canonical?k=1'])];
        $policy = $this->createStub(UrlPolicy::class);
        $policy->method('validateForSave')->willReturnCallback(
            static fn (string $url): SafeUrl => new SafeUrl(
                'https',
                parse_url($url, PHP_URL_HOST) ?: 'crm.example.com',
                443,
                parse_url($url, PHP_URL_HOST) ? '/x' : '/canonical?k=1',
                false
            )
        );
        $admin = new EndpointAdmin($this->fx->repository, $policy, $this->fx->audit, $this->notifier, new \Furan\FormBuilder\Model\Webhook\EndpointInput());

        try {
            $admin->save(WebhookFixtures::input($change + ['url' => 'https://crm.example.com/canonical?k=1']), 7, false, 3);
            $this->fail('The receiver of a personal data endpoint must not change');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('personal', strtolower($e->getMessage()));
        }
        $this->assertNull($this->endpointUpdate());
    }

    public function testItLetsAnAdminWithoutThePersonalPermissionRenameAPersonalDataEndpoint(): void
    {
        $this->fx->rows = [WebhookFixtures::row(['include_personal' => '1', 'url' => 'https://crm.example.com/canonical?k=1'])];

        $this->admin->save(WebhookFixtures::input(['name' => 'Renamed', 'is_active' => false, 'events' => ['submission.created']]), 7, false, 3);

        $bind = $this->endpointUpdate();
        $this->assertSame('Renamed', $bind['name']);
        $this->assertSame(0, $bind['is_active']);
        $this->assertSame(1, $bind['include_personal']);
    }

    /** @return array<string, mixed> */
    private function lockedInput(array $override): array
    {
        return WebhookFixtures::input($override + ['url' => 'https://crm.example.com/canonical?k=1', 'events' => ['submission.created']]);
    }

    private function lockedRow(array $override = []): array
    {
        return WebhookFixtures::row($override + ['include_personal' => '1', 'url' => 'https://crm.example.com/canonical?k=1']);
    }

    /** @return array<string, string> */
    private function refusal(array $input, ?int $id = 7): array
    {
        try {
            $this->admin->save($input, $id, false, 3);
        } catch (EndpointInputException $e) {
            return $e->fieldErrors();
        }
        $this->fail('The save must be refused');
    }

    public function testItRefusesToChangeTheEventsOfAPersonalEndpointWithoutThePersonalPermission(): void
    {
        $this->fx->rows = [$this->lockedRow()];

        $errors = $this->refusal($this->lockedInput(['events' => ['submission.updated']]));

        $this->assertSame(['events'], array_keys($errors));
        $this->assertNull($this->endpointUpdate());
    }

    public function testItRefusesToTurnOnDebugResponsesForAPersonalEndpointWithoutThePersonalPermission(): void
    {
        $this->fx->rows = [$this->lockedRow()];

        $errors = $this->refusal($this->lockedInput(['debug_responses' => true]));

        $this->assertSame(['debug_responses'], array_keys($errors));
        $this->assertNull($this->endpointUpdate());
    }

    public function testItRefusesToReactivateAPersonalEndpointWithoutThePersonalPermission(): void
    {
        $this->fx->rows = [$this->lockedRow(['is_active' => '0'])];

        $errors = $this->refusal($this->lockedInput(['is_active' => true]));

        $this->assertSame(['is_active'], array_keys($errors));
        $this->assertNull($this->endpointUpdate());
    }

    public function testItReportsTheUrlErrorAndTheFieldErrorsTogether(): void
    {
        try {
            $this->admin->save(WebhookFixtures::input(['url' => 'https://internal.example.com/x', 'name' => '', 'events' => []]), null, true, 3);
            $this->fail('The save must be refused');
        } catch (LocalizedException $e) {
            $lines = explode("\n", $e->getMessage());
            $this->assertCount(3, $lines);
            $this->assertStringContainsString('public address', $e->getMessage());
            $this->assertStringContainsString('name is required', $e->getMessage());
            $this->assertStringContainsString('at least one event', $e->getMessage());
        }
    }

    public function testItKeysEachErrorByItsField(): void
    {
        try {
            $this->admin->save(
                WebhookFixtures::input(['url' => 'https://internal.example.com/x', 'name' => '', 'events' => [], 'scope_ids' => [], 'form_ids' => [], 'field_mode' => 'selected']),
                null,
                true,
                3
            );
            $this->fail('The save must be refused');
        } catch (EndpointInputException $e) {
            $this->assertSame(['name', 'scope', 'forms', 'events', 'fields', 'url'], array_keys($e->fieldErrors()));
            $this->assertStringContainsString('public address', $e->fieldErrors()['url']);
        }
    }

    public function testItRefusesAFiftyFirstActiveEndpoint(): void
    {
        $this->fx->connection->method('fetchOne')->willReturn('50');

        try {
            $this->admin->save(WebhookFixtures::input(), null, true, 3);
            $this->fail('The 51st active endpoint must be refused');
        } catch (EndpointInputException $e) {
            $this->assertSame(['is_active'], array_keys($e->fieldErrors()));
        }
        $this->assertNull($this->fx->endpointInsert());
    }

    public function testItRefusesToActivateAnInactiveEndpointWhenFiftyAreActive(): void
    {
        $this->fx->connection->method('fetchOne')->willReturn('50');
        $this->fx->rows = [WebhookFixtures::row(['is_active' => '0', 'url' => 'https://crm.example.com/canonical?k=1'])];

        try {
            $this->admin->save(WebhookFixtures::input(['is_active' => true]), 7, true, 3);
            $this->fail('The activation must be refused');
        } catch (EndpointInputException $e) {
            $this->assertSame(['is_active'], array_keys($e->fieldErrors()));
        }
    }

    public function testItSavesAnAlreadyActiveEndpointWhenFiftyAreActive(): void
    {
        $this->fx->connection->method('fetchOne')->willReturn('50');
        $this->fx->rows = [WebhookFixtures::row(['url' => 'https://crm.example.com/canonical?k=1'])];

        $this->admin->save(WebhookFixtures::input(['name' => 'Renamed']), 7, true, 3);

        $this->assertSame('Renamed', $this->endpointUpdate()['name']);
    }

    public function testItClearsTheFailureStateWhenAnEndpointIsReEnabled(): void
    {
        $this->fx->rows = [WebhookFixtures::row(['is_active' => '0', 'consecutive_failures' => '9', 'url' => 'https://crm.example.com/canonical?k=1'])];

        $this->admin->save(WebhookFixtures::input(['is_active' => true]), 7, true, 3);

        $bind = $this->endpointUpdate();
        $this->assertSame(1, $bind['is_active']);
        $this->assertSame(0, $bind['consecutive_failures']);
        $this->assertNull($bind['circuit_open_until']);
        $this->assertNull($bind['failing_since']);
        $this->assertNull($bind['disabled_reason']);
        $this->assertSame(['enabled'], array_column($this->auditRows(), 'action'));
    }

    public function testItAuditsAnAdminDisablingAnEndpoint(): void
    {
        $this->fx->rows = [WebhookFixtures::row(['url' => 'https://crm.example.com/canonical?k=1'])];

        $this->admin->save(WebhookFixtures::input(['is_active' => false]), 7, true, 3);

        $this->assertArrayNotHasKey('consecutive_failures', $this->endpointUpdate());
        $this->assertSame(['disabled'], array_column($this->auditRows(), 'action'));
    }
}
