<?php

namespace OndraKoupil\Csob;

// src/Client.php 




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
 *    "Some URL to return customers to"
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
	 * @return array Array with results of the call. You don't need to use
	 * any of this, PayID will be set to $payment automatically.
	 *
	 * @throws Exception When something fails.
	 */
	function paymentInit(Payment $payment) {

		$payment->checkAndPrepare($this->config);
		$array = $payment->signAndExport($this->config);

		$this->writeToLog("payment/init started for payment with orderNo " . $payment->orderNo);

		try {
			$ret = $this->sendRequest(
				"payment/init",
				$array,
				"POST",
				array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "authCode")
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
	 * Generates URL to send customer's browser to after initing the payment.
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
	 *	 card number and authorising the transaction
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
	 * @return array|number Number if $returnStatusOnly was true, array otherwise.
	 */
	function paymentStatus($payment, $returnStatusOnly = true) {
		$payId = $this->getPayId($payment);

		$this->writeToLog("payment/status started for payment $payId");

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		try {
			$payload["signature"] = $this->signRequest($payload);

			$ret = $this->sendRequest(
				"payment/status",
				$payload,
				"GET",
				array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "authCode"),
				array("merchantId", "payId", "dttm", "signature")
			);

		} catch (Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
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
	 * @return array|null Array with results of call or null if payment is not
	 * in correct state
	 *
	 *
	 * @throws Exception
	 */
	function paymentReverse($payment, $ignoreWrongPaymentStatusError = false) {
		$payId = $this->getPayId($payment);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		$this->writeToLog("payment/reverse started for payment $payId");

		try {
			$payload["signature"] = $this->signRequest($payload);

			try {

				$ret = $this->sendRequest(
					"payment/reverse",
					$payload,
					"PUT",
					array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "authCode"),
					array("merchantId", "payId", "dttm", "signature")
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
	 *
	 * @return array|null Array with results of call or null if payment is not
	 * in correct state
	 *
	 *
	 * @throws Exception
	 */
	function paymentClose($payment, $ignoreWrongPaymentStatusError = false, $amount = null) {
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

		try {
			$payload["signature"] = $this->signRequest($payload);

			try {

				$ret = $this->sendRequest(
					"payment/close",
					$payload,
					"PUT",
					array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "authCode"),
					array("merchantId", "payId", "dttm", "totalAmount", "signature")
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
	 * @return array|null Array with results of call or null if payment is not
	 * in correct state
	 *
	 *
	 * @throws Exception
	 */
	function paymentRefund($payment, $ignoreWrongPaymentStatusError = false, $amount = null) {
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
					array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus"),
					array("merchantId", "payId", "dttm", "amount", "signature")
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
	 * @see Payment::setRecurrentPayment()
	 */
	function paymentRecurrent($origPayment, Payment $newPayment) {
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
				array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "authCode"),
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
	 * Performs customer/info API call.
	 *
	 * Use this method to check if customer with given ID has any saved cards.
	 * If he does, you can show some icon or change default payment method in
	 * e-shop or do some other action. This is just an auxiliary method and
	 * is not neccessary at all.
	 *
	 * @param string|array|Payment $customerId Customer ID, Payment object or array
	 * as returned from paymentInit
	 * @param bool $returnIfHasCardsOnly
	 * @return bool|int If $returnIfHasCardsOnly is set to true, method returns
	 * boolean indicating whether given customerID has any saved cards. If it is
	 * set to false, then method returns one of CUSTOMER_*** constants which can
	 * be used to distinguish more precisely whether customer just hasn't saved
	 * any cards or was not found at all.
	 *
	 * @throws Exception
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
			$ret = $this->sendRequest(
				"customer/info",
				$payload,
				"GET",
				array("customerId", "dttm", "resultCode", "resultMessage"),
				array("merchantId", "customerId", "dttm", "signature")
			);
		} catch (Exception $e) {
			// Valid call returns non-0 resultCode, which leads to exception
			$resMessage = $e->getMessage();

			switch  ($e->getCode()) {

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
	 * your own array with accordingly named variables.
	 * @return array|null Array with received data or null if no data is present.
	 * @throws Exception When data is present but signature is incorrect.
	 */
	function receiveReturningCustomer($input = null) {

		$returnDataNames = array(
			"payId",
			"dttm",
			"resultCode",
			"resultMessage",
			"paymentStatus",
			"authCode",
			"merchantData",
			"signature"
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

		$mess = "Returning customer: payId ".$input["payId"].", authCode ".$input["authCode"].", payment status ".$input["paymentStatus"];
		if ($input["merchantData"]) {
			$mess .= ", merchantData ".$input["merchantData"];
		}
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
	protected function getDTTM() {
		return date(self::DATE_FORMAT);
	}

	/**
	 * Signs array payload
	 * @param array $arrayToSign
	 * @return string Base64 encoded signature
	 * @ignore
	 */
	protected function signRequest($arrayToSign) {
		$stringToSign = implode("|", $arrayToSign);
		$keyFile = $this->config->privateKeyFile;
		$signature = Crypto::signString(
			$stringToSign,
			$keyFile,
			$this->config->privateKeyPassword
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
	 * @return string|array
	 * @throws Exception
	 * @ignore
	 */
	protected function sendRequest($apiMethod, $payload, $usePostMethod = true, $responseFieldsOrder = null, $requestFieldsOrder = null, $returnUrlOnly = false) {
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

		if ($returnUrlOnly) {
			$this->writeToTraceLog("Returned final URL: " . $url);
			return $url;
		}

		$ch = \curl_init($url);
		$this->writeToTraceLog("URL to send request to: " . $url);

		if ($method === "POST" or $method === "PUT") {
			$encodedPayload = json_encode($payload);
			$this->writeToTraceLog("JSON payload: ".$encodedPayload);
			\curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			\curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedPayload);
		}

		\curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
			throw new Exception(
				"API returned HTTP code $httpCode, which is not code 200."
				. ($httpCode == 400 ? " Probably wrong signature, check crypto keys." : "")
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

		if ($decoded["resultCode"] != "0") {
			$this->writeToTraceLog("Failed: resultCode ".$decoded["resultCode"].", message ".$decoded["resultMessage"]);
			throw new Exception("API returned an error: resultCode \"" . $decoded["resultCode"] . "\", resultMessage: ".$decoded["resultMessage"], $decoded["resultCode"]);
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
			$this->writeToTraceLog("Failed: signature is incorrect.");
			throw new Exception("Result signature is incorrect. Please make sure that bank's public key in file specified in config is correct and up-to-date.");
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
			$sortedResponse = array();
			foreach($responseFieldsOrder as $f) {
				if (isset($responseWithoutSignature[$f])) {
					$sortedResponse[] = $responseWithoutSignature[$f];
				}
			}
			$responseWithoutSignature = $sortedResponse;
		}

		$string = implode("|", $responseWithoutSignature);

		$this->writeToTraceLog("String for verifying signature: \"" . $string . "\", using key " . $this->config->bankPublicKeyFile);

		return Crypto::verifySignature($string, $signature, $this->config->bankPublicKeyFile);
	}

}



// src/Config.php 



/**
 * Configuration for integrating your app to bank gateway.
 */
class Config {

	/**
	 * Bank API path. By default, this is the testing (playground) API.
	 * Change that when you are ready to go to live environment.
	 *
	 * @var string
	 */
	public $url = "https://iapi.iplatebnibrana.csob.cz/api/v1.5";

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
	 * @var type
	 */
	public $closePayment = true;

	/**
	 * Create config with all mandatory values.
	 *
	 * See equally named properties of this class for more info.
	 *
	 * @param string $merchantId
	 * @param string $privateKeyFile
	 * @param string $bankPublicKeyFile
	 * @param string $shopName
	 * @param string $returnUrl
	 * @param string $bankApiUrl
	 * @param string $privateKeyPassword
	 */
	function __construct($merchantId, $privateKeyFile, $bankPublicKeyFile, $shopName, $returnUrl = null, $bankApiUrl = null, $privateKeyPassword = null) {
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
	}


}



// src/Payment.php 




/**
 * A payment request.
 *
 * To init new payment, you need to create an instance
 * of this class and fill its properties with real information
 * from the order.
 */
class Payment {

	const OPERATION_PAYMENT = "payment";
	const OPERATION_RECURRENT = "recurrentPayment";

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
	 * @var string
	 */
	public $customerId;

	/**
	 * Language of the gateway. Default is "CZ".
	 *
	 * See wiki on ČSOB's Github for other values, they are not the same
	 * as standard ISO language codes.
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
	 * @var array
	 * @ignore
	 */
	private $fieldsInOrder = array(
		"merchantId",
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
		"description",
		"merchantData",
		"customerId",
		"language"
	);


	/**
	 * @param string $orderNo
	 * @param mixed $merchantData
	 * @param string $customerId
	 * @param bool|null $recurrentPayment
	 */
	function __construct($orderNo, $merchantData = null, $customerId = null, $recurrentPayment = null) {
		$this->orderNo = $orderNo;

		if ($merchantData) {
			$this->setMerchantData($merchantData);
		}

		if ($customerId) {
			$this->customerId = $customerId;
		}

		if ($recurrentPayment !== null) {
			$this->setRecurrentPayment($recurrentPayment);
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
		$description = trim(Strings::shorten($description, 40, ""));

		$this->cart[] = array(
			"name" => $name,
			"quantity" => $quantity,
			"amount" => $totalAmount,
			"description" => $description
		);

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
	 * After the payment has been saved using payment/init, you can
	 * get PayID from here.
	 *
	 * @return string
	 */
	public function getPayId() {
		return $this->foreignId;
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
	 *
	 * @return \OndraKoupil\Csob\Payment
	 */
	function setRecurrentPayment($recurrent = true) {
		$this->payOperation = $recurrent ? self::OPERATION_RECURRENT : self::OPERATION_PAYMENT;
		return $this;
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
	 * @param Config $config
	 * @return array
	 *
	 * @ignore
	 */
	function signAndExport(Config $config) {
		$arr = array();

		foreach($this->fieldsInOrder as $f) {
			$val = $this->$f;
			if ($val === null) {
				$val = "";
			}
			$arr[$f] = $val;
		}

		$stringToSign = $this->getSignatureString();

		$signed = Crypto::signString($stringToSign, $config->privateKeyFile, $config->privateKeyPassword);
		$arr["signature"] = $signed;

		return $arr;
	}

	/**
	 * Convert to string that serves as base for signing.
	 * @return string
	 * @ignore
	 */
	function getSignatureString() {
		$parts = array();

		foreach($this->fieldsInOrder as $f) {
			$val = $this->$f;
			if ($val === null) {
				$val = "";
			}
			elseif (is_bool($val)) {
				if ($val) {
					$val = "true";
				} else {
					$val = "false";
				}
			} elseif (is_array($val)) {
				// There are never more than 2 levels, we don't need recursive walk
				$valParts = array();
				foreach($val as $v) {
					if (is_scalar($v)) {
						$valParts[] = $v;
					} else {
						$valParts[] = implode("|", $v);
					}
				}
				$val = implode("|", $valParts);
			}
			$parts[] = $val;
		}

		return implode("|", $parts);
	}


}



// src/Crypto.php 



/**
 * Helper class for signing and signature verification
 *
 * @see https://github.com/csob/paymentgateway/blob/master/eshop-integration/eAPI/v1/php/example/crypto.php
 */
class Crypto {

	/**
	 * Currently used has algorithm
	 */
	const HASH_METHOD = \OPENSSL_ALGO_SHA1;

	/**
	 * Signs a string
	 *
	 * @param string $string
	 * @param string $privateKeyFile Path to file with your private key (the .key file from https://iplatebnibrana.csob.cz/keygen/ )
	 * @param string $privateKeyPassword Password to the key, if it was generated with one. Leave empty if you created the key at https://iplatebnibrana.csob.cz/keygen/
	 * @return string Signature encoded with Base64
	 * @throws CryptoException When signing fails or key file path is not valid
	 */
	static function signString($string, $privateKeyFile, $privateKeyPassword = "") {

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

		$ok = openssl_sign($string, $signature, $privateKeyId, self::HASH_METHOD);
		if (!$ok) {
			throw new CryptoException("Signing failed.");
		}
		$signature = base64_encode ($signature);
		openssl_free_key ($privateKeyId);

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
	 * @return bool True if signature is correct
	 * @throws CryptoException When some cryptographic operation fails and key file path is not valid
	 */
	static function verifySignature($textToVerify, $signatureInBase64, $publicKeyFile) {

		if (!function_exists("openssl_get_privatekey")) {
			throw new CryptoException("OpenSSL extension in PHP is required. Please install or enable it.");
		}

		if (!file_exists($publicKeyFile) or !is_readable($publicKeyFile)) {
			throw new CryptoException("Public key file \"$publicKeyFile\" not found or not readable.");
		}

		$keyAsString = file_get_contents($publicKeyFile);
		$publicKeyId = openssl_get_publickey($keyAsString);

		$signature = base64_decode($signatureInBase64);

		$res = openssl_verify($textToVerify, $signature, $publicKeyId, self::HASH_METHOD);
		openssl_free_key($publicKeyId);

		if ($res == -1) {
			throw new CryptoException("Verification of signature failed: ".openssl_error_string());
		}

		return $res ? true : false;
	}

}



// src/Exception.php 



class Exception extends \RuntimeException {}



// src/CryptoException.php 



class CryptoException extends Exception {}


// vendor/ondrakoupil/tools/src/Strings.php 



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

		if (self::strlen($text)<=$length) return $text;
		if (!$ignoreWords) {
			$kdeRezat=$length-4;
			if ($kdeRezat<0) $kdeRezat=0;
			$konecTextu=self::substr($text,$kdeRezat);
			$rozdelovace='\s\-_:."\'&/\(?!\)';
			$match=preg_match('~^([^'.$rozdelovace.']*)['.$rozdelovace.'$]~m',$konecTextu,$casti);
			$kdeRiznout=$length;
			if ($match) {
				$kdeRiznout=$kdeRezat+self::strlen($casti[1]);
			}
		} else {
			$kdeRiznout = $length - self::strlen($ending);
			if ($kdeRiznout < 0) $kdeRiznout = 0;
		}
		$vrat= self::substr($text,0,$kdeRiznout).$ending;

		if ($stripHtml) {
			$vrat=self::nl2br($vrat);
		}

		return $vrat;
	}

	/**
	* Všechny tagy BR (ve formě &lt;br> i &lt;br />) nahradí za \n (LF)
	* @param string $input
	* @return string
	*/
	static function br2nl($input) {
		return preg_replace('~<br\s*/?>~i', "\n", $input);
	}


	/**
	* Nahradí nové řádky za &lt;br />, ale nezanechá je tam.
	* @param string $input
	* @return string
	*/
	static function nl2br($input) {
		$input = str_replace("\r\n", "\n", $input);
		return str_replace("\n", "<br />", $input);
	}

	/**
	 * Nahradí entity v řetězci hodnotami ze zadaného pole.
	 * @param string $string
	 * @param array $valuesArray
	 * @param callback $escapeFunction Funkce, ktrsou se prožene každá nahrazená entita (např. kvůli escapování paznaků). Defaultně Html::escape()
	 * @param string $entityDelimiter Jeden znak
	 * @param string $entityNameChars Rozsah povolených znaků v názvech entit
	 * @return type
	 */
	static function replaceEntities($string, $valuesArray, $escapeFunction = "!!default", $entityDelimiter = "%", $entityNameChars = 'a-z0-9_-') {
		if ($escapeFunction === "!!default") {
			$escapeFunction = "\\OndraKoupil\\Tools\\Html::escape";
		}
		$string = \preg_replace_callback('~'.preg_quote($entityDelimiter).'(['.$entityNameChars.']+)'.preg_quote($entityDelimiter).'~i', function($found) use ($valuesArray, $escapeFunction) {
			if (key_exists($found[1], $valuesArray)) {
				$v = $valuesArray[$found[1]];
				if ($escapeFunction) {
					$v = call_user_func_array($escapeFunction, array($v));
				}
				return $v;
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

}



// vendor/ondrakoupil/tools/src/Arrays.php 



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
}



// vendor/ondrakoupil/tools/src/Files.php 




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
	 * @param type $filename
	 * @return type
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
		$extension=self::extension($filename, "l");
		if (in_array($extension, $unsafeExtensions)) {
			$extension=$safeExtension;
		}
		$name=self::filenameWithoutExtension($filename);
		$name=Strings::safe($name, false);
		if (preg_match('~^(.*)[-_]+$~',$name,$partsName)) {
			$name=$partsName[1];
		}
		if (preg_match('~^[-_](.*)$~',$name,$partsName)) {
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



// vendor/ondrakoupil/tools/src/Exceptions/FileException.php 



class FileException extends \RuntimeException {

}



// vendor/ondrakoupil/tools/src/Exceptions/FileAccessException.php 



class FileAccessException extends \RuntimeException {

}



