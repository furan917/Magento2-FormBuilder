<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Submission;

use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Block\Adminhtml\Submission\View;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\AbstractBlock;
use PHPUnit\Framework\TestCase;

class ViewAnalyticsTest extends TestCase
{
    private function block(?SubmissionInterface $submission, bool $allowed): View
    {
        $block = (new \ReflectionClass(View::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(View::class, 'submission'))->setValue($block, $submission);
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => $allowed && $resource === 'Furan_FormBuilder::analytics'
        );
        (new \ReflectionProperty(\Magento\Backend\Block\Template::class, '_authorization'))->setValue($block, $authorization);
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => $route . '?' . http_build_query($params)
        );
        (new \ReflectionProperty(AbstractBlock::class, '_urlBuilder'))->setValue($block, $url);

        return $block;
    }

    private function submission(int $formId): SubmissionInterface
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getFormId')->willReturn($formId);
        $submission->method('getId')->willReturn(5);

        return $submission;
    }

    public function testItLinksToTheAnalyticsOfTheSubmissionsForm(): void
    {
        $this->assertSame('*/analytics/index?form_id=3', $this->block($this->submission(3), true)->getAnalyticsUrl());
    }

    public function testItShowsNoAnalyticsLinkWithoutThePermission(): void
    {
        $this->assertSame('', $this->block($this->submission(3), false)->getAnalyticsUrl());
    }
}
