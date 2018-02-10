<?php

namespace SilverStripe\Omnipay\Tests;

use Omnipay\Common\Message\NotificationInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Omnipay\GatewayInfo;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\Omnipay\Tests\Extensions\PaymentTestPaymentExtensionHooks;
use SilverStripe\Omnipay\Tests\Extensions\PaymentTestServiceExtensionHooks;

/**
 * Base class with common tests for Void, Capture and Refund Services
 */
abstract class BaseNotificationServiceTest extends PaymentTest
{
    /** @var string the gateway method to call */
    protected $gatewayMethod;

    /** @var string fixture identifier */
    protected $fixtureIdentifier;

    /** @var string the receipt (transaction reference) that is stored in the fixture */
    protected $fixtureReceipt;

    /** @var string the desired start status */
    protected $startStatus;

    /** @var string the pending status */
    protected $pendingStatus;

    /** @var string the end status */
    protected $endStatus;

    /** @var array the messages generated by a successful attempt with the payment loaded from the fixture */
    protected $successFromFixtureMessages;

    /** @var array the messages generated by a successful attempt with a fresh payment */
    protected $successMessages;

    /** @var array failure messages */
    protected $failureMessages;

    /** @var array messages when a failure notification comes in */
    protected $notificationFailureMessages;

    /** @var string class name of the error message generated by this service */
    protected $errorMessageClass;

    /** @var array expected payment hooks that will be called with a successful payment */
    protected $successPaymentExtensionHooks;

    /** @var array expected service hooks that will be called when initiate method finishes */
    protected $initiateServiceExtensionHooks;

    /** @var array expected service hooks that will be called when initiate method was interrupted by gateway error */
    protected $initiateFailedServiceExtensionHooks;

    /**
     * Get the service to use for these tests
     * @param Payment $payment
     * @return \SilverStripe\Omnipay\Service\PaymentService
     */
    abstract protected function getService(Payment $payment);

    public function testSuccess()
    {
        // load an authorized payment from fixture
        $payment = $this->objFromFixture(Payment::class, $this->fixtureIdentifier);

        $stubGateway = $this->buildPaymentGatewayStub(true, $this->fixtureReceipt);

        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        $serviceResponse = $service->initiate();

        // the service should not respond with an error
        $this->assertFalse($serviceResponse->isError());
        // we should get a successful Omnipay response
        $this->assertNotNull($serviceResponse->getOmnipayResponse());
        $this->assertTrue($serviceResponse->getOmnipayResponse()->isSuccessful());
        // check payment status
        $this->assertEquals($payment->Status, $this->endStatus, 'Payment status should be set to ' . $this->endStatus);

        // check existence of messages and existence of references
        SapphireTest::assertListContains($this->successFromFixtureMessages, $payment->Messages());

        // ensure payment hooks were called
        $this->assertEquals(
            $this->successPaymentExtensionHooks,
            $payment->getExtensionInstance(PaymentTestPaymentExtensionHooks::class)->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            $this->initiateServiceExtensionHooks,
            $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->getCalledMethods()
        );
    }

    public function testManualSuccess()
    {
        // Use a manual payment (this payment doesn't have any previous messages to grab transaction reference from)
        $payment = $this->payment->setGateway('Manual');
        $payment->Status = $this->startStatus;

        $stubGateway = $this->buildPaymentGatewayStub(true, 'testThisRecipe123');
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        // Manual payments should succeed, even when there's no transaction reference given!
        $serviceResponse = $service->initiate();

        // the service should not respond with an error
        $this->assertFalse($serviceResponse->isError());

        // we should get a successful Omnipay response
        $this->assertNotNull($serviceResponse->getOmnipayResponse());
        $this->assertTrue($serviceResponse->getOmnipayResponse()->isSuccessful());

        // check payment status
        $this->assertEquals($payment->Status, $this->endStatus, 'Payment status should be set to ' . $this->endStatus);

        // check existance of messages and existence of references
        SapphireTest::assertListContains($this->successMessages, $payment->Messages());

        // ensure payment hooks were called
        $this->assertEquals(
            $this->successPaymentExtensionHooks,
            $payment->getExtensionInstance(PaymentTestPaymentExtensionHooks::class)->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            $this->initiateServiceExtensionHooks,
            $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->getCalledMethods()
        );
    }

