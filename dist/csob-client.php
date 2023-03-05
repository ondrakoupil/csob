<?php

// src/Client.php 

namespace OndraKoupil\Csob {

use \OndraKoupil\Tools\Files;

use \OndraKoupil\Tools\Strings;

use \OndraKoupil\Tools\Arrays;



/**
 * The main class that allows you to use payment gateway's functions.
 *
 * @example
 *
 * <code>
 *
 * use OndraKoupil\Csob;
 *
 * $config = new Config(
 *    "Your Merchant ID",
 *    "Path to your private key file",
 *    "Path to bank's public key file",
 *    "Your e-shop name",
 *    "Some URL to return customers to",
 *    GatewayUrl::TEST_LATEST
 * );
 *
 * $client = new Client($config);
 *
 * // Check if connection and signing works
 * $client->testGetConnection();
 * $client->testPostConnection();
 *
 * // Create new payment with some item in cart
 * $payment = new Payment("12345");
 * $payment->addCartItem("Some cool stuff", 1, 10000);
 *
 * $client->paymentInit($payment);
 *
 * // Check for payment status
 * $client->paymentStatus($payment);
 *
 * // Get URL to send the customer to
 * $url = $client->getPaymentProcessUrl($payment);
 *
 * // Or redirect him there right now
 * $client->redirectToGateway($payment);
 *
 * // Cancel the payment
 * $client->paymentReverse($payment);
 *
 * // Or approve the payment (if not set to do that automatically)
 * $client->paymentClose($payment);
 *
 * </code>
 *
 */
class Client {


	const DATE_FORMAT = "YmdHis";

	/**
	 * Customer with given ID was not found
	 */
	const CUST_NOT_FOUND = 800;

	/**
	 * Customer found, but has no saved cards
	 */
	const CUST_NO_CARDS = 810;

	/**
	 * Customer found and has some cards
	 */
	const CUST_CARDS = 820;


	/**
	 * @var Config
	 * @ignore
	 */
	protected $config;

	/**
	 * @ignore
	 * @var string
	 */
	protected $logFile;

	/**
	 * @ignore
	 * @var callable
	 */
	protected $logCallback;

	/**
	 * @ignore
	 * @var string
	 */
	protected $traceLogFile;

	/**
	 * @ignore
	 * @var callable
	 */
	protected $traceLogCallback;


	// ------- BASICS --------

	/**
	 * Create new client with given Config.
	 *
	 * @param Config $config
	 * @param callable|string $log Log for messages concerning payments
	 * at bussiness-logic level. Either a string filename or a callback
	 * that you can use to forward messages to your own logging system.
	 * @param callable|string $traceLog Log for technical messages with
	 * exact contents of communication.
	 */
	function __construct(Config $config, $log = null, $traceLog = null) {

		$this->config = $config;

		if ($log) {
			$this->setLog($log);
		}

		if ($traceLog) {
			$this->setTraceLog($traceLog);
		}

	}

	/**
	 * @return Config
	 */
	function getConfig() {
		return $this->config;
	}

	/**
	 * @param Config $config
	 * @return Client
	 */
	function setConfig(Config $config) {
		$this->config = $config;
		return $this;
	}


	// ------- API CALL METHODS --------

