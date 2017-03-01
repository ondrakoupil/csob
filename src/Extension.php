<?php

namespace OndraKoupil\Csob;

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
		$signature = Crypto::signString($baseString, $config->privateKeyFile, $config->privateKeyPassword);

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

		return Crypto::verifySignature($baseString, $signature, $config->bankPublicKeyFile);

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






}