    public function testSuccessWithTransactionParameter()
    {
        // set the payment status to the desired start status
        $this->payment->Status = $this->startStatus;

        $stubGateway = $this->buildPaymentGatewayStub(true, 'testThisRecipe123');
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($this->payment);

        // pass transaction reference as parameter
        $serviceResponse = $service->initiate(array('transactionReference' => 'testThisRecipe123'));

        // the service should not respond with an error
        $this->assertFalse($serviceResponse->isError());
        // We should get a successful Omnipay response
        $this->assertNotNull($serviceResponse->getOmnipayResponse());
        $this->assertTrue($serviceResponse->getOmnipayResponse()->isSuccessful());
        // check payment status
        $this->assertEquals($this->payment->Status, $this->endStatus, 'Payment status should be set to ' . $this->endStatus);

        // check existance of messages and existence of references
        SapphireTest::assertListContains($this->successMessages, $this->payment->Messages());

        // ensure payment hooks were called
        $this->assertEquals(
            $this->successPaymentExtensionHooks,
            $this->payment->getExtensionInstance(PaymentTestPaymentExtensionHooks::class)->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            $this->initiateServiceExtensionHooks,
            $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->getCalledMethods()
        );
    }

    public function testSuccessWithLegacyTransactionParameter()
    {
        // set the payment status to the desired start status
        $this->payment->Status = $this->startStatus;

        $stubGateway = $this->buildPaymentGatewayStub(true, 'testThisRecipe123');
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($this->payment);

        // pass transaction reference as parameter
        $serviceResponse = $service->initiate(array('receipt' => 'testThisRecipe123'));

        // the service should not respond with an error
        $this->assertFalse($serviceResponse->isError());
        // We should get a successful Omnipay response
        $this->assertNotNull($serviceResponse->getOmnipayResponse());
        $this->assertTrue($serviceResponse->getOmnipayResponse()->isSuccessful());
        // check payment status
        $this->assertEquals($this->payment->Status, $this->endStatus, 'Payment status should be set to ' . $this->endStatus);

        // check existance of messages and existence of references
        SapphireTest::assertListContains($this->successMessages, $this->payment->Messages());

        // ensure payment hooks were called
        $this->assertEquals(
            $this->successPaymentExtensionHooks,
            $this->payment->getExtensionInstance(PaymentTestPaymentExtensionHooks::class)->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            $this->initiateServiceExtensionHooks,
            $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->getCalledMethods()
        );
    }

