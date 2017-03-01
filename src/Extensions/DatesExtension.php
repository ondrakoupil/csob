<?php

namespace OndraKoupil\Csob\Extensions;

use DateTime;
use OndraKoupil\Csob\Exception;
use OndraKoupil\Csob\Extension;

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
