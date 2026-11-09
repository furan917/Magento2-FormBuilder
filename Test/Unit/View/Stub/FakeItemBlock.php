<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\View\Stub;

use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use Magento\Framework\DataObject;

/** Minimal stand-in for a Template block: assign() view vars, template-id resolution and fetchView(). */
class FakeItemBlock extends DataObject
{
    /** @var array<string, mixed> */
    private array $viewVars = [];

    /** @param array<string, string> $stubs templateId => absolute file overriding the module template */
    public function __construct(array $data = [], private readonly array $stubs = [])
    {
        parent::__construct($data);
    }

    public function assign($key, $value = null): static
    {
        foreach (is_array($key) ? $key : [$key => $value] as $name => $item) {
            $this->viewVars[$name] = $item;
        }
        return $this;
    }

    public function getTemplateFile(string $templateId): string
    {
        return $this->stubs[$templateId]
            ?? __DIR__ . '/../../../../view/frontend/templates/' . explode('::', $templateId)[1];
    }

    public function fetchView(string $file): string
    {
        $block = $this;
        $escaper = EscaperFactory::create();
        $run = static function (string $__file, array $__vars) use ($block, $escaper): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } finally {
                $output = (string)ob_get_clean();
            }
            return $output;
        };
        return $run($file, $this->viewVars);
    }

    public function render(string $templateId): string
    {
        return $this->fetchView($this->getTemplateFile($templateId));
    }
}