    public function testSuccessViaNotification()
    {
        // load a payment from fixture
        $payment = $this->objFromFixture(Payment::class, $this->fixtureIdentifier);

        // use notification on the gateway
        Config::modify()->merge(GatewayInfo::class, $payment->Gateway, array(
            'use_async_notification' => true
        ));

        $stubGateway = $this->buildPaymentGatewayStub(false, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);
        $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->Reset();
        // pass transaction reference as parameter
        $serviceResponse = $service->initiate();

        // the service should not respond with an error
        $this->assertFalse($serviceResponse->isError());
        // When waiting for a notification, request won't be successful from Omnipays point of view
        $this->assertNotNull($serviceResponse->getOmnipayResponse());
        $this->assertFalse($serviceResponse->getOmnipayResponse()->isSuccessful());
        // response should have the "AwaitingNotification" flag
        $this->assertTrue($serviceResponse->isAwaitingNotification());
        // check payment status
        $this->assertEquals(
            $payment->Status,
            $this->pendingStatus,
            'Payment status should be set to ' . $this->pendingStatus
        );

        // check existance of messages and existence of references.
        // Since operation isn't complete, we shave off the latest message from the exptected messages!
        SapphireTest::assertListContains(array_slice($this->successFromFixtureMessages, 0, -1), $payment->Messages());

        // Now a notification comes in
        $response = $this->get('paymentendpoint/'. $payment->Identifier .'/notify');

        $this->assertEquals($response->getStatusCode(), 200);
        $this->assertEquals($response->getBody(), "OK");

        // ensure payment hooks were called
        $this->assertEquals(
            $this->successPaymentExtensionHooks,
            PaymentTestPaymentExtensionHooks::findExtensionForID($payment->ID)->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            array_merge($this->initiateServiceExtensionHooks, ['updateServiceResponse']),
            $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->getCalledMethods()
        );

        // we'll have to "reload" the payment from the DB now
        $payment = Payment::get()->byID($payment->ID);
        $this->assertEquals($payment->Status, $this->endStatus, 'Payment status should be set to ' . $this->endStatus);

        // check existance of messages
        SapphireTest::assertListContains($this->successFromFixtureMessages, $payment->Messages());

        $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->Reset();
        // try to complete a second time
        $service = $this->getService($payment);
        $serviceResponse = $service->complete();

        // the service should not respond with an error
        $this->assertFalse($serviceResponse->isError());
        // since the payment is already completed, we should not touch omnipay again.
        $this->assertNull($serviceResponse->getOmnipayResponse());
        // should not be waiting for notification
        $this->assertFalse($serviceResponse->isAwaitingNotification());
        // must always be true
        $this->assertTrue($serviceResponse->isNotification());

        // only a service response will be generated, as omnipay is no longer involved at this stage
        $this->assertEquals(
            array('updateServiceResponse'),
            $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->getCalledMethods()
        );
    }

    public function testFailure()
    {
        // load an authorized payment from fixture
        $payment = $this->objFromFixture(Payment::class, $this->fixtureIdentifier);

        $stubGateway = $this->buildPaymentGatewayStub(false, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        $serviceResponse = $service->initiate();

        // the service should respond with an error
        $this->assertTrue($serviceResponse->isError());

        // Omnipay response should be unsuccessful
        $this->assertNotNull($serviceResponse->getOmnipayResponse());
        $this->assertFalse($serviceResponse->getOmnipayResponse()->isSuccessful());

        // payment status should be unchanged
        $this->assertEquals($payment->Status, $this->startStatus, 'Payment status should be unchanged');

        // check existance of messages and existence of references
        SapphireTest::assertListContains($this->failureMessages, $payment->Messages());

        // ensure payment hooks were called
        $this->assertEquals(
            [],
            $payment->getExtensionInstance(PaymentTestPaymentExtensionHooks::class)->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            $this->initiateServiceExtensionHooks,
            $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->getCalledMethods()
        );
    }

    public function testGatewayFailure()
    {
        // load an authorized payment from fixture
        /** @var Payment $payment */
        $payment = $this->objFromFixture(Payment::class, $this->fixtureIdentifier);

        $stubGateway = $this->buildPaymentGatewayStub(
            false,
            $this->fixtureReceipt,
            NotificationInterface::STATUS_COMPLETED,
            true
        );
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        $serviceResponse = $service->initiate();

        // the service should respond with an error
        $this->assertTrue($serviceResponse->isError());
        // There should be no omnipay response, as the gateway threw an exception
        $this->assertNull($serviceResponse->getOmnipayResponse());
        // payment status should be unchanged
        $this->assertEquals($payment->Status, $this->startStatus, 'Payment status should be unchanged');

        $msg = $payment->getLatestMessageOfType($this->errorMessageClass);

        $this->assertNotNull($msg, 'An error message should have been generated');
        $this->assertEquals($msg->Message, 'Mock Send Exception');

        // ensure payment hooks were called
        $this->assertEquals(
            [],
            $payment->getExtensionInstance(PaymentTestPaymentExtensionHooks::class)->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            $this->initiateFailedServiceExtensionHooks,
            $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->getCalledMethods()
        );
    }

    /**
     * @expectedException \SilverStripe\Omnipay\Exception\InvalidConfigurationException
     */
    public function testUnsupportedGatewayMethod()
    {
        // Build the dummy gateway that doesn't contain the requested method (eg. void, capture or refund)
        $stubGateway = $this->getMockBuilder('Omnipay\Common\AbstractGateway')
            ->setMethods(array('getName'))
            ->getMock();

        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $this->payment->Status = $this->startStatus;
        $service = $this->getService($this->payment);

        // this should throw an exception, because the gateway doesn't support the method
        $service->initiate(array('receipt' => 'testThisRecipe123'));
    }

    public function testFailureViaNotification()
    {
        // load a payment from fixture
        $payment = $this->objFromFixture(Payment::class, $this->fixtureIdentifier);

        // use notification on the gateway
        Config::modify()->merge(GatewayInfo::class, $payment->Gateway, array(
            'use_async_notification' => true
        ));

        $stubGateway = $this->buildPaymentGatewayStub(
            false,
            $this->fixtureReceipt,
            NotificationInterface::STATUS_FAILED
        );

        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);
        $service->initiate();

        // Now a notification comes in (will fail)
        $this->get('paymentendpoint/'. $payment->Identifier .'/notify');

        // we'll have to "reload" the payment from the DB now
        $payment = Payment::get()->byID($payment->ID);

        // Status should be reset
        $this->assertEquals($this->startStatus, $payment->Status);

        // check existance of messages
        SapphireTest::assertListContains($this->notificationFailureMessages, $payment->Messages());
    }

