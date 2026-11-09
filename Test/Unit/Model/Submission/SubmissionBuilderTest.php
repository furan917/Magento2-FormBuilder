<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterfaceFactory;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Http\IpAnonymizer;
use Furan\FormBuilder\Model\Submission\EmailHasher;
use Furan\FormBuilder\Model\Submission\SubmissionBuilder;
use Furan\FormBuilder\Model\Submission\SummaryBuilder;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class SubmissionBuilderTest extends TestCase
{
    private SubmissionStub $submission;

    private function builder(): SubmissionBuilder
    {
        $this->submission = new SubmissionStub();
        $this->submission->setStatus(2);
        $factory = $this->createStub(SubmissionInterfaceFactory::class);
        $factory->method('create')->willReturn($this->submission);
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturn('k0 k1');

        return new SubmissionBuilder(
            $factory,
            new EmailHasher($config),
            new SummaryBuilder(FieldPools::withFiles(), new \Furan\FormBuilder\Model\Submission\ValueFormatter(FieldPools::withFiles())),
            new IpAnonymizer()
        );
    }

    private function form(): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(7);

        return $form;
    }

    public function testItStoresANewSubmissionWithTheNewStatusConstant(): void
    {
        $submission = $this->builder()->build($this->form(), [], [], [], [], new SubmissionInput(storeId: 2));

        $this->assertSame(SubmissionInterface::STATUS_NEW, $submission->getStatus());
        $this->assertSame(7, $submission->getFormId());
        $this->assertSame(2, $submission->getStoreId());
    }

    public function testItKeepsTheIpOnlyWhenTheFormStoresIps(): void
    {
        $input = new SubmissionInput(clientIp: '203.0.113.77');

        $kept = $this->builder()->build($this->form(), ['store_ip' => true], [], [], [], $input);
        $this->assertSame('203.0.113.0', $kept->getRemoteIp());

        $dropped = $this->builder()->build($this->form(), [], [], [], [], $input);
        $this->assertNull($dropped->getRemoteIp());
    }

    public function testItBuildsThePayloadFromValuesAndFileMetadataInFieldOrder(): void
    {
        $fields = [
            ['name' => 'n', 'label' => 'Name', 'type' => 'text'],
            ['name' => 'cv', 'type' => 'file'],
            ['name' => 'skipped', 'type' => 'text'],
        ];

        $payload = $this->builder()->payload($fields, ['n' => 'Ann'], ['cv' => ['name' => 'cv.pdf']]);

        $this->assertSame([
            ['name' => 'n', 'label' => 'Name', 'type' => 'text', 'value' => 'Ann'],
            ['name' => 'cv', 'label' => 'cv', 'type' => 'file', 'value' => ['name' => 'cv.pdf']],
        ], $payload);
    }

    public function testItKeepsAnEmptyEntryForAVisibleFileFieldLeftWithoutAnUpload(): void
    {
        $fields = [
            ['name' => 'n', 'label' => 'Name', 'type' => 'text'],
            ['name' => 'cv', 'label' => 'CV', 'type' => 'file'],
            ['name' => 'hidden_cv', 'type' => 'file'],
        ];

        $payload = $this->builder()->payload($fields, ['n' => ''], [], ['cv']);

        $this->assertSame([
            ['name' => 'n', 'label' => 'Name', 'type' => 'text', 'value' => ''],
            ['name' => 'cv', 'label' => 'CV', 'type' => 'file', 'value' => ''],
        ], $payload);
    }
}
