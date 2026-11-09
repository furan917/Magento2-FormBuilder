<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Controller\Account;

use Furan\FormBuilder\Api\Data\FormInterface;
use Furan\FormBuilder\Api\Data\SubmissionInterface;
use Furan\FormBuilder\Api\FormRepositoryInterface;
use Furan\FormBuilder\Api\SubmissionRepositoryInterface;
use Furan\FormBuilder\Controller\Account\Edit;
use Furan\FormBuilder\Model\Account\SubmissionReader;
use Furan\FormBuilder\Model\Form\ScheduleGate;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Seo\FormUrls;
use Furan\FormBuilder\Model\Submission\EditToken;
use Furan\FormBuilder\Model\Account\SubmissionPolicy;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class EditTest extends TestCase
{
    use \Furan\FormBuilder\Test\Unit\Helper\AssertedDoubles;

    private SubmissionReader&Stub $reader;
    private FormRepositoryInterface&Stub $forms;
    private EditToken&Stub $token;
    private FormUrls&Stub $urls;
    private Forward&Stub $forward;
    private Redirect&Stub $redirect;
    private ManagerInterface&Stub $messages;
    private ?FormInterface $localized = null;
    private Edit $controller;

    private function build(mixed $customerId = '5'): void
    {
        $this->reader = $this->double('reader', SubmissionReader::class);
        $this->forms = $this->double('forms', FormRepositoryInterface::class);
        $this->token = $this->double('token', EditToken::class);
        $this->urls = $this->double('urls', FormUrls::class);
        $this->forward = $this->double('forward', Forward::class);
        $forwardFactory = $this->createStub(ForwardFactory::class);
        $forwardFactory->method('create')->willReturn($this->forward);
        $this->redirect = $this->double('redirect', Redirect::class);
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn($customerId);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('id')->willReturn('9');
        $submission = $this->createStub(SubmissionInterface::class);
        $submission->method('getStoreId')->willReturn(2);
        $submissions = $this->createMock(SubmissionRepositoryInterface::class);
        $submissions->method('getById')->with(9)->willReturn($submission);

        $this->messages = $this->double('messages', ManagerInterface::class);
        $translator = $this->createStub(Translator::class);
        $translator->method('localize')->willReturnCallback(
            fn (FormInterface $form, int $storeId): FormInterface => $storeId === 2 ? ($this->localized ?? $form) : $form
        );
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(1800000000);
        $this->controller = new Edit(
            $redirectFactory,
            $forwardFactory,
            $session,
            $request,
            $this->reader,
            $this->forms,
            $submissions,
            $this->urls,
            $this->token,
            $this->messages,
            $translator,
            new ScheduleGate($clock),
            new SubmissionPolicy()
        );
    }

    private function row(int $status): void
    {
        $this->reader->method('find')->with(5, 9)
            ->willReturn(['submission_id' => '9', 'form_id' => '3', 'status' => (string)$status]);
    }

    private function allowingForm(array $settings = ['account_edit' => true]): FormInterface&Stub
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getSettings')->willReturn($settings);
        $this->forms->method('getActiveById')->with(3, 2)->willReturn($form);
        return $form;
    }

    public function testItRedirectsAnEditToTheFormUrlOfTheSubmissionsStoreWithASignedTokenInThePageHash(): void
    {
        $this->asserted = ['forms', 'reader', 'token', 'urls', 'redirect'];
        $this->build();
        $this->row(SubmissionInterface::STATUS_NEW);
        $form = $this->allowingForm();
        $this->urls->expects($this->never())->method('url');
        $this->urls->method('storeUrl')->with($form, 2)->willReturn('https://shop.test/forms/survey');
        $this->token->method('issue')->with(9, 5, 3)->willReturn('1.9.abc');
        $this->redirect->expects($this->once())->method('setUrl')
            ->with('https://shop.test/forms/survey#ffb-edit=1.9.abc')->willReturnSelf();

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testItIssuesNoEditTokenAndRedirectsToTheSubmissionViewWhileTheFormIsClosed(): void
    {
        $this->asserted = ['forms', 'reader', 'token', 'redirect', 'messages'];
        $this->build();
        $this->row(SubmissionInterface::STATUS_NEW);
        $this->allowingForm(['account_edit' => true, 'close_at' => '2020-01-01T00:00:00Z', 'closed_message' => 'Closed']);
        $this->localized = $this->createStub(FormInterface::class);
        $this->localized->method('getSettings')
            ->willReturn(['account_edit' => true, 'close_at' => '2020-01-01T00:00:00Z', 'closed_message' => 'Ferme']);
        $this->token->expects($this->never())->method('issue');
        $this->messages->expects($this->once())->method('addErrorMessage')->with('Ferme');
        $this->redirect->expects($this->once())->method('setPath')
            ->with('furanform/account/submission', ['id' => 9])->willReturnSelf();
        $this->redirect->expects($this->never())->method('setUrl');

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testItOffersEditingOnlyWhileTheSubmissionIsNewOrInProgress(): void
    {
        foreach ([[0, true], [1, true], [2, false], [3, false]] as [$status, $allowed]) {
            $this->asserted = ['forms', 'reader', $allowed ? 'redirect' : 'forward'];
            $this->build();
            $this->row($status);
            $this->allowingForm();
            $this->urls->method('storeUrl')->willReturn('https://shop.test/f');
            $this->token->method('issue')->willReturn('t');
            if ($allowed) {
                $this->redirect->expects($this->once())->method('setUrl')->willReturnSelf();
                $this->assertSame($this->redirect, $this->controller->execute(), "status $status");
            } else {
                $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();
                $this->assertSame($this->forward, $this->controller->execute(), "status $status");
            }
        }
    }

    public function testItRefusesEditWhenTheFormDoesNotAllowIt(): void
    {
        $this->asserted = ['forms', 'reader', 'token', 'forward'];
        $this->build();
        $this->row(SubmissionInterface::STATUS_NEW);
        $this->allowingForm(['account_edit' => false]);
        $this->token->expects($this->never())->method('issue');
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();

        $this->assertSame($this->forward, $this->controller->execute());
    }

    public function testItRefusesEditWhenTheFormIsNoLongerActive(): void
    {
        $this->asserted = ['reader', 'forward'];
        $this->build();
        $this->row(SubmissionInterface::STATUS_NEW);
        $this->forms->method('getActiveById')->willThrowException(new NoSuchEntityException());
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();

        $this->assertSame($this->forward, $this->controller->execute());
    }

    public function testItRefusesToEditAnotherCustomersSubmission(): void
    {
        $this->asserted = ['reader', 'token', 'forward'];
        $this->build();
        $this->reader->method('find')->with(5, 9)->willReturn(null);
        $this->token->expects($this->never())->method('issue');
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();

        $this->assertSame($this->forward, $this->controller->execute());
    }

    public function testItRefusesEditWithoutACustomerSession(): void
    {
        $this->asserted = ['reader', 'forward'];
        $this->build(null);
        $this->reader->expects($this->never())->method('find');
        $this->forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();

        $this->assertSame($this->forward, $this->controller->execute());
    }
}
