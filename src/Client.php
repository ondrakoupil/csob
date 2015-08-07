<?php

namespace OndraKoupil\Csob;

use \OndraKoupil\Tools\Files;

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
	 * @throws \RuntimeException When something fails.
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

		} catch (\Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		if (!isset($ret["payId"]) or !$ret["payId"]) {
			$this->writeToLog("Fail, no payId received.");
			throw new \RuntimeException("Bank API did not return a payId value.");
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
	 * @throws \RuntimeException If headers has been already sent
	 */
	function redirectToGateway($payment) {

		if (headers_sent($file, $line)) {
			$this->writeToLog("Can't redirect, headers sent at $file, line $line");
			throw new \RuntimeException("Can't redirect the browser, headers were already sent at $file line $line");
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

		} catch (\Exception $e) {
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
	 * @throws \RuntimeException
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

			} catch (\RuntimeException $e) {
				if ($e->getCode() != 150) { // Not just invalid state
					throw $e;
				}
				if (!$ignoreWrongPaymentStatusError) {
					throw $e;
				}

				$this->writeToLog("payment/reverse failed, payment is not in correct status");
				return null;
			}

		} catch (\Exception $e) {
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
	 * @return array|null Array with results of call or null if payment is not
	 * in correct state
	 *
	 *
	 * @throws \RuntimeException
	 */
	function paymentClose($payment, $ignoreWrongPaymentStatusError = false) {
		$payId = $this->getPayId($payment);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		$this->writeToLog("payment/close started for payment $payId");

		try {
			$payload["signature"] = $this->signRequest($payload);

			try {

				$ret = $this->sendRequest(
					"payment/close",
					$payload,
					"PUT",
					array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "authCode"),
					array("merchantId", "payId", "dttm", "signature")
				);

			} catch (\RuntimeException $e) {
				if ($e->getCode() != 150) { // Not just invalid state
					throw $e;
				}
				if (!$ignoreWrongPaymentStatusError) {
					throw $e;
				}

				$this->writeToLog("payment/close failed, payment is not in correct status");
				return null;
			}

		} catch (\Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("payment/close OK");

		return $ret;
	}

	/**
	 * Performs payment/refind API call.
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
	 * @param string|Payment $payment Either PayID given during paymentInit(),
	 * or just the Payment object you used in paymentInit()
	 *
	 * @param bool $ignoreWrongPaymentStatusError
	 *
	 * @return array|null Array with results of call or null if payment is not
	 * in correct state
	 *
	 *
	 * @throws \RuntimeException
	 */
	function paymentRefund($payment, $ignoreWrongPaymentStatusError = false) {
		$payId = $this->getPayId($payment);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		$this->writeToLog("payment/refund started for payment $payId");

		try {
			$payload["signature"] = $this->signRequest($payload);

			try {

				$ret = $this->sendRequest(
					"payment/refund",
					$payload,
					"PUT",
					array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "authCode"),
					array("merchantId", "payId", "dttm", "signature")
				);

			} catch (\RuntimeException $e) {
				if ($e->getCode() != 150) { // Not just invalid state
					throw $e;
				}
				if (!$ignoreWrongPaymentStatusError) {
					throw $e;
				}

				$this->writeToLog("payment/refund failed, payment is not in correct status");
				return null;
			}

		} catch (\Exception $e) {
			$this->writeToLog("Fail, got exception: " . $e->getCode().", " . $e->getMessage());
			throw $e;
		}

		$this->writeToLog("payment/refund OK");

		return $ret;
	}

	/**
	 * Test the connection using POST method.
	 *
	 * @return array Results of calling the method.
	 * @throw \Exception If something goes wrong. Se exception's message for more.
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
	 * @throw \Exception If something goes wrong. Se exception's message for more.
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
	 * @throws \Exception
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
		} catch (\Exception $e) {
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
	 * @throws \RuntimeException When data is present but signature is incorrect.
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
			throw new \RuntimeException("Signature is invalid.");
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
	 * @throws \InvalidArgumentException
	 */
	protected function getPayId($payment) {
		if (!is_string($payment) and $payment instanceof Payment) {
			$payment = $payment->getPayId();
			if (!$payment) {
				throw new \InvalidArgumentException("Given Payment object does not have payId. Please call paymentInit() first.");
			}
		}
		if (is_array($payment) and isset($payment["payId"])) {
			$payment = $payment["payId"];
		}
		if (!is_string($payment) or strlen($payment) != 15) {
			throw new \InvalidArgumentException("Given Payment ID is not valid - it should be a string with length 15 characters.");
		}
		return $payment;
	}

	/**
	 * Get customerId as string and validate it.
	 * @ignore
	 * @param Payment|string|array $payment String, Payment object or array as returned from paymentInit call
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	protected function getCustomerId($payment) {
		if (!is_string($payment) and $payment instanceof Payment) {
			$payment = $payment->customerId;
		}
		if (is_array($payment) and isset($payment["customerId"])) {
			$payment = $payment["customerId"];
		}
		if (!is_string($payment)) {
			throw new \InvalidArgumentException("Given Customer ID is not valid.");
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
	 * @throws \RuntimeException
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
			throw new \RuntimeException("Failed sending data to API: ".\curl_errno($ch)." ".\curl_error($ch));
		}

		$this->writeToTraceLog("API response: $result");

		$httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($httpCode != 200) {
			$this->writeToTraceLog("Failed: returned HTTP code $httpCode");
			throw new \RuntimeException("API returned HTTP code $httpCode, which is not code 200. Probably wrong signature, check crypto keys.");
		}

		\curl_close($ch);

		$decoded = @json_decode($result, true);
		if ($decoded === null) {
			$this->writeToTraceLog("Failed: returned value is not parsable JSON");
			throw new \RuntimeException("API did not return a parseable JSON string: \"".$result."\"");
		}

		if (!isset($decoded["resultCode"])) {
			$this->writeToTraceLog("Failed: API did not return response with resultCode");
			throw new \RuntimeException("API did not return a response containing resultCode.");
		}

		if ($decoded["resultCode"] != "0") {
			$this->writeToTraceLog("Failed: resultCode ".$decoded["resultCode"].", message ".$decoded["resultMessage"]);
			throw new \RuntimeException("API returned an error: resultCode \"" . $decoded["resultCode"] . "\", resultMessage: ".$decoded["resultMessage"], $decoded["resultCode"]);
		}

		if (!isset($decoded["signature"]) or !$decoded["signature"]) {
			$this->writeToTraceLog("Failed: missing response signature");
			throw new \RuntimeException("Result does not contain signature.");
		}

		$signature = $decoded["signature"];

		try {
			$verificationResult = $this->verifyResponseSignature($decoded, $signature, $responseFieldsOrder);
		} catch (\Exception $e) {
			$this->writeToTraceLog("Failed: error occured when verifying signature.");
			throw $e;
		}

		if (!$verificationResult) {
			$this->writeToTraceLog("Failed: signature is incorrect.");
			throw new \RuntimeException("Result signature is incorrect. Please make sure that bank's public key in file specified in config is correct and up-to-date.");
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

		return Crypto::verifySignature($string, $signature, $this->config->bankPublicKeyFile);
	}

}
