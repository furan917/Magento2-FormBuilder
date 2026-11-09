<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\I18n;

use Furan\FormBuilder\Test\Unit\Helper\ModelFixtures;
use Furan\FormBuilder\Model\FieldType\Consent;
use Furan\FormBuilder\Model\FieldType\Matrix;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Select;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\Form;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Url\RedirectValidator;
use Furan\FormBuilder\Test\Unit\Helper\TranslatorFactory;
use PHPUnit\Framework\TestCase;

class TranslatorTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/translatable-texts.json'), true);
    }

    private function translator(bool $urlsAllowed = true): Translator
    {
        $redirects = $this->createStub(RedirectValidator::class);
        $redirects->method('isAllowed')->willReturn($urlsAllowed);
        return TranslatorFactory::create($redirects, new Pool([
            'text' => new Text(), 'select' => new Select(), 'matrix' => new Matrix(), 'consent' => new Consent(), 'date_range' => new \Furan\FormBuilder\Model\FieldType\DateRange(),
        ]));
    }

    private function overlay(): array
    {
        return [
            'form' => ['submit_label' => ['t' => 'Envoyer', 's' => 'Send']],
            'items' => [
                'p_aaaa01' => ['title' => ['t' => 'À propos', 's' => 'About you']],
                'f_aaaa03' => [
                    'label' => ['t' => 'Nom', 's' => 'Name'],
                    'rules' => ['min_length' => ['message' => ['t' => 'Trop court', 's' => 'Too short']]],
                    'settings' => ['prefix' => ['t' => '€', 's' => '£']],
                ],
                'f_aaaa05' => ['options' => ['two.2' => ['label' => ['t' => 'Deux', 's' => 'Two']]]],
                'f_aaaa06' => ['settings' => ['rows' => ['1' => ['t' => 'Prix', 's' => 'Price']]]],
            ],
        ];
    }

    public function testItAppliesTranslationsAndFallsBackToTheDefaultText(): void
    {
        [$schema, $settings] = $this->translator()->apply($this->fixture['schema'], $this->fixture['settings'], $this->overlay());
        $page = $schema['pages'][0];
        $name = $page['items'][0]['items'][0];

        $this->assertSame('Envoyer', $settings['submit_label']);
        $this->assertSame('Next', $settings['next_label']);
        $this->assertSame('New enquiry', $settings['notify_subject']);
        $this->assertSame('À propos', $page['title']);
        $this->assertSame('Nom', $name['label']);
        $this->assertSame('Your full name', $name['help']);
        $this->assertSame('Trop court', $name['rules'][0]['message']);
        $this->assertSame('€', $name['settings']['prefix']);
        $this->assertSame(['One', 'Deux'], array_column($page['items'][2]['options'], 'label'));
        $this->assertSame(['1', 'two.2'], array_column($page['items'][2]['options'], 'value'));
        $this->assertSame(['Speed', 'Prix'], $page['items'][3]['settings']['rows']);
    }

    public function testItKeepsOnlyCleanTranslationsOfTextsTheFormStillHas(): void
    {
        $raw = [
            '2' => [
                'form' => [
                    'submit_label' => ['t' => "Envoyer\x07 " . str_repeat('x', 80)],
                    'notify_subject' => ['t' => 'Not translatable', 's' => ''],
                    'page_title' => ['t' => 'Default is empty', 's' => ''],
                ],
                'items' => [
                    'f_gone' => ['label' => ['t' => 'Removed field', 's' => 'Old']],
                    'f_aaaa03' => ['label' => ['t' => '   ', 's' => 'Name'], 'help' => ['t' => "Ligne 1\r\nLigne 2", 's' => 'Your full name']],
                    'f_aaaa07' => ['settings' => ['privacy_url' => ['t' => 'https://evil.test/', 's' => '/privacy']]],
                ],
            ],
            '99' => ['form' => ['submit_label' => ['t' => 'Unknown store']]],
        ];

        $clean = $this->translator(false)->normalize($raw, $this->fixture['schema'], $this->fixture['settings'], [1, 2]);

        $this->assertSame([2], array_keys($clean));
        $this->assertSame(['submit_label'], array_keys($clean[2]['form']));
        $this->assertSame(50, mb_strlen($clean[2]['form']['submit_label']['t']));
        $this->assertSame('Send', $clean[2]['form']['submit_label']['s'], 'A missing source is recorded as today\'s default');
        $this->assertSame(['f_aaaa03'], array_keys($clean[2]['items']));
        $this->assertSame(['help'], array_keys($clean[2]['items']['f_aaaa03']));
        $this->assertSame("Ligne 1\nLigne 2", $clean[2]['items']['f_aaaa03']['help']['t']);
    }

    public function testItStripsTagsFromStoreViewTranslationsOfFormTexts(): void
    {
        $raw = [2 => ['form' => [
            'closed_message' => ['t' => '<b>Ferm\u00e9</b> <img src=x onerror=alert(1)>', 's' => '<i>Closed for now</i>'],
        ]]];

        $clean = $this->translator()->normalize($raw, $this->fixture['schema'], $this->fixture['settings'], [2]);

        $this->assertSame('Ferm\u00e9', $clean[2]['form']['closed_message']['t']);
        $this->assertSame('Closed for now', $clean[2]['form']['closed_message']['s']);
    }

    public function testItCountsTranslatedAndOutdatedTexts(): void
    {
        $overlay = $this->overlay();
        $overlay['items']['f_aaaa03']['label']['s'] = 'Full name';

        $progress = $this->translator()->progress($this->fixture['schema'], $this->fixture['settings'], $overlay);

        $this->assertSame(['total' => 31, 'translated' => 7, 'outdated' => 1], $progress);
    }

    public function testLocalizeReturnsACopyAndNeverChangesTheStoredForm(): void
    {
        $form = ModelFixtures::model(Form::class);
        $form->setSchema($this->fixture['schema'])->setSettings($this->fixture['settings'])->setTranslations([2 => $this->overlay()]);
        $translator = $this->translator();

        $french = $translator->localize($form, 2);

        $this->assertNotSame($form, $french);
        $this->assertSame('Envoyer', $french->getSettings()['submit_label']);
        $this->assertSame('Send', $form->getSettings()['submit_label']);
        $this->assertSame($form, $translator->localize($form, 1));
    }

    public function testUntouchedStockTextsFollowTheLanguagePackButAdminWordingIsKept(): void
    {
        $renderer = \Magento\Framework\Phrase::getRenderer();
        $french = $this->createStub(\Magento\Framework\Phrase\RendererInterface::class);
        $french->method('render')->willReturnCallback(
            fn(array $source) => ['Next' => 'Suivant', 'Submit' => 'Envoyer', 'Review your answers' => 'Vérifiez vos réponses'][$source[0]] ?? $source[0]
        );
        \Magento\Framework\Phrase::setRenderer($french);
        try {
            $form = ModelFixtures::model(Form::class);
            $form->setSchema(['pages' => [['id' => 'p_1', 'items' => [
                ['id' => 'c_1', 'kind' => 'content', 'type' => 'summary', 'title' => 'Review your answers'],
            ]]]])->setSettings(['submit_label' => 'Submit', 'next_label' => 'Next', 'prev_label' => 'Go back'])
                ->setTranslations([2 => ['form' => ['submit_label' => ['t' => 'Valider', 's' => 'Submit']]]]);

            $localized = $this->translator()->localize($form, 2);

            $this->assertSame('Valider', $localized->getSettings()['submit_label']);
            $this->assertSame('Suivant', $localized->getSettings()['next_label']);
            $this->assertSame('Go back', $localized->getSettings()['prev_label']);
            $this->assertSame('Vérifiez vos réponses', $localized->getSchema()['pages'][0]['items'][0]['title']);
        } finally {
            \Magento\Framework\Phrase::setRenderer($renderer);
        }
    }

    public function testItKeepsPageAndSectionMetadataWhenTranslating(): void
    {
        $condition = ['match' => 'all', 'rules' => []];
        $form = ModelFixtures::model(Form::class);
        $form->setSchema(['pages' => [['id' => 'p_1', 'title' => 'One', 'condition' => $condition, 'items' => [
            ['id' => 's_1', 'kind' => 'section', 'title' => 'Sec', 'condition' => null, 'skip' => $condition, 'items' => [
                ['id' => 'f_1', 'kind' => 'field', 'type' => 'text', 'name' => 'a', 'label' => 'Name'],
            ]],
        ]]]])->setTranslations([2 => ['items' => ['f_1' => ['label' => ['t' => 'Nom', 's' => 'Name']]]]]);

        $page = $this->translator()->localize($form, 2)->getSchema()['pages'][0];

        $this->assertSame('Nom', $page['items'][0]['items'][0]['label']);
        $this->assertSame($condition, $page['condition']);
        $this->assertSame('Sec', $page['items'][0]['title']);
        $this->assertNull($page['items'][0]['condition']);
        $this->assertSame($condition, $page['items'][0]['skip']);
    }

    public function testItTranslatesTheClosedMessagePerStoreView(): void
    {
        $renderer = \Magento\Framework\Phrase::getRenderer();
        $french = $this->createStub(\Magento\Framework\Phrase\RendererInterface::class);
        $french->method('render')->willReturnCallback(
            fn(array $source) => ['This form is not open yet.' => 'Ce formulaire n\'est pas encore ouvert.'][$source[0]] ?? $source[0]
        );
        \Magento\Framework\Phrase::setRenderer($french);
        try {
            $form = ModelFixtures::model(Form::class);
            $form->setSchema(['pages' => []])
                ->setSettings(['closed_message' => 'This form is closed.', 'not_open_message' => 'This form is not open yet.'])
                ->setTranslations([2 => ['form' => ['closed_message' => ['t' => 'Ce formulaire est fermé.', 's' => 'This form is closed.']]]]);

            $settings = $this->translator()->localize($form, 2)->getSettings();
            $other = $this->translator()->localize($form, 3)->getSettings();

            $this->assertSame('Ce formulaire est fermé.', $settings['closed_message']);
            $this->assertSame('Ce formulaire n\'est pas encore ouvert.', $settings['not_open_message']);
            $this->assertSame('This form is closed.', $other['closed_message']);
            $this->assertSame('This form is closed.', $form->getSettings()['closed_message']);
        } finally {
            \Magento\Framework\Phrase::setRenderer($renderer);
        }
    }
}
