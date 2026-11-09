<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Submission;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterfaceFactory;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Http\IpAnonymizer;
use Furan\FormBuilder\Model\Settings\FormSettings;

/**
 * The payload and the unsaved submission entity built from validated answers.
 */
class SubmissionBuilder
{
    public function __construct(
        private readonly SubmissionInterfaceFactory $submissionFactory,
        private readonly EmailHasher $emailHasher,
        private readonly SummaryBuilder $summaryBuilder,
        private readonly IpAnonymizer $ipAnonymizer
    ) {
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $fileMeta
     * @param string[] $unansweredFiles visible file fields without an upload, kept as empty answers
     * @return array<int, array{name: string, label: string, type: string, value: mixed}>
     */
    public function payload(array $fields, array $values, array $fileMeta, array $unansweredFiles = []): array
    {
        $payload = [];
        foreach ($fields as $field) {
            $name = $field['name'];
            if (array_key_exists($name, $values)) {
                $value = $values[$name];
            } elseif (isset($fileMeta[$name])) {
                $value = $fileMeta[$name];
            } elseif (in_array($name, $unansweredFiles, true)) {
                $value = '';
            } else {
                continue;
            }
            $payload[] = [
                'name' => $name,
                'label' => (string)($field['label'] ?? $name),
                'type' => (string)$field['type'],
                'value' => $value,
            ];
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @param array<int, array{name: string, label: string, type: string, value: mixed}> $payload
     */
    public function build(
        FormInterface $form,
        array $settings,
        array $fields,
        array $values,
        array $payload,
        SubmissionInput $input
    ): SubmissionInterface {
        $customerId = $input->customerId;
        $ip = !empty($settings['store_ip']) ? $this->ipAnonymizer->anonymize($input->clientIp) : null;
        $fingerprint = $this->emailHasher->hash(FormSettings::submitterEmail($input, $settings, $values));

        return $this->submissionFactory->create()
            ->setFormId((int)$form->getId())
            ->setStoreId($input->storeId)
            ->setCustomerId($customerId > 0 ? $customerId : null)
            ->setCustomerGroupId($input->customerGroupId)
            ->setEmailHash($fingerprint['hash'] ?? null)
            ->setEmailHashVersion($fingerprint['version'] ?? null)
            ->setStatus(SubmissionInterface::STATUS_NEW)
            ->setReference(Reference::toString(Reference::generate()))
            ->setCreatedAt(gmdate('Y-m-d H:i:s'))
            ->setPayload($payload)
            ->setSummary($this->summaryBuilder->build($fields, $values))
            ->setRemoteIp($ip);
    }
}
