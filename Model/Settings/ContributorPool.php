<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Settings;

use Furan\FormBuilder\Api\FormSettingsContributorInterface;
use Psr\Log\LoggerInterface;

class ContributorPool
{
    public const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** @var array<string, FormSettingsContributorInterface> */
    private readonly array $contributors;

    /**
     * @param FormSettingsContributorInterface[] $contributors keyed by contributor code
     */
    public function __construct(LoggerInterface $logger, array $contributors = [])
    {
        $valid = [];
        foreach ($contributors as $key => $contributor) {
            if (!$contributor instanceof FormSettingsContributorInterface
                || $contributor->getCode() !== (string)$key
                || preg_match(self::CODE_PATTERN, (string)$key) !== 1
            ) {
                $logger->warning(sprintf('Form settings contributor pool item "%s" skipped: it does not match its code.', $key));
                continue;
            }
            $valid[(string)$key] = $contributor;
        }
        $this->contributors = $valid;
    }

    public function has(string $code): bool
    {
        return isset($this->contributors[$code]);
    }

    /**
     * @return array<string, FormSettingsContributorInterface>
     */
    public function all(): array
    {
        return $this->contributors;
    }
}
