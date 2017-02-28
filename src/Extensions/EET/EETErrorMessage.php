<?php

namespace OndraKoupil\Csob\Extensions\EET;

/**
 * Represents an error message from EET extension
 */
abstract class EETErrorMessage {

	/**
	 * @var string
	 */
	public $code;

	/**
	 * @var string
	 */
	public $desc;

	/**
	 * The constructor
	 *
	 * @param string $code
	 * @param string $desc
	 */
	public function __construct($code, $desc) {
		$this->code = $code;
		$this->desc = $desc;
	}

	/**
	 * @return string
	 */
	public function getSignatureBase() {
		return $this->code . '|' . $this->desc;
	}

}