	/**
	 * Performs payment/init API call.
	 *
	 * Use this to create new payment in payment gateway.
	 * After successful call, the $payment object will be updated by given PayID.
	 *
	 * @param Payment $payment Create and fill this object manually with real data.
	 * @param Extension[]|Extension $extensions Added extensions
	 *
	 * @return array Array with results of the call. You don't need to use
	 * any of this, PayID will be set to $payment automatically.
	 */
	function paymentInit(Payment $payment, $extensions = array()) {

		$payment->checkAndPrepare($this->config);
		$array = $payment->signAndExport($this);

		$this->writeToLog("payment/init started for payment with orderNo " . $payment->orderNo);
		$returnDataNames = array("payId", "dttm", "resultCode", "resultMessage", "?paymentStatus", "?authCode");
		if($this->getConfig()->queryApiVersion('1.8')){
			$returnDataNames = array_merge($returnDataNames, array("?customerCode","?statusDetail"));
		}
		try {
			$ret = $this->sendRequest(
				"payment/init",
				$array,
				"POST",
				$returnDataNames,
				null,
				false,
				false,
				$extensions
			);

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		if (!isset($ret["payId"]) or !$ret["payId"]) {
			$this->writeToLog("Fail, no payId received.");
			throw new Exception("Bank API did not return a payId value.");
		}

		$payment->setPayId($ret["payId"]);

		$this->writeToLog("payment/init OK, got payId ".$ret["payId"]);

		return $ret;

	}

	/**
	 * Generates URL to send customer's browser to after initiating the payment.
	 *
	 * Use this after you successfully called paymentInit() and redirect
	 * the customer's browser on the URL that this method returns manually,
	 * or use redirectToGateway().
	 *
	 * @param string|Payment $payment Either PayID given during paymentInit(),
	 * or just the Payment object you used in paymentInit()
	 *
	 * @return string
	 *
	 * @see redirectToGateway()
	 */
	function getPaymentProcessUrl($payment) {
		$payId = $this->getPayId($payment);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		$payload["signature"] = $this->signRequest($payload);

		$url = $this->sendRequest(
			"payment/process",
			$payload,
			"GET",
			array(),
			array("merchantId", "payId", "dttm", "signature"),
			true
		);

		$this->writeToLog("URL for processing payment ".$payId.": $url");

		return $url;
	}

    /**
     * Generates checkout URL to send customer's browser to after initiating
     * the payment.
     *
     * @param string|Payment $payment Either PayID given during paymentInit(),
     * or just the Payment object you used in paymentInit()
     *
     * @param int $oneClickPaymentCheckbox Flag to indicate whether to display
     * the checkbox for saving card for future payments and to indicate whether
     * it should be preselected or not.
     *   0 - hidden, unchecked
     *   1 - displayed, unchecked
     *   2 - displayed, checked
     *   3 - hidden, checked (you need to indicate to customer that this happens
     *       before initiating the payment)
     *
     * @param bool|null $displayOmnibox Flag to indicate whether to display
     * omnibox in desktop's iframe version instead of card number, expiration
     * and cvc fields
     *
     * @param string|null $returnCheckoutUrl URL for scenario when process needs
     * to get back to checkout page
     *
     * @return string
     */
    function getPaymentCheckoutUrl($payment, $oneClickPaymentCheckbox, $displayOmnibox = null, $returnCheckoutUrl = null) {
        $payId = $this->getPayId($payment);

        $payload = array(
            "merchantId" => $this->config->merchantId,
            "payId" => $payId,
            "dttm" => $this->getDTTM(),
            "oneclickPaymentCheckbox" => $oneClickPaymentCheckbox,
        );

        if ($displayOmnibox !== null) {
            $payload["displayOmnibox"] = $displayOmnibox ? "true" : "false";
        }
        if ($returnCheckoutUrl !== null) {
            $payload["returnCheckoutUrl"] = $returnCheckoutUrl;
        }

        $payload["signature"] = $this->signRequest($payload);

        $url = $this->sendRequest(
            "payment/checkout",
            $payload,
            "GET",
            array(),
            array("merchantId", "payId", "dttm", "oneclickPaymentCheckbox", "displayOmnibox", "returnCheckoutUrl", "signature"),
            true
        );

        $this->writeToLog("URL for processing payment ".$payId.": $url");

        return $url;
    }

	/**
	 * Redirect customer's browser to payment gateway.
	 *
	 * Use this after you successfully called paymentInit()
	 *
	 * Note that HTTP headers must not have been sent before.
	 *
	 * @param string|Payment $payment Either PayID given during paymentInit(),
	 * or just the Payment object you used in paymentInit()
	 *
	 * @throws Exception If headers has been already sent
	 */
	function redirectToGateway($payment) {

		if (headers_sent($file, $line)) {
			$this->writeToLog("Can't redirect, headers sent at $file, line $line");
			throw new Exception("Can't redirect the browser, headers were already sent at $file line $line");
		}

		$url = $this->getPaymentProcessUrl($payment);
		$this->writeToLog("Redirecting to payment gate...");

		header("HTTP/1.1 302 Moved");
		header("Location: $url");
		header("Connection: close");
	}

	/**
	 * Check payment status by calling payment/status API method.
	 *
	 * Use this to check current status of some transaction.
	 * See ČSOB's wiki on Github for explanation of each status.
	 *
	 * Basically, they are:
	 *
	 * - 1 = new; after paymentInit() but before customer starts filling in his
	 *     card number and authorising the transaction
	 * - 2 = in progress; during customer's stay at payment gateway
	 * - 4 = after successful authorisation but before it is approved by you by
	 *   calling paymentClose. This state is skipped if you use
	 *   Payment->closePayment = true or Config->closePayment = true.
	 * - 7 = waiting for being processed by bank. The payment will remain in this
	 *   state for about one working day. You can call paymentReverse() during this
	 *   time to stop it from being processed.
	 * - 5 = cancelled by you. Payment gets here after calling paymentReverse().
	 * - 8 = finished. This means money is already probably on your account.
	 *   If you want to cancel the payment, you can only refund it by calling
	 *   paymentRefund().
	 * - 9 = being refunded
	 * - 10 = refunded
	 * - 6 or 3 = payment was not authorised or was cancelled by customer
	 *
	 * @param string|Payment $payment Either PayID given during paymentInit(),
	 * or just the Payment object you used in paymentInit()
	 *
	 * @param bool $returnStatusOnly Leave on true if you want to return only
	 * status code. Set to false if you want more information as array.
	 *
	 * @param Extension[]|Extension $extensions
	 *
	 * @return array|number Number if $returnStatusOnly was true, array otherwise.
	 */
	function paymentStatus($payment, $returnStatusOnly = true, $extensions = array(), $nullIfPaymentNotFound = true) {
		$payId = $this->getPayId($payment);

		$this->writeToLog("payment/status started for payment $payId");

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		try {
			$payload["signature"] = $this->signRequest($payload);
			// Payment status is optional, bank doesn't include it in signature base if the payment is not found.
			$returnDataNames = array("payId", "dttm", "resultCode", "resultMessage", "?paymentStatus", "?authCode");
			if ($this->getConfig()->queryApiVersion('1.8')){
				 $returnDataNames = array_merge($returnDataNames, array("?customerCode","?statusDetail"));
			}
			if ($this->getConfig()->queryApiVersion('1.9')){
				 $returnDataNames[] = '?actions';
			}
			$ret = $this->sendRequest(
				"payment/status",
				$payload,
				"GET",
				$returnDataNames,
				array(
					"merchantId",
					"payId",
					"dttm",
					"signature",
				),
				false,
				false,
				$extensions
			);

		} catch (Exception $e) {
			if ($nullIfPaymentNotFound and $e->getCode() === 140) {
				// Error 140 = payment not found
				return null;
			} else {
				$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
				throw $e;
			}
		}

		$this->writeToLog("payment/status OK, status of payment $payId is ".$ret["paymentStatus"]);

		if ($returnStatusOnly) {
			return $ret["paymentStatus"];
		}

		return $ret;
	}

	/**
	 * Performs payment/reverse API call.
	 *
	 * Reversing payment means stopping payment that has not yet been processed
	 * by bank (usually about 1 working day after being created).
	 *
	 * Normally, payment must be in state 4 or 7 to be reversable.
	 * If the payment is not in an acceptable state, then the gateway
	 * returns an error code 150 and exception is thrown from here.
	 * Set $ignoreWrongPaymentStatusError to true if you are okay with that
	 * situation and you want to silently ignore it. Method then returns null.
	 *
	 * If some other type of error occurs, exception will be thrown anyway.
	 *
	 * @param string|array|Payment $payment Either PayID given during paymentInit(),
	 * or whole returned array from paymentInit or just the Payment object
	 * you used in paymentInit()
	 *
	 * @param bool $ignoreWrongPaymentStatusError
	 *
	 * @param Extension[]|Extension $extensions
	 *
	 * @return array|null Array with results of call or null if payment is not
	 * in correct state
	 *
	 *
	 * @throws Exception
	 */
	function paymentReverse($payment, $ignoreWrongPaymentStatusError = false, $extensions = array()) {
		$payId = $this->getPayId($payment);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		$returnDataNames = array("payId", "dttm", "resultCode", "resultMessage", "?paymentStatus", "?authCode");
		if($this->getConfig()->queryApiVersion('1.8')){
			$returnDataNames = array_merge($returnDataNames, array("?customerCode","?statusDetail"));
		}
		$this->writeToLog("payment/reverse started for payment $payId");

		try {
			$payload["signature"] = $this->signRequest($payload);

			try {

				$ret = $this->sendRequest(
					"payment/reverse",
					$payload,
					"PUT",
					$returnDataNames,
					array("merchantId", "payId", "dttm", "signature"),
					false,
					false,
					$extensions
				);

			} catch (Exception $e) {
				if ($e->getCode() != 150) { // Not just invalid state
					throw $e;
				}
				if (!$ignoreWrongPaymentStatusError) {
					throw $e;
				}

				$this->writeToLog("payment/reverse failed, payment is not in correct status");
				return null;
			}

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("payment/reverse OK");

		return $ret;
	}

	/**
	 * Performs payment/close API call.
	 *
	 * If you want to accept (close) payments manually, set property $closePayment
	 * of Payment object or Config object to false. Then, payments will wait in
	 * state 4 for your approval by calling paymentClose().
	 *
	 * Normally, payment must be in state 4 to be eligible for this operation.
	 * If the payment is not in an acceptable state, then the gateway
	 * returns an error code 150 and exception is thrown from here.
	 * Set $ignoreWrongPaymentStatusError to true if you are okay with that
	 * situation and you want to silently ignore it. Method then returns null.
	 *
	 * If some other type of error occurs, exception will be thrown in all cases.
	 *
	 * @param string|Payment $payment Either PayID given during paymentInit(),
	 * or just the Payment object you used in paymentInit()
	 *
	 * @param bool $ignoreWrongPaymentStatusError
	 *
	 * @param int $amount Amount of finance to close (if different from originally authorized amount).
	 * Use hundreths of basic currency unit.
	 *
	 * @param Extension[]|Extension $extensions
	 *
	 * @return array|null Array with results of call or null if payment is not
	 * in correct state
	 *
	 *
	 * @throws Exception
	 */
	function paymentClose($payment, $ignoreWrongPaymentStatusError = false, $amount = null, $extensions = array()) {
		$payId = $this->getPayId($payment);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		if ($amount !== null) {
			$payload["totalAmount"] = $amount;
		}

		$this->writeToLog("payment/close started for payment $payId" . ($amount !== null ? ", amount $amount" : ""));

		$returnDataNames = array("payId", "dttm", "resultCode", "resultMessage", "?paymentStatus", "?authCode");
		if($this->getConfig()->queryApiVersion('1.8')){
			$returnDataNames = array_merge($returnDataNames, array("?customerCode","?statusDetail"));
		}
		try {
			$payload["signature"] = $this->signRequest($payload);

			try {

				$ret = $this->sendRequest(
					"payment/close",
					$payload,
					"PUT",
					$returnDataNames,
					array("merchantId", "payId", "dttm", "totalAmount", "signature"),
					false,
					false,
					$extensions
				);

			} catch (Exception $e) {
				if ($e->getCode() != 150) { // Not just invalid state
					throw $e;
				}
				if (!$ignoreWrongPaymentStatusError) {
					throw $e;
				}

				$this->writeToLog("payment/close failed, payment is not in correct status");
				return null;
			}

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("payment/close OK");

		return $ret;
	}

	/**
	 * Performs payment/refund API call.
	 *
	 * If you want to send money back to your customer after payment has been
	 * completely processed and money transferred, use this method.
	 *
	 * Normally, payment must be in state 8 to be eligible for this operation.
	 * If the payment is not in an acceptable state, then the gateway
	 * returns an error code 150 and exception is thrown from here.
	 * Set $ignoreWrongPaymentStatusError to true if you are okay with that
	 * situation and you want to silently ignore it. Method then returns null.
	 *
	 * If some other type of error occurs, exception will be thrown in all cases.
	 *
	 * Note: on testing environment, refunding often ends up with HTTP code 500
	 * and exception thrown. This is a bug in payment gateway - see
	 * https://github.com/csob/paymentgateway/issues/43, however it is still not resolved.
	 * On production environment, this should be OK.
	 *
	 * @param string|Payment $payment Either PayID given during paymentInit(),
	 * or just the Payment object you used in paymentInit()
	 *
	 * @param bool $ignoreWrongPaymentStatusError
	 *
	 * @param int $amount Optionally, an amount (<b>in hundreths of basic money unit</b> - beware!)
	 * can be passed, so that the payment will be refunded partially.
	 * Null means full refund.
	 *
	 * @param Extension[]|Extension $extensions
	 *
	 * @return array|null Array with results of call or null if payment is not
	 * in correct state
	 */
	function paymentRefund($payment, $ignoreWrongPaymentStatusError = false, $amount = null, $extensions = array()) {
		$payId = $this->getPayId($payment);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		if ($amount !== null) {
			if (!is_numeric($amount)) {
				throw new Exception("Amount for refunding must be a number.");
			}
			$payload["amount"] = $amount;
		}

		$this->writeToLog("payment/refund started for payment $payId, amount = " . ($amount !== null ? $amount : "null"));

		$returnDataNames = array("payId", "dttm", "resultCode", "resultMessage", "?paymentStatus", "?authCode");
		if($this->getConfig()->queryApiVersion('1.8')){
			$returnDataNames = array_merge($returnDataNames, array("?customerCode","?statusDetail"));
		}

		try {

			$payloadForSigning = $payload;
			/*
			if (isset($payloadForSigning["amount"])) {
				unset($payloadForSigning["amount"]);
			}
			 */

			$payload["signature"] = $this->signRequest($payloadForSigning);

			try {

				$ret = $this->sendRequest(
					"payment/refund",
					$payload,
					"PUT",
					$returnDataNames,
					array("merchantId", "payId", "dttm", "amount", "signature"),
					false,
					false,
					$extensions
				);

			} catch (Exception $e) {
				if ($e->getCode() != 150) { // Not just invalid state
					throw $e;
				}
				if (!$ignoreWrongPaymentStatusError) {
					throw $e;
				}

				$this->writeToLog("payment/refund failed, payment is not in correct status");
				return null;
			}

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("payment/refund OK");

		return $ret;
	}


	/**
	 * Performs payment/recurrent API call.
	 *
	 * Use this method to redo a payment that has already been marked as
	 * a template for recurring payments and approved by customer
	 * - see Payment::setRecurrentPayment()
	 *
	 * You need PayID of the original payment and a new Payment object.
	 * Only $orderNo, $totalAmount (sum of cart items added by addToCart), $currency
	 * and $description of $newPayment are used, others are ignored.
	 *
	 * Note that if $totalAmount is set, then also $currency must be set. If not,
	 * CZK is used as default value.
	 *
	 * $orderNo is the only mandatory value in $newPayment. Other properties
	 * can be left null to use original values from $origPayment.
	 *
	 * After successful call, received PayID will be set in $newPayment object.
	 *
	 * @param Payment|string $origPayment Either string PayID or a Payment object
	 * @param Payment $newPayment
	 * @return array Data with new values
	 * @throws Exception
	 *
	 * @deprecated Deprecated since eAPI 1.7, please use paymentOneClick() instead.
	 *
	 * @see Payment::setRecurrentPayment()
	 * @see paymentOneClickInit()
	 */
	function paymentRecurrent($origPayment, Payment $newPayment) {
		trigger_error('paymentRecurrent() is deprecated now, please use paymentOneClick() instead.', E_USER_DEPRECATED);

		$origPayId = $this->getPayId($origPayment);

		$newOrderNo = $newPayment->orderNo;

		if (!$newOrderNo or !preg_match('~^\d{1,10}$~', $newOrderNo)) {
			throw new Exception("Given Payment object must have an \$orderNo property, numeric, max. 10 chars length.");
		}

		$newPaymentCart = $newPayment->getCart();
		if ($newPaymentCart) {
			$totalAmount = array_sum(Arrays::transform($newPaymentCart, true, "amount"));
		} else {
			$totalAmount = 0;
		}

		$newDescription = Strings::shorten($newPayment->description, 240, "...");

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"origPayId" => $origPayId,
			"orderNo" => $newOrderNo,
			"dttm" => $this->getDTTM(),
		);

		if ($totalAmount > 0) {
			$payload["totalAmount"] = $totalAmount;
			$payload["currency"] = $newPayment->currency ?: "CZK"; // Currency is mandatory since 2016-01-10
		}

		if ($newDescription) {
			$payload["description"] = $newDescription;
		}

		$this->writeToLog("payment/recurrent started using orig payment $origPayId");

		try {
			$payload["signature"] = $this->signRequest($payload);

			$ret = $this->sendRequest(
				"payment/recurrent",
				$payload,
				"POST",
				array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "?authCode"),
				array("merchantId", "origPayId", "orderNo", "dttm", "totalAmount", "currency", "description", "signature")
			);

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("payment/recurrent OK, new payment got payId " . $ret["payId"]);

		$newPayment->setPayId($ret["payId"]);

		return $ret;


	}

	/**
	 * Performs a payment/oneclick/init API call.
	 *
	 * Use this method to redo a payment that has already been marked as
	 * a template for recurring payments and approved by customer
	 * - see Payment::setOneClickPayment()
	 *
	 * You need PayID of the original payment and a new Payment object.
	 * Only $orderNo, $totalAmount (sum of cart items added by addToCart), $currency
	 * and $description of $newPayment are used, others are ignored.
	 *
	 * Note that if $totalAmount is set, then also $currency must be set. If not,
	 * CZK is used as default value.
	 *
	 * $orderNo is the only mandatory value in $newPayment. Other properties
	 * can be left null to use original values from $origPayment.
	 *
	 * After successful call, received PayID will be set in $newPayment object.
	 * Then, pass this object to paymentOneClickStart method.
	 *
	 * This method is a successor of now deprecated paymentRecurrent() method.
	 *
	 * Since API v 1.8, this method uses oneclick/init endpoint.
	 *
	 * @param Payment|string $origPayment Either string PayID or a Payment object
	 * @param Payment $newPayment
	 * @param Extension[]|Extension $extensions
	 * @param string $clientIp IP address of customer's browser
	 * @param bool $clientInitiated Indicates whether it is possible to payment authentication in the presence of the customer. New in API 1.9.
	 * Strongly recommend setting to false, or you might have to perform additional verification actions that are not quite supported on this library.
	 *
	 * @return array Data with new values
	 *
	 * @see Payment::setOneClickPayment()
	 * @see paymentOneClickStart()
	 */
	function paymentOneClickInit($origPayment, Payment $newPayment, $extensions = array(), $clientIp = '', $clientInitiated = false) {
		$origPayId = $this->getPayId($origPayment);

		$newOrderNo = $newPayment->orderNo;

		$newPayment->origPayId = $origPayId;

		if (!$newOrderNo or !preg_match('~^\d{1,10}$~', $newOrderNo)) {
			throw new Exception("Given Payment object must have an \$orderNo property, numeric, max. 10 chars length.");
		}

		$newPaymentCart = $newPayment->getCart();
		if ($newPaymentCart) {
			$totalAmount = array_sum(Arrays::transform($newPaymentCart, true, "amount"));
		} else {
			$totalAmount = 0;
		}

		$newDescription = Strings::shorten($newPayment->description, 240, "...");
		$version1_8 = $this->config->queryApiVersion('1.8');
		$version1_9 = $this->config->queryApiVersion('1.9');

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"origPayId" => $origPayId,
			"orderNo" => $newOrderNo,
			"dttm" => $this->getDTTM(),
		);

		if ($version1_8) {
			// A new parameter appeared in v 1.8
			$payload['clientIp'] = $clientIp;
		}

		if ($totalAmount > 0) {
			$payload["totalAmount"] = $totalAmount;
			$payload["currency"] = $newPayment->currency ?: "CZK"; // Currency is mandatory since 2016-01-10
		}

		if ($version1_9) {
			$payload['closePayment'] = !!$newPayment->closePayment;
			$payload['returnUrl'] = $newPayment->returnUrl ?: $this->config->returnUrl;
			$payload['returnMethod'] = $newPayment->returnMethod ?: $this->config->returnMethod;

			if ($newPayment->getCustomer()) {
				$payload['customer'] = $newPayment->getCustomer()->export();
			}
			if ($newPayment->getOrder()) {
				$payload['order'] = $newPayment->getOrder()->export();
			}

			$payload['clientInitiated'] = !!$clientInitiated;
		}

		if ($newDescription and !$version1_8) {
			// In v 1.8, there is no description anymore
			$payload["description"] = $newDescription;
		}

		if ($version1_8) {
			// A new parameter appeared in v 1.8
			$payload['merchantData'] = $newPayment->getMerchantDataEncoded();
		}

		$endpointName = $this->config->queryApiVersion('1.8') ? 'oneclick/init' : 'payment/oneclick/init';

		$signatureBase = Tools::linearizeForSigning($payload);
		$payload["signature"] = $this->signRequest(array($signatureBase));


		//$newPayment->checkAndPrepare($this->config);
		//$payload = $newPayment->signAndExport($this);
		//
		//
		//$this->writeToLog($endpointName . " started for payment with orderNo " . $newPayment->orderNo);

		$returnDataNames = array("payId", "dttm", "resultCode", "resultMessage", "?paymentStatus");
		if ($this->getConfig()->queryApiVersion('1.9')){
			$returnDataNames = array_merge($returnDataNames, array("?statusDetail","?actions"));
		}

		try {
			$ret = $this->sendRequest(
				$endpointName,
				$payload,
				"POST",
				$returnDataNames,
				null,
				false,
				false,
				$extensions
			);

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("$endpointName OK, new payment got payId " . $ret["payId"]);

		$newPayment->setPayId($ret["payId"]);

		return $ret;
	}

	/**
	 * Performs a payment/oneclick/start (or oneclick/start) API call.
	 *
	 * Use this method to confirm a recurring one click payment
	 * that was previously initiated using paymentOneClickInit() method.
	 *
	 * @param Payment $newPayment
	 * @param Extension[]|Extension $extensions
	 *
	 * @deprecated Deprecated in API 1.9 - use paymentOneClickProcess now.
	 *
	 * @return array|string
	 */
	function paymentOneClickStart(Payment $newPayment, $extensions = array()) {

		if ($this->config->queryApiVersion('1.9')) {
			throw new Exception('paymentOneClickStart() is not available in API 1.9 anymore, use paymentOneClickProcess() instead.');
		}

		$newPayId = $newPayment->getPayId();
		if (!$newPayId) {
			throw new Exception('Given Payment object does not have a PayId. Please provide a Payment object that was returned from paymentOneClickInit() method.');
		}

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $newPayId,
			"dttm" => $this->getDTTM(),
		);

		$this->writeToLog("payment/oneclick/start started with PayId $newPayId");

		$endpointName = $this->config->queryApiVersion('1.8') ? 'oneclick/start' : 'payment/oneclick/start';

		try {
			$payload["signature"] = $this->signRequest($payload);

			$ret = $this->sendRequest(
				$endpointName,
				$payload,
				"POST",
				array("payId", "dttm", "resultCode", "resultMessage", "?paymentStatus"),
				array("merchantId", "payId", "dttm", "signature"),
				false,
				false,
				$extensions
			);

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("payment/oneclick/start OK");

		return $ret;
	}

	/**
	 * Performs a oneclick/echo API call.
	 *
	 * Use this method to check whether a oneclick template is still ready to be used.
	 *
	 * CAUTION! The method returns a numeric code. 0 means success. Positive number means failure.
	 * Do not just `if ($client->paymentOneClickEcho($payId)) { success(); } else { fail(); }`!!
	 *
	 * See https://github.com/csob/paymentgateway/wiki/Vol%C3%A1n%C3%AD-rozhran%C3%AD-eAPI#operation-return-code
	 * for explanation.
	 *
	 * @param string $origPayId The PayID of original payment template.
	 *
	 * @return number 0 = template is OK, number 700-740 = template is not OK.
	 *
	 * @see https://github.com/csob/paymentgateway/wiki/Vol%C3%A1n%C3%AD-rozhran%C3%AD-eAPI#operation-return-code
	 */
	function paymentOneClickEcho($origPayId) {

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"origPayId" => $origPayId,
			"dttm" => $this->getDTTM(),
		);

		$this->writeToLog("oneclick/echo started with \$origPayId $origPayId");

		$resultCode = 0;

		try {
			$payload["signature"] = $this->signRequest($payload);

			$ret = $this->sendRequest(
				"oneclick/echo",
				$payload,
				"POST",
				array("origPayId", "dttm", "resultCode", "resultMessage", "?paymentStatus"),
				array("merchantId", "origPayId", "dttm", "signature"),
				false,
				false
			);

			$resultCode = $ret['resultCode'];

		} catch (Exception $e) {

			if ($e->getCode() >= 700 and $e->getCode() <= 740) {
				// This is the way bank responds for unusable payment templates (origPayIds).
				$resultCode = $e->getCode();
			} else {
				$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
				throw $e;
			}

		}

		$this->writeToLog("oneclick/echo OK, result " . $resultCode);

		return +$resultCode;

	}


	/**
	 * Performs a oneclick/process API call.
	 *
	 * Use this method to confirm a recurring one click payment after you initialised it using paymentOneClickInit().
	 *
	 *
	 * @param $newPayId
	 *
	 * @return array|string
	 */
	function paymentOneClickProcess($newPayId) {

		if (!$this->config->queryApiVersion('1.9')) {
			throw new Exception('paymentOneClickProcess() is only available since API 1.9.');
		}

		$newPayId = $this->getPayId($newPayId);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $newPayId,
			"dttm" => $this->getDTTM(),
		);

		$this->writeToLog("oneclick/process started with PayId $newPayId");

		try {
			$payload["signature"] = $this->signRequest($payload);

			$ret = $this->sendRequest(
				'oneclick/process',
				$payload,
				"POST",
				array("payId", "dttm", "resultCode", "resultMessage", "?paymentStatus", "?statusDetail","?actions"),
				array("merchantId", "payId", "dttm", "signature"),
				false,
				false,
				array()
			);

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("oneclick/process OK");

		return $ret;
	}


	/**
	 * Performs a payment/button API call in API <= 1.7
	 *
	 * You need a Payment object that was already processed via paymentInit() method
	 * (or was injected with a payId that you received from other source).
	 *
	 * In response, you'll receive an array with [redirect], which should be
	 * another array with [method] and [url] items. Redirect your user to that address
	 * to complete the payment.
	 * Do not use redirectToGateway(), just redirect to `$response[redirect][url]`.
	 *
	 *
	 * @param Payment $payment
	 * @param string $brand "csob" or "era"
	 * @param Extension[]|Extension $extensions
	 *
	 * @return array|string
	 *
	 * @deprecated Not available since API 1.8, use buttonInit() instead
	 */
	function paymentButton(Payment $payment, $brand = "csob", $extensions = array()) {

		$payId = $payment->getPayId();
		if (!$payId) {
			throw new Exception('Given Payment object does not have a PayId. Please provide a Payment object that was returned from paymentInit() method.');
		}

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"brand" => $brand,
			"dttm" => $this->getDTTM(),
		);

		if ($this->config->queryApiVersion('1.8')) {
			throw new Exception('paymentButton() is not available in API 1.8 and newer.');
		}

		$endpointName = 'payment/button';

		$this->writeToLog("$endpointName started with PayId $payId");

		try {
			$payload["signature"] = $this->signRequest($payload);

			$ret = $this->sendRequest(
				$endpointName,
				$payload,
				"POST",
				array("payId", "dttm", "resultCode", "resultMessage", "redirect"),
				array("merchantId", "payId", "brand", "dttm", "signature"),
				false,
				false,
				$extensions
			);

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("$endpointName OK");

		return $ret;


	}

	/**
	 * Performs a button/init API call in API >= 1.8
	 *
	 * You need a Payment object, but DO NOT process it via paymentInit() method. You don't need its PayID.
	 * It is used only as source of data for calling API.
	 *
	 * Items in cart in Payment object are not used, only total sum of their prices.
	 *
	 * In response, you'll receive an array with [redirect], which should be
	 * another array with [method], [url] and possibly [params] items.
	 * Redirect your user to that address to complete the payment.
	 * Do not use redirectToGateway(), just redirect to `$response[redirect][url]`.
	 *
	 * @see https://github.com/csob/paymentgateway/wiki/Metody-pro-platebn%C3%AD-tla%C4%8D%C3%ADtko for details
	 *
	 * @param Payment $payment
	 * @param string $clientIp
	 * @param string $brand "csob" or "era"
	 * @param Extension[]|Extension $extensions
	 *
	 * @return array
	 */
	public function buttonInit(Payment $payment, $clientIp, $brand = 'csob', $extensions = array()) {
		if (!$this->config->queryApiVersion('1.8')) {
			throw new Exception('buttonInit() is not available before API 1.8.');
		}

		if ($brand !== 'csob' and $brand !== 'era') {
			throw new Exception('Invalid $brand, must be "csob" or "era".');
		}

		$payment->checkAndPrepare($this->config);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"orderNo" => $payment->orderNo,
			"dttm" => $this->getDTTM(),
			"clientIp" => $clientIp,
			"totalAmount" => $payment->getTotalAmount(),
			"currency" => $payment->currency,
			"returnUrl" => $payment->returnUrl,
			"returnMethod" => $payment->returnMethod,
			"brand" => $brand,
			"merchantData" => $payment->getMerchantDataEncoded(),
			"language" => $payment->language,
		);

		$payload["signature"] = $this->signRequest($payload);

		$this->writeToLog("button/init started for payment with orderNo " . $payment->orderNo);

		try {

			$ret = $this->sendRequest(
				"button/init",
				$payload,
				"POST",
				array("payId", "dttm", "resultCode", "resultMessage", "?paymentStatus","?redirect.method","?redirect.url","?redirect.params"),
				null,
				false,
				false,
				$extensions
			);

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("button/init OK");

		return $ret;

	}

	/**
	 * Sends an arbitrary request to bank's API with any parameters.
	 *
	 * Use this method to call various masterpass/* API methods or any methods of
	 * API versions that may come in future and are not implemented in this library yet.
	 *
	 * $inputPayload is an associative array with data in order in which they should be signed.
	 * You can leave *dttm* and *merchantId* empty or null, their values will be filled automatically,
	 * however you can't omit them completely, since they are required in the signature.
	 * Signature field will be added automatically.
	 *
	 * $expectedOutputFields should be ordinary array of field names in order they appear in the response.
	 * Their order in the array is important to verify response signature. You can leave this empty, the
	 * base string will be created on order as the keys appear in the response. However, it can't be guaranteed
	 * it is the correct order. If you want it to be more reliable, I recommend to define it.
	 *
	 * Example - testing post connection:
	 *
	 * ```php
	 * $client->customRequest(
	 *   'echo',
	 *   array(
	 *     'merchantId' => null,
	 *     'dttm' => null
	 *   )
	 * );
	 * ```
	 *
	 * @param string $methodUrl API method name, without leading slash, ie. "payment/init"
	 * @param array $inputPayload Input payload in form of associative array. Order of items is significant.
	 * @param array $expectedOutputFields Expected field names of response in order in which they should be returned.
	 * @param Extension[]|Extension $extensions
	 * @param string $method HTTP method
	 * @param bool $logOutput Should be the complete output logged into debug log?
	 * @param bool $ignoreInvalidReturnSignature If set to true, then in case of invalid signature of returned data,
	 * no exception will be thrown and method will return received data as usual. Then, you should handle the situation by yourself.
	 * Do not use this option on regular basis, it is intended only as workaround for cases when returned data or its signature is more complex
	 * and its verification fails for some reason.
	 *
	 * @return array|string
	 */
	function customRequest($methodUrl, $inputPayload, $expectedOutputFields = array(), $extensions = array(), $method = "POST", $logOutput = false, $ignoreInvalidReturnSignature = false) {

		if (array_key_exists('dttm', $inputPayload) and !$inputPayload['dttm']) {
			$inputPayload['dttm'] = $this->getDTTM();
		}

		if (array_key_exists('merchantId', $inputPayload) and !$inputPayload['merchantId']) {
			$inputPayload['merchantId'] = $this->config->merchantId;
		}

		$signature = $this->signRequest($inputPayload);
		$inputPayload['signature'] = $signature;

		$this->writeToLog("custom request to $methodUrl - start");

		try {

			$ret = $this->sendRequest(
				$methodUrl,
				$inputPayload,
				$method,
				$expectedOutputFields,
				array_keys($inputPayload),
				false,
				$ignoreInvalidReturnSignature,
				$extensions
			);

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("custom request to $methodUrl - OK");

		if ($logOutput) {
			$this->writeToTraceLog(print_r($ret, true));
		}

		return $ret;

	}



	/**
	 * Test the connection using POST method.
	 *
	 * @return array Results of calling the method.
	 * @throw Exception If something goes wrong. Se exception's message for more.
	 */
	function testPostConnection() {
		$payload = array(
			"merchantId" => $this->config->merchantId,
			"dttm" => $this->getDTTM()
		);

		$payload["signature"] = $this->signRequest($payload);

		$ret = $this->sendRequest("echo", $payload, true, array("dttm", "resultCode", "resultMessage"));

		$this->writeToLog("Connection test POST successful.");

		return $ret;
	}

	/**
	 * Test the connection using GET method.
	 *
	 * @return array Results of calling the method.
	 * @throw Exception If something goes wrong. Se exception's message for more.
	 */
	function testGetConnection() {
		$payload = array(
			"merchantId" => $this->config->merchantId,
			"dttm" => $this->getDTTM()
		);

		$payload["signature"] = $this->signRequest($payload);

		$ret = $this->sendRequest("echo", $payload, false, array("dttm", "resultCode", "resultMessage"), array("merchantId", "dttm", "signature"));

		$this->writeToLog("Connection test GET successful.");

		return $ret;
	}

	/**
	 * Performs customer/info (below v1.8) or echo/customer (>=v1.8) API call.
	 *
	 * Use this method to check if customer with given ID has any saved cards.
	 * If he does, you can show some icon or change default payment method in
	 * e-shop or do some other action. This is just an auxiliary method and
	 * is not neccessary at all.
	 *
	 * @param string|array|Payment $customerId Customer ID, Payment object or array
	 * as returned from paymentInit
	 * @param bool $returnIfHasCardsOnly
	 *
	 * @return bool|int If $returnIfHasCardsOnly is set to true, method returns
	 * boolean indicating whether given customerID has any saved cards. If it is
	 * set to false, then method returns one of CUSTOMER_*** constants which can
	 * be used to distinguish more precisely whether customer just hasn't saved
	 * any cards or was not found at all.
	 */
	function customerInfo($customerId, $returnIfHasCardsOnly = true) {
		$customerId = $this->getCustomerId($customerId);

		$this->writeToLog("customer/info started for customer $customerId");

		if (!$customerId) {
			$this->writeToLog("no customer Id give, aborting");
			return null;
		}

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"customerId" => $customerId,
			"dttm" => $this->getDTTM()
		);

		$payload["signature"] = $this->signRequest($payload);

		$result = 0;
		$resMessage = "";

		try {

			$endpointName = 'customer/info';
			if ($this->getConfig()->queryApiVersion('1.8')) {
				$endpointName = 'echo/customer'; // API 1.8 renamed this method
			}

			$ret = $this->sendRequest(
				$endpointName,
				$payload,
				"GET",
				array("customerId", "dttm", "resultCode", "resultMessage"),
				array("merchantId", "customerId", "dttm", "signature")
			);
		} catch (Exception $e) {
			// Valid call returns non-0 resultCode, which leads to exception
			$resMessage = $e->getMessage();

			switch  ($e->getCode()) {

				// V 1.8 returns 404 for nonexistent user
				case 404:
					$result = self::CUST_NOT_FOUND;
					break;

				case self::CUST_CARDS:
				case self::CUST_NO_CARDS:
				case self::CUST_NOT_FOUND:
					$result = $e->getCode();
					break;

				default:
					throw $e;
					// this is really some error
			}
		}

		$this->writeToLog("Result: $result, $resMessage");

		if ($returnIfHasCardsOnly) {
			return ($result == self::CUST_CARDS);
		}

		return $result;
	}

	/**
	 * Processes the data that are sent together with customer when he
	 * returns back from payment gateway.
	 *
	 * Call this method on your returnUrl to extract all data from request,
	 * validate signature, decode merchant data from base64 and return
	 * it all as an array. Method automatically reads data from GET or POST.
	 *
	 *
	 * @param array|null $input If return data is not in GET or POST, supply
	 *                          your own array with accordingly named variables.
	 *
	 * @return array|null Array with received data or null if no data is present.
	 */
	function receiveReturningCustomer($input = null) {
		$returnDataNames = array(
			"payId",
			"dttm",
			"resultCode",
			"resultMessage",
			"?paymentStatus",
			"?authCode",
			"?merchantData",
			"?statusDetail",
			// "signature"
		);

		if (!$input) {
			if (isset($_GET["payId"])) $input = $_GET;
			elseif (isset($_POST["payId"])) $input = $_POST;
		}

		if (!$input) {
			return null;
		}

		$this->writeToTraceLog("Received data from returning customer: ".str_replace("\n", " ", print_r($input, true)));

		$nullFields = array_fill_keys($returnDataNames, null);
		$input += $nullFields;

		$signatureOk = $this->verifyResponseSignature($input, $input["signature"], $returnDataNames);

		if (!$signatureOk) {
			$this->writeToTraceLog("Signature is invalid.");
			$this->writeToLog("Returning customer: payId $input[payId], has invalid signature.");
			throw new Exception("Signature is invalid.");
		}

		$merch = @base64_decode($input["merchantData"]);
		if ($merch) {
			$input["merchantData"] = $merch;
		}

		$mess = "Returning customer: payId ".$input["payId"];
		if (isset($input["authCode"]) and $input['authCode']) $mess .= ', authCode ' . $input["authCode"];
		if (isset($input["paymentStatus"]) and $input['paymentStatus']) $mess .= ', paymentStatus ' . $input["paymentStatus"];
		if (isset($input["merchantData"]) and $input['merchantData']) $mess .= ', merchantData ' . $input["merchantData"];
		if (isset($input["statusDetail"]) and $input['statusDetail']) $mess .= ', statusDetail ' . $input["statusDetail"];
		$this->writeToLog($mess);

		return $input;

	}


	// ------ LOGGING -------

	/**
	 * Sets logging for bussiness-logic level messages.
	 *
	 * @param string|callback $log String filename or callback that forwards
	 * messages to your own logging system.
	 *
	 * @return Client
	 */
	function setLog($log) {
		if (!$log) {
			$this->logFile = null;
			$this->logCallback = null;
		} elseif (is_callable($log)) {
			$this->logFile = null;
			$this->logCallback = $log;
		} else {
			Files::create($log);
			$this->logFile = $log;
			$this->logCallback = null;
		}
		return $this;
	}

	/**
	 * Sets logging for exact contents of communication
	 *
	 * @param string|callback $log String filename or callback that forwards
	 * messages to your own logging system.
	 *
	 * @return Client
	 */
	function setTraceLog($log) {
		if (!$log) {
			$this->traceLogFile = null;
			$this->traceLogCallback = null;
		} elseif (is_callable($log)) {
			$this->traceLogFile = null;
			$this->traceLogCallback = $log;
		} else {
			Files::create($log);
			$this->traceLogFile = $log;
			$this->traceLogCallback = null;
		}
		return $this;
	}

	/**
	 * @ignore
	 *
	 * @param string $message
	 */
	function writeToLog($message) {
		if ($this->logFile) {
			$timestamp = date("Y-m-d H:i:s");
			$timestamp = str_pad($timestamp, 20);
			if (isset($_SERVER["REMOTE_ADDR"])) {
				$ip = $_SERVER["REMOTE_ADDR"];
			} else {
				$ip = "Unknown IP";
			}
			$ip = str_pad($ip, 15);
			$taggedMessage = "$timestamp $ip $message\n";
			file_put_contents($this->logFile, $taggedMessage, FILE_APPEND);
		}
		if ($this->logCallback) {
			call_user_func_array($this->logCallback, array($message));
		}
	}

	/**
	 * @ignore
	 *
	 * @param string $message
	 */
	function writeToTraceLog($message) {
		if ($this->traceLogFile) {
			$timestamp = date("Y-m-d H:i:s");
			$timestamp = str_pad($timestamp, 20);
			if (isset($_SERVER["REMOTE_ADDR"])) {
				$ip = $_SERVER["REMOTE_ADDR"];
			} else {
				$ip = "Unknown IP";
			}
			$ip = str_pad($ip, 15);
			$taggedMessage = "$timestamp $ip $message\n";
			file_put_contents($this->traceLogFile, $taggedMessage, FILE_APPEND);
		}
		if ($this->traceLogCallback) {
			call_user_func_array($this->traceLogCallback, array($message));
		}
	}

	// ------ COMMUNICATION ------

	/**
	 * Get payId as string and validate it.
	 * @ignore
	 * @param Payment|string|array $payment String, Payment object or array as returned from paymentInit call
	 * @return string
	 * @throws Exception
	 */
	protected function getPayId($payment) {
		if (!is_string($payment) and $payment instanceof Payment) {
			$payment = $payment->getPayId();
			if (!$payment) {
				throw new Exception("Given Payment object does not have payId. Please call paymentInit() first.");
			}
		}
		if (is_array($payment) and isset($payment["payId"])) {
			$payment = $payment["payId"];
		}
		if (!is_string($payment) or strlen($payment) != 15) {
			throw new Exception("Given Payment ID is not valid - it should be a string with length 15 characters.");
		}
		return $payment;
	}

	/**
	 * Get customerId as string and validate it.
	 * @ignore
	 * @param Payment|string|array $payment String, Payment object or array as returned from paymentInit call
	 * @return string
	 * @throws Exception
	 */
	protected function getCustomerId($payment) {
		if (!is_string($payment) and $payment instanceof Payment) {
			$payment = $payment->customerId;
		}
		if (is_array($payment) and isset($payment["customerId"])) {
			$payment = $payment["customerId"];
		}
		if (!is_string($payment)) {
			throw new Exception("Given Customer ID is not valid.");
		}
		return $payment;
	}


	/**
	 * Get current timestamp in payment gate's format.
	 * @return string
	 * @ignore
	 */
	public function getDTTM() {
		return date(self::DATE_FORMAT);
	}

	/**
	 * Signs array payload
	 * @param array $arrayToSign
	 * @return string Base64 encoded signature
	 * @ignore
	 */
	protected function signRequest($arrayToSign) {
		$stringToSign = Crypto::createSignatureBaseFromArray($arrayToSign);
		$keyFile = $this->config->privateKeyFile;
		$signature = Crypto::signString(
			$stringToSign,
			$keyFile,
			$this->config->privateKeyPassword,
			$this->config->getHashMethod()
		);

		$this->writeToTraceLog("Signing string \"$stringToSign\" using key $keyFile, result: ".$signature);

		return $signature;
	}

	/**
	 * Send prepared request.
	 *
	 * @param string $apiMethod
	 * @param array $payload
	 * @param bool|string $usePostMethod True = post, false = get, string = exact method
	 * @param array $responseFieldsOrder
	 * @param array $requestFieldsOrder
	 * @param bool $returnUrlOnly
	 * @param bool $allowInvalidReturnSignature Set to true if you want to ignore the fact
	 * that the signature of returned data was incorrect, so that you can receive the returned data anyway
	 * and handle the situation by yourself. If false, an exception will be thrown instead of returning the received data.
	 * @param Extension[]|Extension $extensions
	 *
	 * @return array|string
	 * @ignore
	 */
	protected function sendRequest(
		$apiMethod,
		$payload,
		$usePostMethod = true,
		$responseFieldsOrder = null,
		$requestFieldsOrder = null,
		$returnUrlOnly = false,
		$allowInvalidReturnSignature = false,
		$extensions = array()
	) {
		$url = $this->getApiMethodUrl($apiMethod);

		$method = $usePostMethod;

		$this->writeToTraceLog("Will send request to method $apiMethod");

		if (!$usePostMethod or $usePostMethod === "GET") {
			$method = "GET";

			if (!$requestFieldsOrder) {
				$requestFieldsOrder = $responseFieldsOrder;
			}
			$parametersToUrl = $requestFieldsOrder ? $requestFieldsOrder : array_keys($payload);
			foreach($parametersToUrl as $param) {
				if (isset($payload[$param])) {
					$url .= "/" . urlencode($payload[$param]);
				}
			}
		}

		if ($method === true) {
			$method = "POST";
		}

		if ($extensions) {
			$extensions = Arrays::arrayize($extensions);
		}

		if ($extensions) {
			$payload["extensions"] = array();
			/** @var Extension $extension */
			foreach ($extensions as $extension) {
				if (!($extension instanceof Extension)) {
					throw new Exception('Given argument is not Extension object.');
				}
				$extension->setHashMethod($this->config->getHashMethod());
				$addedData = $extension->createRequestArray($this);
				if ($addedData) {
					$payload["extensions"][] = $addedData;
				}
			}
		}

		if ($returnUrlOnly) {
			$this->writeToTraceLog("Returned final URL: " . $url);
			return $url;
		}

		$ch = \curl_init($url);
		$this->writeToTraceLog("URL to send request to: " . $url);

		if ($method === "POST" or $method === "PUT") {
			$encodedPayload = json_encode($payload);
			if (json_last_error()) {
				$msg = 'Request data could not be encoded to JSON: ' . json_last_error();
				if (function_exists('json_last_error_msg')) {
					$msg .= ' - ' . json_last_error_msg();
				}
				$this->writeToTraceLog($msg);
				throw new Exception($msg, 1);
			}
			$this->writeToTraceLog("JSON payload: ".$encodedPayload);
			\curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			\curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedPayload);
		}

		if (!$this->config->sslCertificatePath) {
			\curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		} else {
			if (is_dir($this->config->sslCertificatePath)) {
				\curl_setopt($ch, CURLOPT_CAPATH, $this->config->sslCertificatePath);
			} else {
				\curl_setopt($ch, CURLOPT_CAINFO, $this->config->sslCertificatePath);
			}
		}

		if ($this->config->sslVersion) {
			\curl_setopt($ch, CURLOPT_SSLVERSION, $this->config->sslVersion);
		}

		\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Accept: application/json;charset=UTF-8'
		));

