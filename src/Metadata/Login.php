<?php

namespace OndraKoupil\Csob\Metadata;

use DateTime;
use OndraKoupil\Csob\Tools;

/**
 * @see https://github.com/csob/paymentgateway/wiki/Purchase-metadata#customerlogin-data-
 */
class Login {

	const AUTH_GUEST = 'guest';
	const AUTH_ACCOUNT = 'account';
	const AUTH_FEDERATED = 'federated';
	const AUTH_ISSUER = 'issuer';
	const AUTH_THIRDPARTY = 'thirdparty';
	const AUTH_FIDO = 'fido';
	const AUTH_FIDO_SIGNED = 'fido_signed';
	const AUTH_API = 'api';

	/**
	 * Use AUTH_* class constants
	 * @var string
	 */
	public $auth = '';

	/**
	 * @var DateTime
	 */
	protected $authAt;

	public $authData = '';

	/**
	 * @return mixed
	 */
	public function getAuthAt() {
		return $this->authAt;
	}

	/**
	 * @param mixed $authAt
	 *
	 * @return Login
	 */
	public function setAuthAt(DateTime $authAt) {
		$this->authAt = $authAt;

		return $this;
	}

	function export() {
		$a = array(
			'auth'     => trim($this->auth),
			'authAt'   => $this->authAt ? $this->authAt->format('c') : null,
			'authData' => trim($this->authData),
		);

		$a = Tools::filterOutEmptyFields($a);

		return $a;
	}


}