    public function testGatewayNotificationFailure()
    {
        $payment = $this->objFromFixture(Payment::class, $this->fixtureIdentifier);

        $stubGateway = $this->buildPaymentGatewayStub(
            true,
            $this->fixtureReceipt,
            NotificationInterface::STATUS_COMPLETED,
            true
        );
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $payment->Status = $this->pendingStatus;
        $service = $this->getService($payment);

        $serviceResponse = $service->complete();

        // the service should respond with an error
        $this->assertTrue($serviceResponse->isError());
        // There should be no omnipay notification, as the gateway threw an exception
        $this->assertNull($serviceResponse->getOmnipayResponse());
        // payment status should be unchanged
        $this->assertEquals($payment->Status, $this->pendingStatus, 'Payment status should be unchanged');

        // ensure payment hooks were called
        $this->assertEquals(
            [],
            $payment->getExtensionInstance(PaymentTestPaymentExtensionHooks::class)->getCalledMethods()
        );

        // only a service response will be generated with the notification
        $this->assertEquals(
            array('updateServiceResponse'),
            $service->getExtensionInstance(PaymentTestServiceExtensionHooks::class)->getCalledMethods()
        );
    }

    public function testNotificationTransactionReferenceMismatch()
    {
        $payment = $this->objFromFixture(Payment::class, $this->fixtureIdentifier);

        // create gateway but use a different transaction reference
        $stubGateway = $this->buildPaymentGatewayStub(true, 'DifferentReference');

        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $payment->Status = $this->pendingStatus;
        $service = $this->getService($payment);

        $serviceResponse = $service->complete();

        // the service should respond with an error
        $this->assertTrue($serviceResponse->isError());
        // There should be an omnipay notification
        $this->assertNotNull($serviceResponse->getOmnipayResponse());
        $this->assertInstanceOf(
            '\Omnipay\Common\Message\NotificationInterface',
            $serviceResponse->getOmnipayResponse()
        );
        // payment status should be unchanged
        $this->assertEquals($payment->Status, $this->pendingStatus, 'Payment status should be unchanged');
    }

