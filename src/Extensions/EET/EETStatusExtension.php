<?php

namespace OndraKoupil\Csob\Extensions\EET;

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
