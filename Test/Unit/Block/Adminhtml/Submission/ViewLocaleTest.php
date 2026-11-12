<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Submission;

use Furan\FormBuilder\Block\Adminhtml\Submission\View;
use Furan\FormBuilder\Model\Submission\AnswerDate;
use Furan\FormBuilder\Model\Submission\AnswerView;
use Furan\FormBuilder\Model\Submission\ValueFormatter;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Magento\Framework\Locale\ResolverInterface;
use PHPUnit\Framework\TestCase;

class ViewLocaleTest extends TestCase
{
    public function testItShowsAnswersInTheAdminLocale(): void
    {
        $pool = FieldPools::adminView();
        $resolver = $this->createStub(ResolverInterface::class);
        $resolver->method('getLocale')->willReturn('fr_FR');
        $block = (new \ReflectionClass(View::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(View::class, 'answerView'))->setValue($block, new AnswerView(new ValueFormatter($pool, new AnswerDate()), $pool));
        (new \ReflectionProperty(View::class, 'answerDate'))->setValue($block, new AnswerDate());
        (new \ReflectionProperty(View::class, 'localeResolver'))->setValue($block, $resolver);
        $address = ['type' => 'address', 'value' => ['city' => 'Lyon', 'country_id' => 'GB']];

        $this->assertSame(['Royaume-Uni'], $block->getDisplayValues(['type' => 'country', 'value' => 'GB']));
        $this->assertSame('Lyon, Royaume-Uni', $block->getDisplayHtml($address, EscaperFactory::create()));
        $this->assertSame('2 oct. 2026', $block->getDisplayDate(['type' => 'date', 'value' => '2026-10-02']));
        $this->assertTrue($block->isAnswered(['type' => 'country', 'value' => 'GB']));
    }
}
