<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use PHPUnit\Framework\TestCase;

/** The webhook tables keep no personal data, and an erased submission takes its deliveries with it. */
class WebhookPrivacySchemaTest extends TestCase
{
    private const PREFIX = 'furan_formbuilder_';

    private function schema(): \SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/db_schema.xml');
        $this->assertNotFalse($xml);

        return $xml;
    }

    private function cascades(string $table, string $column, string $referenceTable): bool
    {
        foreach ($this->schema()->xpath('//table[@name="' . self::PREFIX . $table . '"]/constraint') as $constraint) {
            if ((string)$constraint['column'] === $column
                && (string)$constraint['referenceTable'] === self::PREFIX . $referenceTable
                && (string)$constraint['onDelete'] === 'CASCADE') {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function columns(string $table): array
    {
        return array_map('strval', $this->schema()->xpath('//table[@name="' . self::PREFIX . $table . '"]/column/@name'));
    }

    public function testItRemovesWebhookDeliveriesWithAnErasedSubmission(): void
    {
        $this->assertTrue($this->cascades('webhook_delivery', 'submission_id', 'submission'));
        $this->assertTrue($this->cascades('webhook_attempt', 'delivery_id', 'webhook_delivery'));
        $this->assertTrue($this->cascades('webhook_attempt_debug', 'attempt_id', 'webhook_attempt'));
    }

    public function testItNeverHoldsAPayloadSnapshot(): void
    {
        $this->assertSame(
            [
                'delivery_id', 'endpoint_id', 'submission_id', 'event', 'event_data', 'webhook_id', 'replay_of',
                'admin_user_id', 'status', 'attempts', 'next_attempt_at', 'last_status_code', 'last_error',
                'created_at', 'updated_at', 'delivered_at',
            ],
            $this->columns('webhook_delivery')
        );
        $this->assertSame(
            [
                'attempt_id', 'delivery_id', 'started_at', 'duration_ms', 'status_code', 'error_class', 'errno',
                'connected_ip', 'location', 'retry_after',
            ],
            $this->columns('webhook_attempt')
        );
        $this->assertSame(['attempt_id', 'excerpt', 'created_at'], $this->columns('webhook_attempt_debug'));
    }

    public function testItKeepsOnlyIdsAndHostInTheRecipientTable(): void
    {
        $this->assertTrue($this->cascades('webhook_recipient', 'submission_id', 'submission'));
        $this->assertSame(['submission_id', 'endpoint_id', 'host', 'first_delivered_at'], $this->columns('webhook_recipient'));
        $this->assertSame(
            ['submission_id', 'endpoint_id'],
            array_map('strval', $this->schema()->xpath(
                '//table[@name="' . self::PREFIX . 'webhook_recipient"]/constraint[@referenceId="PRIMARY"]/column/@name'
            ) ?: [])
        );
    }
}
