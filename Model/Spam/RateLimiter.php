<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Spam;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\SpamCheckInterface;
use Furan\FormBuilder\Api\SpamResult;
use Furan\FormBuilder\Api\SubmissionInput;
use Furan\FormBuilder\Model\Config;
use Furan\FormBuilder\Model\Http\ClientIp;
use Furan\FormBuilder\Model\Throttle\Counter;
use Magento\Framework\Phrase;

class RateLimiter implements SpamCheckInterface
{
    /** @var callable */
    private $clock;

    public function __construct(
        private readonly Counter $counter,
        private readonly ClientIp $clientIp,
        private readonly Config $config,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** Per visitor and form. The per-form ceiling is applied by the processor once the token is spent. */
    public function check(SubmissionInput $input, FormInterface $form): SpamResult
    {
        $limit = $this->config->getRateLimit();
        if ($limit > 0 && !$this->slide($this->visitorKey($this->clientIp->bucketOf($input->clientIp), (string)(int)$form->getId()), $limit, $this->window())) {
            return SpamResult::reject(
                'rate_limit',
                new Phrase('Too many submissions. Please wait a few minutes and try again.'),
                429
            );
        }

        return SpamResult::pass();
    }

    /**
     * Backstop across all visitors. Only called for attempts that passed the per-visitor limit, spam checks
     * and token, so one source can use at most its own per-visitor share of it.
     */
    public function formCeilingReached(FormInterface $form): bool
    {
        $limit = $this->config->getFormRateLimit();
        if ($limit <= 0) {
            return false;
        }
        return !$this->slide('ffb_rlf_' . (int)$form->getId(), $limit, $this->window());
    }

    /** Counts one attempt for the current client in $scope; false once $limit is exceeded. */
    public function allow(string $scope, int $limit, int $windowSeconds): bool
    {
        return $this->slide($this->visitorKey($this->clientIp->bucket(), $scope), $limit, $windowSeconds);
    }

    private function visitorKey(string $bucket, string $scope): string
    {
        return 'ffb_rl_' . $bucket . '|' . $scope;
    }

    /**
     * Generic keyed limit: counts one hit on $key (an opaque caller-built string; only its digest is stored)
     * and returns false once more than $limit hits fall in the sliding window.
     */
    public function hit(string $key, int $limit, int $windowSeconds): bool
    {
        return $this->slide('ffb_key_' . $key, $limit, $windowSeconds);
    }

    /**
     * Sliding window: the previous window counts by the share of it still inside the last $window seconds,
     * so a burst straddling a boundary cannot reach twice the limit.
     */
    private function slide(string $base, int $limit, int $window): bool
    {
        $window = max(1, $window);
        $now = (int)($this->clock)();
        $bucket = intdiv($now, $window);
        $current = $this->counter->hit($base . '|' . $bucket, 2 * $window);
        $previous = $this->counter->peek($base . '|' . ($bucket - 1));

        return $previous * (1 - ($now % $window) / $window) + $current <= $limit;
    }

    private function window(): int
    {
        return max(1, $this->config->getRateWindowMinutes()) * 60;
    }
}
