<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Stub;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Block\Form;
use Furan\FormBuilder\Test\Unit\View\Stub\FakeItemBlock;

/** Renders templates by including them directly, recording each render through the seam. */
class TestableForm extends Form
{
    /** @var array<int, array{0: string, 1: array}> */
    public array $rendered = [];
    /** @var array<string, string> templateId => absolute file overriding the module template */
    public array $stubs = [];
    public ?FormInterface $lazyForm = null;
    /** @var array<string, array> route => params of the last getUrl() call for it */
    public array $urlParams = [];

    public function getForm(): ?FormInterface
    {
        return $this->lazyForm ?? parent::getForm();
    }

    public function getUrl($route = '', $params = []): string
    {
        $this->urlParams[(string)$route] = $params;
        $query = isset($params['_query']) ? '?' . http_build_query($params['_query']) : '';
        return 'https://shop.test/' . $route . $query;
    }

    public function getViewFileUrl($fileId, array $params = []): string
    {
        return 'https://static.test/' . str_replace('::', '/', $fileId);
    }

    protected function renderTemplate(string $templateId, array $vars): string
    {
        $this->rendered[] = [$templateId, $vars];
        return (new FakeItemBlock($vars, $this->stubs))->render($templateId);
    }
}
