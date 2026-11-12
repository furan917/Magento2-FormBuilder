<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Model\Prefill;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Model\Access\FormAccess;
use Furan\FormBuilder\Model\Prefill\LockedValues;
use Furan\FormBuilder\Model\Prefill\PrefillService;
use Furan\FormBuilder\Test\Unit\Helper\FieldPools;
use Furan\FormBuilder\Model\Prefill\PreviousAnswers;
use Furan\FormBuilder\Test\Unit\Helper\ArrayPrefillSource;
use Furan\FormBuilder\Test\Unit\Helper\PrefillSourcePools;
use Furan\FormBuilder\Model\Prefill\TokenPayload;
use Furan\FormBuilder\Model\Submission\EditToken;
use Furan\FormBuilder\Model\Survey\Respondents;
use Magento\Customer\Model\Session;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class TokenPayloadTest extends TestCase
{
    private Session&Stub $session;
    private PrefillService&Stub $prefill;
    private Respondents&Stub $respondents;
    private PreviousAnswers&Stub $previous;
    private EditToken&Stub $editToken;
    private TokenPayload $payload;

    protected function setUp(): void
    {
        $this->fixture();
    }

    private function fixture(?EditToken $editToken = null, ?PrefillService $prefill = null, ?PreviousAnswers $previous = null, ?Respondents $respondents = null): void
    {
        $this->session = $this->createStub(Session::class);
        $this->prefill = $prefill ?? $this->createStub(PrefillService::class);
        $this->respondents = $respondents ?? $this->createStub(Respondents::class);
        $this->previous = $previous ?? $this->createStub(PreviousAnswers::class);
        $this->editToken = $editToken ?? $this->createStub(EditToken::class);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->with('furanform/account/login', ['_scope_to_url' => true])->willReturn('https://shop.test/furanform/account/login');
        $sources = PrefillSourcePools::builtIn([
            'customer.loyalty' => new ArrayPrefillSource('customer.loyalty', 'customer', ['text'], 'L-1'),
            'product.stock' => new ArrayPrefillSource('product.stock', 'product', ['text'], '42'),
        ]);
        $this->payload = new TokenPayload(
            $this->session,
            new FormAccess(),
            $this->prefill,
            $this->respondents,
            $sources,
            $this->previous,
            $this->editToken,
            $url,
            new LockedValues($this->prefill, $sources, FieldPools::builtIn())
        );
    }

    private function login(int $id = 5, int $group = 1): void
    {
        $this->session->method('isLoggedIn')->willReturn(true);
        $this->session->method('getCustomerId')->willReturn($id);
        $this->session->method('getCustomerGroupId')->willReturn($group);
    }

    private function form(array $fields, array $settings = []): FormInterface
    {
        $items = [];
        foreach ($fields as $name => $spec) {
            $address = in_array($spec['prefill'] ?? '', ['address.billing', 'address.shipping'], true);
            $items[] = ['kind' => 'field', 'name' => $name] + $spec + ['type' => $address ? 'address' : 'text'];
        }
        $form = $this->createStub(FormInterface::class);
        $form->method('getId')->willReturn(12);
        $form->method('getSchema')->willReturn(['pages' => [
            ['items' => [$items[0] ?? [], ['kind' => 'section', 'items' => array_slice($items, 1)]]],
        ]]);
        $form->method('getSettings')->willReturn($settings);
        return $form;
    }

    public function testItReturnsNoPrefillValueWhenTheSourceDoesNotSuitTheFieldType(): void
    {
        $prefill = $this->createMock(PrefillService::class);
        $this->fixture(prefill: $prefill);
        $this->login();
        $prefill->expects($this->never())->method('valuesFor');

        $result = $this->payload->build($this->form(['pick' => ['type' => 'select', 'prefill' => 'customer.firstname']]), 3, 0);

        $this->assertSame([], $result['prefill']);
    }

    public function testItAsksTheSourcePoolWhichSourcesAreProductSources(): void
    {
        $prefill = $this->createMock(PrefillService::class);
        $this->fixture(prefill: $prefill);
        $this->login();
        $prefill->expects($this->once())->method('product')->with(9, 3)->willReturn(['id' => 9]);
        $this->prefill->method('valuesFor')->willReturn(['customer.loyalty' => 'L-1']);

        $result = $this->payload->build($this->form([
            'stock' => ['prefill' => 'product.stock'],
            'loyal' => ['prefill' => 'customer.loyalty'],
        ]), 3, 9);

        $this->assertSame(['id' => 9], $result['product']);
        $this->assertSame(['loyal' => 'L-1'], $result['prefill']);
    }

    public function testItReturnsAccessStateLoginNeededForAGuestOnALoginOnlyForm(): void
    {
        $this->session->method('isLoggedIn')->willReturn(false);
        $result = $this->payload->build($this->form(['a' => []], ['access_login_required' => true]), 3, 0);

        $this->assertSame(
            ['allowed' => false, 'reason' => 'login', 'loginUrl' => 'https://shop.test/furanform/account/login'],
            $result['access']
        );
    }

    public function testItReturnsPrefillValuesOnlyForFieldsMappedToAPrefillSource(): void
    {
        $prefill = $this->createMock(PrefillService::class);
        $this->fixture(prefill: $prefill);
        $this->login();
        $prefill->expects($this->once())->method('valuesFor')
            ->with(5, ['customer.firstname', 'address.billing'], 3)
            ->willReturn(['customer.firstname' => 'Ann', 'address.billing' => ['city' => 'X']]);
        $form = $this->form([
            'first' => ['prefill' => 'customer.firstname'],
            'plain' => [],
            'addr' => ['prefill' => 'address.billing'],
        ]);

        $result = $this->payload->build($form, 3, 0);

        $this->assertSame(['first' => 'Ann', 'addr' => ['city' => 'X']], $result['prefill']);
    }

    public function testItReturnsNoPersonalDataToGuests(): void
    {
        $prefill = $this->createMock(PrefillService::class);
        $this->fixture(prefill: $prefill);
        $this->session->method('isLoggedIn')->willReturn(false);
        $prefill->expects($this->never())->method('valuesFor');
        $result = $this->payload->build($this->form(['a' => ['prefill' => 'customer.email', 'locked' => true]]), 3, 0);

        $this->assertSame([], $result['prefill']);
        $this->assertSame([], $result['locked']);
        $this->assertFalse($result['customer']);
    }

    public function testItReturnsTheProductContextForAVisibleProductId(): void
    {
        $prefill = $this->createMock(PrefillService::class);
        $this->fixture(prefill: $prefill);
        $this->session->method('isLoggedIn')->willReturn(false);
        $product = ['id' => 9, 'sku' => 'S', 'name' => 'N', 'url' => 'u', 'price' => 1.5, 'priceFormatted' => '$1.50', 'currency' => 'USD'];
        $prefill->expects($this->once())->method('product')->with(9, 3)->willReturn($product);

        $result = $this->payload->build($this->form(['p' => ['prefill' => 'product.name']]), 3, 9);

        $this->assertSame($product, $result['product']);
    }

    public function testItReturnsNoPrefillWhenAccessIsDenied(): void
    {
        $prefill = $this->createMock(PrefillService::class);
        $this->fixture(prefill: $prefill);
        $this->login(5, 1);
        $prefill->expects($this->never())->method('valuesFor');
        $prefill->expects($this->never())->method('product');
        $form = $this->form(['a' => ['prefill' => 'customer.email', 'locked' => true], 'p' => ['prefill' => 'product.sku']], ['access_groups' => [2]]);

        $result = $this->payload->build($form, 3, 9);

        $this->assertSame('group', $result['access']['reason']);
        $this->assertSame([], $result['prefill']);
        $this->assertSame([], $result['locked']);
        $this->assertNull($result['product']);
        $this->assertTrue($result['customer'], 'a signed-in customer is never offered sign in or registration');
    }

    public function testItReturnsAccessStateAnsweredForACustomerWhoAlreadyAnsweredASurveyForm(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(respondents: $respondents);
        $this->login(5);
        $respondents->method('customerKey')->with(5)->willReturn('c5');
        $respondents->expects($this->exactly(2))->method('find')->with(12, ['c5'])
            ->willReturnOnConsecutiveCalls(['submission_id' => 4], ['submission_id' => null]);
        $form = $this->form(['a' => []], ['survey_mode' => 'once']);

        foreach ([1, 2] as $ignored) {
            $result = $this->payload->build($form, 3, 0);
            $this->assertFalse($result['access']['allowed']);
            $this->assertSame('answered', $result['access']['reason']);
            $this->assertTrue($result['customer']);
        }
    }

    public function testItReturnsCustomerTrueForALoggedInCustomer(): void
    {
        $this->login();
        $this->assertTrue($this->payload->build($this->form(['a' => []]), 3, 0)['customer']);
    }

    public function testItSkipsCustomerPrefillProductAndRespondentLookupsTheFormDoesNotNeed(): void
    {
        $prefill = $this->createMock(PrefillService::class);
        $respondents = $this->createMock(Respondents::class);
        $this->fixture(prefill: $prefill, respondents: $respondents);
        $this->login();
        $prefill->expects($this->never())->method('valuesFor');
        $prefill->expects($this->never())->method('product');
        $respondents->expects($this->never())->method('find');

        $result = $this->payload->build($this->form(['a' => ['prefill' => 'product.sku'], 'b' => []]), 3, 0);
        $this->payload->build($this->form(['a' => []], ['survey_mode' => 'off']), 3, 9);

        $this->assertNull($result['product']);
    }

    public function testItListsALockedFieldEvenWhenTheCustomerHasNoValueForIt(): void
    {
        $this->login();
        $this->prefill->method('valuesFor')->willReturn(['customer.firstname' => 'Ann', 'customer.taxvat' => '']);
        $form = $this->form([
            'first' => ['prefill' => 'customer.firstname', 'locked' => true],
            'tax' => ['prefill' => 'customer.taxvat', 'locked' => true],
            'last' => ['prefill' => 'customer.firstname'],
        ]);

        $result = $this->payload->build($form, 3, 0);

        $this->assertSame(['first', 'tax'], $result['locked']);
        $this->assertSame(['first' => 'Ann', 'last' => 'Ann'], $result['prefill']);
    }

    public function testItListsAPrefilledServerValueFieldAsLockedForCustomersEvenWhenUnlockedOrEmpty(): void
    {
        $this->login();
        $this->prefill->method('valuesFor')->willReturn(['customer.email' => 'ann@example.com', 'customer.taxvat' => '']);
        $form = $this->form([
            'mail' => ['type' => 'hidden', 'prefill' => 'customer.email'],
            'tax' => ['type' => 'hidden', 'prefill' => 'customer.taxvat'],
            'plain' => ['type' => 'hidden', 'default' => 'x'],
            'typed' => ['prefill' => 'customer.email'],
        ]);

        $result = $this->payload->build($form, 3, 0);

        $this->assertSame(['mail', 'tax'], $result['locked']);
        $this->assertSame(['mail' => 'ann@example.com', 'typed' => 'ann@example.com'], $result['prefill']);
    }

    public function testItReturnsThePreviousAnswersToTheOwner(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $previous = $this->createMock(PreviousAnswers::class);
        $this->fixture(respondents: $respondents, previous: $previous);
        $this->login(5);
        $respondents->method('customerKey')->with(5)->willReturn('c5');
        $respondents->method('find')->with(12, ['c5'])->willReturn(['submission_id' => 4]);
        $form = $this->form(['a' => []], ['survey_mode' => 'edit']);
        $previous->expects($this->once())->method('load')
            ->with(4, 12, 5, $form->getSchema())->willReturn(['answers' => ['a' => 'x'], 'savedAt' => 1800000000000]);

        $result = $this->payload->build($form, 3, 0);

        $this->assertSame(['a' => 'x'], $result['previous']);
        $this->assertSame(1800000000000, $result['previousAt']);
        $this->assertTrue($result['access']['allowed']);
    }

    public function testItNeverReturnsPreviousAnswersToGuests(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $previous = $this->createMock(PreviousAnswers::class);
        $this->fixture(respondents: $respondents, previous: $previous);
        $this->session->method('isLoggedIn')->willReturn(false);
        $respondents->expects($this->never())->method('find');
        $previous->expects($this->never())->method('load');

        $result = $this->payload->build($this->form(['a' => []], ['survey_mode' => 'edit']), 3, 0);

        $this->assertSame([], $result['previous']);
    }

    public function testItReturnsAccessStateAnsweredWhenThePreviousSubmissionCanNoLongerBeEdited(): void
    {
        $this->login(5);
        $this->respondents->method('customerKey')->willReturn('c5');
        $this->respondents->method('find')->willReturn(['submission_id' => 4]);
        $this->previous->method('load')->willReturn(null);

        $result = $this->payload->build($this->form(['a' => []], ['survey_mode' => 'edit']), 3, 0);

        $this->assertFalse($result['access']['allowed']);
        $this->assertSame('answered', $result['access']['reason']);
        $this->assertSame([], $result['previous']);
        $this->assertTrue($result['customer']);
    }

    public function testItReturnsAccessStateAnsweredWhenTheCustomerDeletedTheirSurveyAnswer(): void
    {
        $previous = $this->createMock(PreviousAnswers::class);
        $this->fixture(previous: $previous);
        $this->login(5);
        $this->respondents->method('customerKey')->willReturn('c5');
        $this->respondents->method('find')->willReturn(['submission_id' => null]);
        $previous->expects($this->never())->method('load');

        $result = $this->payload->build($this->form(['a' => []], ['survey_mode' => 'edit']), 3, 0);

        $this->assertSame('answered', $result['access']['reason']);
        $this->assertSame([], $result['previous']);
    }

    public function testItReturnsThePreviousAnswersForAValidEditToken(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $previous = $this->createMock(PreviousAnswers::class);
        $editToken = $this->createMock(EditToken::class);
        $this->fixture(respondents: $respondents, previous: $previous, editToken: $editToken);
        $this->login();
        $form = $this->form(['note' => []], ['account_edit' => true, 'survey_mode' => 'once']);
        $editToken->method('verify')->with('tok', 5, 12)->willReturn(77);
        $previous->expects($this->once())->method('load')
            ->with(77, 12, 5, $form->getSchema())->willReturn(['answers' => ['note' => 'old'], 'savedAt' => 1800000000000]);
        $respondents->expects($this->never())->method('find');

        $result = $this->payload->build($form, 3, 0, 'tok');

        $this->assertSame(['note' => 'old'], $result['previous']);
        $this->assertSame(1800000000000, $result['previousAt']);
        $this->assertSame('tok', $result['edit']);
        $this->assertNull($result['access']['reason']);
    }

    public function testItRefusesAnEditTokenWhenTheFormDoesNotAllowAccountEdits(): void
    {
        $previous = $this->createMock(PreviousAnswers::class);
        $this->fixture(previous: $previous);
        $this->login();
        $this->editToken->method('verify')->willReturn(77);
        $previous->expects($this->never())->method('load');

        $result = $this->payload->build($this->form(['note' => []], ['account_edit' => false]), 3, 0, 'tok');

        $this->assertSame([], $result['previous']);
        $this->assertArrayNotHasKey('edit', $result);
        $this->assertTrue($result['access']['allowed']);
    }

    public function testItRefusesAnInvalidEditTokenWithoutChangingAccess(): void
    {
        $previous = $this->createMock(PreviousAnswers::class);
        $this->fixture(previous: $previous);
        $this->login();
        $this->editToken->method('verify')->willReturn(null);
        $previous->expects($this->never())->method('load');

        $result = $this->payload->build($this->form(['note' => []], ['account_edit' => true]), 3, 0, 'bad');

        $this->assertSame([], $result['previous']);
        $this->assertArrayNotHasKey('edit', $result);
        $this->assertSame(['allowed' => true, 'reason' => null], array_intersect_key($result['access'], ['allowed' => 1, 'reason' => 1]));
    }

    public function testItReturnsNoPreviousAnswersForAnEditTokenOnASubmissionThatCanNoLongerBeEdited(): void
    {
        $this->login();
        $this->editToken->method('verify')->willReturn(77);
        $this->previous->method('load')->willReturn(null);

        $result = $this->payload->build($this->form(['note' => []], ['account_edit' => true]), 3, 0, 'tok');

        $this->assertSame([], $result['previous']);
        $this->assertArrayNotHasKey('edit', $result);
        $this->assertNull($result['access']['reason']);
    }

    public function testItIgnoresAnEditTokenForGuests(): void
    {
        $editToken = $this->createMock(EditToken::class);
        $this->fixture(editToken: $editToken);
        $this->session->method('isLoggedIn')->willReturn(false);
        $editToken->expects($this->never())->method('verify');

        $result = $this->payload->build($this->form(['note' => []], ['account_edit' => true]), 3, 0, 'tok');

        $this->assertArrayNotHasKey('edit', $result);
    }

    public function testItReturnsOnlyTheAccessVerdictWithoutLoadingAnyAnswers(): void
    {
        $respondents = $this->createMock(Respondents::class);
        $previous = $this->createMock(PreviousAnswers::class);
        $this->fixture(respondents: $respondents, previous: $previous);
        $this->session->method('isLoggedIn')->willReturn(false);
        $respondents->expects($this->never())->method('find');
        $previous->expects($this->never())->method('load');

        $result = $this->payload->access($this->form([], ['access_login_required' => true]));

        $this->assertSame(['access'], array_keys($result));
        $this->assertFalse($result['access']['allowed']);
        $this->assertSame('login', $result['access']['reason']);
        $this->assertSame('https://shop.test/furanform/account/login', $result['access']['loginUrl']);
    }
}
