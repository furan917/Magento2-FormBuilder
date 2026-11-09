<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Webhook;

use PHPUnit\Framework\TestCase;

class AuditTest extends TestCase
{
    public function testItRecordsAnAuditEntryWithoutSecrets(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));

        $fx->audit->record(7, 3, 'url_changed', 'crm.example.com https://crm.example.com/in?token=abc#frag');

        [$table, $row] = $fx->inserts[0];
        $this->assertSame('furan_formbuilder_webhook_audit', $table);
        $this->assertSame(['endpoint_id', 'admin_user_id', 'action', 'detail', 'created_at'], array_keys($row));
        $this->assertSame(7, $row['endpoint_id']);
        $this->assertSame(3, $row['admin_user_id']);
        $this->assertSame('url_changed', $row['action']);
        $this->assertSame('crm.example.com https://crm.example.com/in', $row['detail']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $row['created_at']);
    }

    public function testItRecordsSystemActionsWithoutAnAdminAndCapsTheDetail(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));

        $fx->audit->record(7, null, 'auto_disabled', str_repeat('é', 400));

        $this->assertNull($fx->inserts[0][1]['admin_user_id']);
        $this->assertSame(255, mb_strlen($fx->inserts[0][1]['detail']));
    }

    public function testItRefusesAnUnknownAction(): void
    {
        $fx = new WebhookFixtures($this->createStub(...));

        $this->expectException(\InvalidArgumentException::class);
        try {
            $fx->audit->record(7, 3, 'revealed_secret');
        } finally {
            $this->assertSame([], $fx->inserts);
        }
    }
}
