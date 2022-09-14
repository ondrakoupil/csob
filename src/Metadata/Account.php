<?php

namespace OndraKoupil\Csob\Metadata;

use DateTime;
use OndraKoupil\Csob\Tools;

/**
 * @see https://github.com/csob/paymentgateway/wiki/Purchase-metadata#customer
 */
class Account {

	/**
	 * @var DateTime
	 */
	protected $createdAt;

	/**
	 * @var DateTime
	 */
	protected $changedAt;

	/**
	 * @var DateTime
	 */
	protected $changedPwdAt;

	/**
	 * @var int
	 */
	public $orderHistory = 0;

	/**
	 * @var int
	 */
	public $paymentsDay = 0;

	/**
	 * @var int
	 */
	public $paymentsYear = 0;

	/**
	 * @var int
	 */
	public $oneclickAdds = 0;

	/**
	 * @var bool
	 */
	public $suspicious = false;

	/**
	 * @return DateTime
	 */
	public function getCreatedAt() {
		return $this->createdAt;
	}

	/**
	 * @param DateTime $createdAt
	 *
	 * @return Account
	 */
	public function setCreatedAt(DateTime $createdAt) {
		$this->createdAt = $createdAt;

		return $this;
	}

	/**
	 * @return DateTime
	 */
	public function getChangedAt() {
		return $this->changedAt;
	}

	/**
	 * @param DateTime $changedAt
	 *
	 * @return Account
	 */
	public function setChangedAt(DateTime $changedAt) {
		$this->changedAt = $changedAt;
		return $this;
	}

	/**
	 * @return DateTime
	 */
	public function getChangedPwdAt() {
		return $this->changedPwdAt;
	}

	/**
	 * @param DateTime $changedPwdAt
	 *
	 * @return Account
	 */
	public function setChangedPwdAt(DateTime $changedPwdAt) {
		$this->changedPwdAt = $changedPwdAt;

		return $this;
	}

	public function export() {
		$a = array(
			'createdAt' => $this->createdAt ? $this->createdAt->format('c') : null,
			'changedAt' => $this->changedAt ? $this->changedAt->format('c') : null,
			'changedPwdAt' => $this->changedPwdAt ? $this->changedPwdAt->format('c') : null,
			'orderHistory' => +$this->orderHistory,
			'paymentsDay' => +$this->paymentsDay,
			'paymentsYear' => +$this->paymentsYear,
			'oneclickAdds' => +$this->oneclickAdds,
			'suspicious' => !!$this->suspicious,
		);

		$a = Tools::filterOutEmptyFields($a);

		return $a;
	}


}
