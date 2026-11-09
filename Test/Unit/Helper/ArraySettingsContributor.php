<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Api\FormSettingsContributorInterface;
use Furan\FormBuilder\Model\Settings\ContributorPool;
use Psr\Log\NullLogger;

/** Form settings contributor with a fixed spec. */
class ArraySettingsContributor implements FormSettingsContributorInterface
{
    public function __construct(
        private readonly string $code = 'acme_crm',
        private readonly ?array $spec = null,
        private readonly ?string $acl = null
    ) {
    }

    public static function pool(FormSettingsContributorInterface ...$contributors): ContributorPool
    {
        $byCode = [];
        foreach ($contributors as $contributor) {
            $byCode[$contributor->getCode()] = $contributor;
        }
        return new ContributorPool(new NullLogger(), $byCode);
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return 'Acme CRM';
    }

    public function getSpec(): array
    {
        return $this->spec ?? [
            'list_id' => ['type' => 'string', 'default' => 'main', 'label' => 'List id', 'max_length' => 10],
            'sync' => ['type' => 'bool', 'default' => true, 'label' => 'Sync'],
            'mode' => ['type' => 'enum', 'default' => 'a', 'values' => ['a', 'b'], 'label' => 'Mode'],
        ];
    }

    public function getAclResource(): ?string
    {
        return $this->acl;
    }
}
