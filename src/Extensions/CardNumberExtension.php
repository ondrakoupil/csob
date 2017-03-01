<?php

namespace OndraKoupil\Csob\Extensions;

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
