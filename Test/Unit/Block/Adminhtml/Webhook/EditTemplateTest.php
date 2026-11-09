<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Block\Adminhtml\Webhook;

use Furan\FormBuilder\Block\Adminhtml\Webhook\Edit;
use Furan\FormBuilder\Test\Unit\Helper\EscaperFactory;
use PHPUnit\Framework\TestCase;

class EditTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../../view/adminhtml/templates/webhook/edit.phtml';

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function render(array $values = [], array $errors = [], bool $new = false, bool $readOnly = false, bool $locked = false, ?string $secret = null): string
    {
        $block = $this->createStub(Edit::class);
        $block->method('getValues')->willReturn($values + [
            'name' => 'CRM', 'url' => 'https://crm.example.com', 'is_active' => true, 'scope_type' => 'store',
            'scope_ids' => [1], 'all_forms' => true, 'form_ids' => [], 'events' => ['submission.created'],
            'field_mode' => 'all', 'fields' => '', 'include_personal' => false, 'debug_responses' => false,
        ]);
        $block->method('getFieldError')->willReturnCallback(static fn (string $f): ?string => $errors[$f] ?? null);
        $block->method('getFieldAria')->willReturnCallback(
            static fn (string $f, string $hint = '', bool $invalid = true): string => ' data-aria="' . $f . '|' . $hint . '|' . ($invalid ? 'invalid' : 'group') . '"'
        );
        $block->method('getFieldErrorHtml')->willReturnCallback(
            static fn (string $f): string => isset($errors[$f]) ? '<p data-error-for="' . $f . '"></p>' : ''
        );
        $block->method('isNew')->willReturn($new);
        $block->method('getEndpointId')->willReturn($new ? 0 : 7);
        $block->method('isReadOnly')->willReturn($readOnly);
        $block->method('isReceiverLocked')->willReturn($locked);
        $block->method('getRevealedSecret')->willReturn($secret);
        $block->method('getEventOptions')->willReturn([
            'submission.created' => 'Created', 'submission.updated' => 'Updated', 'submission.status_changed' => 'Status',
        ]);
        $block->method('getWebsiteOptions')->willReturn([1 => 'Main']);
        $block->method('getStoreOptions')->willReturn([1 => 'Default']);
        $block->method('getFormOptions')->willReturn([3 => 'Contact']);
        $block->method('getViewFileUrl')->willReturn('/static/x.css');
        $escaper = EscaperFactory::create();

        return (static function () use ($block, $escaper): string {
            ob_start();
            include self::TEMPLATE;
            return (string)ob_get_clean();
        })();
    }

    public function testItRendersEachFieldErrorNextToItsControlAndDescribesTheControlFromTheBlock(): void
    {
        $html = $this->render([], ['url' => 'Bad', 'events' => 'Pick one']);

        $this->assertMatchesRegularExpression('/id="ffb-webhook-url"[^>]*data-aria="url\|ffb-webhook-url-hint\|invalid"/', $html);
        $this->assertMatchesRegularExpression('/id="ffb-webhook-name"[^>]*data-aria="name\|\|invalid"/', $html);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*data-aria="events\|\|group"/', $html);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*data-aria="scope\|ffb-webhook-scope-hint\|group"/', $html);
        $this->assertMatchesRegularExpression('/id="ffb-webhook-url"[^>]*>.*?<p data-error-for="url"><\/p>/s', $html);
        $this->assertStringContainsString('<p data-error-for="events"></p>', $html);
        $this->assertStringNotContainsString('data-error-for="name"', $html);
    }

    public function testItRendersTheRevealedSecretAsANamelessReadonlyInputWithACopyButton(): void
    {
        $html = $this->render([], [], false, false, false, 's3cr"et');

        $this->assertMatchesRegularExpression('/<input(?![^>]*\sname=)[^>]*id="ffb-webhook-secret-value"[^>]*readonly[^>]*autocomplete="off"[^>]*spellcheck="false"[^>]*value="s3cr&quot;et"/', $html);
        $this->assertStringContainsString('New signing secret, shown once', $html);
        $this->assertStringContainsString('data-ffb-copy-secret', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testItAsksForConfirmationBeforeRollingTheSecret(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression('/<details[^>]*>\s*<summary[^>]*>Roll secret<\/summary>.*?name="window_hours"/s', $html);
        $this->assertStringContainsString('0 hours', $html);
        $this->assertStringNotContainsString('Needs the permission', $html);
        $this->assertStringNotContainsString('rotate', $html);
    }

    public function testItRendersASingleColumnWhenThereIsNoSidebar(): void
    {
        $this->assertStringContainsString('ffb-rule-edit-single', $this->render([], [], true));
        $this->assertStringContainsString('ffb-rule-edit-single', $this->render([], [], false, true));
        $this->assertStringNotContainsString('ffb-rule-edit-single', $this->render());
    }

    public function testItPostsTheStoredEventsOfALockedEndpoint(): void
    {
        $html = $this->render(['events' => ['submission.created', 'submission.updated'], 'debug_responses' => false, 'is_active' => false], [], false, false, true);

        $this->assertSame(2, preg_match_all('/<input type="hidden" name="events\[\]" value="submission\.(created|updated)">/', $html));
        $this->assertDoesNotMatchRegularExpression('/<input type="checkbox" name="events\[\]"(?![^>]*disabled)/', $html);
        $this->assertMatchesRegularExpression('/name="debug_responses"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/name="is_active"[^>]*disabled/', $html);
    }

    public function testItKeepsTheHiddenScopeCopiesOfALockedEndpoint(): void
    {
        $html = $this->render(['scope_type' => 'website', 'scope_ids' => [1]], [], false, false, true);

        $this->assertStringContainsString('<input type="hidden" name="scope_type" value="website">', $html);
        $this->assertStringContainsString('<input type="hidden" name="website_ids[]" value="1">', $html);
        $this->assertDoesNotMatchRegularExpression('/<input type="radio" name="scope_type"(?![^>]*disabled)/', $html);
    }
}
