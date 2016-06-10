<?php

namespace OndraKoupil\Csob;

/**
 * Helper class for signing and signature verification
 *
 * @see https://github.com/csob/paymentgateway/blob/master/eshop-integration/eAPI/v1/php/example/crypto.php
 */
class Crypto {

	/**
	 * Currently used has algorithm
	 */
	const HASH_METHOD = \OPENSSL_ALGO_SHA1;

	/**
	 * Signs a string
	 *
	 * @param string $string
	 * @param string $privateKeyFile Path to file with your private key (the .key file from https://iplatebnibrana.csob.cz/keygen/ )
	 * @param string $privateKeyPassword Password to the key, if it was generated with one. Leave empty if you created the key at https://iplatebnibrana.csob.cz/keygen/
	 * @return string Signature encoded with Base64
	 * @throws CryptoException When signing fails or key file path is not valid
	 */
	static function signString($string, $privateKeyFile, $privateKeyPassword = "") {

		if (!function_exists("openssl_get_privatekey")) {
			throw new CryptoException("OpenSSL extension in PHP is required. Please install or enable it.");
		}

		if (!file_exists($privateKeyFile) or !is_readable($privateKeyFile)) {
			throw new CryptoException("Private key file \"$privateKeyFile\" not found or not readable.");
		}

		$keyAsString = file_get_contents($privateKeyFile);

		$privateKeyId = openssl_get_privatekey($keyAsString, $privateKeyPassword);
		if (!$privateKeyId) {
			throw new CryptoException("Private key could not be loaded from file \"$privateKeyFile\". Please make sure that the file contains valid private key in PEM format.");
		}

		$ok = openssl_sign($string, $signature, $privateKeyId, self::HASH_METHOD);
		if (!$ok) {
			throw new CryptoException("Signing failed.");
		}
		$signature = base64_encode ($signature);
		openssl_free_key ($privateKeyId);

		return $signature;
	}


	/**
	 * Verifies signature of a string
	 *
	 * @param string $textToVerify The text that was signed
	 * @param string $signatureInBase64 The signature encoded with Base64
	 * @param string $publicKeyFile Path to file where bank's public key is saved
	 * (you can obtain it from bank's app https://iposman.iplatebnibrana.csob.cz/posmerchant
	 * or from their package on GitHub)
	 * @return bool True if signature is correct
	 * @throws CryptoException When some cryptographic operation fails and key file path is not valid
	 */
	static function verifySignature($textToVerify, $signatureInBase64, $publicKeyFile) {

		if (!function_exists("openssl_get_privatekey")) {
			throw new CryptoException("OpenSSL extension in PHP is required. Please install or enable it.");
		}

		if (!file_exists($publicKeyFile) or !is_readable($publicKeyFile)) {
			throw new CryptoException("Public key file \"$publicKeyFile\" not found or not readable.");
		}

		$keyAsString = file_get_contents($publicKeyFile);
		$publicKeyId = openssl_get_publickey($keyAsString);

		$signature = base64_decode($signatureInBase64);

		$res = openssl_verify($textToVerify, $signature, $publicKeyId, self::HASH_METHOD);
		openssl_free_key($publicKeyId);

		if ($res == -1) {
			throw new CryptoException("Verification of signature failed: ".openssl_error_string());
		}

		return $res ? true : false;
	}

}