		$result = \curl_exec($ch);

		if (\curl_errno($ch)) {
			$this->writeToTraceLog("CURL failed: " . \curl_errno($ch) . " " . \curl_error($ch));
			throw new Exception("Failed sending data to API: ".\curl_errno($ch)." ".\curl_error($ch));
		}

		$this->writeToTraceLog("API response: $result");

		$httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($httpCode != 200) {
			$this->writeToTraceLog("Failed: returned HTTP code $httpCode");

			$errorMessage = '';
			$decoded = @json_decode($result, true);
			if ($decoded) {
				if (isset($decoded['resultMessage']) and isset($decoded['resultCode'])) {
					$errorMessage = $decoded['resultMessage'] . ' (resultCode ' . $decoded['resultCode'] . ')';
				}
			}

			throw new Exception(
				"API returned HTTP code $httpCode, which is not code 200."
				. ($errorMessage ? (' ' . $errorMessage) : ''),
				$httpCode
			);
		}

		\curl_close($ch);

		$decoded = @json_decode($result, true);
		if ($decoded === null) {
			$this->writeToTraceLog("Failed: returned value is not parsable JSON");
			throw new Exception("API did not return a parseable JSON string: \"".$result."\"");
		}

		if (!isset($decoded["resultCode"])) {
			$this->writeToTraceLog("Failed: API did not return response with resultCode");
			throw new Exception("API did not return a response containing resultCode.");
		}

		if (!isset($decoded["signature"]) or !$decoded["signature"]) {
			$this->writeToTraceLog("Failed: missing response signature");
			throw new Exception("Result does not contain signature.");
		}

		$signature = $decoded["signature"];

		try {
			$verificationResult = $this->verifyResponseSignature($decoded, $signature, $responseFieldsOrder);
		} catch (Exception $e) {
			$this->writeToTraceLog("Failed: error occured when verifying signature.");
			throw $e;
		}

		if (!$verificationResult) {

			if (!$allowInvalidReturnSignature) {
				$this->writeToTraceLog("Failed: signature is incorrect.");
				throw new Exception("Result signature is incorrect. Please make sure that bank's public key in file specified in config is correct and up-to-date.");
			} else {
				$this->writeToTraceLog("Signature is incorrect, but method was called with \$allowInvalidReturnSignature = true, so we'll ignore it.");
			}
		}

		if ($decoded["resultCode"] != "0") {
			$this->writeToTraceLog("Failed: resultCode ".$decoded["resultCode"].", message ".$decoded["resultMessage"]);
			throw new Exception("API returned an error: resultCode \"" . $decoded["resultCode"] . "\", resultMessage: ".$decoded["resultMessage"], $decoded["resultCode"]);
		}

		if ($extensions) {
			$extensionsById = array();
			foreach ($extensions as $extension) {
				$extensionsById[$extension->getExtensionId()] = $extension;
			}
			$extensionsDataDecoded = isset($decoded["extensions"]) ? $decoded["extensions"] : array();
			foreach ($extensionsDataDecoded as $extensionData) {
				$extensionId = $extensionData['extension'];
				if (isset($extensionsById[$extensionId])) {
					/** @var Extension $extensionObject */
					$extensionObject = $extensionsById[$extensionId];
					$extensionObject->setResponseData($extensionData);
					$extensionObject->setHashMethod($this->config->getHashMethod());
					$signatureResult = $extensionObject->verifySignature($extensionData, $this);
					if (!$signatureResult) {
						$this->writeToTraceLog("Signature of extension $extensionId is incorrect.");
						if ($extension->getStrictSignatureVerification()) {
							throw new Exception("Result signature of extension $extensionId is incorrect. Please make sure that bank's public key in file specified in config is correct and up-to-date.");
						} else {
							$extension->setSignatureCorrect(false);
						}
					} else {
						$extension->setSignatureCorrect(true);
					}
				}
			}
		}

		$this->writeToTraceLog("OK");

		return $decoded;
	}

	/**
	 * Gets the URL of API method
	 * @param string $apiMethod
	 * @return string
	 */
	function getApiMethodUrl($apiMethod) {
		return $this->config->url . "/" . $apiMethod;
	}

	/**
	 * @param array $response
	 * @param string $signature in Base64
	 * @param array $responseFieldsOrder
	 * @return bool
	 * @ignore
	 */
	function verifyResponseSignature($response, $signature, $responseFieldsOrder = array()) {

		$responseWithoutSignature = $response;
		if (isset($responseWithoutSignature["signature"])) {
			unset($responseWithoutSignature["signature"]);
		}

		if ($responseFieldsOrder) {
			$string = Crypto::createSignatureBaseWithOrder($responseWithoutSignature, $responseFieldsOrder, false);
		} else {
			$string = Crypto::createSignatureBaseFromArray($responseWithoutSignature, false);
		}

		$this->writeToTraceLog("String for verifying signature: \"" . $string . "\", using key " . $this->config->bankPublicKeyFile);

		return Crypto::verifySignature($string, $signature, $this->config->bankPublicKeyFile, $this->config->getHashMethod());
	}


}

}


// src/Config.php 

namespace OndraKoupil\Csob {


/**
 * Configuration for integrating your app to bank gateway.
 */
class Config {

	/**
	 * Bank API path. By default, this is the testing (playground) API.
	 * Change that when you are ready to go to live environment.
	 *
	 * @var string
	 *
	 * @see GatewayUrl
	 */
	public $url = GatewayUrl::TEST_LATEST;

	/**
	 * API Version. Version 1.8 brings some BC breaks, so the library needs to know which version you want to call.
	 * Use this property to explicitly specify API version. Leave null to autodetect from endpoint URL.
	 *
	 * @var string
	 */
	public $apiVersion = null;

	/**
	 * @var int|null One of OPENSSL_HASH_* constants or null for auto detection
	 */
	public $hashMethod = null;

	/**
	 * Path to file where bank's public key is saved.
	 *
	 * You can obtain the key from bank's app
	 * https://iposman.iplatebnibrana.csob.cz/posmerchant
	 * or from their package on GitHub)
	 *
	 * @var string
	 */
	public $bankPublicKeyFile = "";

	/**
	 * Your Merchant ID.
	 *
	 * You obtain that from the bank or from https://iplatebnibrana.csob.cz/keygen/
	 *
	 * @var string
	 */
	public $merchantId = "";

	/**
	 * Path to file where your private key is saved.
	 *
	 * You obtain that key from https://iplatebnibrana.csob.cz/keygen/ - it is
	 * the .key file you download from the keygen.
	 *
	 * Careful - that file MUST NOT BE publicly accessible on webserver!
	 * @var string
	 */
	public $privateKeyFile = "";

	/**
	 * Password for your private key.
	 *
	 * You need to specify this only if your private key was not generated
	 * using bank's keygen https://iplatebnibrana.csob.cz/keygen/
	 * @var string
	 */
	public $privateKeyPassword = null;

	/**
	 * A URL of your e-shop to return your customers after the have paid.
	 *
	 * @var string
	 */
	public $returnUrl;

	/**
	 * A method to return customers on $returnUrl.
	 *
	 * Right now (api v1) it is not much significant, since (according to their doc)
	 * you must support both GET and POST methods.
	 *
	 * @var string
	 */
	public $returnMethod = "POST";


	/**
	 * Name of your e-shop or app - it will be used on some points of
	 * creating payments.
	 *
	 * @var string
	 */
	public $shopName;

	/**
	 * Should payments be created with closePayment = true by default?
	 * See Wiki on ČSOB's github for more information.
	 *
	 * @var boolean
	 */
	public $closePayment = true;

	/**
	 * Path to a CA certificate chain or a directory containing certificates to verify
	 * bank's certificate when initiating a HTTPS connection.
	 *
	 * Leave null to disable certificate validation.
	 *
	 * @see CURLOPT_SSL_VERIFYPEER, CURLOPT_CAINFO, CURLOPT_CAPATH
	 *
	 * @var string
	 */
	public $sslCertificatePath = null;

	/**
	 * Force the client to use a specific SSL version.
	 *
	 * Leave null to use automatic selection (default).
	 *
	 * @var number Use one of CURL_SSLVERSION_* or CURL_SSLVERSION_MAX_* constants
	 *
	 * @see https://www.php.net/manual/en/function.curl-setopt.php
	 */
	public $sslVersion = null;

	/**
	 * Create config with all mandatory values.
	 *
	 * See equally named properties of this class for more info.
	 *
	 * To specify $bankApiUrl, you can use constants of GatewayUrl class.
	 *
	 * @param string $merchantId
	 * @param string $privateKeyFile
	 * @param string $bankPublicKeyFile
	 * @param string $shopName
	 * @param string|null $returnUrl
	 * @param string|null $bankApiUrl
	 * @param string|null $privateKeyPassword
	 * @param string|null $sslCertificatePath
	 * @param string|null $apiVersion Leave null to autodetect from $bankApiUrl
	 * @param int|null $hashMethod One of OPENSSL_HASH_* constants, leave null for auto detection from given $bankApiUrl. Read via getHashMethod();
	 */
	function __construct(
		$merchantId,
		$privateKeyFile,
		$bankPublicKeyFile,
		$shopName,
		$returnUrl = null,
		$bankApiUrl = null,
		$privateKeyPassword = null,
		$sslCertificatePath = null,
		$apiVersion = null,
		$hashMethod = null
	) {
		if ($bankApiUrl) {
			$this->url = $bankApiUrl;
		}
		if ($privateKeyPassword) {
			$this->privateKeyPassword = $privateKeyPassword;
		}

		$this->merchantId = $merchantId;
		$this->privateKeyFile = $privateKeyFile;
		$this->bankPublicKeyFile = $bankPublicKeyFile;

		$this->returnUrl = $returnUrl;
		$this->shopName = $shopName;
		$this->sslCertificatePath = $sslCertificatePath;
		$this->hashMethod = $hashMethod;
		$this->apiVersion = $apiVersion;
	}

	function getVersion() {
		if (!$this->apiVersion) {
			if (!$this->url) {
				throw new Exception('You must specify bank API URL first.');
			}
			$match = preg_match('~\/api\/v([0-9.]+)$~', $this->url, $matches);
			if ($match) {
				$this->apiVersion = $matches[1];
			} else {
				throw new Exception('Can not deduce API version from URL: ' . $this->url);
			}
		}
		return $this->apiVersion;
	}

	/**
	 * Return the set hashing method or deduce it from bank API's version.
	 *
	 * @return int
	 */
	function getHashMethod() {
		if ($this->hashMethod) {
			return $this->hashMethod;
		}
		if ($this->queryApiVersion('1.8')) {
			return OPENSSL_ALGO_SHA256;
		} else {
			return OPENSSL_ALGO_SHA1;
		}
	}

	/**
	 * Returns true if currently set API version is at least $version or greater.
	 *
	 * @param string $version
	 *
	 * @return boolean
	 */
	function queryApiVersion($version) {
		return !!version_compare($this->getVersion(), $version, '>=');
	}

}

}


// src/Payment.php 

namespace OndraKoupil\Csob {

use OndraKoupil\Csob\Metadata\Customer;

use OndraKoupil\Csob\Metadata\Order;

use \OndraKoupil\Tools\Strings;

use \OndraKoupil\Tools\Arrays;


use DateTime;

/**
 * A payment request.
 *
 * To init new payment, you need to create an instance
 * of this class and fill its properties with real information
 * from the order.
 */
class Payment {

	/**
	 * Běžná platba
	 */
	const OPERATION_PAYMENT = "payment";

	/**
	 * Opakovaná platba
	 *
	 * @deprecated Deprecated since eAPI 1.7 - use one click payments
	 */
	const OPERATION_RECURRENT = "recurrentPayment";

	/**
	 * Platba na klik
	 */
	const OPERATION_ONE_CLICK = "oneclickPayment";

	/**
	 * Custom platba
	 */
	const OPERATION_CUSTOM_PAYMENT = "customPayment";

	/**
	 * @ignore
	 * @var string
	 */
	protected $merchantId;

	/**
	 * Number of your order, a string of 1 to 10 numbers
	 * (this is basically the Variable symbol).
	 *
	 * This is the only one mandatory value you need to supply.
	 *
	 * @var string
	 */
	public $orderNo;

	/**
	 * @ignore
	 * @var number
	 */
	protected $totalAmount = 0;

	/**
	 * For oneclick payments use only
	 * @internal
	 * @var string
	 */
	public $origPayId;

	/**
	 * Currency of the transaction. Default value is "CZK".
	 * @var string
	 */
	public $currency;

	/**
	 * Should the payment be processed right on?
	 * See Wiki on ČSOB's github for more information.
	 *
	 * If not set, value from Config us used (true by default).
	 *
	 * @var bool|null
	 */
	public $closePayment = null;

	/**
	 * Return URL to send your customers back to.
	 *
	 * You need to specify this only if you don't want to use the default
	 * URL from your Config. Leave empty to use the default one.
	 *
	 * @var string
	 */
	public $returnUrl;

	/**
	 * Return method. Leave empty to use the default one.
	 * @var string
	 * @see returnUrl
	 */
	public $returnMethod;

	/**
	 * @ignore
	 * @var array
	 */
	protected $cart = array();

	/**
	 * Description of the order that will be shown to customer during payment
	 * process.
	 *
	 * Leave empty to use your e-shop's name as given in Config.
	 *
	 * @var string
	 */
	public $description;

	/**
	 * @ignore
	 * @var string
	 */
	protected $merchantData;

	/**
	 * Your customer's ID (e-mail, number, whatever...)
	 *
	 * Leave empty if you don't want to use some features relying on knowing
	 * customer ID.
	 *
	 */
	public string $customerId = '';

	/**
	 * Language of the gateway. Default is "CZ".
	 *
	 * See wiki on ČSOB's Github for other values, they are not the same
	 * as standard ISO language codes.
	 *
	 * @see https://github.com/csob/paymentgateway/wiki/Basic-Methods
	 *
	 * @var string
	 */
	public $language;

	/**
	 * @ignore
	 * @var string
	 */

	protected $dttm;

	/**
	 * payOperation value. Leave empty to use the default
	 * (and the only one valid) value.
	 *
	 * Using API v1, you can ignore this.
	 *
	 * @var string
	 */
	public $payOperation;

	/**
	 * payMethod value. Leave empty to use the default
	 * (and the only one valid) value.
	 *
	 * Using API v1, you can ignore this.
	 *
	 * @var string
	 */
	public $payMethod;

	/**
	 * The PayID value that you will need fo call other methods.
	 * It is given to your payment by bank.
	 *
	 * @var string
	 * @see getPayId
	 */
	protected $foreignId;

	/**
	 * Lifetime of the transaction in seconds. Number from 300 to 1800.
	 *
	 * @var int
	 */
	public $ttlSec;

	/**
	 * Version of logo.
	 *
	 * @var int
	 */
	public $logoVersion;

	/**
	 * Color version
	 *
	 * @var int
	 */
	public $colorSchemeVersion;

	/**
	 * @var Customer
	 */
	protected $customer;


	/**
	 * @var Order
	 */
	protected $order;


	/**
	 * @var DateTime
	 */
	protected $customExpiry;

	/**
	 * @var array
	 * @ignore
	 */
	private $fieldsInOrder = array(
		"merchantId",
		"*origPayId", // placeholder
		"orderNo",
		"dttm",
		"payOperation",
		"payMethod",
		"totalAmount",
		"currency",
		"closePayment",
		"returnUrl",
		"returnMethod",
		"cart",
		"*customer", // placeholder
		"*order", // placeholder
		"description",
		"merchantData",
		"customerId",
		"language",
		"ttlSec",
	);

	private $auxFieldsInOrder = array(
		"logoVersion",
		"colorSchemeVersion",
		"customExpiry"
	);


	/**
	 * @param string $orderNo
	 * @param mixed $merchantData
	 * @param string $customerId
	 * @param bool|null $oneClickPayment
	 */
	function __construct($orderNo = '', $merchantData = null, $customerId = '', $oneClickPayment = null) {
		$this->orderNo = $orderNo;

		if ($merchantData) {
			$this->setMerchantData($merchantData);
		}

		if ($customerId) {
			$this->customerId = $customerId;
		}

		if ($oneClickPayment !== null) {
			$this->setOneClickPayment($oneClickPayment);
		}
	}

	/**
	 * Add one cart item.
	 *
	 * You are required to add one or two cart items (at least on API v1).
	 *
	 * Remember that $totalAmount must be given in **hundredth of currency units**
	 * (cents for USD or EUR, "halíře" for CZK)
	 *
	 * @param string $name Name that customer will see
	 * (will be automatically trimmed to 20 characters)
	 * @param number $quantity
	 * @param number $totalAmount Total price (total sum for all $quantity),
	 * in **hundredths** of currency unit
	 * @param string $description Aux description (trimmed to 40 chars max)
	 *
	 * @return Payment Fluent interface
	 *
	 * @throws Exception When more than 2nd cart item is to be added or other argument is invalid
	 */
	function addCartItem($name, $quantity, $totalAmount, $description = "") {

		if (count($this->cart) >= 2) {
			throw new Exception("This version of banks's API supports only up to 2 cart items in single payment, you can't add any more items.");
		}

		if (!is_numeric($quantity) or $quantity < 1) {
			throw new Exception("Invalid quantity: $quantity. It must be numeric and >= 1");
		}

		$name = trim(Strings::shorten($name, 20, "", true, true));
		$description = trim(Strings::shorten($description, 40, "", true, true));

		$this->cart[] = array(
			"name" => $name,
			"quantity" => $quantity,
			"amount" => intval(round($totalAmount)),
			"description" => $description
		);

		return $this;
	}

	/**
	 * @return Customer
	 */
	public function getCustomer() {
		return $this->customer;
	}

	/**
	 * @param Customer $customer
	 *
	 * @return Payment
	 */
	public function setCustomer($customer) {
		$this->customer = $customer;

		return $this;
	}

	/**
	 * @return Order
	 */
	public function getOrder() {
		return $this->order;
	}

	/**
	 * @param Order $order
	 *
	 * @return Payment
	 */
	public function setOrder($order) {
		$this->order = $order;

		return $this;
	}





	/**
	 * Set some arbitrary data you will receive back when customer returns
	 *
	 * @param string $data
	 * @param bool $alreadyEncoded True if given $data is already encoded to Base64
	 *
	 * @return Payment Fluent interface
	 *
	 * @throws Exception When the data is too long and can't be encoded.
	 */
	public function setMerchantData($data, $alreadyEncoded = false) {
		if (!$alreadyEncoded) {
			$data = base64_encode($data);
		}
		if (strlen($data) > 255) {
			throw new Exception("Merchant data can not be longer than 255 characters after base64 encoding.");
		}
		$this->merchantData = $data;
		return $this;
	}

	/**
	 * Get back merchantData, decoded to original value.
	 *
	 * @return string
	 */
	public function getMerchantData() {
		if ($this->merchantData) {
			return base64_decode($this->merchantData);
		}
		return "";
	}

	/**
	 * Get back MerchantData encoded as base64.
	 *
	 * @return string
	 */
	public function getMerchantDataEncoded() {
		return $this->merchantData ?: '';
	}

	/**
	 * After the payment has been saved using payment/init, you can
	 * get PayID from here.
	 *
	 * @return string
	 */
	public function getPayId() {
		return $this->foreignId;
	}

	/**
	 * Returns sum of all cart items in **hundreths** of base currency unit.
	 *
	 * @return number
	 */
	public function getTotalAmount() {
		$sumOfItems = array_sum(Arrays::transform($this->cart, true, "amount"));
		$this->totalAmount = $sumOfItems;
		return $this->totalAmount;
	}