    /**
     * @expectedException \SilverStripe\Omnipay\Exception\InvalidConfigurationException
     */
    public function testInvalidStatus()
    {
        $this->payment->Status = 'Created';

        // create a service with a payment that is created
        $service = $this->getService($this->payment);

        // this should throw an exception
        $service->initiate();
    }

    /**
     * @expectedException \SilverStripe\Omnipay\Exception\InvalidStateException
     */
    public function testInvalidCompleteStatus()
    {
        $this->payment->Status = 'Created';

        // create a service with a payment that is created
        $service = $this->getService($this->payment);

        // this should throw an exception
        $service->complete();
    }

    /**
     * @expectedException \SilverStripe\Omnipay\Exception\MissingParameterException
     */
    public function testMissingTransactionReference()
    {
        $this->payment->Status = $this->startStatus;

        // create a service with a payment that has the correct status
        // but doesn't have any transaction references in messages
        $service = $this->getService($this->payment);

        // this should throw an exception
        $service->initiate();
    }

    /**
     * @expectedException \SilverStripe\Omnipay\Exception\InvalidConfigurationException
     */
    public function testMethodDisabled()
    {
        // disallow the service via config
        $method = 'allow_' . $this->gatewayMethod;
        Config::modify()->merge(GatewayInfo::class, 'Dummy', array(
            $method => false
        ));
        $this->payment->setGateway('Dummy')->Status = 'Created';

        // create a service with a payment that is created
        $service = $this->getService($this->payment);

        // this should throw an exception
        $service->initiate();
    }


    protected function buildPaymentGatewayStub(
        $successValue,
        $transactionReference,
        $returnState = NotificationInterface::STATUS_COMPLETED,
        $throwGatewayException = false
    ) {
        //--------------------------------------------------------------------------------------------------------------
        // void request and response

        $mockResponse = $this->getMockBuilder('Omnipay\Common\Message\AbstractResponse')
            ->disableOriginalConstructor()->getMock();

        $mockResponse->expects($this->any())
            ->method('isSuccessful')->will($this->returnValue($successValue));

        $mockResponse->expects($this->any())
            ->method('getTransactionReference')->will($this->returnValue($transactionReference));

        $mockRequest = $this->getMockBuilder('Omnipay\Common\Message\AbstractRequest')
            ->disableOriginalConstructor()->getMock();

        if ($throwGatewayException) {
            $mockRequest->expects($this->any())->method('send')->will($this->throwException(
                new \Omnipay\Common\Exception\RuntimeException('Mock Send Exception')
            ));
        } else {
            $mockRequest->expects($this->any())
                ->method('send')->will($this->returnValue($mockResponse));
        }

        $mockRequest->expects($this->any())
            ->method('getTransactionReference')->will($this->returnValue($transactionReference));

        //--------------------------------------------------------------------------------------------------------------
        // Notification

        $notificationResponse = $this->getMockBuilder('Omnipay\Common\Message\NotificationInterface')
            ->disableOriginalConstructor()->getMock();

        $notificationResponse->expects($this->any())
            ->method('getTransactionStatus')->will($this->returnValue($returnState));

        $notificationResponse->expects($this->any())
            ->method('getTransactionReference')->will($this->returnValue($transactionReference));


        //--------------------------------------------------------------------------------------------------------------
        // Build the gateway

        $stubGateway = $this->getMockBuilder('Omnipay\Common\AbstractGateway')
            ->setMethods(array($this->gatewayMethod, 'acceptNotification', 'getName'))
            ->getMock();

        $stubGateway->expects($this->any())
            ->method($this->gatewayMethod)
            ->will($this->returnValue($mockRequest));

        $stubGateway->expects($this->any())
            ->method('acceptNotification')
            ->will(
                $throwGatewayException
                    ? $this->throwException(new \Omnipay\Common\Exception\RuntimeException('Mock Notification Exception'))
                    : $this->returnValue($notificationResponse)
            );

        return $stubGateway;
    }
}
