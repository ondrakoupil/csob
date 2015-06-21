<?php

namespace OndraKoupil\Csob;

class Config {

	public $url = "https://iapi.iplatebnibrana.csob.cz/api/v1";
	public $publicKeyFile = "";
	public $merchantId = "";
	public $privateKeyFile = "";
	public $privateKeyPassword = null;
	public $bankPublicKeyFile;

	public $returnUrl;
	public $returnMethod = "POST";

	public $shopName;

	function __construct($merchantId, $publicKeyFile, $privateKeyFile, $shopName, $bankPublicKeyFile, $returnUrl = null, $bankApiUrl = null, $privateKeyPassword = null) {
		if ($bankApiUrl) {
			$this->url = $bankApiUrl;
		}
		if ($privateKeyPassword) {
			$this->privateKeyPassword = $privateKeyPassword;
		}

		$this->publicKeyFile = $publicKeyFile;
		$this->merchantId = $merchantId;
		$this->privateKeyFile = $privateKeyFile;
		$this->bankPublicKeyFile = $bankPublicKeyFile;

		$this->returnUrl = $returnUrl;
		$this->shopName = $shopName;
	}


}
