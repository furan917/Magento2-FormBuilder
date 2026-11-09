<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Settings;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Settings\ContributorPool;
use Furan\FormBuilder\Model\Settings\FormSettings;
use Furan\FormBuilder\Test\Unit\Helper\ArraySettingsContributor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FormSettingsTest extends TestCase
{
    private function form(array $settings): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn($settings);
        return $form;
    }

    public function testItFillsMissingKeysWithContributorDefaults(): void
    {
        $settings = new FormSettings(ArraySettingsContributor::pool(new ArraySettingsContributor()));

        $this->assertSame(
            ['list_id' => 'vip', 'sync' => true, 'mode' => 'a'],
            $settings->extension($this->form(['ext' => ['acme_crm' => ['list_id' => 'vip']]]), 'acme_crm')
        );
        $this->assertSame(['list_id' => 'main', 'sync' => true, 'mode' => 'a'], $settings->extension($this->form([]), 'acme_crm'));
    }

    public function testItReturnsStoredValuesAsTheyAreForAnUnregisteredCode(): void
    {
        $settings = new FormSettings(ArraySettingsContributor::pool());

        $this->assertSame(['a' => 1], $settings->extension($this->form(['ext' => ['gone' => ['a' => 1]]]), 'gone'));
        $this->assertSame([], $settings->extension($this->form([]), 'gone'));
    }

    public function testItSkipsAndLogsPoolItemsWithAMismatchedOrInvalidCode(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('warning');

        $pool = new ContributorPool($logger, [
            'wrong_key' => new ArraySettingsContributor('acme_crm'),
            'Bad-Code' => new ArraySettingsContributor('Bad-Code'),
            'plain' => new \stdClass(),
            'acme_crm' => new ArraySettingsContributor('acme_crm'),
        ]);

        $this->assertSame(['acme_crm'], array_keys($pool->all()));
    }

    public function testItResolvesTheSubmitterEmailFieldInOnePlace(): void
    {
        $settings = ['email_field' => 'mail', 'autoresponder_email_field' => 'other'];

        $this->assertSame('mail', FormSettings::submitterEmailField($settings));
        $this->assertSame('other', FormSettings::submitterEmailField(['email_field' => '', 'autoresponder_email_field' => 'other']));
        $this->assertSame('', FormSettings::submitterEmailField([]));

        $this->assertSame('c@x.test', FormSettings::submitterEmail(new SubmissionInput(customerEmail: 'c@x.test'), $settings, ['mail' => 'a@x.test']));
        $this->assertSame('a@x.test', FormSettings::submitterEmail(new SubmissionInput(), $settings, ['mail' => 'a@x.test']));
        $this->assertSame('b@x.test', FormSettings::submitterEmail(new SubmissionInput(), ['autoresponder_email_field' => 'other'], ['other' => 'b@x.test']));
        $this->assertSame('', FormSettings::submitterEmail(new SubmissionInput(), $settings, ['mail' => ['x']]));
    }
}