	/**
	 * Cart items as array.
	 * @return array
	 */
	function getCart() {
		return $this->cart;
	}

	/**
	 * Do not call this on your own. Really.
	 *
	 * @param string $id
	 */
	public function setPayId($id) {
		$this->foreignId = $id;
	}



	/**
	 * Mark this payment as a template for recurrent payments.
	 *
	 * Basically, this is a lazy method for setting $payOperation to OPERATION_RECURRENT.
	 *
	 * @param bool $recurrent
	 * @deprecated Deprecated and replaced by setOneClickPayment
	 *
	 * @return \OndraKoupil\Csob\Payment
	 */
	function setRecurrentPayment($recurrent = true) {
		$this->payOperation = $recurrent ? self::OPERATION_RECURRENT : self::OPERATION_PAYMENT;
		trigger_error('setRecurrentPayment() is deprecated, use setOneClickPayment() instead.', E_USER_DEPRECATED);
		return $this;
	}

	/**
	 * Mark this payment as one-click payment template
	 *
	 * Basically, this is a lazy method for setting $payOperation to OPERATION_ONE_CLICK
	 *
	 * @param bool $oneClick
	 *
	 * @return $this
	 */
	function setOneClickPayment($oneClick = true) {
		$this->payOperation = $oneClick ? self::OPERATION_ONE_CLICK : self::OPERATION_PAYMENT;
		return $this;
	}

	function setCustomExpiry(DateTime $customExpiry) {
		$this->customExpiry = $customExpiry->format('YmdHis');
	}

	/**
	 * Validate and initialise properties. This method is called
	 * automatically in proper time, you never have to call it on your own.
	 *
	 * @param Config $config
	 * @throws Exception
	 * @return Payment Fluent interface
	 *
	 * @ignore
	 */
	function checkAndPrepare(Config $config) {
		$this->merchantId = $config->merchantId;

		$this->dttm = date(Client::DATE_FORMAT);

		if (!$this->payOperation) {
			$this->payOperation = self::OPERATION_PAYMENT;
		}

		if (!$this->payMethod) {
			$this->payMethod = "card";
		}

		if (!$this->currency) {
			$this->currency = "CZK";
		}

		if (!$this->language) {
			$this->language = "CZ";
		}
		
		if (!$this->ttlSec or !is_numeric($this->ttlSec)) {
			$this->ttlSec = 1800;
		}

		if ($this->closePayment === null) {
			$this->closePayment = $config->closePayment ? true : false;
		}

		if (!$this->returnUrl) {
			$this->returnUrl = $config->returnUrl;
		}
		if (!$this->returnUrl) {
			throw new Exception("A ReturnUrl must be set - either by setting \$returnUrl property, or by specifying it in Config.");
		}

		if (!$this->returnMethod) {
			$this->returnMethod = $config->returnMethod;
		}

		if (!$this->description) {
			$this->description = $config->shopName.", ".$this->orderNo;
		}
		$this->description = Strings::shorten($this->description, 240, "...");

		$this->customerId = Strings::shorten($this->customerId, 50, "", true, true);

		if (!$this->cart) {
			throw new Exception("Cart is empty. Please add one or two items into cart using addCartItem() method.");
		}

		if (!$this->orderNo or !preg_match('~^[0-9]{1,10}$~', $this->orderNo)) {
			throw new Exception("Invalid orderNo - it must be a non-empty numeric value, 10 characters max.");
		}

		$sumOfItems = array_sum(Arrays::transform($this->cart, true, "amount"));
		$this->totalAmount = $sumOfItems;

		return $this;
	}

	/**
	 * Add signature and export to array. This method is called automatically
	 * and you don't need to call is on your own.
	 *
	 * @param Client $client
	 * @return array
	 *
	 * @ignore
	 */
	function signAndExport(Client $client) {
		$arr = array();

		$config = $client->getConfig();

		$fieldNames = $this->fieldsInOrder;
		if ($client->getConfig()->queryApiVersion('1.8')) {
			// Version 1.8 omitted $description parameter
			$fieldNames = Arrays::deleteValue($fieldNames, 'description');
		}

		foreach($fieldNames as $f) {
			if ($f[0] === '*') {
				continue; // skip those beginning with asterisk - they are just placeholders
			}
			$val = $this->$f;
			if ($val === null) {
				$val = "";
			}
			$arr[$f] = $val;
		}

		foreach ($this->auxFieldsInOrder as $f) {
			$val = $this->$f;
			if ($val !== null) {
				$arr[$f] = $val;
			}
		}

		// Sice API 1.9, we add a complex customer and order objects to the payment data.
		if ($client->getConfig()->queryApiVersion('1.9')) {
			if ($this->customer) {
				$arr['customer'] = $this->customer->export();
			}
			if ($this->order) {
				$arr['order'] = $this->order->export();
			}
			if ($this->origPayId) {
				$arr['origPayId'] = $this->origPayId;
			}
		}

		$stringToSign = $this->getSignatureString($client);

		$client->writeToTraceLog('Signing payment request, base for the signature:' . "\n" . $stringToSign);

		$signed = Crypto::signString($stringToSign, $config->privateKeyFile, $config->privateKeyPassword, $client->getConfig()->getHashMethod());
		$arr["signature"] = $signed;

		return $arr;
	}

	/**
	 * Convert to string that serves as base for signing.
	 *
	 * @param Client $client
	 *
	 * @return string
	 * @ignore
	 */
	function getSignatureString(Client $client) {
		$parts = array();

		$fieldNames = $this->fieldsInOrder;
		if ($client->getConfig()->queryApiVersion('1.8')) {
			// Version 1.8 omitted $description parameter
			$fieldNames = Arrays::deleteValue($fieldNames, 'description');
		}

		$partsToSign = array();

		foreach($fieldNames as $f) {
			if ($f[0] === '*') {
				// These needs special treatment
				if ($f === '*customer') {
					if ($this->customer and $client->getConfig()->queryApiVersion('1.9')) {
						$partsToSign[] = $this->customer->export();
					}
				}
				if ($f === '*order') {
					if ($this->order and $client->getConfig()->queryApiVersion('1.9')) {
						$partsToSign[] = $this->order->export();
					}
				}
				if ($f === '*origPayId' and $this->origPayId and $client->getConfig()->queryApiVersion('1.9')) {
					$partsToSign[] = $this->origPayId;
				}
				continue;
			}

			$partsToSign[] = $this->$f;
		}

		foreach ($this->auxFieldsInOrder as $f) {
			$val = $this->$f;
			if ($val !== null) {
				$partsToSign[] = $val;
			}
		}

		return Tools::linearizeForSigning($partsToSign);
	}


}

}


// src/Crypto.php 

namespace OndraKoupil\Csob {


/**
 * Helper class for signing and signature verification
 *
 * @see https://github.com/csob/paymentgateway/blob/master/eshop-integration/eAPI/v1/php/example/crypto.php
 */
class Crypto {

	const DEFAULT_HASH_METHOD = OPENSSL_ALGO_SHA1;

	const HASH_SHA1 = OPENSSL_ALGO_SHA1;
	const HASH_SHA256 = OPENSSL_ALGO_SHA256;

	/**
	 * Signs a string
	 *
	 * @param string $string
	 * @param string $privateKeyFile Path to file with your private key (the .key file from https://iplatebnibrana.csob.cz/keygen/ )
	 * @param string $privateKeyPassword Password to the key, if it was generated with one. Leave empty if you created the key at https://iplatebnibrana.csob.cz/keygen/
	 * @param int $hashMethod One of OPENSSL_HASH_* constants
	 * @return string Signature encoded with Base64
	 * @throws CryptoException When signing fails or key file path is not valid
	 */
	static function signString($string, $privateKeyFile, $privateKeyPassword = "", $hashMethod = self::DEFAULT_HASH_METHOD) {

		if (!function_exists("openssl_get_privatekey")) {
			throw new CryptoException("OpenSSL extension in PHP is required. Please install or enable it.");
		}

		if (!file_exists($privateKeyFile) or !is_readable($privateKeyFile)) {
			throw new CryptoException("Private key file \"$privateKeyFile\" not found or not readable.");
		}

		$keyAsString = file_get_contents($privateKeyFile);

		$privateKeyId = openssl_get_privatekey($keyAsString, $privateKeyPassword);
		if (!$privateKeyId) {
			throw new CryptoException("Private key could not be loaded from file \"$privateKeyFile\". Please make sure that the file contains valid private key in PEM format.");
		}

		$ok = openssl_sign($string, $signature, $privateKeyId, $hashMethod);
		if (!$ok) {
			throw new CryptoException("Signing failed.");
		}
		$signature = base64_encode ($signature);
		if (version_compare(PHP_VERSION, '8.0', '<')) {
			// https://github.com/ondrakoupil/csob/issues/33
			openssl_free_key ($privateKeyId);
		}

		return $signature;
	}


	/**
	 * Verifies signature of a string
	 *
	 * @param string $textToVerify The text that was signed
	 * @param string $signatureInBase64 The signature encoded with Base64
	 * @param string $publicKeyFile Path to file where bank's public key is saved
	 * (you can obtain it from bank's app https://iposman.iplatebnibrana.csob.cz/posmerchant
	 * or from their package on GitHub)
	 * @param int $hashMethod One of OPENSSL_HASH_* constants
	 *
	 * @return bool True if signature is correct
	 */
	static function verifySignature($textToVerify, $signatureInBase64, $publicKeyFile, $hashMethod = self::DEFAULT_HASH_METHOD) {

		if (!function_exists("openssl_get_privatekey")) {
			throw new CryptoException("OpenSSL extension in PHP is required. Please install or enable it.");
		}

		if (!file_exists($publicKeyFile) or !is_readable($publicKeyFile)) {
			throw new CryptoException("Public key file \"$publicKeyFile\" not found or not readable.");
		}

		$keyAsString = file_get_contents($publicKeyFile);
		$publicKeyId = openssl_get_publickey($keyAsString);

		$signature = base64_decode($signatureInBase64);

		$res = openssl_verify($textToVerify, $signature, $publicKeyId, $hashMethod);
		if (version_compare(PHP_VERSION, '8.0', '<')) {
			// https://github.com/ondrakoupil/csob/issues/33
			openssl_free_key($publicKeyId);
		}

		if ($res == -1) {
			throw new CryptoException("Verification of signature failed: ".openssl_error_string());
		}

		return $res ? true : false;
	}

	/**
	 * Vytvoří z array (i víceúrovňového) string pro výpočet podpisu.
	 *
	 * @param array $array
	 * @param bool $returnAsArray
	 *
	 * @return string|array
	 */
	static function createSignatureBaseFromArray($array, $returnAsArray = false) {
		$linearizedArray = self::createSignatureBaseRecursion($array);
		if ($returnAsArray) {
			return $linearizedArray;
		}
		return implode('|', $linearizedArray);
	}

	protected static function createSignatureBaseRecursion($array, $depthCheck = 0) {
		if ($depthCheck > 10) {
			return array();
		}
		$ret = array();
		foreach ($array as $val) {
			if (is_array($val)) {
				$ret = array_merge(
					$ret,
					self::createSignatureBaseRecursion($val, $depthCheck + 1)
				);
			} elseif (is_bool($val)) {
				$ret[] = $val ? 'true' : 'false';
			} else {
				$ret[] = $val;
			}
		}
		return $ret;
	}

	/**
	 * Generická implementace linearizace pole s dopředu zadaným požadovaným pořadím.
	 *
	 * V $order by mělo být požadované pořadí položek formou stringových "keypath".
	 * Keypath je název klíče v poli $data, pokud je víceúrovňové, klíče jsou spojeny tečkou.
	 *
	 * Pokud keypath začíná znakem otazník, považuje se za nepovinnou a není-li taková
	 * položka nalezena, z výsledku se vynechá. V opačném případě se vloží prázdný řetězec.
	 *
	 * Pokud keypath odkazuje na další array, to se vloží postupně položka po položce.
	 *
	 * Příklad:
	 *
	 * ```php
	 * $data = array(
	 *    'foo' => 'bar',
	 *    'arr' => array(
	 *        'a' => 'A',
	 *        'b' => 'B'
	 *    )
	 * );
	 *
	 * $order = array(
	 *    'foo',
	 *    'arr.a',
	 *    'somethingRequired',
	 *    '?somethingOptional',
	 *    'foo',
	 *    'arr.x',
	 *    'foo',
	 *    'arr'
	 * );
	 *
	 * $result = Crypto::createSignatureBaseWithOrder($data, $order, false);
	 *
	 * $result == array('bar', 'A', '', 'bar', '', 'bar', 'A', 'B');
	 * ```
	 *
	 * @param array $data Pole s daty
	 * @param array $order Požadované pořadí položek.
	 * @param bool $returnAsArray
	 *
	 * @return array
	 */
	static function createSignatureBaseWithOrder($data, $order, $returnAsArray = false) {

		$result = array();

		foreach ($order as $key) {
			$optional = false;
			if ($key[0] == '?') {
				$optional = true;
				$key = substr($key, 1);
			}
			$keyPath = explode('.', $key);

			$pos = $data;
			$found = true;
			foreach ($keyPath as $keyPathComponent) {
				// NULLs are not included in signature as well
				if (array_key_exists($keyPathComponent, $pos) and $pos[$keyPathComponent] !== null) {
					$pos = $pos[$keyPathComponent];
				} else {
					$found = false;
					break;
				}
			}

			if ($found) {
				if (is_array($pos)) {
					$result = array_merge($result, self::createSignatureBaseFromArray($pos, true));
				} else {
					if (is_bool($pos)) {
						$result[] = $pos ? 'true' : 'false';
					} else {
						$result[] = $pos;
					}
				}
			} else {
				if (!$optional) {
					$result[] = '';
				}
			}
		}

		if ($returnAsArray) {
			return $result;
		}

		return implode('|', $result);

	}

}

}


// src/Exception.php 

namespace OndraKoupil\Csob {


class Exception extends \RuntimeException {}

}


// src/CryptoException.php 

namespace OndraKoupil\Csob {


class CryptoException extends Exception {}
}


// src/GatewayUrl.php 

namespace OndraKoupil\Csob {


/**
 * Class containing for CSOB gateway URLs
 */
class GatewayUrl {

	const TEST_LATEST = self::TEST_1_9;

	const PRODUCTION_LATEST = self::PRODUCTION_1_9;

	const TEST_1_0 = "https://iapi.iplatebnibrana.csob.cz/api/v1";

	const PRODUCTION_1_0 = "https://api.platebnibrana.csob.cz/api/v1";

	const TEST_1_5 = "https://iapi.iplatebnibrana.csob.cz/api/v1.5";

	const PRODUCTION_1_5 = "https://api.platebnibrana.csob.cz/api/v1.5";

	const TEST_1_6 = "https://iapi.iplatebnibrana.csob.cz/api/v1.6";

	const PRODUCTION_1_6 = "https://api.platebnibrana.csob.cz/api/v1.6";

	const TEST_1_7 = "https://iapi.iplatebnibrana.csob.cz/api/v1.7";

	const PRODUCTION_1_7 = "https://api.platebnibrana.csob.cz/api/v1.7";

	const TEST_1_8 = "https://iapi.iplatebnibrana.csob.cz/api/v1.8";

	const PRODUCTION_1_8 = "https://api.platebnibrana.csob.cz/api/v1.8";

	const TEST_1_9 = "https://iapi.iplatebnibrana.csob.cz/api/v1.9";

	const PRODUCTION_1_9 = "https://api.platebnibrana.csob.cz/api/v1.9";

}

}


// src/Extension.php 

namespace OndraKoupil\Csob {


/**
 * Represents additional data to be sent with a request to the API
 * and also defines how to verify the response.
 *
 * Each method call can have several extensions.
 */
class Extension {

	/**
	 * @var array
	 */
	protected $inputData;

	/**
	 * @var array
	 */
	protected $responseData;

	/**
	 * @var array
	 */
	protected $expectedResponseKeysOrder;

	/**
	 * @var string
	 */
	protected $extensionId;

	/**
	 * @var bool
	 */
	protected $strictSignatureVerification = true;

	/**
	 * @var bool
	 */
	protected $signatureCorrect = false;

	/**
	 * @var int
	 */
	protected $hashMethod = Crypto::DEFAULT_HASH_METHOD;

	/**
	 * @param string $extensionId
	 */
	function __construct($extensionId) {
		if (!$extensionId) {
			throw new Exception('No Extension ID given!');
		}
		$this->extensionId = $extensionId;
	}

	/**
	 * @return array
	 */
	public function getInputData() {
		return $this->inputData;
	}

