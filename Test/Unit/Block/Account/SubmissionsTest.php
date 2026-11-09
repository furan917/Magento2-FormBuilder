<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Account;

use Furan\FormBuilder\Block\Account\Submissions;
use Furan\FormBuilder\Model\Account\SubmissionReader;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SubmissionsTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private const LAYOUT_DIR = __DIR__ . '/../../../../view/frontend/layout/';

    private SubmissionReader&Stub $reader;

    private function block(mixed $customerId, mixed $before = null, bool $answered = false): Submissions
    {
        $respondents = $this->createStub(Respondents::class);
        $respondents->method('customerKey')->willReturnCallback(static fn (int $id): string => 'c' . $id);
        $respondents->method('hasAnswered')->willReturnCallback(static fn (string $key): bool => $answered && $key === 'c7');
        $this->reader = $this->double('reader', SubmissionReader::class);
        $request = $this->createMock(Http::class);
        $request->method('getParam')->with('before')->willReturn($before);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getScopeConfig')->willReturn($this->createStub(ScopeConfigInterface::class));
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn($customerId);
        return new class ($context, $this->reader, $session, $respondents) extends Submissions {
            public function getUrl($route = '', $params = []): string
            {
                return 'https://shop.test/' . $route . '?' . http_build_query($params);
            }
        };
    }

    public function testItListsOnlyTheCustomersOwnSubmissionsNewestFirst(): void
    {
        $this->asserted = ['reader'];
        $block = $this->block('7');
        $page = ['rows' => [['submission_id' => '9']], 'older' => null];
        $this->reader->expects($this->once())->method('listPage')->with(7, 0, 20)->willReturn($page);

        $this->assertSame($page, $block->getPage());
        $block->getPage();
    }

    public function testItPagesByLastSeenId(): void
    {
        $this->asserted = ['reader'];
        $block = $this->block('7', '31abc');
        $this->reader->method('listPage')->with(7, 31, 20)->willReturn(['rows' => [], 'older' => 12]);

        $this->assertSame('https://shop.test/furanform/account/submissions?before=12', $block->getOlderUrl());
        $this->assertSame(
            'https://shop.test/furanform/account/submission?id=5',
            $block->getViewUrl(5)
        );
    }

    public function testItTreatsANegativeCursorAsTheFirstPage(): void
    {
        $this->asserted = ['reader'];
        $block = $this->block('7', '-4');
        $this->reader->expects($this->once())->method('listPage')->with(7, -4, 20)
            ->willReturn(['rows' => [], 'older' => null]);
        $this->assertNull($block->getOlderUrl());
    }

    public function testItAddsAMySubmissionsLinkToTheAccountNavigation(): void
    {
        $xml = simplexml_load_file(self::LAYOUT_DIR . 'customer_account.xml');
        $block = $xml->xpath('//referenceBlock[@name="customer_account_navigation"]/block')[0];
        $this->assertSame('Magento\Customer\Block\Account\SortLinkInterface', (string)$block['class']);
        $this->assertArrayNotHasKey('ifconfig', (array)$block->attributes());
        $args = [];
        foreach ($block->arguments->argument as $argument) {
            $args[(string)$argument['name']] = (string)$argument;
        }
        $this->assertSame('furanform/account/submissions', $args['path']);
        $this->assertSame('215', $args['sortOrder']);
        $this->assertSame('My submissions', $args['label']);
    }

    public function testItMarksTheAccountPageBlocksUncacheable(): void
    {
        foreach (['furanform_account_submissions.xml', 'furanform_account_submission.xml'] as $file) {
            $xml = simplexml_load_file(self::LAYOUT_DIR . $file);
            $this->assertCount(1, $xml->xpath('/page/update[@handle="customer_account"]'), $file);
            $blocks = $xml->xpath('//referenceContainer[@name="content"]/block');
            $this->assertCount(1, $blocks, $file);
            $this->assertSame('false', (string)$blocks[0]['cacheable'], $file);
        }
    }

    public function testItReturnsNoRowsWithoutACustomerId(): void
    {
        $this->asserted = ['reader'];
        $block = $this->block(null);
        $this->reader->method('listPage')->with(0, 0, 20)->willReturn(['rows' => [], 'older' => null]);
        $this->assertSame([], $block->getPage()['rows']);
    }

    public function testItWarnsAboutOneResponseFormsOnlyWhenTheCustomerAnsweredOne(): void
    {
        $this->assertTrue($this->block('7', null, true)->hasOneResponseAnswers());
        $this->assertFalse($this->block('7')->hasOneResponseAnswers());
        $this->assertFalse($this->block(null, null, true)->hasOneResponseAnswers());
    }
}
