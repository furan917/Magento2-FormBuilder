<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EndpointRepositoryTest extends TestCase
{
    public function testItStoresAnEndpointWithItsScopeFormsAndEvents(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));

        $issued = $fx->repository->create(WebhookFixtures::input(), 3);

        $row = $fx->endpointInsert();
        $this->assertNotNull($row);
        $this->assertSame(7, $issued->endpointId());
        $this->assertSame('CRM', $row['name']);
        $this->assertSame('https://crm.example.com/hooks/in', $row['url']);
        $this->assertSame('store', $row['scope_type']);
        $this->assertSame('[1,2]', $row['scope_ids']);
        $this->assertSame(0, $row['all_forms']);
        $this->assertSame('[5,6]', $row['form_ids']);
        $this->assertSame('["submission.created","submission.updated"]', $row['events']);
        $this->assertSame(0, $row['include_personal']);
    }

    public function testItRefusesAnEndpointWithoutFormsUnlessAllFormsIsChosen(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));

        try {
            $fx->repository->create(WebhookFixtures::input(['form_ids' => [], 'all_forms' => false]), null);
            $this->fail('An endpoint with no forms and no all-forms flag must be refused');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('form', strtolower($e->getMessage()));
        }
        $this->assertSame([], $fx->inserts);

        $fx->repository->create(WebhookFixtures::input(['form_ids' => [], 'all_forms' => true]), null);
        $row = $fx->endpointInsert();
        $this->assertSame(1, $row['all_forms']);
        $this->assertSame('[]', $row['form_ids']);
    }

    public function testItGeneratesAWhsecSecretAndStoresItEncrypted(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));

        $plain = $fx->repository->create(WebhookFixtures::input(), null)->plain();

        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9+\/]{43}=$/D', $plain);
        $this->assertSame(32, strlen((string)base64_decode(substr($plain, 6), true)));
        $row = $fx->endpointInsert();
        $this->assertSame('enc[' . $plain . ']', $row['secret_enc']);
        $this->assertStringNotContainsString($plain, json_encode(array_diff_key($row, ['secret_enc' => 1]), JSON_THROW_ON_ERROR));
        $other = $fx->repository->create(WebhookFixtures::input(), null)->plain();
        $this->assertNotSame($plain, $other);
    }

    public function testItReturnsThePlainSecretOnlyOnceAndNeverFromAReader(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));
        $issued = $fx->repository->create(WebhookFixtures::input(), null);
        $this->assertStringNotContainsString($issued->plain(), print_r($issued, true));

        $fx->rows = [WebhookFixtures::row()];
        $fx->selectedColumns = [];
        $view = $fx->repository->getView(7);

        $this->assertSame([1, 2], $view->scopeIds());
        $this->assertSame([5, 6], $view->formIds());
        $this->assertSame(['submission.created'], $view->events());
        $this->assertCount(1, $fx->selectedColumns);
        foreach ($fx->selectedColumns as $columns) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString('secret', (string)$column);
            }
        }
        $this->assertStringNotContainsString('secret', strtolower(implode(',', get_class_methods($view))));
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass($view))->getProperties()
        );
        $this->assertStringNotContainsString('secret', strtolower(implode(',', $properties)));
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function windows(): array
    {
        return [
            'seventy two hours' => [72, 72],
            'beyond the cap' => [500, 72],
            'one day' => [24, 24],
            'negative' => [-5, 0],
        ];
    }

    #[DataProvider('windows')]
    public function testItKeepsThePreviousSecretForAtMostSeventyTwoHoursAfterRotation(int $asked, int $hours): void
    {
        $fx = new WebhookFixtures($this->createStub(...));

        $issued = $fx->repository->rotate(7, $asked, 3);

        [$table, $bind, $where] = $fx->updates[0];
        $this->assertSame('furan_formbuilder_webhook', $table);
        $this->assertSame(7, $issued->endpointId());
        $this->assertSame('enc[' . $issued->plain() . ']', $bind['secret_enc']);
        $this->assertSame(['previous_secret_enc', 'previous_expires_at', 'secret_enc'], array_keys($bind));
        if ($hours === 0) {
            $this->assertNull($bind['previous_secret_enc']);
            $this->assertNull($bind['previous_expires_at']);
        } else {
            $this->assertInstanceOf(\Zend_Db_Expr::class, $bind['previous_secret_enc']);
            $this->assertSame('secret_enc', (string)$bind['previous_secret_enc']);
            $this->assertSame(gmdate('Y-m-d H:i:s', WebhookFixtures::NOW + $hours * 3600), $bind['previous_expires_at']);
        }
        $audit = array_values(array_filter(
            $fx->inserts,
            static fn (array $i): bool => $i[0] === 'furan_formbuilder_webhook_audit'
        ));
        $this->assertSame('secret_rotated', $audit[0][1]['action']);
        $this->assertSame(7, $audit[0][1]['endpoint_id']);
        $this->assertSame(3, $audit[0][1]['admin_user_id']);
    }

    public function testItRefusesToRotateAnEndpointThatNoLongerExists(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));
        $fx->updateAffects = 0;

        $this->expectException(NoSuchEntityException::class);
        $fx->repository->rotate(99, 24, null);
    }

    public function testItLoadsTheEncryptedSecretsOnlyThroughTheSigningReader(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));
        $fx->rows = [['endpoint_id' => '7', 'secret_enc' => 'enc[a]', 'previous_secret_enc' => null, 'previous_expires_at' => null]];

        $endpoint = $fx->repository->getForSigning(7);

        $this->assertSame(7, $endpoint->id());
        $this->assertSame('enc[a]', $endpoint->secretEnc());
        $this->assertSame(['endpoint_id', 'secret_enc', 'previous_secret_enc', 'previous_expires_at'], $fx->selectedColumns[0]);

        $fx->rows = [];
        $this->expectException(NoSuchEntityException::class);
        $fx->repository->getForSigning(8);
    }

    public function testItPagesEndpointsByKeysetWithoutSecretColumns(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));
        $fx->rows = [
            WebhookFixtures::row(['endpoint_id' => '1']),
            WebhookFixtures::row(['endpoint_id' => '2']),
            WebhookFixtures::row(['endpoint_id' => '3']),
        ];

        $page = $fx->repository->page(0, 2);

        $this->assertSame([1, 2], array_map(static fn ($v): int => $v->id(), $page['rows']));
        $this->assertSame(2, $page['next']);
        foreach ($fx->selectedColumns as $columns) {
            $this->assertNotContains('secret_enc', $columns);
            $this->assertNotContains('previous_secret_enc', $columns);
        }

        $fx->rows = [WebhookFixtures::row(['endpoint_id' => '3'])];
        $last = $fx->repository->page(2, 2);
        $this->assertCount(1, $last['rows']);
        $this->assertNull($last['next']);
    }

    public function testItReadsTheRoutingStateOfEveryEndpointThatIsNotBeingPurgedWithoutSecrets(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));
        $fx->rows = [
            ['endpoint_id' => '3', 'is_active' => '1', 'circuit_open_until' => null],
            ['endpoint_id' => '8', 'is_active' => '0', 'circuit_open_until' => '2027-01-15 08:00:00'],
        ];
        $fx->selectedColumns = [];

        $this->assertSame(
            [
                3 => ['active' => true, 'circuit_open_until' => null],
                8 => ['active' => false, 'circuit_open_until' => '2027-01-15 08:00:00'],
            ],
            $fx->repository->routingStates()
        );
        $this->assertSame([['endpoint_id', 'is_active', 'circuit_open_until']], $fx->selectedColumns);
    }
}
