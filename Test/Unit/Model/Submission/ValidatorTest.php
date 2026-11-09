<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Submission;

use Furan\FormBuilder\Model\Condition\Evaluator;
use Furan\FormBuilder\Model\FieldType\Email;
use Furan\FormBuilder\Model\FieldType\File;
use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\FieldType\Text;
use Furan\FormBuilder\Model\FieldType\Hidden;
use Furan\FormBuilder\Model\FieldType\Select;
use Furan\FormBuilder\Model\FieldType\Date;
use Furan\FormBuilder\Model\Condition\Today;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Furan\FormBuilder\Model\FieldType\Checkboxes;
use Furan\FormBuilder\Model\Submission\Validator;
use Furan\FormBuilder\Model\Validation\Rule\MinLength;
use Furan\FormBuilder\Model\Validation\RulePool;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ValidatorTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private LoggerInterface&Stub $logger;

    private function validator(array $extraTypes = []): Validator
    {
        $this->logger ??= $this->createStub(LoggerInterface::class);
        return new Validator(
            new Pool($extraTypes + [
                'text' => new Text(), 'email' => new Email(), 'file' => new File(),
                'checkboxes' => new Checkboxes(), 'hidden' => new Hidden(), 'select' => new Select(), 'date' => new Date(),
            ]),
            new RulePool(['min_length' => new MinLength()]),
            new Evaluator(\Furan\FormBuilder\Test\Unit\Helper\OperatorPools::builtIn(), \Furan\FormBuilder\Test\Unit\Helper\FieldPools::builtIn(), \Furan\FormBuilder\Test\Unit\Helper\ActionPools::builtIn()),
            $this->logger,
            new Today($this->createStub(ScopeConfigInterface::class))
        );
    }

    private function field(string $id, string $type, string $name, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'kind' => 'field', 'type' => $type, 'name' => $name, 'required' => false,
            'rules' => [], 'options' => [], 'settings' => [], 'condition' => null,
        ], $extra);
    }

    private function schema(array $items): array
    {
        return ['version' => 1, 'pages' => [['id' => 'p_aaaa', 'condition' => null, 'items' => $items]]];
    }

    private function hideWhenNo(): array
    {
        return ['match' => 'all',
            'rules' => [['field' => 'toggle', 'operator' => 'equals', 'value' => 'yes']]];
    }

    private function newsletterType(array $activeStores): \Furan\FormBuilder\Model\FieldType\Newsletter
    {
        $bridge = $this->createStub(\Furan\FormBuilder\Model\Newsletter\NewsletterBridge::class);
        $bridge->method('isAvailable')->willReturnCallback(
            static fn (?int $store): bool => $store === null || in_array($store, $activeStores, true)
        );

        return new \Furan\FormBuilder\Model\FieldType\Newsletter($bridge);
    }

    public function testItSkipsTheNewsletterFieldInValidationForAStoreWhereNewslettersAreOff(): void
    {
        $validator = $this->validator(['newsletter' => $this->newsletterType([1])]);
        $schema = $this->schema([$this->field('f_aaaa', 'newsletter', 'news'), $this->field('f_bbbb', 'text', 'name')]);

        $on = $validator->validate($schema, ['news' => 'yes', 'name' => 'Bob'], [], 1);
        $off = $validator->validate($schema, ['news' => 'yes', 'name' => 'Bob'], [], 2);

        $this->assertSame(['news' => 'yes', 'name' => 'Bob'], $on->getValues());
        $this->assertSame(['name' => 'Bob'], $off->getValues());
        $this->assertTrue($off->isValid());
    }

    public function testItReturnsNormalisedValuesForVisibleFields(): void
    {
        $result = $this->validator()->validate(
            $this->schema([$this->field('f_aaaa', 'text', 'name'), $this->field('f_bbbb', 'checkboxes', 'tags', [
                'options' => [['label' => 'A', 'value' => 'a']],
            ])]),
            ['name' => "  Bob\n", 'tags' => ['a']],
            []
        );
        $this->assertTrue($result->isValid());
        $this->assertSame(['name' => 'Bob', 'tags' => ['a']], $result->getValues());
    }

    public function testItReportsRequiredErrorsForEmptyVisibleRequiredFields(): void
    {
        $result = $this->validator()->validate(
            $this->schema([$this->field('f_aaaa', 'text', 'name', ['required' => true])]),
            ['name' => '  '],
            []
        );
        $this->assertFalse($result->isValid());
        $this->assertSame(['name' => 'This field is required.'], $result->getErrors());
    }

    public function testItSkipsValidationForFieldsHiddenByConditions(): void
    {
        $result = $this->validator()->validate(
            $this->schema([
                $this->field('f_aaaa', 'text', 'toggle'),
                $this->field('f_bbbb', 'email', 'mail', ['required' => true, 'condition' => $this->hideWhenNo()]),
            ]),
            ['toggle' => 'no', 'mail' => 'bad'],
            []
        );
        $this->assertTrue($result->isValid());
    }

    public function testItDiscardsValuesOfHiddenFields(): void
    {
        $result = $this->validator()->validate(
            $this->schema([
                $this->field('f_aaaa', 'text', 'toggle'),
                $this->field('f_bbbb', 'text', 'secret', ['condition' => $this->hideWhenNo()]),
            ]),
            ['toggle' => 'no', 'secret' => 'x'],
            []
        );
        $this->assertSame(['toggle' => 'no'], $result->getValues());
    }

    public function testItIgnoresPostedNamesThatAreNotInTheSchema(): void
    {
        $result = $this->validator()->validate(
            $this->schema([$this->field('f_aaaa', 'text', 'name')]),
            ['name' => 'a', 'evil' => 'b'],
            []
        );
        $this->assertSame(['name' => 'a'], $result->getValues());
    }

    public function testItUsesTheAdminMessageWhenARuleFails(): void
    {
        $schema = $this->schema([$this->field('f_aaaa', 'text', 'name', [
            'rules' => [['rule' => 'min_length', 'value' => 5, 'message' => 'Too short!']],
        ])]);
        $result = $this->validator()->validate($schema, ['name' => 'ab'], []);
        $this->assertSame(['name' => 'Too short!'], $result->getErrors());

        $schema['pages'][0]['items'][0]['rules'][0]['message'] = '';
        $result = $this->validator()->validate($schema, ['name' => 'ab'], []);
        $this->assertSame('Please enter at least 5 characters.', $result->getErrors()['name']);
    }

    public function testItValidatesIntrinsicTypeFormatsBeforeRules(): void
    {
        $schema = $this->schema([$this->field('f_aaaa', 'email', 'mail', [
            'rules' => [['rule' => 'min_length', 'value' => 50, 'message' => 'RULE']],
        ])]);
        $result = $this->validator()->validate($schema, ['mail' => 'nope'], []);
        $this->assertArrayHasKey('mail', $result->getErrors());
        $this->assertNotSame('RULE', $result->getErrors()['mail']);
    }

    public function testItCollectsUploadsOnlyForVisibleFileFields(): void
    {
        $upload = ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $schema = $this->schema([
            $this->field('f_aaaa', 'text', 'toggle'),
            $this->field('f_bbbb', 'file', 'cv'),
            $this->field('f_cccc', 'file', 'hid', ['condition' => $this->hideWhenNo()]),
        ]);
        $result = $this->validator()->validate(
            $schema,
            ['toggle' => 'no'],
            ['cv' => $upload, 'hid' => $upload, 'unknown' => $upload]
        );
        $this->assertSame(['cv' => $upload], $result->getFiles());
        $this->assertArrayNotHasKey('cv', $result->getValues());
    }

    public function testItListsVisibleFileFieldsLeftWithoutAnUpload(): void
    {
        $upload = ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 3];
        $none = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $schema = $this->schema([
            $this->field('f_aaaa', 'text', 'toggle'),
            $this->field('f_bbbb', 'file', 'cv'),
            $this->field('f_cccc', 'file', 'photo'),
            $this->field('f_dddd', 'file', 'letter'),
            $this->field('f_eeee', 'file', 'hid', ['condition' => $this->hideWhenNo()]),
        ]);

        $result = $this->validator()->validate($schema, ['toggle' => 'no'], ['cv' => $upload, 'photo' => $none]);

        $this->assertSame(['photo', 'letter'], $result->getUnansweredFiles());
    }

    public function testItReportsARequiredErrorForAMissingRequiredFile(): void
    {
        $schema = $this->schema([$this->field('f_aaaa', 'file', 'cv', ['required' => true])]);
        $none = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        foreach ([[], ['cv' => $none]] as $files) {
            $result = $this->validator()->validate($schema, [], $files);
            $this->assertSame(['cv' => 'This field is required.'], $result->getErrors());
            $this->assertSame([], $result->getFiles());
        }
    }

    public function testItPassesFailedUploadsThroughSoTheFileValidatorCanReportThem(): void
    {
        $schema = $this->schema([$this->field('f_aaaa', 'file', 'cv', ['required' => true])]);
        $failed = ['name' => 'a.pdf', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];
        $result = $this->validator()->validate($schema, [], ['cv' => $failed]);
        $this->assertSame(['cv' => $failed], $result->getFiles());
        $this->assertSame([], $result->getErrors());
    }

    public function testItSkipsFieldsOfAnUnknownTypeDuringValidationAndLogsAWarningOnce(): void
    {
        $this->asserted = ['logger'];
        $this->logger = $this->double('logger', LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');
        $validator = $this->validator();
        $schema = $this->schema([
            $this->field('f_aaaa', 'gone', 'a', ['required' => true]),
            $this->field('f_bbbb', 'gone', 'b'),
            $this->field('f_cccc', 'text', 'name'),
        ]);
        $result = $validator->validate($schema, ['name' => 'Bob'], []);
        $validator->validate($schema, ['name' => 'Bob'], []);

        $this->assertTrue($result->isValid());
        $this->assertSame(['name' => 'Bob'], $result->getValues());
    }

    public function testItNeverStoresAPostedValueForAFieldOfAnUnknownType(): void
    {
        $result = $this->validator()->validate(
            $this->schema([$this->field('f_aaaa', 'gone', 'a'), $this->field('f_bbbb', 'text', 'name')]),
            ['a' => 'injected', 'name' => 'Bob'],
            []
        );
        $this->assertSame(['name' => 'Bob'], $result->getValues());
        $this->assertSame([], $result->getErrors());
    }

    private function when(array $actions, string $field = 'toggle', string $value = 'yes'): array
    {
        return ['actions' => $actions, 'match' => 'all', 'rules' => [['field' => $field, 'operator' => 'equals', 'value' => $value]]];
    }

    public function testItRequiresAFieldWhenARequireActionMatchesInTheValidator(): void
    {
        $schema = $this->schema([
            $this->field('f_aaaa', 'text', 'toggle'),
            $this->field('f_bbbb', 'text', 'note', ['condition' => $this->when([['action' => 'require']])]),
        ]);
        $this->assertSame(['note' => 'This field is required.'], $this->validator()->validate($schema, ['toggle' => 'yes'], [])->getErrors());
        $this->assertTrue($this->validator()->validate($schema, ['toggle' => 'no'], [])->isValid());
    }

    public function testItOverwritesALockedFieldWithASetValueActionInTheValidator(): void
    {
        $schema = $this->schema([
            $this->field('f_aaaa', 'text', 'toggle'),
            $this->field('f_bbbb', 'text', 'note', ['locked' => true, 'condition' => $this->when([['action' => 'set_value', 'param' => 'fixed']])]),
            $this->field('f_cccc', 'hidden', 'ref', ['condition' => $this->when([['action' => 'set_value', 'param' => 'fixed']])]),
            $this->field('f_dddd', 'text', 'free', ['condition' => $this->when([['action' => 'set_value', 'param' => 'fixed']])]),
        ]);
        $result = $this->validator()->validate($schema, ['toggle' => 'yes', 'note' => 'a', 'ref' => 'b', 'free' => 'mine'], []);
        $this->assertSame(['toggle' => 'yes', 'note' => 'fixed', 'ref' => 'fixed', 'free' => 'mine'], $result->getValues());
    }

    public function testItAppliesASetValueBeforeLaterConditionsReadItInTheValidator(): void
    {
        $schema = $this->schema([
            $this->field('f_aaaa', 'text', 'toggle'),
            $this->field('f_bbbb', 'text', 'note', ['locked' => true, 'condition' => $this->when([['action' => 'set_value', 'param' => 'fixed']])]),
            $this->field('f_cccc', 'text', 'later', ['required' => true, 'condition' => $this->when([['action' => 'show']], 'note', 'fixed')]),
        ]);
        $result = $this->validator()->validate($schema, ['toggle' => 'yes', 'note' => 'a'], []);
        $this->assertSame(['later' => 'This field is required.'], $result->getErrors());
    }

    public function testItRejectsAHiddenOptionChosenByTheVisitor(): void
    {
        $options = [['label' => 'A', 'value' => 'a'], ['label' => 'B', 'value' => 'b']];
        $schema = $this->schema([
            $this->field('f_aaaa', 'text', 'toggle'),
            $this->field('f_bbbb', 'select', 'pick', [
                'options' => $options,
                'condition' => $this->when([['action' => 'hide_options', 'param' => ['b']]]),
            ]),
            $this->field('f_cccc', 'checkboxes', 'many', [
                'options' => $options,
                'condition' => $this->when([['action' => 'hide_options', 'param' => ['b']]]),
            ]),
        ]);
        $result = $this->validator()->validate($schema, ['toggle' => 'yes', 'pick' => 'b', 'many' => ['a', 'b']], []);
        $this->assertSame(['pick', 'many'], array_keys($result->getErrors()));
        $this->assertSame('This option is not available.', $result->getErrors()['pick']);
        $this->assertTrue($this->validator()->validate($schema, ['toggle' => 'yes', 'pick' => 'a', 'many' => ['a']], [])->isValid());
        $this->assertTrue($this->validator()->validate($schema, ['toggle' => 'no', 'pick' => 'b'], [])->isValid());
    }

    public function testItKeepsAStoredFileFieldOptionalOnEditEvenWhenARequireActionMatches(): void
    {
        $schema = $this->schema([
            $this->field('f_aaaa', 'text', 'toggle'),
            $this->field('f_bbbb', 'file', 'doc', ['required' => true, 'condition' => $this->when([['action' => 'require']])]),
            $this->field('f_cccc', 'text', 'note', ['condition' => $this->when([['action' => 'require']])]),
        ]);
        $strict = $this->validator()->validate($schema, ['toggle' => 'yes', 'note' => 'x'], []);
        $this->assertSame(['doc' => 'This field is required.'], $strict->getErrors());
        $exempt = $this->validator()->validate($schema, ['toggle' => 'yes', 'note' => 'x'], [], 0, ['doc']);
        $this->assertTrue($exempt->isValid());
    }

    public function testItKeepsAForcedUntickedNewsletterFieldOptionalWhateverTheSchemaSays(): void
    {
        $schema = $this->schema([
            $this->field('f_aaaa', 'text', 'toggle'),
            $this->field('f_bbbb', 'newsletter', 'news', ['condition' => $this->when([['action' => 'require']])]),
        ]);
        $validator = $this->validator(['newsletter' => $this->newsletterType([0])]);
        $this->assertFalse($validator->validate($schema, ['toggle' => 'yes', 'news' => ''], [])->isValid());
        $this->assertTrue($validator->validate($schema, ['toggle' => 'yes', 'news' => ''], [], 0, ['news'])->isValid());
    }

    public function testItEvaluatesRelativeDateRulesAgainstTheStoresToday(): void
    {
        $rule = ['field' => 'when', 'operator' => 'less_than', 'value_date' => ['days' => 3650]];
        $schema = $this->schema([
            $this->field('f_aaaa', 'date', 'when'),
            $this->field('f_bbbb', 'text', 'note', ['required' => true, 'condition' => ['actions' => [['action' => 'show']], 'match' => 'all', 'rules' => [$rule]]]),
        ]);
        $result = $this->validator()->validate($schema, ['when' => gmdate('Y-m-d')], []);
        $this->assertSame(['note' => 'This field is required.'], $result->getErrors());
    }
}
