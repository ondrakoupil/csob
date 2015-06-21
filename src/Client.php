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
		$ret = $this->sendRequest("payment/init", $array, array("payId", "dttm", "resultCode", "resultMessage", "paymentStatus", "authCode"));
		return $ret;

	}

	function paymentProcess($payment) {

	}

	function paymentStatus($payment) {

	}

	function paymentReverse($payment) {

	}

	function paymentClose($payment) {

	}

	function paymentRefund($payment) {

	}

	function testConnection() {
		$payload = array(
			"merchantId" => $this->config->merchantId,
			"dttm" => $this->getDTTM()
		);

		$payload["signature"] = $this->signRequest($payload);

		$ret = $this->sendRequest("echo", $payload);

		return $ret;
	}

	function customerInfo() {

	}


	// ------ COMMUNICATION ------

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

	protected function sendRequest($apiMethod, $payload, $responseFieldsOrder = null) {
		$url = $this->getApiMethodUrl($apiMethod);

		$ch = curl_init ($url);

		$encodedPayload = json_encode($payload);

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedPayload);
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

		print_r($decoded);

		if (!isset($decoded["resultCode"])) {
			throw new \RuntimeException("API did not return a response containing resultCode.");
		}

		if ($decoded["resultCode"] != "0") {
			throw new \RuntimeException("API returned an error: resultCode \"" . $decoded["resultCode"] . "\", resultMessage: ".$decoded["resultMessage"]);
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
