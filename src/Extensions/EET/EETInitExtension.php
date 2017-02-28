<?php

namespace OndraKoupil\Csob\Extensions\EET;

use OndraKoupil\Csob\Exception;
use OndraKoupil\Csob\Extension;

/**
 * Extension for EET - payment/init and payment/oneclick/init operations
 */
class EETInitExtension extends Extension {

	/**
	 * @var bool Ověřovací (testovací) režim?
	 */
	public $verificationMode;

	/**
	 * @var EETData
	 */
	protected $data;

	/**
	 * @param EETData $data Data for EET
	 * @param bool $verificationMode Should verification (testing) mode be used?
	 */
	function __construct(EETData $data, $verificationMode = false) {

		parent::__construct('eetV3');

		$this->data = $data;
		$this->verificationMode = $verificationMode ? true : false;

		$this->expectedResponseKeysOrder = null;
	}

	/**
	 * @return EETData
	 */
	public function getEETData() {
		return $this->data;
	}

	/**
	 * Builds input data array
	 *
	 * @return array
	 */
	public function getInputData() {
		$a = array(
			'extension' => $this->extensionId,
			'dttm' => null,
			'data' => $this->data->asArray(),
			'verificationMode' => $this->verificationMode ? 'true' : 'false'
		);

		return $a;
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
