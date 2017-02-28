<?php

namespace OndraKoupil\Csob\Extensions\EET;

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
