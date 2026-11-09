<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Thread\RecipientResolver;
use Furan\FormBuilder\Model\Thread\ThreadAccess;
use Furan\FormBuilder\Model\Thread\ThreadToken;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Url;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ThreadAccessTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private ?int $version = 2;
    private ?string $recipient = 'guest@example.com';
    private ?int $storeId = 1;
    private ?int $customerId = null;
    private bool $exists = true;
    private string $keys = 'k1 k2';
    private array $urls = [];
    private ?StoreInterface $store = null;
    private bool $storeExists = true;
    private AdapterInterface&Stub $connection;

    private function access(): ThreadAccess
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(fn (string $path) => $path === 'crypt/key' ? $this->keys : null);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection = $this->double('connection', AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturnCallback(fn () => $this->version === null ? false : (string)$this->version);
        $this->connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, $value): string => str_replace('?', implode(',', (array)$value), $text)
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(42);
        $submission->method('getFormId')->willReturn(3);
        $submission->method('getStoreId')->willReturnCallback(fn () => $this->storeId);
        $submission->method('getCustomerId')->willReturnCallback(fn () => $this->customerId);
        $submissions = $this->createStub(SubmissionRepositoryInterface::class);
        $submissions->method('getById')->willReturnCallback(function (int $id) use ($submission) {
            if (!$this->exists || $id !== 42) {
                throw new NoSuchEntityException();
            }
            return $submission;
        });
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('getById')->willReturn($this->createStub(FormInterface::class));
        $recipients = $this->createStub(RecipientResolver::class);
        $recipients->method('resolve')->willReturnCallback(fn () => $this->recipient);
        $url = $this->createStub(Url::class);
        $url->method('getUrl')->willReturnCallback(function (string $route, array $params) {
            $this->urls[] = [$route, $params];
            return 'https://shop.test/' . $route . '?' . http_build_query($params['_query'] ?? []);
        });

        $this->store = $this->createStub(StoreInterface::class);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturnCallback(function ($id) {
            if (!$this->storeExists) {
                throw new NoSuchEntityException();
            }
            return $this->store;
        });

        return new ThreadAccess(
            $resource,
            new ThreadToken($config),
            new EmailHasher($config),
            $recipients,
            $submissions,
            $forms,
            $url,
            $stores
        );
    }

    private function guestLinkToken(): string
    {
        $url = $this->access()->replyUrl($this->submission(), 'guest@example.com');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return (string)($query['t'] ?? '');
    }

    private function submission(): SubmissionInterface
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getId')->willReturn(42);
        $submission->method('getStoreId')->willReturnCallback(fn () => $this->storeId);
        $submission->method('getCustomerId')->willReturnCallback(fn () => $this->customerId);
        return $submission;
    }

    public function testItLinksGuestsToTheSignedThreadPageOnTheSubmissionStore(): void
    {
        $token = $this->guestLinkToken();

        $this->assertMatchesRegularExpression('/^\d+\.42\.[0-9a-f]{64}$/D', $token);
        [$route, $params] = $this->urls[0];
        $this->assertSame('furanform/thread/view', $route);
        $this->assertTrue($params['_nosid']);
    }

    public function testItBuildsTheGuestAndOwnerReplyLinksForTheSubmissionsStoreObject(): void
    {
        $access = $this->access();
        $access->replyUrl($this->submission(), 'guest@example.com');
        $this->customerId = 9;
        $access->replyUrl($this->submission(), 'account@example.com');

        $this->assertCount(2, $this->urls);
        foreach ($this->urls as [$route, $params]) {
            $this->assertSame($this->store, $params['_scope'], $route);
            $this->assertTrue($params['_scope_to_url'], $route);
        }
    }

    public function testItGivesNoLinkWhenTheStoreCanNoLongerBeLoaded(): void
    {
        $this->storeExists = false;
        $this->assertSame('', $this->access()->replyUrl($this->submission(), 'guest@example.com'));
        $this->assertSame([], $this->urls);
    }

    public function testItLinksOwningCustomersToTheirAccountSubmissionPage(): void
    {
        $this->customerId = 9;
        $url = $this->access()->replyUrl($this->submission(), 'account@example.com');

        $this->assertSame('https://shop.test/furanform/account/submission?id=42', $url);
        $this->assertStringNotContainsString('t=', $url);
    }

    public function testItBuildsLinksWithTheFrontendUrlModelNotTheBackendOne(): void
    {
        $params = (new \ReflectionClass(ThreadAccess::class))->getConstructor()->getParameters();
        $types = array_map(static fn (\ReflectionParameter $p): string => (string)$p->getType(), $params);
        $this->assertContains(Url::class, $types);
        $this->assertNotContains(\Magento\Framework\UrlInterface::class, $types);
        $this->assertNotContains(\Magento\Backend\Model\UrlInterface::class, $types);
    }

    public function testItGivesNoLinkWhenTheSubmissionStoreIsGone(): void
    {
        $this->storeId = null;
        $this->assertSame('', $this->access()->replyUrl($this->submission(), 'guest@example.com'));
        $this->customerId = 9;
        $this->assertSame('', $this->access()->replyUrl($this->submission(), 'guest@example.com'));
    }

    public function testItGivesNoGuestLinkWithoutACryptKey(): void
    {
        $this->keys = '';
        $this->assertSame('', $this->access()->replyUrl($this->submission(), 'guest@example.com'));
    }

    public function testItRedeemsAValidTokenOnTheSubmissionStore(): void
    {
        $context = $this->access()->redeem($this->guestLinkToken(), 1);

        $this->assertNotNull($context);
        $this->assertSame(42, $context['submission']->getId());
        $this->assertSame(2, $context['version']);
        $this->assertCount(2, $context['fingerprints']);
    }

    public function testItRefusesTokensForAnotherStoreDeletedRowsAndRevokedOrRerecipientedThreads(): void
    {
        $token = $this->guestLinkToken();

        $this->assertNull($this->access()->redeem($token, 2), 'wrong store');
        $this->storeId = null;
        $this->assertNull($this->access()->redeem($token, 1), 'store deleted');
        $this->storeId = 1;
        $this->version = 3;
        $this->assertNull($this->access()->redeem($token, 1), 'revoked');
        $this->version = 2;
        $this->recipient = 'other@example.com';
        $this->assertNull($this->access()->redeem($token, 1), 'recipient changed');
        $this->recipient = null;
        $this->assertNull($this->access()->redeem($token, 1), 'anonymised');
        $this->recipient = 'guest@example.com';
        $this->exists = false;
        $this->assertNull($this->access()->redeem($token, 1), 'deleted');
        $this->exists = true;
        $this->assertNull($this->access()->redeem('garbage', 1));
        $this->assertNotNull($this->access()->redeem($token, 1));
    }

    public function testItLoadsAThreadContextOnlyForTheCurrentStore(): void
    {
        $this->assertNotNull($this->access()->load(42, 1));
        $this->assertNull($this->access()->load(42, 2));
        $this->assertNull($this->access()->load(0, 1));
    }

    public function testItRevokesLinksByBumpingTheCounter(): void
    {
        $this->asserted = ['connection'];
        $access = $this->access();
        $this->connection->expects($this->once())->method('update')->with(
            'furan_formbuilder_submission',
            $this->callback(static fn (array $bind): bool => (string)$bind['thread_token_version'] === 'thread_token_version + 1'),
            'submission_id IN (4,5)'
        );
        $access->revoke([4, 5]);
    }

    public function testItSkipsTheRevokeQueryForNoIds(): void
    {
        $this->asserted = ['connection'];
        $access = $this->access();
        $this->connection->expects($this->never())->method('update');
        $access->revoke([]);
    }
}
