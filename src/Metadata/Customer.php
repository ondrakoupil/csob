<?php

namespace OndraKoupil\Csob\Metadata;

use OndraKoupil\Csob\Tools;
use OndraKoupil\Tools\Strings;

/**
 * @see https://github.com/csob/paymentgateway/wiki/Purchase-metadata#customer
 */
class Customer {

	/**
	 * @var string
	 */
	public $name = '';

	/**
	 * @var string
	 */
	public $email = '';

	/**
	 * @var string
	 */
	public $homePhone = '';

	/**
	 * @var string
	 */
	public $workPhone = '';

	/**
	 * @var string
	 */
	public $mobilePhone = '';

	/**
	 * @var Account
	 */
	protected $account;

	/**
	 * @var Login
	 */
	protected $login;

	/**
	 * @return Account
	 */
	public function getAccount() {
		return $this->account;
	}

	/**
	 * @param Account $account
	 *
	 * @return Customer
	 */
	public function setAccount($account) {
		$this->account = $account;
		return $this;
	}

	/**
	 * @return Login
	 */
	public function getLogin() {
		return $this->login;
	}

	/**
	 * @param Login $login
	 *
	 * @return Customer
	 */
	public function setLogin($login) {
		$this->login = $login;
		return $this;
	}




	function export() {

		$a = array(
			'name' => Strings::shorten(trim($this->name), 45, '', true, true),
			'email' => Strings::shorten(trim($this->email), 100, '', true, true),
			'homePhone' => trim($this->homePhone),
			'workPhone' => trim($this->workPhone),
			'mobilePhone' => trim($this->mobilePhone),
			'account' => $this->account ? $this->account->export() : null,
			'login' => $this->login ? $this->login->export() : null,
		);

		$a = Tools::filterOutEmptyFields($a);

		return $a;

	}



}