	/**
	 * Sets the data that are sent with your request to API.
	 *
	 * Set this to falsey value to disable sending extension data with your request
	 * (this extension will affect only response).
	 *
	 * Order of keys is significant because of generating base for signature.
	 * Check CSOB wiki for correct order.
	 *
	 * If you need to insert "dttm" parameter, you can just set it to null, real valu ewill be added automatically.
	 * The same is for "extension" parameter, which can be filled with extensionId.
	 *
	 * If signature base is being generated incorrectly, reimplement getRequestSignatureBase()
	 * with a better one.
	 *
	 * @param array $inputData
	 *
	 * @return Extension
	 */
	public function setInputData($inputData) {
		$this->inputData = $inputData;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getExpectedResponseKeysOrder() {
		return $this->expectedResponseKeysOrder;
	}

	/**
	 * Use this method to hint in which order should the base string
	 * for verifying signature of response be generated.
	 * See Crypto::createSignatureBaseWithOrder() for options for specifying key paths.
	 *
	 * If response signatures are verifyed incorrectly because of wrong order of parts
	 * in base string, you can reimplement getResponseSignatureBase() method with a better one.
	 *
	 * Set to falsey value to disable parsing of the extension object in response,
	 * the extension will then affect only sending the request.
	 *
	 * @param array $expectedResponseKeysOrder
	 *
	 * @return Extension
	 */
	public function setExpectedResponseKeysOrder($expectedResponseKeysOrder) {
		$this->expectedResponseKeysOrder = $expectedResponseKeysOrder;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getExtensionId() {
		return $this->extensionId;
	}

	/**
	 * Creates array for request.
	 *
	 * If requests for your extension are signed incorrectly because of
	 * wrong order of parts of the base string, check that order of keys in
	 * your input data given to setInputData() is the same as in extension's documentation on CSOB wiki,
	 * or extend this class and reimplement getRequestSignatureBase() method.
	 *
	 * @param Client $client
	 *
	 * @return array
	 */
	public function createRequestArray(Client $client) {

		$sourceArray = $this->getInputData();

		if (!$sourceArray) {
			return null;
		}

		$config = $client->getConfig();

		/*
		if (!array_key_exists('dttm', $sourceArray)) {
			$sourceArray = array(
					'dttm' => $this->getDTTM()
				) + $sourceArray;
		} elseif (!$sourceArray['dttm']) {
			$sourceArray['dttm'] = $this->getDTTM();
		}

		if (!array_key_exists('extension', $sourceArray)) {
			$sourceArray = array(
				'extension' => $this->getExtensionId()
			) + $sourceArray;
		} elseif (!$sourceArray['extension']) {
			$sourceArray['extension'] = $this->getExtensionId();
		}*/

		if (array_key_exists('dttm', $sourceArray) and !$sourceArray['dttm']) {
			$sourceArray['dttm'] = $client->getDTTM();
		}
		if (array_key_exists('extension', $sourceArray) and !$sourceArray['extension']) {
			$sourceArray['extension'] = $this->getExtensionId();
		}

		$baseString = $this->getRequestSignatureBase($sourceArray);
		$client->writeToTraceLog('Signing request of extension ' . $this->extensionId . ', base string is:' . "\n" . $baseString);
		$signature = Crypto::signString($baseString, $config->privateKeyFile, $config->privateKeyPassword, $this->hashMethod);

		$sourceArray['signature'] = $signature;

		return $sourceArray;

	}

	/**
	 * Returns string that is used as basis for signature.
	 *
	 * Default implementation uses Crypto::createSignatureBaseFromArray.
	 * This means that order of keys is significant. If the calculated signature
	 * for the extension is incorrect, extend the class with your own and reimplement
	 * this method with a better one.
	 *
	 * @param array $dataArray Including dttm and extension ID
	 *
	 * @return string
	 */
	public function getRequestSignatureBase($dataArray) {
		return Crypto::createSignatureBaseFromArray($dataArray, false);
	}

	/**
	 * Verifies signature.
	 *
	 * @param array $receivedData
	 * @param Client $client
	 *
	 * @return bool
	 *
	 */
	public function verifySignature($receivedData, Client $client) {

		$signature = isset($receivedData['signature']) ? $receivedData['signature'] : '';
		if (!$signature) {
			return false;
		}

		$responseWithoutSignature = $receivedData;
		unset($responseWithoutSignature["signature"]);

		$baseString = $this->getResponseSignatureBase($responseWithoutSignature);

		$config = $client->getConfig();
		$client->writeToTraceLog('Verifying signature of response of extension ' . $this->extensionId . ', base string is:' . "\n" . $baseString);

		return Crypto::verifySignature($baseString, $signature, $config->bankPublicKeyFile, $this->hashMethod);

	}

	/**
	 * Returns base string for verifying signature of response.
	 *
	 * If verifying signature fails because its base string has parts
	 * in incorrect order, check that the order of keys given to
	 * setExpectedResponseKeysOrder() is the same as in CSOB wiki,
	 * or reimplement this method with a better one.
	 *
	 * @param array $responseWithoutSignature
	 *
	 * @return string
	 */
	public function getResponseSignatureBase($responseWithoutSignature) {
		$keys = $this->getExpectedResponseKeysOrder();
		if ($keys) {
			$baseString = Crypto::createSignatureBaseWithOrder($responseWithoutSignature, $keys, false);
		} else {
			$baseString = Crypto::createSignatureBaseFromArray($responseWithoutSignature, false);
		}
		return $baseString;
	}

	/**
	 * @return bool
	 */
	public function getStrictSignatureVerification() {
		return $this->strictSignatureVerification;
	}

	/**
	 * @param bool $strictSignatureVerification
	 *
	 * @return self
	 */
	public function setStrictSignatureVerification($strictSignatureVerification) {
		$this->strictSignatureVerification = $strictSignatureVerification;
		return $this;
	}

	/**
	 * @return bool
	 */
	public function isSignatureCorrect() {
		return $this->signatureCorrect;
	}

	/**
	 * @param bool $signatureCorrect
	 */
	public function setSignatureCorrect($signatureCorrect) {
		$this->signatureCorrect = $signatureCorrect;
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getResponseData() {
		return $this->responseData;
	}

	/**
	 * @param mixed $responseData
	 */
	public function setResponseData($responseData) {
		$this->responseData = $responseData;
	}

	/**
	 * @return int
	 */
	public function getHashMethod() {
		return $this->hashMethod;
	}

	/**
	 * @param int $hashMethod
	 */
	public function setHashMethod($hashMethod) {
		$this->hashMethod = $hashMethod;
	}


}

}


// src/Metadata/Account.php 

namespace OndraKoupil\Csob\Metadata {

use OndraKoupil\Csob\Tools;


use DateTime;

/**
 * @see https://github.com/csob/paymentgateway/wiki/Purchase-metadata#customer
 */
class Account {

	/**
	 * @var DateTime
	 */
	protected $createdAt;

	/**
	 * @var DateTime
	 */
	protected $changedAt;

	/**
	 * @var DateTime
	 */
	protected $changedPwdAt;

	/**
	 * @var int
	 */
	public $orderHistory = 0;

	/**
	 * @var int
	 */
	public $paymentsDay = 0;

	/**
	 * @var int
	 */
	public $paymentsYear = 0;

	/**
	 * @var int
	 */
	public $oneclickAdds = 0;

	/**
	 * @var bool
	 */
	public $suspicious = false;

	/**
	 * @return DateTime
	 */
	public function getCreatedAt() {
		return $this->createdAt;
	}

	/**
	 * @param DateTime $createdAt
	 *
	 * @return Account
	 */
	public function setCreatedAt(DateTime $createdAt) {
		$this->createdAt = $createdAt;

		return $this;
	}

	/**
	 * @return DateTime
	 */
	public function getChangedAt() {
		return $this->changedAt;
	}

	/**
	 * @param DateTime $changedAt
	 *
	 * @return Account
	 */
	public function setChangedAt(DateTime $changedAt) {
		$this->changedAt = $changedAt;
		return $this;
	}

	/**
	 * @return DateTime
	 */
	public function getChangedPwdAt() {
		return $this->changedPwdAt;
	}

	/**
	 * @param DateTime $changedPwdAt
	 *
	 * @return Account
	 */
	public function setChangedPwdAt(DateTime $changedPwdAt) {
		$this->changedPwdAt = $changedPwdAt;

		return $this;
	}

	public function export() {
		$a = array(
			'createdAt' => $this->createdAt ? $this->createdAt->format('c') : null,
			'changedAt' => $this->changedAt ? $this->changedAt->format('c') : null,
			'changedPwdAt' => $this->changedPwdAt ? $this->changedPwdAt->format('c') : null,
			'orderHistory' => +$this->orderHistory,
			'paymentsDay' => +$this->paymentsDay,
			'paymentsYear' => +$this->paymentsYear,
			'oneclickAdds' => +$this->oneclickAdds,
			'suspicious' => !!$this->suspicious,
		);

		$a = Tools::filterOutEmptyFields($a);

		return $a;
	}


}

}


// src/Metadata/Address.php 

namespace OndraKoupil\Csob\Metadata {

use OndraKoupil\Csob\Tools;

use OndraKoupil\Tools\Strings;



class Address {

	/**
	 * @var string
	 */
	public $address1 = '';

	/**
	 * @var string
	 */
	public $address2 = '';

	/**
	 * @var string
	 */
	public $address3 = '';

	/**
	 * @var string
	 */
	public $city = '';

	/**
	 * @var string
	 */
	public $zip = '';

	/**
	 * @var string
	 */
	public $state = '';

	/**
	 * @var string
	 */
	public $country = '';

	/**
	 * @param string $address1
	 * @param string $city
	 * @param string $zip
	 * @param string $country
	 */
	public function __construct($address1, $city, $zip, $country) {
		$this->address1 = $address1;
		$this->city = $city;
		$this->zip = $zip;
		$this->country = $country;
	}

	public function export() {
		$a = array(
			'address1' => Strings::shorten($this->address1, 50, '', true, true),
			'address2' => Strings::shorten($this->address2, 50, '', true, true),
			'address3' => Strings::shorten($this->address3, 50, '', true, true),
			'city' => Strings::shorten($this->city, 50, '', true, true),
			'zip' => Strings::shorten($this->zip, 16, '', true, true),
			'state' => trim($this->state),
			'country' => trim($this->country),
		);

		return Tools::filterOutEmptyFields($a);
	}


}

}


// src/Metadata/Customer.php 

namespace OndraKoupil\Csob\Metadata {

use OndraKoupil\Csob\Tools;

use OndraKoupil\Tools\Strings;



/**
 * @see https://github.com/csob/paymentgateway/wiki/Purchase-metadata#customer
 */
class Customer {

	/**
	 * @var string
	 */
	public $name = '';

	/**
	 * @var string
	 */
	public $email = '';

	/**
	 * @var string
	 */
	public $homePhone = '';

	/**
	 * @var string
	 */
	public $workPhone = '';

	/**
	 * @var string
	 */
	public $mobilePhone = '';

	/**
	 * @var Account
	 */
	protected $account;

	/**
	 * @var Login
	 */
	protected $login;

	/**
	 * @return Account
	 */
	public function getAccount() {
		return $this->account;
	}

	/**
	 * @param Account $account
	 *
	 * @return Customer
	 */
	public function setAccount($account) {
		$this->account = $account;
		return $this;
	}

	/**
	 * @return Login
	 */
	public function getLogin() {
		return $this->login;
	}

	/**
	 * @param Login $login
	 *
	 * @return Customer
	 */
	public function setLogin($login) {
		$this->login = $login;
		return $this;
	}




	function export() {

		$a = array(
			'name' => Strings::shorten(trim($this->name), 45, '', true, true),
			'email' => Strings::shorten(trim($this->email), 100, '', true, true),
			'homePhone' => trim($this->homePhone),
			'workPhone' => trim($this->workPhone),
			'mobilePhone' => trim($this->mobilePhone),
			'account' => $this->account ? $this->account->export() : null,
			'login' => $this->login ? $this->login->export() : null,
		);

		$a = Tools::filterOutEmptyFields($a);

		return $a;

	}



}

}


// src/Metadata/GiftCards.php 

namespace OndraKoupil\Csob\Metadata {

use OndraKoupil\Csob\Tools;



class GiftCards {

	/**
	 * @var number
	 */
	public $totalAmount;

	/**
	 * @var string
	 */
	public $currency;

	/**
	 * @var number
	 */
	public $quantity;

	public function export() {
		$a = array(
			'totalAmount' => $this->totalAmount,
			'currency' => $this->currency,
			'quantity' => $this->quantity,
		);

		return Tools::filterOutEmptyFields($a);
	}

}

}


// src/Metadata/Login.php 

namespace OndraKoupil\Csob\Metadata {

use OndraKoupil\Csob\Tools;


use DateTime;

/**
 * @see https://github.com/csob/paymentgateway/wiki/Purchase-metadata#customerlogin-data-
 */
class Login {

	const AUTH_GUEST = 'guest';
	const AUTH_ACCOUNT = 'account';
	const AUTH_FEDERATED = 'federated';
	const AUTH_ISSUER = 'issuer';
	const AUTH_THIRDPARTY = 'thirdparty';
	const AUTH_FIDO = 'fido';
	const AUTH_FIDO_SIGNED = 'fido_signed';
	const AUTH_API = 'api';

	/**
	 * Use AUTH_* class constants
	 * @var string
	 */
	public $auth = '';

	/**
	 * @var DateTime
	 */
	protected $authAt;

	public $authData = '';

	/**
	 * @return mixed
	 */
	public function getAuthAt() {
		return $this->authAt;
	}

	/**
	 * @param mixed $authAt
	 *
	 * @return Login
	 */
	public function setAuthAt(DateTime $authAt) {
		$this->authAt = $authAt;

		return $this;
	}

	function export() {
		$a = array(
			'auth'     => trim($this->auth),
			'authAt'   => $this->authAt ? $this->authAt->format('c') : null,
			'authData' => trim($this->authData),
		);

		$a = Tools::filterOutEmptyFields($a);

		return $a;
	}


}

}


// src/Metadata/Order.php 

namespace OndraKoupil\Csob\Metadata {

use OndraKoupil\Csob\Tools;

use OndraKoupil\Tools\Strings;


use DateTime;

class Order {

	const TYPE_PURCHASE = 'purchase';
	const TYPE_BALANCE = 'balance';
	const TYPE_PREPAID = 'prepaid';
	const TYPE_CASH = 'cash';
	const TYPE_CHECK = 'check';

	const AVAILABILITY_NOW = 'now';
	const AVAILABILITY_PREORDER = 'preorder';

	const DELIVERY_SHIPPING = 'shipping';
	const DELIVERY_SHIPPING_VERIFIED = 'shipping_verified';
	const DELIVERY_INSTORE = 'instore';
	const DELIVERY_DIGITAL = 'digital';
	const DELIVERY_TICKET = 'ticket';
	const DELIVERY_OTHER = 'other';

	const DELIVERY_MODE_ELECTRONIC = 0;
	const DELIVERY_MODE_SAME_DAY = 1;
	const DELIVERY_MODE_NEXT_DAY = 2;
	const DELIVERY_MODE_LATER = 3;

	/**
	 * @var string
	 */
	public $type = '';

	/**
	 * @var string
	 */
	public $availability = '';

	/**
	 * @var string
	 */
	public $delivery = '';

	/**
	 * @var int
	 */
	public $deliveryMode = 0;

	/**
	 * @var string
	 */
	public $deliveryEmail = '';

	/**
	 * @var bool
	 */
	public $nameMatch;

	/**
	 * @var bool
	 */
	public $addressMatch;

	/**
	 * @var Address
	 */
	protected $billing;

	/**
	 * @var Address
	 */
	protected $shipping;

	/**
	 * @var DateTime
	 */
	protected $shippingAddedAt;


	/**
	 * @var bool
	 */
	public $reorder;

	/**
	 * @var GiftCards
	 */
	protected $giftCards;

	/**
	 * @return Address
	 */
	public function getBilling() {
		return $this->billing;
	}

	/**
	 * @param Address $billing
	 *
	 * @return Order
	 */
	public function setBilling($billing) {
		$this->billing = $billing;

		return $this;
	}

	/**
	 * @return Address
	 */
	public function getShipping() {
		return $this->shipping;
	}

	/**
	 * @param Address $shipping
	 *
	 * @return Order
	 */
	public function setShipping($shipping) {
		$this->shipping = $shipping;

		return $this;
	}

	/**
	 * @return DateTime
	 */
	public function getShippingAddedAt() {
		return $this->shippingAddedAt;
	}

	/**
	 * @param DateTime $shippingAddedAt
	 *
	 * @return Order
	 */
	public function setShippingAddedAt($shippingAddedAt) {
		$this->shippingAddedAt = $shippingAddedAt;

		return $this;
	}

	/**
	 * @return GiftCards
	 */
	public function getGiftCards() {
		return $this->giftCards;
	}

	/**
	 * @param GiftCards $giftCards
	 *
	 * @return Order
	 */
	public function setGiftCards($giftCards) {
		$this->giftCards = $giftCards;
		return $this;
	}

	public function export() {
		$a = array(
			'type' => trim($this->type),
			'availability' => trim($this->availability),
			'delivery' => trim($this->delivery),
			'deliveryMode' => +$this->deliveryMode,
			'deliveryEmail' => Strings::shorten($this->deliveryEmail, 100, '', true, true),
			'nameMatch' => !!$this->nameMatch,
			'addressMatch' => !!$this->addressMatch,
			'billing' => $this->billing ? $this->billing->export() : null,
			'shipping' => $this->shipping ? $this->shipping->export() : null,
			'shippingAddedAt' => $this->shippingAddedAt ? $this->shippingAddedAt->format('c') : null,
			'reorder' => !!$this->reorder,
			'giftcards' => $this->giftCards ? $this->giftCards->export() : null,
		);

		return Tools::filterOutEmptyFields($a);
	}


}

}


// src/Tools.php 

namespace OndraKoupil\Csob {


class Tools {

	public static function linearizeForSigning($input) {
		if ($input === null) {
			return '';
		}
		if (is_bool($input)) {
			return $input ? 'true' : 'false';
		}
		if (is_array($input)) {
			$parts = array();
			foreach ($input as $inputItem) {
				$parts[] = self::linearizeForSigning($inputItem);
			}

			return implode('|', $parts);
		}

		return $input;
	}

	public static function filterOutEmptyFields(array $input) {
		return array_filter(
			$input,
			function($value) {
				return ($value !== null and $value !== '');
			}
		);
	}

}

}


// src/Extensions/CardNumberExtension.php 

namespace OndraKoupil\Csob\Extensions {

use OndraKoupil\Csob\Exception;

use OndraKoupil\Csob\Extension;



/**
 * 'maskClnRP' extension for payment/status
 */
class CardNumberExtension extends Extension {

	/**
	 * @var string
	 */
	protected $maskedCln;

	/**
	 * @var string
	 */
	protected $expiration;

	/**
	 * @var string
	 */
	protected $longMaskedCln;


	function __construct() {
		parent::__construct('maskClnRP');
	}

	function getExpectedResponseKeysOrder() {
		return array(
			'extension',
			'dttm',
			'maskedCln',
			'expiration',
			'longMaskedCln'
		);
	}

	function setInputData($inputData) {
		throw new Exception('You cannot call this directly.');
	}

	function setExpectedResponseKeysOrder($inputData) {
		throw new Exception('You cannot call this directly.');
	}

	function setResponseData($responseData) {
		if (isset($responseData['maskedCln'])) {
			$this->maskedCln = $responseData['maskedCln'];
		}
		if (isset($responseData['expiration'])) {
			$this->expiration = $responseData['expiration'];
		}
		if (isset($responseData['longMaskedCln'])) {
			$this->longMaskedCln = $responseData['longMaskedCln'];
		}
	}

	/**
	 * Returns payment card number as ****XXXX
	 *
	 * @return string
	 */
	public function getMaskedCln() {
		return $this->maskedCln;
	}

	/**
	 * Returns payment card expiration as MM/YY
	 *
	 * @return string
	 */
	public function getExpiration() {
		return $this->expiration;
	}

	/**
	 * Returns payment card number as PPPPPP****XXXX
	 *
	 * @return string
	 */
	public function getLongMaskedCln() {
		return $this->longMaskedCln;
	}




}

}


// src/Extensions/DatesExtension.php 

namespace OndraKoupil\Csob\Extensions {

use OndraKoupil\Csob\Exception;

use OndraKoupil\Csob\Extension;


use DateTime;

/**
 * 'trxDates' extension for use in payment/status method
 */
class DatesExtension extends Extension {

	/**
	 * @var DateTime
	 */
	protected $createdDate;

	/**
	 * @var DateTime
	 */
	protected $settlementDate;

	/**
	 * @var DateTime
	 */
	protected $authDate;

	function __construct() {
		parent::__construct('trxDates');
	}

	function getExpectedResponseKeysOrder() {
		return array(
			'extension',
			'dttm',
			'?createdDate',
			'?authDate',
			'?settlementDate',
		);
	}

	function setInputData($inputData) {
		throw new Exception('You cannot call this directly.');
	}

	function setExpectedResponseKeysOrder($inputData) {
		throw new Exception('You cannot call this directly.');
	}

	function setResponseData($responseData) {
		if (isset($responseData['createdDate'])) {
			$this->createdDate = new DateTime($responseData['createdDate']);
		} else {
			$this->createdDate = null;
		}
		if (isset($responseData['authDate'])) {
			$ok = preg_match('~^(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$~', $responseData['authDate'], $parts);
			if ($ok) {
				$authDateHinted = "$parts[1]-$parts[2]-$parts[3] $parts[4]:$parts[5]:$parts[6]";
				$this->authDate = new DateTime($authDateHinted);
			} else {
				$this->authDate = null;
			}
		} else {
			$this->authDate = null;
		}
		if (isset($responseData['settlementDate'])) {
			$ok = preg_match('~^(\d{4})(\d{2})(\d{2})$~', $responseData['settlementDate'], $parts);
			if ($ok) {
				$settlementDateHinted = "$parts[1]-$parts[2]-$parts[3]";
				$this->settlementDate = new DateTime($settlementDateHinted);
			} else {
				$this->settlementDate = null;
			}
		} else {
			$this->settlementDate = null;
		}

	}

	/**
	 * Returns createdDate as DateTime or null if it has not been in the response
	 *
	 * @return DateTime|null
	 */
	public function getCreatedDate() {
		return $this->createdDate;
	}

	/**
	 * Returns createdDate as DateTime or null if it has not been in the response
	 *
	 * @return DateTime|null
	 */
	public function getSettlementDate() {
		return $this->settlementDate;
	}

	/**
	 * Returns createdDate as DateTime or null if it has not been in the response
	 *
	 * @return DateTime|null
	 */
	public function getAuthDate() {
		return $this->authDate;
	}


}

}


// src/Extensions/EET/EETCloseExtension.php 

namespace OndraKoupil\Csob\Extensions\EET {

use OndraKoupil\Csob\Exception;

use OndraKoupil\Csob\Extension;



class EETCloseExtension extends Extension {

	/**
	 * @var EETData
	 */
	protected $data;

	/**
	 * @param EETData $data
	 */
	function __construct(EETData $data = null) {

		parent::__construct('eetV3');
		$this->data = $data;

		$this->expectedResponseKeysOrder = null;
	}

	/**
	 * @return EETData
	 */
	public function getEETData() {
		return $this->data;
	}

	/**
	 * @param EETData $data
	 */
	public function setData($data = null) {
		$this->data = $data;
	}

	/**
	 * Builds input data array
	 *
	 * @return array
	 */
	public function getInputData() {
		if ($this->data) {
			return array(
				'extension' => $this->extensionId,
				'dttm' => null,
				'data' => $this->data->asArray(),
			);
		}

		return null;
	}

	/**
	 * Do not call this directly.
	 *
	 * @param array $inputData
	 * @throws Exception
	 *
	 * @return void
	 */
	public function setInputData($inputData) {
		throw new Exception('You cannot call this method directly.');
	}
}

}


// src/Extensions/EET/EETData.php 

namespace OndraKoupil\Csob\Extensions\EET {


/**
 * Data set for the EET extension.
 *
 * You need to create this object when calling paymentInit.
 *
 * Then, you will receive this object in responses of other methods.
 *
 * When creating the object, remember that $totalPrice is in CZK, not halers (as is in Payment object)
 *
 * See https://github.com/csob/paymentgateway/wiki/Specifikace-API-roz%C5%A1%C3%AD%C5%99en%C3%AD-pro-EET
 * for details on meaning of each property.
 */
class EETData {



	/**
	 * @var number Označení provozovny
	 */
	public $premiseId;

	/**
	 * @var string Označení pokladního zařízení poplatníka
	 */
	public $cashRegisterId;

	/**
	 * @var number Celková částka tržby, v případě nahlášení platby (volání v rámci payment/init, payment/oneclick/init a payment/close) musí být kladné číslo, v případě odhlášení platby (volání v rámci payment/refund) musí být záporné číslo.
	 */
	public $totalPrice;

	/**
	 * @var string DIČ pověřujícího poplatníka
	 */
	public $delegatedVatId;

	/**
	 * @var number Celková částka plnění osvobozených od DPH, ostatních plnění
	 */
	public $priceZeroVat;

	/**
	 * @var number Celkový základ daně se základní sazbou DPH
	 */
	public $priceStandardVat;

	/**
	 * @var number Celková DPH se základní sazbou
	 */
	public $vatStandard;

	/**
	 * @var number Celkový základ daně s první sníženou sazbou DPH
	 */
	public $priceFirstReducedVat;

	/**
	 * @var number Celková DPH s první sníženou sazbou
	 */
	public $vatFirstReduced;

	/**
	 * @var number Celkový základ daně s druhou sníženou sazbou DPH
	 */
	public $priceSecondReducedVat;

	/**
	 * @var number Celková DPH s druhou sníženou sazbou
	 */
	public $vatSecondReduced;

	/**
	 * @var number Celková částka v režimu DPH pro cestovní službu
	 */
	public $priceTravelService;

	/**
	 * @var number Celková částka v režimu DPH pro prodej použitého zboží se základní sazbou
	 */
	public $priceUsedGoodsStandardVat;

	/**
	 * @var number Celková částka v režimu DPH pro prodej použitého zboží s první sníženou sazbou
	 */
	public $priceUsedGoodsFirstReduced;

	/**
	 * @var number Celková částka v režimu DPH pro prodej použitého zboží s druhou sníženou sazbou
	 */
	public $priceUsedGoodsSecondReduced;

	/**
	 * @var number Celková částka plateb určená k následnému čerpání nebo zúčtování
	 */
	public $priceSubsequentSettlement;

	/**
	 * @var number Celková částka plateb, které jsou následným čerpáním nebo zúčtováním platby
	 */
	public $priceUsedSubsequentSettlement;

	/**
	 * @var array Contains raw data as were received from API. Only for responses.
	 */
	public $rawData;

	/**
	 * Constructor allows to set three mandatory properties
	 *
	 * @param number $premiseId
	 * @param string $cashRegisterId
	 * @param number $totalPrice
	 */
	public function __construct($premiseId = null, $cashRegisterId = null, $totalPrice = null) {
		$this->premiseId = $premiseId;
		$this->cashRegisterId = $cashRegisterId;
		$this->totalPrice = $totalPrice;
	}

	/**
	 * Export as array
	 *
	 * @return array
	 */
	public function asArray() {

		$a = array();

		$a['premiseId'] = +$this->premiseId;
		$a['cashRegisterId'] = $this->cashRegisterId;
		$a['totalPrice'] = self::formatPriceValue($this->totalPrice);

		if ($this->delegatedVatId) {
			$a['delegatedVatId'] = $this->delegatedVatId;
		}
		if ($this->priceZeroVat) {
			$a['priceZeroVat'] = self::formatPriceValue($this->priceZeroVat);
		}                
		if ($this->priceStandardVat) {
			$a['priceStandardVat'] = self::formatPriceValue($this->priceStandardVat);
		}
		if ($this->vatStandard) {
			$a['vatStandard'] = self::formatPriceValue($this->vatStandard);
		}
		if ($this->priceFirstReducedVat) {
			$a['priceFirstReducedVat'] = self::formatPriceValue($this->priceFirstReducedVat);
		}
		if ($this->vatFirstReduced) {
			$a['vatFirstReduced'] = self::formatPriceValue($this->vatFirstReduced);
		}
		if ($this->priceSecondReducedVat) {
			$a['priceSecondReducedVat'] = self::formatPriceValue($this->priceSecondReducedVat);
		}
		if ($this->vatSecondReduced) {
			$a['vatSecondReduced'] = self::formatPriceValue($this->vatSecondReduced);
		}
		if ($this->priceTravelService) {
			$a['priceTravelService'] = self::formatPriceValue($this->priceTravelService);
		}
		if ($this->priceUsedGoodsStandardVat) {
			$a['priceUsedGoodsStandardVat'] = self::formatPriceValue($this->priceUsedGoodsStandardVat);
		}
		if ($this->priceUsedGoodsFirstReduced) {
			$a['priceUsedGoodsFirstReduced'] = self::formatPriceValue($this->priceUsedGoodsFirstReduced);
		}
		if ($this->priceUsedGoodsSecondReduced) {
			$a['priceUsedGoodsSecondReduced'] = self::formatPriceValue($this->priceUsedGoodsSecondReduced);
		}
		if ($this->priceSubsequentSettlement) {
			$a['priceSubsequentSettlement'] = self::formatPriceValue($this->priceSubsequentSettlement);
		}
		if ($this->priceUsedSubsequentSettlement) {
			$a['priceUsedSubsequentSettlement'] = self::formatPriceValue($this->priceUsedSubsequentSettlement);
		}

		return $a;
	}


	/**
	 * Format a numeric price for use in EET extension
	 *
	 * @param number $price
	 *
	 * @return number
	 */
	static function formatPriceValue($price) {
		return number_format($price, 2, '.', '');
	}

	static protected $keyNames = array(
		'premiseId',
		'cashRegisterId',
		'totalPrice',
		'delegatedVatId',
		'priceZeroVat',
		'priceStandardVat',
		'vatStandard',
		'priceFirstReducedVat',
		'vatFirstReduced',
		'priceSecondReducedVat',
		'vatSecondReduced',
		'priceTravelService',
		'priceUsedGoodsStandardVat',
		'priceUsedGoodsFirstReduced',
		'priceUsedGoodsSecondReduced',
		'priceSubsequentSettlement',
		'priceUsedSubsequentSettlement',
	);

	/**
	 * Creates EETData object from array received from API
	 *
	 * @param $array
	 *
	 * @return EETData
	 */
	static function fromArray($array) {

		$data = new EETData();

		foreach (self::$keyNames as $key) {
			if (array_key_exists($key, $array)) {
				$data->$key = $array[$key];
			}
		}

		$data->rawData = $array;

		return $data;

	}

	/**
	 * Return part of the string required for building the signature string.
	 *
	 * @return string
	 */
	public function getSignatureBase() {
		$array = $this->asArray();
		return implode('|', $this->asArray());
	}


}

}


// src/Extensions/EET/EETError.php 

namespace OndraKoupil\Csob\Extensions\EET {


/**
 * Common base for error and warning in EET extension
 */
class EETError extends EETErrorMessage {

}

}


// src/Extensions/EET/EETErrorMessage.php 

namespace OndraKoupil\Csob\Extensions\EET {


/**
 * Represents an error message from EET extension
 */
abstract class EETErrorMessage {

	/**
	 * @var string
	 */
	public $code;

	/**
	 * @var string
	 */
	public $desc;

	/**
	 * The constructor
	 *
	 * @param string $code
	 * @param string $desc
	 */
	public function __construct($code, $desc) {
		$this->code = $code;
		$this->desc = $desc;
	}

	/**
	 * @return string
	 */
	public function getSignatureBase() {
		return $this->code . '|' . $this->desc;
	}

}

}


// src/Extensions/EET/EETInitExtension.php 

namespace OndraKoupil\Csob\Extensions\EET {

use OndraKoupil\Csob\Exception;

use OndraKoupil\Csob\Extension;



/**
 * Extension for EET - payment/init and payment/oneclick/init operations
 */
class EETInitExtension extends Extension {

	/**
	 * @var bool Ověřovací (testovací) režim?
	 */
	public $verificationMode;

	/**
	 * @var EETData
	 */
	protected $data;

	/**
	 * @param EETData $data Data for EET
	 * @param bool $verificationMode Should verification (testing) mode be used?
	 */
	function __construct(EETData $data, $verificationMode = false) {

		parent::__construct('eetV3');

		$this->data = $data;
		$this->verificationMode = $verificationMode ? true : false;

		$this->expectedResponseKeysOrder = null;
	}

	/**
	 * @return EETData
	 */
	public function getEETData() {
		return $this->data;
	}

	/**
	 * Builds input data array
	 *
	 * @return array
	 */
	public function getInputData() {
		$a = array(
			'extension' => $this->extensionId,
			'dttm' => null,
			'data' => $this->data->asArray(),
			'verificationMode' => $this->verificationMode ? 'true' : 'false'
		);

		return $a;
	}

	/**
	 * Do not call this directly.
	 *
	 * @param array $inputData
	 * @throws Exception
	 *
	 * @return void
	 */
	public function setInputData($inputData) {
		throw new Exception('You cannot call this method directly.');
	}

}

}


// src/Extensions/EET/EETRefundExtension.php 

namespace OndraKoupil\Csob\Extensions\EET {

use OndraKoupil\Csob\Exception;

use OndraKoupil\Csob\Extension;



/**
 * Remember that in data object, the amount should be NEGATIVE!
 */
class EETRefundExtension extends Extension {

	/**
	 * @var EETData
	 */
	protected $data;

	/**
	 * @param EETData $data
	 */
	function __construct(EETData $data = null) {

		parent::__construct('eetV3');
		$this->data = $data;

		$this->expectedResponseKeysOrder = null;
	}

	/**
	 * @return EETData
	 */
	public function getEETData() {
		return $this->data;
	}

	/**
	 * @param EETData $data
	 */
	public function setData($data = null) {
		$this->data = $data;
	}

	/**
	 * Builds input data array
	 *
	 * @return array
	 */
	public function getInputData() {
		if ($this->data) {
			return array(
				'extension' => $this->extensionId,
				'dttm' => null,
				'data' => $this->data->asArray(),
			);
		}

		return null;
	}

	/**
	 * Do not call this directly.
	 *
	 * @param array $inputData
	 * @throws Exception
	 *
	 * @return void
	 */
	public function setInputData($inputData) {
		throw new Exception('You cannot call this method directly.');
	}
}

}


// src/Extensions/EET/EETReport.php 

namespace OndraKoupil\Csob\Extensions\EET {


use DateTime;

/**
 * Represents a result of payment/status call with EET extension activated.
 *
 * You will receive this object from responses, but won't ever need to create it manually.
 */
class EETReport {

	/**
	 * @var number stav nahlášení platby, viz životní cyklus tržby
	 * @see https://github.com/csob/paymentgateway/wiki/%C5%BDivotn%C3%AD-cyklus-tr%C5%BEby
	 */
	public $eetStatus;

	/**
	 * @var EETData
	 */
	public $data;

	/**
	 * @var boolean Příznak ověřovacího módu odesílání
	 */
	public $verificationMode;

	/**
	 * @var string DIČ poplatníka
	 */
	public $vatId;

	/**
	 * @var string Pořadové číslo účtenky, formát YYYYMMRXXXXXXXXXX, kde YYYY je rok, MM měsíc, R znak identifikující nahlášení platby, XXXXXXXXXX pořadové číslo účtenky, např. 201701R0000000004
	 */
	public $receiptNumber;

	/**
	 * @var DateTime|null Datum a čas přijetí tržby
	 */
	public $receiptTime;

	/**
	 * @var number Režim platby, platební brána podporuje pouze běžný režim, bude vrácena hodnota 0
	 */
	public $evidenceMode;

	/**
	 * @var string UUID datové zprávy evidované tržby
	 */
	public $uuid;

	/**
	 * @var DateTime|null Datum a čas odeslání zprávy z platební brány
	 */
	public $sendTime;

	/**
	 * @var DateTime|null Datum a čas přijetí zprávy na FS
	 */
	public $acceptTime;

	/**
	 * @var string Bezpečnostní kód poplatníka
	 */
	public $bkp;

	/**
	 * @var string Podpisový kód poplatníka
	 */
	public $pkp;

	/**
	 * @var string Fiskální identifikační kód
	 */
	public $fik;

	/**
	 * @var DateTime|null Datum a čas odmítnutí zprávy na FS
	 */
	public $rejectTime;

	/**
	 * @var EETError|null error zpracování na FS, viz popis objektu error
	 */
	public $error;

	/**
	 * @var EETWarning[] Seznam případných varování z FS, viz popis objektu warning
	 */
	public $warning = array();

	/**
	 * @var array
	 */
	public $rawData = array();

	/**
	 * Creates an EETStatus object from received data array
	 *
	 * @param array $array
	 *
	 * @return EETReport
	 */
	static public function fromArray($array) {

		$status = new EETReport();

		$status->rawData = $array;

		if (array_key_exists('eetStatus', $array)) {
			$status->eetStatus = $array['eetStatus'];
		}
		if (array_key_exists('data', $array)) {
			$status->data = EETData::fromArray($array['data']);
		}
		if (array_key_exists('verificationMode', $array)) {
			$status->verificationMode = $array['verificationMode'] ? true : false;
		}
		if (array_key_exists('vatId', $array)) {
			$status->vatId = $array['vatId'];
		}
		if (array_key_exists('receiptNumber', $array)) {
			$status->receiptNumber = $array['receiptNumber'];
		}
		if (array_key_exists('receiptTime', $array)) {
			$status->receiptTime = new DateTime($array['receiptTime']);
		}
		if (array_key_exists('evidenceMode', $array)) {
			$status->evidenceMode = $array['evidenceMode'];
		}
		if (array_key_exists('uuid', $array)) {
			$status->uuid = $array['uuid'];
		}
		if (array_key_exists('sendTime', $array)) {
			$status->sendTime = new DateTime($array['sendTime']);
		}
		if (array_key_exists('acceptTime', $array)) {
			$status->acceptTime = new DateTime($array['acceptTime']);
		}
		if (array_key_exists('bkp', $array)) {
			$status->bkp = $array['bkp'];
		}
		if (array_key_exists('pkp', $array)) {
			$status->pkp = $array['pkp'];
		}
		if (array_key_exists('fik', $array)) {
			$status->fik = $array['fik'];
		}
		if (array_key_exists('rejectTime', $array)) {
			$status->rejectTime = new DateTime($array['rejectTime']);
		}

		if (array_key_exists('error', $array) and $array['error']) {
			$status->error = new EETError($array['error']['code'], $array['error']['desc']);
		}

		if (array_key_exists('warning', $array) and is_array($array['warning'])) {
			foreach ($array['warning'] as $warningData) {
				$status->warning[] = new EETWarning($warningData['code'], $warningData['desc']);
			}
		}

		return $status;

	}

	/**
	 * @inheritdoc
	 *
	 * @return string
	 */
	public function getSignatureBase() {
		$fields = array();

		if ($this->eetStatus !== null) {
			$fields[] = $this->eetStatus;
		}
		if ($this->data) {
			$fields[] = $this->data->getSignatureBase();
		}
		if ($this->verificationMode !== null) {
			$fields[] = $this->verificationMode ? 'true' : 'false';
		}
		if ($this->vatId) {
			$fields[] = $this->vatId;
		}
		if ($this->receiptNumber) {
			$fields[] = $this->receiptNumber;
		}
		if ($this->receiptTime) {
			if (isset($this->rawData['receiptTime']) and $this->rawData['receiptTime']) {
				$fields[] = $this->rawData['receiptTime'];
			} else {
				$fields[] = self::formatTime($this->receiptTime);
			}
		}
		if ($this->evidenceMode !== null) {
			$fields[] = $this->evidenceMode;
		}
		if ($this->uuid !== null) {
			$fields[] = $this->uuid;
		}
		if ($this->sendTime) {
			if (isset($this->rawData['sendTime']) and $this->rawData['sendTime']) {
				$fields[] = $this->rawData['sendTime'];
			} else {
				$fields[] = self::formatTime($this->sendTime);
			}
		}
		if ($this->acceptTime) {
			if (isset($this->rawData['acceptTime']) and $this->rawData['acceptTime']) {
				$fields[] = $this->rawData['acceptTime'];
			} else {
				$fields[] = self::formatTime($this->acceptTime);
			}
		}
		if ($this->bkp) {
			$fields[] = $this->bkp;
		}
		if ($this->pkp) {
			$fields[] = $this->pkp;
		}
		if ($this->fik) {
			$fields[] = $this->fik;
		}
		if ($this->rejectTime) {
			if (isset($this->rawData['rejectTime']) and $this->rawData['rejectTime']) {
				$fields[] = $this->rawData['rejectTime'];
			} else {
				$fields[] = self::formatTime($this->rejectTime);
			}
		}
		if ($this->error) {
			$fields[] = $this->error->getSignatureBase();
		}
		if ($this->warning) {
			foreach ($this->warning as $w) {
				$fields[] = $w->getSignatureBase();
			}
		}

		return implode('|', $fields);
	}

	/**
	 * Formats DateTime to format used in API
	 *
	 * @param DateTime $dt
	 *
	 * @return string
	 */
	static function formatTime(DateTime $dt) {
		return $dt->format('c');
	}

}

}


// src/Extensions/EET/EETStatusExtension.php 

namespace OndraKoupil\Csob\Extensions\EET {

use OndraKoupil\Csob\Exception;

use OndraKoupil\Csob\Extension;



/**
 * Represents extension for EET for payment/status method.
 */
class EETStatusExtension extends Extension {

	/**
	 * Data from "report" section of response
	 *
	 * @var EETReport
	 */
	protected $report;

	/**
	 * Data from "cancel" section of response
	 *
	 * @var EETReport[]
	 */
	protected $cancels;

	/**
	 * The constructor
	 */
	function __construct() {
		parent::__construct('eetV3');
	}


	/**
	 * Builds input data array
	 *
	 * @return array
	 */
	public function getInputData() {
		return null;
	}

	/**
	 * Do not call this directly.
	 *
	 * @param array $inputData
	 * @throws Exception
	 *
	 * @return void
	 */
	public function setInputData($inputData) {
		throw new Exception('You cannot call this method directly.');
	}

	/**
	 * @inheritdoc
	 *
	 * @param array $data
	 *
	 * @return void
	 */
	public function setResponseData($data) {
		$this->responseData = $data;
		if (isset($data['report'])) {
			$this->report = EETReport::fromArray($data['report']);
		}
		$this->cancels = array();
		if (isset($data['cancel'])) {
			foreach ($data['cancel'] as $cancel) {
				$this->cancels[] = EETReport::fromArray($cancel);
			}
		}
	}

	/**
	 * @inheritdoc
	 *
	 * @param array $dataArray
	 *
	 * @return string
	 */
	public function getResponseSignatureBase($dataArray) {

		$base = array();
		$base[] = $dataArray['extension'];
		$base[] = $dataArray['dttm'];
		if ($this->report) {
			$base[] = $this->report->getSignatureBase();
		}
		if ($this->cancels) {
			foreach ($this->cancels as $cancel) {
				$base[] = $cancel->getSignatureBase();
			}
		}
		return implode('|', $base);
	}

	/**
	 * @return EETReport
	 */
	public function getReport() {
		return $this->report;
	}

	/**
	 * @return EETReport[]
	 */
	public function getCancels() {
		return $this->cancels;
	}

	/**
	 * Shortcut to get BKP from response's report
	 *
	 * @return string
	 */
	public function getBKP() {
		if ($this->report) {
			return $this->report->bkp;
		}
		return "";
	}

	/**
	 * Shortcut to get PKP from response's report
	 *
	 * @return string
	 */
	public function getPKP() {
		if ($this->report) {
			return $this->report->pkp;
		}
		return "";
	}

	/**
	 * Shortcut to get FIK from response's report
	 *
	 * @return string
	 */
	public function getFIK() {
		if ($this->report) {
			return $this->report->fik;
		}
		return "";
	}

	/**
	 * Shortcut to get EET status from response's report
	 *
	 * @return number|string
	 */
	public function getEETStatus() {
		if ($this->report) {
			return $this->report->eetStatus;
		}
		return "";
	}


}

}


// src/Extensions/EET/EETWarning.php 

namespace OndraKoupil\Csob\Extensions\EET {


class EETWarning extends EETErrorMessage {

}

}


// vendor/ondrakoupil/tools/src/Strings.php 

namespace OndraKoupil\Tools {


use ArrayAccess;

class Strings {

	/**
	 * Skloňuje řetězec dle českých pravidel řetězec
	 * @param number $amount
	 * @param string $one Lze použít dvě procenta - %% - pro nahrazení za $amount
	 * @param string $two
	 * @param string $five Vynechat nebo null = použít $two
	 * @param string $zero Vynechat nebo null = použít $five
	 * @return string
	 */
	static function plural($amount, $one, $two = null, $five = null, $zero = null) {
		if ($two === null) $two = $one;
		if ($five === null) $five = $two;
		if ($zero === null) $zero = $five;
		if ($amount==1) return str_replace("%%",$amount,$one);
		if ($amount>1 and $amount<5) return str_replace("%%",$amount,$two);
		if ($amount == 0) return str_replace("%%",$amount,$zero);
		return str_replace("%%",$amount,$five);
	}

	/**
	 * strlen pro UTF-8
	 * @param string $input
	 * @return int
	 */
	static function length($input) {
		return mb_strlen($input, "utf-8");
	}

	/**
	 * strlen pro UTF-8
	 * @param string $input
	 * @return int
	 */
	static function strlen($input) {
		return self::length($input);
	}

	/**
	 * substr() pro UTF-8
	 *
	 * @param string $input
	 * @param int $start
	 * @param int $length
	 * @return string
	 */
	static function substring($input, $start, $length = null) {
		return self::substr($input, $start, $length, "utf-8");
	}

	/**
	 * substr() pro UTF-8
	 *
	 * @param string $input
	 * @param int $start
	 * @param int $length
	 * @return string
	 */
	static function substr($input, $start, $length = null) {
		if ($length === null) {
			$length = self::length($input) - $start;
		}
		return mb_substr($input, $start, $length, "utf-8");
	}

	static function strpos($haystack, $needle, $offset = 0) {
		return mb_strpos($haystack, $needle, $offset, "utf-8");
	}


	static function strToLower($string) {
		return mb_strtolower($string, "utf-8");
	}

	static function lower($string) {
		return self::strToLower($string);
	}

	static function strToUpper($string) {
		return mb_strtoupper($string, "utf-8");
	}

	static function upper($string) {
		return self::strToUpper($string);
	}

    /**
     * Otestuje zda řetězec obsahuje hledaný výraz
     * @param $haystack
     * @param $needle
     * @return bool
     */
    public static function contains($haystack, $needle) {
        return strpos($haystack, $needle) !== FALSE;
    }

    /**
     * Otestuje zda řetězec obsahuje hledaný výraz, nedbá na velikost znaků
     * @param $haystack
     * @param $needle
     * @return bool
     */
    public static function icontains($haystack, $needle) {
        return stripos($haystack, $needle) !== FALSE;
    }

	/**
	* Funkce pro zkrácení dlouhého textu na menší délku.
	* Ořezává tak, aby nerozdělovala slova, a případně umí i odstranit HTML znaky.
	* @param string $text Původní (dlouhý) text
	* @param int $length Požadovaná délka textu. Oříznutý text nebude mít přesně tuto délku, může být o nějaké znaky kratší nebo delší podle toho, kde končí slovo.
	* @param string $ending Pokud dojde ke zkrácení textu, tak s na jeho konec přilepí tento řetězec. Defaultně trojtečka (jako HTML entita &amp;hellip;). TRUE = &amp;hellip; (nemusíš si pak pamatovat tu entitu)
	* @param bool $stripHtml Mají se odstraňovat HTML tagy? True = odstranit. Zachovají se pouze <br />, a všechny konce řádků (\n i \r) budou nahrazeny za <br />.
	* Odstraňování je důležité, jinak by mohlo dojít k ořezu uprostřed HTML tagu, anebo by nebyl nějaký tag správně ukončen.
	* Pro ořezávání se zachováním html tagů je shortenHtml().
	* @param bool $ignoreWords Ignorovat slova a rozdělit přesně.
	* @return string Zkrácený text
	*/
	static function shorten($text, $length, $ending="&hellip;", $stripHtml=true, $ignoreWords = false) {
		if ($stripHtml) {
			$text=self::br2nl($text);
			$text=strip_tags($text);
		}
		$text=trim($text);
		if ($ending===true) $ending="&hellip;";

		$text = trim($text);

		$needsTrim = (self::strlen($text) > $length);
		if (!$needsTrim) {
			return $text;
		}

		$hardTrimmed = self::substr($text, 0, $length);

		if (!$ignoreWords) {
			$nextChar = self::substr($text, $length, 1);
			if (!preg_match('~[\s.,/\-]~', $nextChar)) {
				$endingRemains = preg_match('~[\s.,/\-]([^\s.,/\-]*)$~', $hardTrimmed, $foundParts);
				if ($endingRemains) {
					$endingLength = self::strlen($foundParts[1]);
					$hardTrimmed = self::substr($hardTrimmed, 0, -1 * $endingLength - 1);
				}
			}
		}

		$hardTrimmed .= $ending;

		$hardTrimmed = trim($hardTrimmed);

		return $hardTrimmed;
	}

	/**
	* Všechny tagy BR (ve formě &lt;br> i &lt;br />) nahradí za \n (LF)
	* @param string $input
	* @return string
	*/
	static function br2nl($input) {
		return preg_replace('~<br\s*/?>~i', "\n", $input ?: '');
	}


	/**
	* Nahradí nové řádky za &lt;br />, ale nezanechá je tam.
	* @param string $input
	* @return string
	*/
	static function nl2br($input) {
		$input = str_replace("\r\n", "\n", $input ?: '');
		return str_replace("\n", "<br />", $input ?: '');
	}

	/**
	 * Nahradí entity v řetězci hodnotami ze zadaného pole.
	 * @param string $string
	 * @param array|ArrayAccess $valuesArray
	 * @param callback $escapeFunction Funkce, ktrsou se prožene každá nahrazená entita (např. kvůli escapování paznaků). Defaultně Html::escape()
	 * @param string $entityDelimiter Jeden znak
	 * @param string $entityNameChars Rozsah povolených znaků v názvech entit
	 * @return string
	 */
	static function replaceEntities($string, $valuesArray, $escapeFunction = "!!default", $entityDelimiter = "%", $entityNameChars = 'a-z0-9_-') {
		if ($escapeFunction === "!!default") {
			$escapeFunction = "\\OndraKoupil\\Tools\\Html::escape";
		}
		$arrayMode = is_array($valuesArray);
		$arrayAccessMode = (!is_array($valuesArray) and $valuesArray instanceof ArrayAccess);
		$string = \preg_replace_callback('~'.preg_quote($entityDelimiter).'(['.$entityNameChars.']+)'.preg_quote($entityDelimiter).'~i', function($found) use ($valuesArray, $escapeFunction, $arrayMode, $arrayAccessMode) {
			if ($arrayMode and key_exists($found[1], $valuesArray)) {
				$v = $valuesArray[$found[1]];
				if ($escapeFunction) {
					$v = call_user_func_array($escapeFunction, array($v));
				}
				return $v;
			}
			if ($arrayAccessMode) {
				if (isset($valuesArray[$found[1]])) {
					$v = $valuesArray[$found[1]];
					if ($escapeFunction) {
						$v = call_user_func_array($escapeFunction, array($v));
					}
					return $v;
				}
			}
			if (!$arrayAccessMode and !$arrayMode) {
				if (property_exists($valuesArray, $found[1])) {
					$v = $valuesArray->{$found[1]};
					if ($escapeFunction) {
						$v = call_user_func_array($escapeFunction, array($v));
					}
					return $v;
				}
			}

			return $found[0];

		}, $string);

		return $string;
	}

	/**
	 * Převede číslo s lidsky čitelným násobitelem, jako to zadávané v php.ini (např. 100M jako 100 mega), na normální číslo
	 * @param string $number
	 * @return number|boolean False, pokud je vstup nepřevoditelný
	 */
	static function parsePhpNumber($number) {
		$number = trim($number);

		if (is_numeric($number)) {
			return $number * 1;
		}

		if (preg_match('~^(-?)([0-9\.,]+)([kmgt]?)$~i', $number, $parts)) {
			$base = self::number($parts[2]);

			switch ($parts[3]) {
				case "K": case "k":
					$base *= 1024;
					break;

				case "M": case "m":
					$base *= 1024 * 1024;
					break;

				case "G": case "g":
					$base *= 1024 * 1024 * 1024;
					break;

				case "T": case "t":
					$base *= 1024 * 1024 * 1024 * 1024;
					break;

			}

			if ($parts[1]) {
				$c = -1;
			} else {
				$c = 1;
			}

			return $base * $c;
		}

		return false;
	}

	/**
	 * Naformátuje telefonní číslo
	 * @param string $input
	 * @param bool $international Nechat/přidat mezinárodní předvolbu?
	 * @param bool|string $spaces Přidat mezery pro trojčíslí? True = mezery. False = žádné mezery. String = zadaný řetězec použít jako mezeru.
	 * @param string $internationalPrefix Prefix pro mezinárodní řpedvolbu, používá se většinou "+" nebo "00"
	 * @param string $defaultInternational Výchozí mezinárodní předvolba (je-li $international == true a $input je bez předvolby). Zadávej BEZ prefixu.
	 * @return string
	 */

	static function phoneNumberFormatter($input, $international = true, $spaces = false, $internationalPrefix = "+", $defaultInternational = "420") {

		if (!trim($input)) {
			return "";
		}

		if ($spaces === true) {
			$spaces = " ";
		}
		$filteredInput = preg_replace('~\D~', '', $input);

		$parsedInternational = "";
		$parsedMain = "";
		if (strlen($filteredInput) > 9) {
			$parsedInternational = self::substr($filteredInput, 0, -9);
			$parsedMain = self::substr($filteredInput, -9);
		} else {
			$parsedMain = $filteredInput;
		}
		if (self::startsWith($parsedInternational, $internationalPrefix)) {
			$parsedInternational = self::substr($parsedInternational, self::strlen($internationalPrefix));
		}

		if ($spaces) {
			$spacedMain = "";
			$len = self::strlen($parsedMain);
			for ($i = $len; $i > -3; $i-=3) {
				$spacedMain = self::substr($parsedMain, ($i >= 0 ? $i : 0), ($i >= 0 ? 3 : (3 - $i * -1)))
					.($spacedMain ? ($spaces.$spacedMain) : "");
			}
		} else {
			$spacedMain = $parsedMain;
		}

		$output = "";
		if ($international) {
			if (!$parsedInternational) {
				$parsedInternational = $defaultInternational;
			}
			$output .= $internationalPrefix.$parsedInternational;
			if ($spaces) {
				$output .= $spaces;
			}
		}
		$output .= $spacedMain;

		return $output;


	}

	/**
	 * Začíná $string na $startsWith?
	 * @param string $string
	 * @param string $startsWith
	 * @param bool $caseSensitive
	 * @return bool
	 */
	static function startsWith($string, $startsWith, $caseSensitive = true) {
		$len = self::strlen($startsWith);
		if ($caseSensitive) return self::substr($string, 0, $len) == $startsWith;
		return self::strtolower(self::substr($string, 0, $len)) == self::strtolower($startsWith);
	}

	/**
	 * Končí $string na $endsWith?
	 * @param string $string
	 * @param string $endsWith
	 * @return string
	 */
	static function endsWith($string, $endsWith, $caseSensitive = true) {
		$len = self::strlen($endsWith);
		if ($caseSensitive) return self::substr($string, -1 * $len) == $endsWith;
		return self::strtolower(self::substr($string, -1 * $len)) == self::strtolower($endsWith);
	}

	/**
	* Ošetří zadanou hodnotu tak, aby z ní bylo číslo.
	* (normalizuje desetinnou čárku na tečku a ověří is_numeric).
	* @param mixed $string
	* @param int|float $default Vrátí se, pokud $vstup není čílený řetězec ani číslo (tj. je array, object, bool nebo nenumerický řetězec)
	* @param bool $positiveOnly Dáš-li true, tak se záporné číslo bude považovat za nepřijatelné a vrátí se $default (vhodné např. pro strtotime)
	* @return int|float
	*/
	static function number($string, $default = 0, $positiveOnly = false) {
		if (is_bool($string) or is_object($string) or is_array($string)) return $default;
		$string = (string)$string;
		$string=str_replace(array(","," "),array(".",""),trim($string));
		if (!is_numeric($string)) return $default;
		$string = $string * 1; // Convert to number
		if ($positiveOnly and $string<0) return $default;
		return $string;
	}

	/**
	* Funkce zlikviduje z řetězce všechno kromě číselných znaků a vybraného desetinného oddělovače.
	* @param string $string
	* @param string $decimalPoint
	* @param string $convertedDecimalPoint Takto lze normalizovat desetinný oddělovač.
	* @return string
	*/
	static function numberOnly($string, $decimalPoint = ".", $convertedDecimalPoint = ".") {
		$vystup="";
		for ($i=0;$i<strlen($string);$i++) {
			$znak=substr($string,$i,1);
			if (is_numeric($znak)) $vystup.=$znak;
			else {
				if ($znak==$decimalPoint) {
					$vystup.=$convertedDecimalPoint;
				}
			}
		}
		return $vystup;
	}

	/**
	 * Převede řetězec na základní alfanumerické znaky a pomlčky [a-z0-9.], umožní nechat tečku (vhodné pro jména souborů)
	 * <br />Alias pro webalize()
	 * @param string $string
	 * @param bool $allowDot Povolit tečku?
	 * @return string
	 */
	static function safe($string, $allowDot = true) {
		return self::webalize($string, $allowDot ? "." : "");
	}

	/**
	 * Converts to ASCII.
	 * @param  string  UTF-8 encoding
	 * @return string  ASCII
	 * @author Nette Framework
	 */
	public static function toAscii($s)
	{
		$s = preg_replace('#[^\x09\x0A\x0D\x20-\x7E\xA0-\x{2FF}\x{370}-\x{10FFFF}]#u', '', $s);
		$s = strtr($s, '`\'"^~', "\x01\x02\x03\x04\x05");
		if (ICONV_IMPL === 'glibc') {
			$s = @iconv('UTF-8', 'WINDOWS-1250//TRANSLIT', $s); // intentionally @
			$s = strtr($s, "\xa5\xa3\xbc\x8c\xa7\x8a\xaa\x8d\x8f\x8e\xaf\xb9\xb3\xbe\x9c\x9a\xba\x9d\x9f\x9e"
				. "\xbf\xc0\xc1\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xcb\xcc\xcd\xce\xcf\xd0\xd1\xd2\xd3"
				. "\xd4\xd5\xd6\xd7\xd8\xd9\xda\xdb\xdc\xdd\xde\xdf\xe0\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8"
				. "\xe9\xea\xeb\xec\xed\xee\xef\xf0\xf1\xf2\xf3\xf4\xf5\xf6\xf8\xf9\xfa\xfb\xfc\xfd\xfe\x96",
				"ALLSSSSTZZZallssstzzzRAAAALCCCEEEEIIDDNNOOOOxRUUUUYTsraaaalccceeeeiiddnnooooruuuuyt-");
		} else {
			$s = @iconv('UTF-8', 'ASCII//TRANSLIT', $s); // intentionally @
		}
		$s = str_replace(array('`', "'", '"', '^', '~'), '', $s);
		return strtr($s, "\x01\x02\x03\x04\x05", '`\'"^~');
	}


	/**
	 * Převede řetězec na základní alfanumerické znaky a pomlčky [a-z0-9.-]
	 * @param string $s Řetězec, UTF-8 encoding
	 * @param string $charlist allowed characters jako regexp
	 * @param bool $lower Zmenšit na malá písmena?
	 * @return string
	 * @author Nette Framework
	 */
	public static function webalize($s, $charlist = NULL, $lower = TRUE)
	{
		$s = self::toAscii($s);
		if ($lower) {
			$s = strtolower($s);
		}
		if (!$charlist) {
			$charlist = '';
		}
		$s = preg_replace('#[^a-z0-9' . preg_quote($charlist, '#') . ']+#i', '-', $s);
		$s = trim($s, '-');
		return $s;
	}


    /**
     * Převede číselnou velikost na textové výjádření v jednotkách velikosti (KB,MB,...)
     * @param $size
     * @return string
     */
    public static function formatSize($size, $decimalPrecision = 2) {

        if ($size < 1024)                           return $size . ' B';
        elseif ($size < 1048576)                   return round($size / 1024, $decimalPrecision) . ' kB';
        elseif ($size < 1073741824)                return round($size / 1048576, $decimalPrecision) . ' MB';
        elseif ($size < 1099511627776)             return round($size / 1073741824, $decimalPrecision) . ' GB';
        elseif ($size < 1125899906842624)          return round($size / 1099511627776, $decimalPrecision) . ' TB';
        elseif ($size < 1152921504606846976)       return round($size / 1125899906842624, $decimalPrecision) . ' PB';
        else return round($size / 1152921504606846976, $decimalPrecision) . ' EB';
    }

	/**
	 * Ošetření paznaků v HTML kódu
	 *
	 * @param string $input
	 * @param bool $doubleEncode
	 *
	 * @return string
	 */
    public static function specChars($input, $doubleEncode = false) {
    	return htmlspecialchars($input, ENT_QUOTES, 'utf-8', $doubleEncode);
	}

	/**
	 * Vygeneruje náhodný alfanumerický řetězec zadané délky
	 *
	 * @param int $length
	 * @return string Skládá se z [a-zA-Z0-9] nebo [a-z0-9] při $lowercase === true
	 */
	public static function randomString($length, $lowercase = false) {
		$bytesLength = ceil($length * 3/4) + 1;
		$randomBytes = openssl_random_pseudo_bytes($bytesLength);
		$hex = base64_encode($randomBytes);
		$hex = preg_replace('~[/+=]~', '', $hex);
		$len = strlen($hex);
		if ($len > $length) {
			$hex = substr($hex, 0, $length);
		}
		if ($len < $length) {
			$hex .= self::randomString($length - $len);
		}
		if ($lowercase) {
			$hex = strtolower($hex);
		}
		return $hex;

	}

	/**
	 * Převede excelovské značení sloupců (a, b, c, ..., aa, ab, ac, ...) na zero-based (0, 1, 2, ..., 26, 27, 28, ...) číslování.
	 * @param string $excelSloupec
	 * @return int
	 */
	static function excelToNumber($excelSloupec) {
		$excelSloupec = strtolower(trim($excelSloupec));
		$cislo = 0;
		while ($excelSloupec) {
			$pismenko = $excelSloupec[0];
			$cislo *= 26;
			$cislo += ord($pismenko) - 96;
			$excelSloupec = substr($excelSloupec, 1);
		}
		return $cislo - 1;
	}

}

}


// vendor/ondrakoupil/tools/src/Arrays.php 

namespace OndraKoupil\Tools {


class Arrays {

	/**
	 * Zajistí, aby zadaný argument byl array.
	 *
	 * Převede booly nebo nully na array(), pole nechá být, ArrayAccess a Traversable
	 * také, vše ostatní převede na array(0=>$hodnota)
	 *
	 * @param mixed $value
	 * @param bool $forceArrayFromObject True = Traversable objekty také převádět na array
	 * @return array|\ArrayAccess|\Traversable
	 */
	static function arrayize($value, $forceArrayFromObject = false) {
		if (is_array($value)) return $value;
		if (is_bool($value) or $value===null) return array();
		if ($value instanceof \Traversable) {
			if ($forceArrayFromObject) {
				return iterator_to_array($value);
			}
			return $value;
		}
		if ($value instanceof \ArrayAccess) {
			return $value;
		}
		return array(0=>$value);
	}

	/**
	 * Pokud je pole, převede na řetězec, jinak nechá být
	 * @param array|mixed $value
	 * @param string $glue
	 * @return mixed
	 */
	static function dearrayize($value,$glue=",") {
		if (is_array($value)) return implode($glue, $value);
		return $value;
	}

	/**
	 * Transformace dvoj(či více)-rozměrných polí či Traversable objektů
	 * @param array $input Vstupní pole.
	 * @param mixed $outputKeys Jak mají být tvořeny indexy výstupního pole?
	 * <br />False = numericky indexovat od 0.
	 * <br />True = zachovat původní indexy.
	 * <br />Cokoliv jiného - použít takto pojmenovanou hodnotu z druhého rozměru
	 * @param mixed $outputValue Jak mají být tvořeny hodnoty výstupního pole?
	 * <br />True = zachovat původní položky
	 * <br />String nebo array = vybrat pouze takto pojmenovanou položku nebo položky.
	 * <br />False = původní index. Může být zadán i jako prvek pole, pak bude daný prvek mít index [key].
	 * @return mixed
	 */
	static function transform($input,$outputKeys,$outputValue) {
		$input=self::arrayize($input);
		$output=array();
		foreach($input as $inputI=>$inputR) {
			if (is_array($outputValue)) {
				$novaPolozka=array();
				foreach($outputValue as $ov) {
					if ($ov===false) {
						$novaPolozka["key"]=$inputI;
					} else {
						if (isset($inputR[$ov])) {
							$novaPolozka[$ov]=$inputR[$ov];
						} else {
							$novaPolozka[$ov]=null;
						}
					}
				}
			} else {
				if ($outputValue===true) {
					$novaPolozka=$inputR;
				} elseif ($outputValue===false) {
					$novaPolozka=$inputI;
				} elseif (isset($inputR[$outputValue])) {
					$novaPolozka=$inputR[$outputValue];
				} else {
					$novaPolozka=null;
				}
			}


			if ($outputKeys===false) {
				$output[]=$novaPolozka;
			} elseif ($outputKeys===true) {
				$output[$inputI]=$novaPolozka;
			} else {
				if (isset($inputR[$outputKeys])) {
					$output[$inputR[$outputKeys]]=$novaPolozka;
				} else {
					$output[]=$novaPolozka;
				}
			}
		}
		return $output;
	}

	/**
	 * Seřadí prvky v jednom poli dle klíčů podle pořadí hodnot v jiném poli
	 * @param array $dataArray
	 * @param array $keysArray
	 * @return null
	 */
	static function sortByExternalKeys($dataArray, $keysArray) {
		$returnArray = array();
		foreach($keysArray as $k) {
			if (isset($dataArray[$k])) {
				$returnArray[$k] = $dataArray[$k];
			} else {
				$returnArray[$k] = null;
			}
		}
		return $returnArray;
	}


	/**
	* Vybere všechny možné hodnoty z dvourozměrného asociativního pole či Traversable objektu.
	* Funkce iteruje po prvním rozměru pole $array a ve druhém rozměru hledá $hodnota. Ve druhém rozměru
	* mohou být jak pole, tak objekty.
	* Vrátí všechny různé nalezené hodnoty (bez duplikátů).
	* @param array $array
	* @param string $hodnota Index nebo jméno hodnoty, který chceme získat
	* @param array $ignoredValues Volitelně lze doplnit hodnoty, které mají být ignorovány (pro porovnávání se
	 * používá striktní === ekvivalence)
	* @return array
	*/
	static function valuePicker($array, $hodnota, $ignoredValues = null) {
		$vrat=array();
		foreach($array as $a) {
			if ((is_array($a) or ($a instanceof \ArrayAccess)) and isset($a[$hodnota])) {
				$vrat[]=$a[$hodnota];
			} elseif (is_object($a) and isset($a->$hodnota)) {
				$vrat[]=$a->$hodnota;
			}
		}
		$vrat=array_values(array_unique($vrat));

		if ($ignoredValues) {
			$ignoredValues = self::arrayize($ignoredValues);
			foreach($vrat as $i=>$r) {
				if (in_array($r, $ignoredValues, true)) unset($vrat[$i]);
			}
			$vrat = array_values($vrat);
		}

		return $vrat;
	}

	/**
	 * Ze zadaného pole vybere jen ty položky, které mají klíč udaný v druhém poli.
	 * @param array|\ArrayAccess $array Asociativní pole
	 * @param array $requiredKeys Obyčejné pole klíčů
	 * @return array
	 */
	static function filterByKeys($array, $requiredKeys) {
		if (is_array($array)) {
			return array_intersect_key($array, array_fill_keys($requiredKeys, true));
		}
		if ($array instanceof \ArrayAccess) {
			$ret = array();
			foreach ($requiredKeys as $k) {
				if (isset($array[$k])) {
					$ret[$k] = $array[$k];
				}
			}
			return $ret;
		}

		throw new \InvalidArgumentException("Argument must be an array or object with ArrayAccess");
	}

	/**
	 * Z klasického dvojrozměrného pole udělá trojrozměrné pole, kde první index bude sdružovat řádku dle nějaké z hodnot.
	 * @param array $data
	 * @param string $groupBy Název políčka v $data, podle něhož se má sdružovat
	 * @param bool|string $orderByKey False (def.) = nechat, jak to přišlo pod ruku. True = seřadit dle sdružované hodnoty. String "desc" = sestupně.
	 * @return array
	 */
	static public function group($data,$groupBy,$orderByKey=false) {
		$vrat=array();
		foreach($data as $index=>$radek) {
			if (!isset($radek[$groupBy])) {
				$radek[$groupBy]="0";
			}
			if (!isset($vrat[$radek[$groupBy]])) {
				$vrat[$radek[$groupBy]]=array();
			}
			$vrat[$radek[$groupBy]][$index]=$radek;
		}
		if ($orderByKey) {
			ksort($vrat);
		}
		if ($orderByKey==="desc") {
			$vrat=array_reverse($vrat);
		}
		return $vrat;
	}

	/**
	 * Zadané dvourozměrné pole nebo traversable objekt přeindexuje tak, že jeho jednotlivé indexy
	 * budou tvořeny určitým prvkem nebo public vlastností z každého prvku.
	 *
	 * Pokud některý z prvků vstupního pole neobsahuje $keyName, zachová se jeho původní index.
	 *
	 * @param array|\Traversable $input Vstupní pole/objekt
	 * @param string $keyName Podle čeho indexovat
	 * @return array
	 */
	static public function indexByKey($input, $keyName) {
		if (!is_array($input) and !($input instanceof \Traversable)) {
			throw new \InvalidArgumentException("Given argument must be an array or traversable object.");
		}

		$returnedArray = array();

		foreach($input as $index => $f) {
			if (is_array($f)) {
				$key = array_key_exists($keyName, $f) ? $f[$keyName] : $index;
				$returnedArray[$key] = $f;
			} elseif (is_object($f)) {
				$key = property_exists($f, $keyName) ? $f->$keyName : $index;
				$returnedArray[$key] = $f;
			} else {
				if (!isset($returnedArray[$index])) {
					$returnedArray[$index] = $f;
				}
			}
		}

		return $returnedArray;
	}

	/**
	 * Zruší z pole všechny výskyty určité hodnoty.
	 * @param array $dataArray
	 * @param mixed $valueToDelete Nesmí být null!
	 * @param bool $keysInsignificant True = přečíslovat vrácené pole, indexy nejsou podstatné. False = nechat původní indexy.
	 * @param bool $strict == nebo ===
	 * @return array Upravené $dataArray
	 */
	static public function deleteValue($dataArray, $valueToDelete, $keysInsignificant = true, $strict = false) {
		if ($valueToDelete === null) throw new \InvalidArgumentException("\$valueToDelete cannot be null.");
		$keys = array_keys($dataArray, $valueToDelete, $strict);
		if ($keys) {
			foreach($keys as $k) {
				unset($dataArray[$k]);
			}
			if ($keysInsignificant) {
				$dataArray = array_values($dataArray);
			}
		}
		return $dataArray;
	}

	/**
	 * Zruší z jednoho pole všechny hodnoty, které se vyskytují ve druhém poli.
	 * Ve druhém poli musí jít o skalární typy, objekty nebo array povedou k chybě.
	 * @param array $dataArray
	 * @param array $arrayOfValuesToDelete
	 * @param bool $keysInsignificant True = přečíslovat vrácené pole, indexy nejsou podstatné. False = nechat původní indexy.
	 * @return array Upravené $dataArray
	 */
	static public function deleteValues($dataArray, $arrayOfValuesToDelete, $keysInsignificant = true) {
		$arrayOfValuesToDelete = self::arrayize($arrayOfValuesToDelete);
		$invertedDeletes = array_fill_keys($arrayOfValuesToDelete, true);
		foreach ($dataArray as $i=>$r) {
			if (isset($invertedDeletes[$r])) {
				unset($dataArray[$i]);
			}
		}
		if ($keysInsignificant) {
			$dataArray = array_values($dataArray);
		}

		return $dataArray;
	}


	/**
	 * Obohatí $mainArray o nějaké prvky z $mixinArray. Obě pole by měla být dvourozměrná pole, kde
	 * první rozměr je ID a další rozměr je asociativní pole s nějakými vlastnostmi.
	 * <br />Data z $mainArray se považují za prioritnější a správnější, a pokud již příslušný prvek obsahují,
	 * nepřepíší se tím z $mixinArray.
	 * @param array $mainArray
	 * @param array $mixinArray
	 * @param bool|array|string $fields True = obohatit vším, co v $mixinArray je. Jinak string/array stringů.
	 * @param array $changeIndexes Do $mainField lze použít jiné indexy, než v originále. Sem zadej "překladovou tabulku" ve tvaru array([original_key] => new_key).
	 * Ve $fields používej již indexy po přejmenování.
	 * @return array Obohacené $mainArray
	 */
	static public function enrich($mainArray, $mixinArray, $fields=true, $changeIndexes = array()) {
		if ($fields!==true) $fields=self::arrayize($fields);
		foreach($mixinArray as $mixinId=>$mixinData) {
			if (!isset($mainArray[$mixinId])) continue;
			if ($changeIndexes) {
				foreach($changeIndexes as $fromI=>$toI) {
					if (isset($mixinData[$fromI])) {
						$mixinData[$toI] = $mixinData[$fromI];
						unset($mixinData[$fromI]);
					} else {
						$mixinData[$toI] = null;
					}
				}
			}
			if ($fields===true) {
				$mainArray[$mixinId]+=$mixinData;
			} else {
				foreach($fields as $field) {
					if (!isset($mainArray[$mixinId][$field])) {
						if (isset($mixinData[$field])) {
							$mainArray[$mixinId][$field]=$mixinData[$field];
						} else {
							$mainArray[$mixinId][$field]=null;
						}
					}
				}
			}
		}
		return $mainArray;
	}

	/**
	 * Konverze asociativního pole na objekt třídy stdClass
	 * @param array|Traversable $array
	 * @return \stdClass
	 */
	static function toObject($array) {
		if (!is_array($array) and !($array instanceof \Traversable)) {
			throw new \InvalidArgumentException("You must give me an array!");
		}
		$obj = new \stdClass();
		foreach ($array as $i=>$r) {
			$obj->$i = $r;
		}
		return $obj;
	}

	/**
	 * Z dvourozměrného pole, které bylo sgrupované podle nějaké hodnoty, udělá zpět jednorozměrné, s výčtem jednotlivých hodnot.
	 * Funguje pouze za předpokladu, že jednotlivé hodnoty jsou obyčejné skalární typy. Objekty nebo array třetího rozměru povede k chybě.
	 * @param array $array
	 * @return array
	 */
	static public function flatten($array) {
		$out=array();
		foreach($array as $i=>$subArray) {
			foreach($subArray as $value) {
				$out[$value]=true;
			}
		}
		return array_keys($out);
	}


	/**
	 * Normalizuje hodnoty v poli do rozsahu &lt;0-1&gt;
	 * @param array $array
	 * @return array
	 */
	static public function normaliseValues($array) {
		$array=self::arrayize($array);
		if (!$array) return $array;
		$minValue=min($array);
		$maxValue=max($array);
		if ($maxValue==$minValue) {
			$minValue-=1;
		}
		foreach($array as $index=>$value) {
			$array[$index]=($value-$minValue)/($maxValue-$minValue);
		}
		return $array;
	}

	/**
	 * Rekurzivně převede traversable objekt na obyčejné array.
	 * @param \Traversable $traversable
	 * @param int $depth Interní, pro kontorlu nekonečné rekurze
	 * @return array
	 * @throws \RuntimeException
	 */
	static function traversableToArray($traversable, $depth = 0) {
		$vrat = array();
		if ($depth > 10) throw new \RuntimeException("Recursion is too deep.");
		if (!is_array($traversable) and !($traversable instanceof \Traversable)) {
			throw new \InvalidArgumentException("\$traversable must be an array or Traversable object.");
		}
		foreach ($traversable as $i=>$r) {
			if (is_array($r) or ($r instanceof \Traversable)) {
				$vrat[$i] = self::traversableToArray($r, $depth + 1);
			} else {
				$vrat[$i] = $r;
			}
		}
		return $vrat;
	}


	/**
	* Pomocná funkce zjednodušující práci s různými číselníky definovanými jako array v PHP. Umožňuje buď "lidsky" zformátovat jeden vybraný prvek z číselníku, nebo vrátit celé array hodnot.
	* @param array $data Celé array se všemi položkami ve tvaru [index]=>$value
	* @param string|int|bool $index False = vrať array se všemi. Jinak zadej index jedné konkrétní položky.
	* @param string|bool $pattern False = vrať tak, jak to je v $data. String = naformátuj. Entity %index%, %value%, %i%. %i% označuje pořadí a vyplňuje se jen je-li $index false a je 0-based.
	* @param string|int $default Pokud by snad v $data nebyla položka s indexem $indexPolozky, hledej index $default, pokud není, vrať $default.
	* @param bool $reverse dej True, má-li se vrátit v opačném pořadí.
	* @return array|string Array pokud $indexPolozky je false, jinak string.
	*/
	static function enumItem ($data,$index,$pattern=false,$default=0,$reverse=false) {
		if ($index!==false) {
			if (!isset($data[$index])) {
				$index=$default;
				if (!isset($data[$index])) return $default;
			}
			if ($pattern===false) return $data[$index];
			return self::enumItemPattern($pattern,$index,$data[$index],"");
		}

		if ($pattern===false) {
			if ($reverse) return array_reverse($data,true);
			return $data;
		}

		$vrat=array();
		$i=0;
		foreach($data as $di=>$dr) {
			$vrat[$di]=self::enumItemPattern($pattern,$di,$dr,$i);
			$i++;
		}
		if ($reverse) return array_reverse($vrat,true);
		return $vrat;
	}

	/**
	* @ignore
	*/
	protected static function enumItemPattern($pattern,$index,$value,$i) {
		return str_replace(
			array("%index%","%i%","%value%"),
			array($index,$i,$value),
			$pattern
		);
	}

	/**
	 * Porovná, zda jsou hodnoty ve dvou polích stejné. Nezáleží na indexech ani na pořadí prvků v poli.
	 * @param array $array1
	 * @param array $array2
	 * @param bool $strict Používat ===
	 * @return boolean True = stejné. False = rozdílné.
	 */
	static function compareValues($array1, $array2, $strict = false) {
		if (count($array1) != count($array2)) return false;

		$array1 = array_values($array1);
		$array2 = array_values($array2);
		sort($array1, SORT_STRING);
		sort($array2, SORT_STRING);

		foreach($array1 as $i=>$r) {
			if ($array2[$i] != $r) return false;
			if ($strict and $array2[$i] !== $r) return false;
		}

		return true;
	}

	/**
	* Rekurzivní změna kódování libovolného typu proměnné (array, string, atd., kromě objektů).
	* @param string $from Vstupní kódování
	* @param string $to Výstupní kódování
	* @param mixed $array Co překódovat
	* @param bool $keys Mají se iconvovat i klíče? Def. false.
	* @param int $checkDepth Tento parametr ignoruj, používá se jako pojistka proti nekonečné rekurzi.
	* @return mixed
	*/
	static function iconv($from, $to, $array, $keys=false, $checkDepth = 0) {
		if (is_object($array)) {
			return $array;
		}
		if (!is_array($array)) {
			if (is_string($array)) {
				return iconv($from,$to,$array);
			} else {
				return $array;
			}
		}
		if ($checkDepth>20) return $array;
		$vrat=array();
		foreach($array as $i=>$r) {
			if ($keys) {
				$i=iconv($from,$to,$i);
			}
			$vrat[$i]=self::iconv($from,$to,$r,$keys,$checkDepth+1);
		}
		return $vrat;
	}

	/**
	 * Vytvoří kartézský součin.
	 * <code>
	 * $input = array(
	 *		"barva" => array("red", "green"),
	 *		"size" => array("small", "big")
	 * );
	 *
	 * $output = array(
	 *		[0] => array("barva" => "red", "size" => "small"),
	 *		[1] => array("barva" => "green", "size" => "small"),
	 *		[2] => array("barva" => "red", "size" => "big"),
	 *		[3] => array("barva" => "green", "size" => "big")
	 * );
	 *
	 * </code>
	 * @param array $input
	 * @return array
	 * @see http://stackoverflow.com/questions/6311779/finding-cartesian-product-with-php-associative-arrays
	 */
	static function cartesian($input) {
		$input = array_filter($input);

		$result = array(array());

		foreach ($input as $key => $values) {
			$append = array();

			foreach($result as $product) {
				foreach($values as $item) {
					$product[$key] = $item;
					$append[] = $product;
				}
			}

			$result = $append;
		}

		return $result;
	}

    /**
     * Zjistí, zda má pole pouze číselné indexy
     * @param array $array
     * @return bool
	 * @author Michael Pavlista
     */
    public static function isNumeric(array $array) {

        return empty($array) ? TRUE : is_numeric(implode('', array_keys($array)));
    }


    /**
     * Zjistí, zda je pole asociativní
     * @param array $array
     * @return bool
	 * @author Michael Pavlista
     */
    public static function isAssoc(array $array) {

        return empty($array) ? TRUE : !self::isNumeric($array);
    }

	/**
	 * @param array $old
	 * @param array $new
	 * @return array
	 *
	 * @author Paul's Simple Diff Algorithm v 0.1
	 * (C) Paul Butler 2007 <http://www.paulbutler.org/>
     * May be used and distributed under the zlib/libpng license.
	 */
	public static function diff($old, $new) {
		$matrix = array();
		$maxlen = 0;
		foreach($old as $oindex => $ovalue){
			$nkeys = array_keys($new, $ovalue);
			foreach($nkeys as $nindex){
				$matrix[$oindex][$nindex] = isset($matrix[$oindex - 1][$nindex - 1]) ?
					$matrix[$oindex - 1][$nindex - 1] + 1 : 1;
				if($matrix[$oindex][$nindex] > $maxlen){
					$maxlen = $matrix[$oindex][$nindex];
					$omax = $oindex + 1 - $maxlen;
					$nmax = $nindex + 1 - $maxlen;
				}
			}
		}
		if($maxlen == 0) return array(array('d'=>$old, 'i'=>$new));
		return array_merge(
			self::diff(array_slice($old, 0, $omax), array_slice($new, 0, $nmax)),
			array_slice($new, $nmax, $maxlen),
			self::diff(array_slice($old, $omax + $maxlen), array_slice($new, $nmax + $maxlen)));
	}
}

}


// vendor/ondrakoupil/tools/src/Files.php 

namespace OndraKoupil\Tools {

use OndraKoupil\Tools\Exceptions\FileException;

use OndraKoupil\Tools\Exceptions\FileAccessException;



/**
 * Pár vylepšených nsátrojů pro práci se soubory
 */
class Files {

	const LOWERCASE = "L";
	const UPPERCASE = "U";

	/**
	 * Vrací jen jméno souboru
	 *
	 * `/var/www/vhosts/somefile.txt` => `somefile.txt`
	 *
	 * @param string $in
	 * @return string
	 */
	static function filename($in) {
		return basename($in);
	}

	/**
	 * Přípona souboru
	 *
	 * `/var/www/vhosts/somefile.txt` => `txt`
	 * @param string $in
	 * @param string $case self::LOWERCASE nebo self::UPPERCASE. Cokoliv jiného = neměnit velikost přípony.
	 * @return string
	 */
	static function extension($in,$case=false) {

		$name=self::filename($in);

		if (preg_match('~\.(\w{1,10})\s*$~',$name,$parts)) {
			if (!$case) return $parts[1];
			if (strtoupper($case)==self::LOWERCASE) return Strings::lower($parts[1]);
			if (strtoupper($case)==self::UPPERCASE) return Strings::upper($parts[1]);
			return $parts[1];
		}
		return "";
	}

	/**
	 * Jméno souboru, ale bez přípony.
	 *
	 * `/var/www/vhosts/somefile.txt` => `somefile`
	 * @param string $filename
	 * @return string
	 */
	static function filenameWithoutExtension($filename) {
		$filename=self::filename($filename);
		if (preg_match('~(.*)\.(\w{1,10})$~',$filename,$parts)) {
			return $parts[1];
		}
		return $filename;
	}

	/**
	 * Vrátí jméno souboru, jako kdyby byl přejmenován, ale ve stejném adresáři
	 *
	 * `/var/www/vhosts/somefile.txt` => `/var/www/vhosts/anotherfile.txt`
	 *
	 * @param string $path Původní cesta k souboru
	 * @param string $to Nové jméno souboru
	 * @return string
	 */
	static function changedFilename($path, $newName) {
		return self::dir($path)."/".$newName;
	}

	/**
	 * Jen cesta k adresáři.
	 *
	 * `/var/www/vhosts/somefile.txt` => `/var/www/vhosts`
	 *
	 * @param string $in
	 * @param bool $real True = použít realpath()
	 * @return string Pokud je $real==true a $in neexistuje, vrací empty string
	 */
	static function dir($in,$real=false) {
		if ($real) {
			$in=realpath($in);
			if ($in and is_dir($in)) $in.="/file";
		}
		return dirname($in);
	}

	/**
	 * Přidá do jména souboru něco na konec, před příponu.
	 *
	 * `/var/www/vhosts/somefile.txt` => `/var/www/vhosts/somefile-affix.txt`
	 *
	 * @param string $filename
	 * @param string $addedString
	 * @param bool $withPath Vracet i s cestou? Anebo jen jméno souboru?
	 * @return string
	 */
	static function addBeforeExtension($filename,$addedString,$withPath=true) {
		if ($withPath) {
			$dir=self::dir($filename)."/";
		} else {
			$dir="";
		}
		if (!$dir or $dir=="./") $dir="";
		$filenameWithoutExtension=self::filenameWithoutExtension($filename);
		$extension=self::extension($filename);
		if ($extension) $addExtension=".".$extension;
			else $addExtension="";
		return $dir.$filenameWithoutExtension.$addedString.$addExtension;
	}

	/**
	 * Nastaví práva, aby $filename bylo zapisovatelné, ať už je to soubor nebo adresář
	 * @param string $filename
	 * @return bool Dle úspěchu
	 * @throws Exceptions\FileException Pokud zadaná cesta není
	 * @throws Exceptions\FileAccessException Pokud změna selže
	 */
	static function perms($filename) {

		if (!file_exists($filename)) {
			throw new FileException("Missing: $filename");
		}
		if (!is_writeable($filename)) {
			throw new FileException("Not writable: $filename");
		}

		if (is_dir($filename)) {
			$ok=chmod($filename,0777);
		} else {
			$ok=chmod($filename,0666);
		}

		if (!$ok) {
			throw new FileAccessException("Could not chmod $filename");
		}

		return $ok;
	}

	/**
	 * Přesune soubor i s adresářovou strukturou zpod jednoho do jiného.
	 * @param string $file Cílový soubor
	 * @param string $from Adresář, který brát jako základ
	 * @param string $to Clový adresář
	 * @param bool $copy True (default) = kopírovat, false = přesunout
	 * @return string Cesta k novému souboru
	 * @throws FileException Když $file není nalezeno nebo když selže kopírování
	 * @throws \InvalidArgumentException Když $file není umístěno v $from
	 */
	static function rebaseFile($file, $from, $to, $copy=false) {
		if (!file_exists($file)) {
			throw new FileException("Not found: $file");
		}
		if (!Strings::startsWith($file, $from)) {
			throw new \InvalidArgumentException("File $file is not in directory $from");
		}
		$newPath=$to."/".Strings::substring($file, Strings::length($from));
		$newDir=self::dir($newPath);
		self::createDirectories($newDir);
		if ($copy) {
			$ok=copy($file,$newPath);
		} else {
			$ok=rename($file, $newPath);
		}
		if (!$ok) {
			throw new FileException("Failed copying to $newPath");
		}
		self::perms($newPath);
		return $newPath;
	}

	/**
	 * Vrátí cestu k souboru, jako kdyby byl umístěn do jiného adresáře i s cestou k sobě.
	 * @param string $file Jméno souboru
	 * @param string $from Cesta k němu
	 * @param string $to Adresář, kam ho chceš přesunout
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	static function  rebasedFilename($file,$from,$to) {
		if (!Strings::startsWith($file, $from)) {
			throw new \InvalidArgumentException("File $file is not in directory $from");
		}
		$secondPart=Strings::substring($file, Strings::length($from));
		if ($secondPart[0]=="/") $secondPart=substr($secondPart,1);
		$newPath=$to."/".$secondPart;
		return $newPath;
	}

	/**
	 * Ověří, zda soubor je v zadaném adresáři.
	 * @param string $file
	 * @param string $dir
	 * @return bool
	 */
	static function isFileInDir($file,$dir) {
		if (!Strings::endsWith($dir, "/")) $dir.="/";
		return Strings::startsWith($file, $dir);
	}

	/**
	 * Vytvoří bezpečné jméno pro soubor
	 * @param string $filename
	 * @param array $unsafeExtensions
	 * @param string $safeExtension
	 * @return string
	 */
	static function safeName($filename,$unsafeExtensions=null,$safeExtension="txt") {
		if ($unsafeExtensions===null) $unsafeExtensions=array("php","phtml","inc","php3","php4","php5");
		if ($filename[0] == '.') {
			$filename = substr($filename, 1);
		}
		$filename = str_replace(DIRECTORY_SEPARATOR, '-', $filename);
		$extension=self::extension($filename, "l");
		if (in_array($extension, $unsafeExtensions)) {
			$extension=$safeExtension;
		}
		$name=self::filenameWithoutExtension($filename);
		$name=Strings::safe($name, false);
		if (preg_match('~^(.*)[-_]+$~',$name,$partsName)) {
			$name=$partsName[1];
		}
		if (preg_match('~^[-_]+(.*)$~',$name,$partsName)) {
			$name=$partsName[1];
		}
		$ret=$name;
		if ($extension) $ret.=".".$extension;
		return $ret;
	}

	/**
	 * Vytvoří soubor, pokud neexistuje, a udělá ho zapisovatelným
	 * @param string $filename
	 * @param bool $createDirectoriesIfNeeded
	 * @param string $content Pokud se má vytvořit nový soubor, naplní se tímto obsahem
	 * @return string Jméno vytvořného souboru (cesta k němu)
	 * @throws \InvalidArgumentException
	 * @throws FileException
	 * @throws FileAccessException
	 */
	static function create($filename, $createDirectoriesIfNeeded=true, $content="") {
		if (!$filename) {
			throw new \InvalidArgumentException("Completely missing argument!");
		}
		if (file_exists($filename) and is_dir($filename)) {
			throw new FileException("$filename is directory!");
		}
		if (file_exists($filename)) {
			self::perms($filename);
			return $filename;
		}
		if ($createDirectoriesIfNeeded) self::createDirectories(self::dir($filename, false));
		$ok=@touch($filename);
		if (!$ok) {
			throw new FileAccessException("Could not create file $filename");
		}
		self::perms($filename);
		if ($content) {
			file_put_contents($filename, $content);
		}
		return $filename;
	}

	/**
	 * Vrací práva k určitému souboru či afdresáři jako třímístný string.
	 * @param string $path
	 * @return string Např. "644" nebo "777"
	 * @throws FileException
	 */
	static function getPerms($path) {
		//http://us3.php.net/manual/en/function.fileperms.php example #1
		if (!file_exists($path)) {
			throw new FileException("File '$path' is missing");
		}
		return substr(sprintf('%o', fileperms($path)), -3);
	}

	/**
	 * Pokusí se vytvořit strukturu adresářů v zadané cestě.
	 * @param string $path
	 * @return string Vytvořená cesta
	 * @throws FileException Když už takto pojmenovaný soubor existuje a jde o obyčejný soubor nebo když vytváření selže.
	 */
	static function createDirectories($path) {

		if (!$path) throw new \InvalidArgumentException("\$path can not be empty.");

		/*
		$parts=explode("/",$path);
		$pathPart="";
		foreach($parts as $i=>$p) {
			if ($i) $pathPart.="/";
			$pathPart.=$p;
			if ($pathPart) {
				if (@file_exists($pathPart) and !is_dir($pathPart)) {
					throw new FileException("\"$pathPart\" is a regular file!");
				}
				if (!(@file_exists($pathPart))) {
					self::mkdir($pathPart,false);
				}
			}
		}
		return $pathPart;
		 *
		 */

		if (file_exists($path)) {
			if (is_dir($path)) {
				return $path;
			}
			throw new FileException("\"$path\" is a regular file!");
		}

		$ret = @mkdir($path, 0777, true);
		if (!$ret) {
			throw new FileException("Directory \"$path\ could not be created.");
		}

		return $path;
	}

	/**
	 * Vytvoří adresář, pokud neexistuje, a udělá ho obecně zapisovatelným
	 * @param string $filename
	 * @param bool $createDirectoriesIfNeeded
	 * @return string Jméno vytvořneého adresáře
	 * @throws \InvalidArgumentException
	 * @throws FileException
	 * @throws FileAccessException
	 */
	static function mkdir($filename, $createDirectoriesIfNeeded=true) {
		if (!$filename) {
			throw new \InvalidArgumentException("Completely missing argument!");
		}
		if (file_exists($filename) and !is_dir($filename)) {
			throw new FileException("$filename is not a directory!");
		}
		if (file_exists($filename)) {
			self::perms($filename);
			return $filename;
		}
		if ($createDirectoriesIfNeeded) {
			self::createDirectories($filename);
		} else {
			$ok=@mkdir($filename);
			if (!$ok) {
				throw new FileAccessException("Could not create directory $filename");
			}
		}
		self::perms($filename);
		return $filename;
	}

	/**
	 * Najde volné pojmenování pro soubor v určitém adresáři tak, aby bylo jméno volné.
	 * <br />Pokus je obsazené, pokouší se přidávat pomlčku a čísla až do 99, pak přejde na uniqid():
	 * <br />freeFilename("/files/somewhere","abc.txt");
	 * <br />Bude zkoušet: abc.txt, abc-2.txt, abc-3.txt atd.
	 *
	 * @param string $path Adresář
	 * @param string $filename Požadované jméno souboru
	 * @return string Jméno souboru (ne celá cesta, jen jméno souboru)
	 * @throws AccessException
	 */
	static function freeFilename($path,$filename) {
		if (!file_exists($path) or !is_dir($path) or !is_writable($path)) {
			throw new FileAccessException("Directory $path is missing or not writeble.");
		}
		if (!file_exists($path."/".$filename)) {
			return $filename;
		}
		$maxTries=99;
		$filenamePart=self::filenameWithoutExtension($filename);
		$extension=self::extension($filename);
		$addExtension=$extension?".$extension":"";
		for ( $addedIndex=2 ; $addedIndex<$maxTries ; $addedIndex++ ) {
			if (!file_exists($path."/".$filenamePart."-".$addedIndex.$addExtension)) {
				break;
			}
		}
		if ($addedIndex==$maxTries) {
			return $filenamePart."-".uniqid("").$addExtension;
		}
		return $filenamePart."-".$addedIndex.$addExtension;
	}

	/**
	 * Vymaže obsah adresáře
	 * @param string $dir
	 * @return boolean Dle úspěchu
	 * @throws \InvalidArgumentException
	 */
	static function purgeDir($dir) {
		if (!is_dir($dir)) {
			throw new \InvalidArgumentException("$dir is not directory.");
		}
		$content=glob($dir."/*");
		if ($content) {
			foreach($content as $sub) {
				if ($sub=="." or $sub=="..") continue;
				self::remove($sub);
			}
		}
		return true;
	}

	/**
	 * Smaže adresář a rekurzivně i jeho obsah
	 * @param string $dir
	 * @param int $depthLock Interní, ochrana proti nekonečné rekurzi
	 * @return boolean Dle úspěchu
	 * @throws \RuntimeException
	 * @throws \InvalidArgumentException
	 * @throws FileAccessException
	 */
	static function removeDir($dir,$depthLock=0) {
		if ($depthLock > 15) {
			throw new \RuntimeException("Recursion too deep at $dir");
		}
		if (!file_exists($dir)) {
			return true;
		}
		if (!is_dir($dir)) {
			throw new \InvalidArgumentException("$dir is not directory.");
		}

		$content=glob($dir."/*");
		if ($content) {
			foreach($content as $sub) {
				if ($sub=="." or $sub=="..") continue;
				if (is_dir($sub)) {
					self::removeDir($sub,$depthLock+1);
				} else {
					if (is_writable($sub)) {
						unlink($sub);
					} else {
						throw new FileAccessException("Could not delete file $sub");
					}
				}
			}
		}
		$ok=rmdir($dir);
		if (!$ok) {
			throw new FileAccessException("Could not remove dir $dir");
		}

		return true;
	}

	/**
	 * Smaže $path, ať již je to adresář nebo soubor
	 * @param string $path
	 * @param bool $onlyFiles Zakáže mazání adresářů
	 * @return boolean Dle úspěchu
	 * @throws FileAccessException
	 * @throws FileException
	 */
	static function remove($path, $onlyFiles=false) {
		if (!file_exists($path)) {
			return true;
		}
		if (is_dir($path)) {
			if ($onlyFiles) throw new FileException("$path is a directory!");
			return self::removeDir($path);
		}
		else {
			$ok=unlink($path);
			if (!$ok) throw new FileAccessException("Could not delete file $path");
		}
		return true;
	}

    /**
     * Stažení vzdáleného souboru pomocí  cURL
     * @param $url URL vzdáleného souboru
     * @param $path Kam stažený soubor uložit?
     * @param bool $stream
     */
    public static function downloadFile($url, $path, $stream = TRUE) {

        $curl = curl_init($url);

        if(!$stream) {

            curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
            file_put_contents($path, curl_exec($curl));
        }
        else {

            $fp = fopen($path, 'w');

            curl_setopt($curl, CURLOPT_FILE, $fp);
            curl_exec($curl);
            fclose($fp);
        }

        curl_close($curl);
    }

	/**
	 * Vrací maximální nahratelnou velikost souboru.
	 *
	 * Bere menší z hodnot post_max_size a upload_max_filesize a převede je na obyčejné číslo.
	 * @return int Bytes
	 */
	static function maxUploadFileSize() {
		$file_max = Strings::parsePhpNumber(ini_get("post_max_size"));
		$post_max = Strings::parsePhpNumber(ini_get("upload_max_filesize"));
		$php_max = min($file_max,$post_max);
		return $php_max;
	}

}

}


// vendor/ondrakoupil/tools/src/Exceptions/FileException.php 

namespace OndraKoupil\Tools {


class FileException extends \RuntimeException {

}

}


// vendor/ondrakoupil/tools/src/Exceptions/FileAccessException.php 

namespace OndraKoupil\Tools {


class FileAccessException extends \RuntimeException {

}

}


