<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Config;

use Furan\FormBuilder\Model\Action\ActionQueue;
use Furan\FormBuilder\Model\Export\ExportQueue;
use Furan\FormBuilder\Model\Mail\MailQueue;
use Furan\FormBuilder\Model\Privacy\DeletedAccountQueue;
use Furan\FormBuilder\Model\Webhook\Outbox;
use PHPUnit\Framework\TestCase;

class QueueConfigTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../etc/';

    public static function topics(): array
    {
        return [[MailQueue::TOPIC], [ExportQueue::TOPIC], [ActionQueue::TOPIC], [Outbox::TOPIC], [DeletedAccountQueue::TOPIC]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('topics')]
    public function testItWiresEachTopicThroughPublisherTopologyAndConsumer(string $topic): void
    {
        $communication = simplexml_load_file(self::ROOT . 'communication.xml');
        $this->assertSame('string', (string)$communication->xpath('//topic[@name="' . $topic . '"]')[0]['request']);
        $publisher = simplexml_load_file(self::ROOT . 'queue_publisher.xml')->xpath('//publisher[@topic="' . $topic . '"]')[0];
        $binding = simplexml_load_file(self::ROOT . 'queue_topology.xml')->xpath('//binding[@topic="' . $topic . '"]')[0];
        $consumer = simplexml_load_file(self::ROOT . 'queue_consumer.xml')->xpath('//consumer[@name="' . $topic . '"]')[0];
        $this->assertSame((string)$binding['destination'], (string)$consumer['queue']);
        $this->assertSame((string)$publisher['queue'], (string)$consumer['queue']);

        [$class, $method] = explode('::', (string)$consumer['handler']);
        $parameter = (new \ReflectionMethod($class, $method))->getParameters()[0];
        $this->assertSame('string', (string)$parameter->getType());
    }

    public function testItLeavesTheConnectionToTheInstallationSoItWorksWithoutRabbitMq(): void
    {
        foreach (['queue_publisher.xml', 'queue_topology.xml', 'queue_consumer.xml'] as $file) {
            $xml = (string)file_get_contents(self::ROOT . $file);
            $this->assertStringNotContainsString('connection=', $xml, $file);
            $this->assertStringNotContainsString('<connection', $xml, $file);
        }
    }

    public function testItKeepsBackgroundActionsOffByDefaultBecauseTheyNeedARunningConsumer(): void
    {
        $config = simplexml_load_file(self::ROOT . 'config.xml');
        $this->assertSame('0', (string)$config->default->furan_formbuilder->general->async_actions);
    }

    public function testItKeepsBackgroundMailOffByDefaultBecauseItNeedsARunningConsumer(): void
    {
        $config = simplexml_load_file(self::ROOT . 'config.xml');
        $this->assertSame('0', (string)$config->default->furan_formbuilder->email->async);
    }
}
