<?php

namespace OndraKoupil\Csob;

/**
 * Configuration for integrating your app to bank gateway.
 */
class Config {

	/**
	 * Bank API path. By default, this is the testing (playground) API.
	 * Change that when you are ready to go to live environment.
	 *
	 * @var string
	 */
	public $url = "https://iapi.iplatebnibrana.csob.cz/api/v1.5";

	/**
	 * Path to file where bank's public key is saved.
	 *
	 * You can obtain the key from bank's app
	 * https://iposman.iplatebnibrana.csob.cz/posmerchant
	 * or from their package on GitHub)
	 *
	 * @var string
	 */
	public $bankPublicKeyFile = "";

	/**
	 * Your Merchant ID.
	 *
	 * You obtain that from the bank or from https://iplatebnibrana.csob.cz/keygen/
	 *
	 * @var string
	 */
	public $merchantId = "";

	/**
	 * Path to file where your private key is saved.
	 *
	 * You obtain that key from https://iplatebnibrana.csob.cz/keygen/ - it is
	 * the .key file you download from the keygen.
	 *
	 * Careful - that file MUST NOT BE publicly accessible on webserver!
	 * @var string
	 */
	public $privateKeyFile = "";

	/**
	 * Password for your private key.
	 *
	 * You need to specify this only if your private key was not generated
	 * using bank's keygen https://iplatebnibrana.csob.cz/keygen/
	 * @var string
	 */
	public $privateKeyPassword = null;

	/**
	 * A URL of your e-shop to return your customers after the have paid.
	 *
	 * @var string
	 */
	public $returnUrl;

	/**
	 * A method to return customers on $returnUrl.
	 *
	 * Right now (api v1) it is not much significant, since (according to their doc)
	 * you must support both GET and POST methods.
	 *
	 * @var string
	 */
	public $returnMethod = "POST";


	/**
	 * Name of your e-shop or app - it will be used on some points of
	 * creating payments.
	 *
	 * @var string
	 */
	public $shopName;

	/**
	 * Should payments be created with closePayment = true by default?
	 * See Wiki on ČSOB's github for more information.
	 *
	 * @var type
	 */
	public $closePayment = true;

	/**
	 * Create config with all mandatory values.
	 *
	 * See equally named properties of this class for more info.
	 *
	 * @param string $merchantId
	 * @param string $privateKeyFile
	 * @param string $bankPublicKeyFile
	 * @param string $shopName
	 * @param string $returnUrl
	 * @param string $bankApiUrl
	 * @param string $privateKeyPassword
	 */
	function __construct($merchantId, $privateKeyFile, $bankPublicKeyFile, $shopName, $returnUrl = null, $bankApiUrl = null, $privateKeyPassword = null) {
		if ($bankApiUrl) {
			$this->url = $bankApiUrl;
		}
		if ($privateKeyPassword) {
			$this->privateKeyPassword = $privateKeyPassword;
		}

		$this->merchantId = $merchantId;
		$this->privateKeyFile = $privateKeyFile;
		$this->bankPublicKeyFile = $bankPublicKeyFile;

		$this->returnUrl = $returnUrl;
		$this->shopName = $shopName;
	}


}
