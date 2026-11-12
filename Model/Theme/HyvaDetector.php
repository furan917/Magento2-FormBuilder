<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Theme;

use Magento\Framework\View\Design\ThemeInterface;
use Magento\Framework\View\DesignInterface;

/** Whether the storefront theme is Hyvä-based: the current design theme or one of its parents is a Hyva/* theme. */
class HyvaDetector
{
    private const PREFIX = 'Hyva/';
    private const MAX_DEPTH = 20;

    private ?bool $isHyva = null;

    public function __construct(private readonly DesignInterface $design)
    {
    }

    public function isHyva(): bool
    {
        return $this->isHyva ??= $this->inAncestry($this->design->getDesignTheme());
    }

    private function inAncestry(?ThemeInterface $theme): bool
    {
        for ($depth = 0; $theme !== null && $depth < self::MAX_DEPTH; $depth++) {
            if (str_starts_with((string)$theme->getCode(), self::PREFIX)) {
                return true;
            }
            $theme = $theme->getParentTheme();
        }
        return false;
    }
}
