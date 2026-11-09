<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Thread;

use Furan\FormBuilder\Model\Email\SafeAddress;
use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Model\Settings\FormSettings;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/** Address admin replies go to: the linked account's current email, else the form's email answer. */
class RecipientResolver
{
    public function __construct(private readonly CustomerRepositoryInterface $customerRepository)
    {
    }

    public function resolve(SubmissionInterface $submission, FormInterface $form): ?string
    {
        $customerId = (int)$submission->getCustomerId();
        $address = null;
        if ($customerId > 0) {
            try {
                $address = $this->customerRepository->getById($customerId)->getEmail();
            } catch (LocalizedException) {
                $address = null;
            }
        }
        $address ??= $this->answeredEmail($submission, $form);

        return SafeAddress::isValid($address) ? $address : null;
    }

    private function answeredEmail(SubmissionInterface $submission, FormInterface $form): ?string
    {
        $settings = $form->getSettings();
        $name = FormSettings::submitterEmailField($settings);
        if ($name === '') {
            return null;
        }
        foreach ($submission->getPayload() as $entry) {
            if (($entry['name'] ?? null) === $name && is_string($entry['value'] ?? null)) {
                return $entry['value'];
            }
        }

        return null;
    }
}
