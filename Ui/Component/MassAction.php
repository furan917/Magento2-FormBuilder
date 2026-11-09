<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Ui\Component;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Ui\Component\MassAction as BaseMassAction;

/**
 * Core mass actions have no ACL setting; this drops actions the admin user is not allowed to run. On a form's own
 * grid the form id is added to every action URL, because the grid's form filter only reads it from the request.
 */
class MassAction extends BaseMassAction
{
    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        private readonly AuthorizationInterface $authorization,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $components, $data);
    }

    public function prepare()
    {
        $acl = (array)($this->getData('acl') ?? []);
        $formId = $this->formId();
        foreach ($this->getChildComponents() as $action) {
            $config = $action->getConfiguration();
            $resource = $acl[$config['type'] ?? ''] ?? null;
            if ($resource !== null && !$this->authorization->isAllowed((string)$resource)) {
                $config = ['actionDisable' => true] + $config;
            }
            $action->setData('config', $formId === null ? $config : $this->scoped($config, $formId));
        }
        parent::prepare();
    }

    private function formId(): ?int
    {
        $value = $this->getContext()->getRequestParam('form_id');
        return is_string($value) && ctype_digit($value) && (int)$value > 0 ? (int)$value : null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function scoped(array $config, int $formId): array
    {
        if (is_string($config['url'] ?? null)) {
            $config['url'] .= (str_contains($config['url'], '?') ? '&' : '?') . 'form_id=' . $formId;
        }
        if (is_array($config['actions'] ?? null)) {
            $config['actions'] = array_map(
                fn (mixed $child): mixed => is_array($child) ? $this->scoped($child, $formId) : $child,
                $config['actions']
            );
        }
        return $config;
    }
}
