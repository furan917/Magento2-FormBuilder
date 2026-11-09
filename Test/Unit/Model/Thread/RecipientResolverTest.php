<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Thread;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Thread\RecipientResolver;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class RecipientResolverTest extends TestCase
{
    private function resolve(?int $customerId, array $payload, array $settings, ?string $accountEmail = null): ?string
    {
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getCustomerId')->willReturn($customerId);
        $submission->method('getPayload')->willReturn($payload);
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn($settings);
        $customers = $this->createStub(CustomerRepositoryInterface::class);
        if ($accountEmail === null) {
            $customers->method('getById')->willThrowException(new NoSuchEntityException());
        } else {
            $customer = $this->createStub(CustomerInterface::class);
            $customer->method('getEmail')->willReturn($accountEmail);
            $customers->method('getById')->willReturn($customer);
        }
        return (new RecipientResolver($customers))->resolve($submission, $form);
    }

    public function testItPrefersTheLiveAccountEmailOfALinkedCustomer(): void
    {
        $this->assertSame('account@example.com', $this->resolve(
            12,
            [['name' => 'mail', 'value' => 'typed@example.com']],
            ['email_field' => 'mail'],
            'account@example.com'
        ));
    }

    public function testItFallsBackToTheEmailFieldThenTheAutoresponderField(): void
    {
        $payload = [['name' => 'a', 'value' => 'a@example.com'], ['name' => 'b', 'value' => 'b@example.com']];
        $this->assertSame('a@example.com', $this->resolve(null, $payload, ['email_field' => 'a', 'autoresponder_email_field' => 'b']));
        $this->assertSame('b@example.com', $this->resolve(null, $payload, ['autoresponder_email_field' => 'b']));
        $this->assertSame('a@example.com', $this->resolve(5, $payload, ['email_field' => 'a']));
    }

    public function testItRejectsMissingInvalidOrHeaderInjectingAddresses(): void
    {
        $this->assertNull($this->resolve(null, [], []));
        $this->assertNull($this->resolve(null, [['name' => 'a', 'value' => 'nope']], ['email_field' => 'a']));
        $this->assertNull($this->resolve(null, [['name' => 'a', 'value' => "a@b.co\r\nBcc: x@y.co"]], ['email_field' => 'a']));
        $this->assertNull($this->resolve(null, [['name' => 'a', 'value' => ['a@b.co']]], ['email_field' => 'a']));
    }
}
