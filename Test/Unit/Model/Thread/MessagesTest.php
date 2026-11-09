<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Model\Event\SubmissionEvents;
use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\Model\Submission\StatusWriter;
use Furan\FormBuilder\Model\Thread\Messages;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessagesTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private Mysql&Stub $connection;

    private SubmissionEvents&Stub $events;

    private StatusWriter&Stub $statusWriter;

    private LoggerInterface&Stub $logger;

    private SubmissionResource&Stub $submissions;

    private function messages(): Messages
    {
        $this->events = $this->createStub(SubmissionEvents::class);
        $this->connection = $this->double('connection', Mysql::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->statusWriter = $this->double('statusWriter', StatusWriter::class);

        $this->logger = $this->createStub(LoggerInterface::class);
        $this->submissions = $this->double('submissions', SubmissionResource::class);

        return new Messages($resource, $this->events, $this->statusWriter, $this->submissions, $this->logger);
    }

    public function testItStoresTheReplyOnTheSubmissionThread(): void
    {
        $this->asserted = ['connection'];
        $messages = $this->messages();
        $this->connection->expects($this->once())->method('insert')->with(
            'furan_formbuilder_message',
            ['submission_id' => 7, 'author' => 'admin', 'admin_user_id' => 9, 'body' => 'Hi']
        );
        $this->connection->method('lastInsertId')->willReturn('55');

        $this->assertSame(55, $messages->add(7, 9, 'Hi'));
    }

    public function testItDispatchesThreadMessageAddedForAdminCustomerAndGuestMessages(): void
    {
        $messages = $this->messages();
        $this->connection->method('lastInsertId')->willReturnOnConsecutiveCalls('55', '56', '57');
        $this->connection->method('fetchOne')->willReturn('12');
        $seen = [];
        $this->events->method('threadMessageAdded')->willReturnCallback(
            function (int $submission, int $form, string $author, int $message) use (&$seen): void {
                $seen[] = [$submission, $form, $author, $message];
            }
        );

        $messages->add(7, 9, 'a');
        $messages->addCustomer(7, 'b', 'customer');
        $messages->addCustomer(7, 'c', 'guest');

        $this->assertSame([[7, 12, 'admin', 55], [7, 12, 'customer', 56], [7, 12, 'guest', 57]], $seen);
    }

    public function testItStoresGuestRepliesWithTheCustomerAuthor(): void
    {
        $this->asserted = ['connection'];
        $messages = $this->messages();
        $this->connection->expects($this->once())->method('insert')->with(
            'furan_formbuilder_message',
            ['submission_id' => 7, 'author' => 'customer', 'admin_user_id' => null, 'body' => 'Hi']
        );
        $this->connection->method('lastInsertId')->willReturn('55');

        $messages->addCustomer(7, 'Hi', 'guest');
    }

    /** @return array<string, array{string}> */
    public static function customerAuthors(): array
    {
        return ['customer' => ['customer'], 'guest' => ['guest']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('customerAuthors')]
    public function testItMarksTheSubmissionUnreadWhenTheCustomerOrGuestReplies(string $author): void
    {
        $this->asserted = ['submissions'];
        $messages = $this->messages();
        $this->connection->method('lastInsertId')->willReturn('55');
        $this->submissions->expects($this->once())->method('markUnread')->with(7);

        $this->assertSame(55, $messages->addCustomer(7, 'Hi', $author));
    }

    public function testItLeavesTheReadStateAloneForAnAdminReply(): void
    {
        $this->asserted = ['submissions'];
        $messages = $this->messages();
        $this->connection->method('lastInsertId')->willReturn('55');
        $this->submissions->expects($this->never())->method('markUnread');

        $messages->add(7, 9, 'Hi');
    }

    public function testItKeepsTheCustomerReplyWhenTheUnreadWriteFails(): void
    {
        $messages = $this->messages();
        $this->connection->method('lastInsertId')->willReturn('55');
        $this->submissions->method('markUnread')->willThrowException(new \RuntimeException('db host'));
        $logged = [];
        $this->logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$logged): void {
            $logged[] = $message . json_encode($context);
        });

        $this->assertSame(55, $messages->addCustomer(7, 'Hi', 'guest'));
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('RuntimeException', $logged[0]);
        $this->assertStringNotContainsString('db host', $logged[0]);
    }

    public function testItReadsACustomerMessageByIdOnly(): void
    {
        $messages = $this->messages();
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $wheres = [];
        $select->expects($this->exactly(4))->method('where')->willReturnCallback(
            function (string $cond, mixed $value) use (&$wheres, $select): Select {
                $wheres[] = [$cond, $value];
                return $select;
            }
        );
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['submission_id' => '9', 'body' => 'Hi'],
            false
        );

        $this->assertSame(['submission_id' => 9, 'body' => 'Hi'], $messages->customerMessage(31));
        $this->assertNull($messages->customerMessage(32));
        $this->assertSame(['message_id = ?', 31], $wheres[0]);
        $this->assertSame(['author = ?', 'customer'], $wheres[1]);
    }

    public function testItReadsAtMostTheLatestMessagesWithAdminNames(): void
    {
        $messages = $this->messages();
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->expects($this->once())->method('limit')->with(101)->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $calls = 0;
        $this->connection->method('fetchAll')->willReturnCallback(
            static function () use (&$calls): array {
                return ++$calls === 1
                ? [
                    ['message_id' => 3, 'author' => 'customer', 'admin_user_id' => null, 'body' => 'c'],
                    ['message_id' => 2, 'author' => 'admin', 'admin_user_id' => 9, 'body' => 'b'],
                    ['message_id' => 1, 'author' => 'admin', 'admin_user_id' => 8, 'body' => 'a'],
                ]
                : [['user_id' => 9, 'firstname' => 'Ann', 'lastname' => 'Lee']];
            }
        );

        $page = $messages->page(7);

        $this->assertSame([1, 2, 3], array_column($page['messages'], 'message_id'));
        $this->assertSame(['Admin', 'Ann Lee', 'Customer'], array_column($page['messages'], 'author_name'));
        $this->assertFalse($page['earlier']);
    }

    public function testItStoresACustomerReplyWithoutAnAdminUser(): void
    {
        $this->asserted = ['connection'];
        $messages = $this->messages();
        $this->connection->expects($this->once())->method('insert')->with(
            'furan_formbuilder_message',
            ['submission_id' => 7, 'author' => 'customer', 'admin_user_id' => null, 'body' => 'Thanks']
        );
        $this->connection->method('lastInsertId')->willReturn('56');

        $this->assertSame(56, $messages->addCustomer(7, 'Thanks', 'customer'));
    }

    public function testItNeverSelectsAdminUsersForTheCustomerThread(): void
    {
        $this->asserted = ['connection'];
        $messages = $this->messages();
        $select = $this->createMock(Select::class);
        $select->expects($this->once())->method('from')->with(
            'furan_formbuilder_message',
            $this->callback(static fn (array $cols): bool => !in_array('admin_user_id', $cols, true))
        )->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->expects($this->once())->method('limit')->with(101)->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->expects($this->once())->method('fetchAll')->willReturn([
            ['message_id' => 2, 'author' => 'customer', 'body' => 'b', 'created_at' => 'x'],
            ['message_id' => 1, 'author' => 'admin', 'body' => 'a', 'created_at' => 'y'],
        ]);

        $rows = $messages->pageForCustomer(7, 'My Shop')['messages'];

        $this->assertSame([1, 2], array_column($rows, 'message_id'));
        $this->assertSame(['My Shop', 'You'], array_column($rows, 'author_name'));
    }

    public function testItFlagsEarlierMessagesAndPagesBackByMessageId(): void
    {
        $messages = $this->messages();
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $wheres = [];
        $select->method('where')->willReturnCallback(function (string $cond, $value = null) use (&$wheres, $select) {
            $wheres[] = [$cond, $value];
            return $select;
        });
        $select->method('order')->with('message_id DESC')->willReturnSelf();
        $select->method('limit')->with(101)->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $rows = array_map(
            static fn (int $id): array => ['message_id' => $id, 'author' => 'customer', 'body' => 'x', 'created_at' => 'y'],
            range(400, 300)
        );
        $this->connection->method('fetchAll')->willReturn($rows);

        $page = $messages->pageForCustomer(7, 'Shop', 401);

        $this->assertSame([['submission_id = ?', 7], ['message_id < ?', 401]], $wheres);
        $this->assertTrue($page['earlier']);
        $this->assertCount(100, $page['messages']);
        $this->assertSame(301, $page['messages'][0]['message_id']);
        $this->assertSame(400, $page['messages'][99]['message_id']);
    }

    public function testItCountsThreadMessagesUpToACap(): void
    {
        $this->asserted = ['connection'];
        $messages = $this->messages();
        $inner = $this->createMock(Select::class);
        $inner->method('from')->with('furan_formbuilder_message', $this->anything())->willReturnSelf();
        $inner->method('where')->with('submission_id = ?', 7)->willReturnSelf();
        $inner->expects($this->once())->method('limit')->with(201)->willReturnSelf();
        $outer = $this->createMock(Select::class);
        $outer->expects($this->once())->method('from')->with(['t' => $inner], $this->anything())->willReturnSelf();
        $this->connection->method('select')->willReturnOnConsecutiveCalls($inner, $outer);
        $this->connection->expects($this->once())->method('fetchOne')->with($outer)->willReturn('201');

        $this->assertSame(201, $messages->countUpTo(7, 200));
    }

    public function testItMovesAnAnsweredSubmissionBackToInProgressThroughTheStatusWriter(): void
    {
        $this->asserted = ['connection', 'statusWriter'];
        $messages = $this->messages();
        $this->connection->expects($this->never())->method('update');
        $this->statusWriter->expects($this->once())->method('change')->with([7], 1, 'customer', 2);

        $messages->reopenIfAnswered(7);
    }

    public function testItKeepsTheCustomerReplyWhenTheWebhookWriteFails(): void
    {
        $messages = $this->messages();
        $this->statusWriter->method('change')->willThrowException(new \RuntimeException('secret broker host'));
        $logged = [];
        $this->logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$logged): void {
            $logged[] = $message . json_encode($context);
        });

        $messages->reopenIfAnswered(7);

        $this->assertCount(1, $logged);
        $this->assertStringContainsString('RuntimeException', $logged[0]);
        $this->assertStringContainsString('7', $logged[0]);
        $this->assertStringNotContainsString('secret broker host', $logged[0]);
    }
}
