<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\FieldGroup;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * What the form builder's field group endpoints do, as [HTTP code, JSON body] pairs. Group ids and content
 * only ever come from the stored row or the (re-normalised) posted copy, never from client-supplied ids.
 */
class BuilderGroups
{
    public const MAX_PAYLOAD_BYTES = 2000000;

    public function __construct(
        private readonly FieldGroupManager $manager,
        private readonly FieldGroupList $list,
        private readonly Json $json
    ) {
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    public function create(mixed $raw): array
    {
        if (!is_string($raw)) {
            return $this->failure(400, (string)__('The group could not be read.'));
        }
        if (strlen($raw) > self::MAX_PAYLOAD_BYTES) {
            return $this->failure(413, (string)__('This group is too large to save.'));
        }
        try {
            $data = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException) {
            $data = null;
        }
        if (!is_array($data) || array_is_list($data)) {
            return $this->failure(400, (string)__('The group could not be read.'));
        }
        try {
            $group = $this->manager->save([
                'name' => $data['name'] ?? '',
                'description' => $data['description'] ?? '',
                'fields' => $data['fields'] ?? null,
                'translations' => $data['translations'] ?? [],
            ], null);
        } catch (LocalizedException $e) {
            return [422, ['success' => false, 'errors' => explode("\n", $e->getMessage())]];
        }
        return [200, ['success' => true, 'id' => (int)$group->getId(), 'name' => (string)$group->getData('name')]];
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    public function palette(int $afterId, int $limit = FieldGroupList::DEFAULT_LIMIT): array
    {
        $page = $this->list->page($afterId, $limit);
        $groups = array_map(
            static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'description' => $row['description']],
            $page['rows']
        );
        return [200, ['success' => true, 'groups' => $groups, 'next' => $page['next']]];
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    public function fetch(int $id): array
    {
        try {
            $group = $this->manager->get($id);
        } catch (NoSuchEntityException $e) {
            return $this->failure(404, $e->getMessage());
        }
        return [200, [
            'success' => true,
            'id' => (int)$group->getId(),
            'name' => (string)$group->getData('name'),
            'fields' => $this->manager->fieldsOf($group),
            'translations' => $this->manager->translationsOf($group),
        ]];
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private function failure(int $code, string $message): array
    {
        return [$code, ['success' => false, 'errors' => [$message]]];
    }
}
