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
	 *
	 * @see GatewayUrl
	 */
	public $url = GatewayUrl::TEST_LATEST;

	/**
	 * API Version. Version 1.8 brings some BC breaks, so the library needs to know which version you want to call.
	 * Use this property to explicitly specify API version. Leave null to autodetect from endpoint URL.
	 *
	 * @var string
	 */
	public $apiVersion = null;

	/**
	 * @var int|null One of OPENSSL_HASH_* constants or null for auto detection
	 */
	public $hashMethod = null;

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
	 * @var boolean
	 */
	public $closePayment = true;

	/**
	 * Path to a CA certificate chain or a directory containing certificates to verify
	 * bank's certificate when initiating a HTTPS connection.
	 *
	 * Leave null to disable certificate validation.
	 *
	 * @see CURLOPT_SSL_VERIFYPEER, CURLOPT_CAINFO, CURLOPT_CAPATH
	 *
	 * @var string
	 */
	public $sslCertificatePath = null;

	/**
	 * Force the client to use a specific SSL version.
	 *
	 * Leave null to use automatic selection (default).
	 *
	 * @var number Use one of CURL_SSLVERSION_* or CURL_SSLVERSION_MAX_* constants
	 *
	 * @see https://www.php.net/manual/en/function.curl-setopt.php
	 */
	public $sslVersion = null;

	/**
	 * Create config with all mandatory values.
	 *
	 * See equally named properties of this class for more info.
	 *
	 * To specify $bankApiUrl, you can use constants of GatewayUrl class.
	 *
	 * @param string $merchantId
	 * @param string $privateKeyFile
	 * @param string $bankPublicKeyFile
	 * @param string $shopName
	 * @param string|null $returnUrl
	 * @param string|null $bankApiUrl
	 * @param string|null $privateKeyPassword
	 * @param string|null $sslCertificatePath
	 * @param string|null $apiVersion Leave null to autodetect from $bankApiUrl
	 * @param int|null $hashMethod One of OPENSSL_HASH_* constants, leave null for auto detection from given $bankApiUrl. Read via getHashMethod();
	 */
	function __construct(
		$merchantId,
		$privateKeyFile,
		$bankPublicKeyFile,
		$shopName,
		$returnUrl = null,
		$bankApiUrl = null,
		$privateKeyPassword = null,
		$sslCertificatePath = null,
		$apiVersion = null,
		$hashMethod = null
	) {
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
		$this->sslCertificatePath = $sslCertificatePath;
		$this->hashMethod = $hashMethod;
		$this->apiVersion = $apiVersion;
	}

	function getVersion() {
		if (!$this->apiVersion) {
			if (!$this->url) {
				throw new Exception('You must specify bank API URL first.');
			}
			$match = preg_match('~\/api\/v([0-9.]+)$~', $this->url, $matches);
			if ($match) {
				$this->apiVersion = $matches[1];
			} else {
				throw new Exception('Can not deduce API version from URL: ' . $this->url);
			}
		}
		return $this->apiVersion;
	}

	/**
	 * Return the set hashing method or deduce it from bank API's version.
	 *
	 * @return int
	 */
	function getHashMethod() {
		if ($this->hashMethod) {
			return $this->hashMethod;
		}
		if ($this->queryApiVersion('1.8')) {
			return OPENSSL_ALGO_SHA256;
		} else {
			return OPENSSL_ALGO_SHA1;
		}
	}

	/**
	 * Returns true if currently set API version is at least $version or greater.
	 *
	 * @param string $version
	 *
	 * @return boolean
	 */
	function queryApiVersion($version) {
		return !!version_compare($this->getVersion(), $version, '>=');
	}

}
