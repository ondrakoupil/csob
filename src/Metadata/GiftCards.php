<?php

namespace OndraKoupil\Csob\Metadata;

use OndraKoupil\Csob\Tools;

class GiftCards {

	/**
	 * @var number
	 */
	public $totalAmount;

	/**
	 * @var string
	 */
	public $currency;

	/**
	 * @var number
	 */
	public $quantity;

	public function export() {
		$a = array(
			'totalAmount' => $this->totalAmount,
			'currency' => $this->currency,
			'quantity' => $this->quantity,
		);

		return Tools::filterOutEmptyFields($a);
	}

}
