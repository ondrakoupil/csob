<?php

namespace OndraKoupil\Csob;

class Client {

	const DATE_FORMAT = "YmdHis";

	protected $config;

	// ------- BASICS --------

	function __construct(Config $config) {

		$this->config = $config;

	}

	function getConfig() {
		return $this->config;
	}

	function setConfig(Config $config) {
		$this->config = $config;
	}


	// ------- API CALL METHODS --------

	function paymentInit(Payment $payment) {

		$payment->checkAndPrepare($this->config);
		$array = $payment->signAndExport($this->config);
		$ret = $this->sendRequest(
			"payment/init",
			$array,
			"POST",
			array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "authCode")
		);

		if (!isset($ret["payId"]) or !$ret["payId"]) {
			throw new \RuntimeException("Bank API did not return a payId value.");
		}

		$payment->setBankId($ret["payId"]);

		return $ret;

	}

	function getPaymentProcessUrl($payment) {
		$payId = $this->getPayId($payment);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		$payload["signature"] = $this->signRequest($payload);

		return $this->sendRequest(
			"payment/process",
			$payload,
			"POST",
			array(),
			array("merchantId", "payId", "dttm", "signature"),
			true
		);
	}

	function paymentStatus($payment, $returnStatusOnly = true) {
		$payId = $this->getPayId($payment);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

		$payload["signature"] = $this->signRequest($payload);

		$ret = $this->sendRequest(
			"payment/status",
			$payload,
			"GET",
			array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "authCode"),
			array("merchantId", "payId", "dttm", "signature")
		);

		if ($returnStatusOnly) {
			return $ret["paymentStatus"];
		}

		return $ret;
	}

	function paymentReverse($payment, $ignoreWrongPaymentStatusError = false) {
		$payId = $this->getPayId($payment);

		$payload = array(
			"merchantId" => $this->config->merchantId,
			"payId" => $payId,
			"dttm" => $this->getDTTM()
		);

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

			return null;
		}

		return $ret;
	}

	function paymentClose($payment) {

	}

	function paymentRefund($payment) {

	}

	function testPostConnection() {
		$payload = array(
			"merchantId" => $this->config->merchantId,
			"dttm" => $this->getDTTM()
		);

		$payload["signature"] = $this->signRequest($payload);

		$ret = $this->sendRequest("echo", $payload, true, array("dttm", "resultCode", "resultMessage"));

		return $ret;
	}

	function testGetConnection() {
		$payload = array(
			"merchantId" => $this->config->merchantId,
			"dttm" => $this->getDTTM()
		);

		$payload["signature"] = $this->signRequest($payload);

		$ret = $this->sendRequest("echo", $payload, false, array("dttm", "resultCode", "resultMessage"), array("merchantId", "dttm", "signature"));

		return $ret;
	}

	function customerInfo() {

	}


	// ------ COMMUNICATION ------

	protected function getPayId($payment) {
		if (!is_string($payment) and $payment instanceof Payment) {
			$payment = $payment->getBankId();
			if (!$payment) {
				throw new \InvalidArgumentException("Given Payment object does not have payId. Please call paymentInit() first.");
			}
		}
		if (!is_string($payment) or strlen($payment) != 15) {
			throw new \InvalidArgumentException("Given Payment ID is not valid - it should be a string with length 15 characters.");
		}
		return $payment;
	}

	protected function getDTTM() {
		return date(self::DATE_FORMAT);
	}

	protected function signRequest($arrayToSign) {
		return Crypto::signString(
			implode("|", $arrayToSign),
			$this->config->privateKeyFile,
			$this->config->privateKeyPassword
		);
	}

	protected function sendRequest($apiMethod, $payload, $usePostMethod = true, $responseFieldsOrder = null, $requestFieldsOrder = null, $returnUrlOnly = false) {
		$url = $this->getApiMethodUrl($apiMethod);

		$method = $usePostMethod;

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
			return $url;
		}

		$ch = curl_init($url);

		if ($method === "POST" or $method === "PUT") {
			$encodedPayload = json_encode($payload);
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
			throw new \RuntimeException("Failed sending data to API: ".curl_errno($ch)." ".curl_error($ch));
		}

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($httpCode != 200) {
			throw new \RuntimeException("API returned HTTP code $httpCode, which is not code 200.");
		}

		curl_close($ch);

		$decoded = @json_decode($result, true);
		if ($decoded === null) {
			throw new \RuntimeException("API did not return a parseable JSON string: \"".$result."\"");
		}

		if (!isset($decoded["resultCode"])) {
			throw new \RuntimeException("API did not return a response containing resultCode.");
		}

		if ($decoded["resultCode"] != "0") {
			throw new \RuntimeException("API returned an error: resultCode \"" . $decoded["resultCode"] . "\", resultMessage: ".$decoded["resultMessage"], $decoded["resultCode"]);
		}

		if (!isset($decoded["signature"]) or !$decoded["signature"]) {
			throw new \RuntimeException("Result does not contain signature.");
		}

		$signature = $decoded["signature"];
		$verificationResult = $this->verifyResponseSignature($decoded, $signature, $responseFieldsOrder);

		if (!$verificationResult) {
			throw new \RuntimeException("Result signature is incorrect. Please make sure that bank's public key in file specified in config is correct and up-to-date.");
		}

		return $decoded;
	}

	function getApiMethodUrl($apiMethod) {
		return $this->config->url . "/" . $apiMethod;
	}

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
