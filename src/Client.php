<?php

namespace OndraKoupil\Csob;

use \OndraKoupil\Tools\Files;

/**
 * The most important class that allows you to use payment gateway's functions.
 */
class Client {

	const DATE_FORMAT = "YmdHis";

	/**
	 * @var Config
	 * @ignore
	 */
	protected $config;

	protected $logFile;
	protected $logCallback;

	protected $traceLogFile;
	protected $traceLogCallback;


	// ------- BASICS --------

	/**
	 * Create new client with given Config.
	 * @param Config $config
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
	 * Normally, if the payment is not in a state where it's possible to reverse
	 * it, than gateway returns an error code 150 and exception is thrown from here.
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
					array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus"),
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

	function paymentClose($payment) {

	}

	function paymentRefund($payment) {

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

	function customerInfo() {

	}


	// ------ LOGGING -------

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
	 * @param Payment|string $payment
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
		if (!is_string($payment) or strlen($payment) != 15) {
			throw new \InvalidArgumentException("Given Payment ID is not valid - it should be a string with length 15 characters.");
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
		return Crypto::signString(
			implode("|", $arrayToSign),
			$this->config->privateKeyFile,
			$this->config->privateKeyPassword
		);
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

		$ch = curl_init($url);
		$this->writeToTraceLog("URL to send request to: " . $url);

		if ($method === "POST" or $method === "PUT") {
			$encodedPayload = json_encode($payload);
			$this->writeToTraceLog("JSON payload: ".$encodedPayload);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedPayload);
		}

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Accept: application/json;charset=UTF-8'
		));

		$result = curl_exec($ch);

		if (curl_errno($ch)) {
			$this->writeToTraceLog("CURL failed: " . curl_errno($ch) . " " . curl_error($ch));
			throw new \RuntimeException("Failed sending data to API: ".curl_errno($ch)." ".curl_error($ch));
		}

		$this->writeToTraceLog("API response: $result");

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($httpCode != 200) {
			$this->writeToTraceLog("Failed: returned HTTP code $httpCode");
			throw new \RuntimeException("API returned HTTP code $httpCode, which is not code 200. Probably wrong signature, check crypto keys.");
		}

		curl_close($ch);

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
	 * @return steing
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
