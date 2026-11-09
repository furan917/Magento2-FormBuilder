<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\ViewModel\Submission;

use Furan\FormBuilder\Model\ResourceModel\Submission as SubmissionResource;
use Furan\FormBuilder\ViewModel\Submission\GridNotice;
use Magento\Framework\Locale\ResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GridNoticeTest extends TestCase
{
    private int $limit = 0;

    private function notice(string $locale, int $count = 0): GridNotice
    {
        $submissions = $this->createStub(SubmissionResource::class);
        $submissions->method('countUpTo')->willReturnCallback(function (int $limit) use ($count): int {
            $this->limit = $limit;

            return min($count, $limit);
        });
        $resolver = $this->createStub(ResolverInterface::class);
        $resolver->method('getLocale')->willReturn($locale);

        return new GridNotice($submissions, $resolver);
    }

    /** @return array<string, array{string, string}> */
    public static function locales(): array
    {
        return ['english' => ['en_US', '10,000'], 'german' => ['de_DE', '10.000']];
    }

    #[DataProvider('locales')]
    public function testItFormatsTheCapForTheAdminLocale(string $locale, string $expected): void
    {
        $this->assertSame($expected, $this->notice($locale)->formattedCap());
    }

    public function testItShowsTheCapNoticeOnlyWhenTheUnfilteredCountReachesTheCap(): void
    {
        $this->assertTrue($this->notice('en_US', 20000)->isVisible());
        $this->assertTrue($this->notice('en_US', 10001)->isVisible());
        $this->assertFalse($this->notice('en_US', 10000)->isVisible());
        $this->assertFalse($this->notice('en_US', 3)->isVisible());
    }

    public function testItCountsAtMostOneRowPastTheCap(): void
    {
        $this->notice('en_US', 5)->isVisible();

        $this->assertSame(10001, $this->limit);
    }
}
