<?php

namespace OndraKoupil\Csob;

/**
 * @see https://github.com/csob/paymentgateway/blob/master/eshop-integration/eAPI/v1/php/example/crypto.php
 */

class Crypto {

	/**
	 * Vytvoří podpis řetězce
	 * @param string $string
	 * @param string $privateKeyFile
	 * @param string $privateKeyPassword
	 * @return string Podpis zakódovaný do base64
	 * @throws \RuntimeException Když selže proces podepisování
	 * @throws \InvalidArgumentException Když soubor s klíčem není nalezen
	 */
	static function signString($string, $privateKeyFile, $privateKeyPassword) {

		if (!function_exists("openssl_get_privatekey")) {
			throw new \RuntimeException("OpenSSL extension in PHP is required. Please install or enable it.");
		}

		if (!file_exists($privateKeyFile) or !is_readable($privateKeyFile)) {
			throw new \InvalidArgumentException("Private key file \"$privateKeyFile\" not found or not readable.");
		}

		$keyAsString = file_get_contents($privateKeyFile);

		$privateKeyId = openssl_get_privatekey($keyAsString, $privateKeyPassword);
		if (!$privateKeyId) {
			throw new \RuntimeException("Private key could not be loaded from file \"$privateKeyFile\". Please make sure that the file contains valid private key in PEM format.");
		}

		$ok = openssl_sign($string, $signature, $privateKeyId);
		if (!$ok) {
			throw new \RuntimeException("Signing failed.");
		}
		$signature = base64_encode ($signature);
		openssl_free_key ($privateKeyId);

		return $signature;
	}


	static function verifySignature($textToVerify, $signatureInBase64, $publicKeyFile) {

		if (!function_exists("openssl_get_privatekey")) {
			throw new \RuntimeException("OpenSSL extension in PHP is required. Please install or enable it.");
		}

		if (!file_exists($publicKeyFile) or !is_readable($publicKeyFile)) {
			throw new \InvalidArgumentException("Public key file \"$publicKeyFile\" not found or not readable.");
		}

		$keyAsString = file_get_contents($publicKeyFile);
		$publicKeyId = openssl_get_publickey($keyAsString);

		$signature = base64_decode($signatureInBase64);

		$res = openssl_verify($textToVerify, $signature, $publicKeyId);
		openssl_free_key($publicKeyId);

		if ($res == -1) {
			throw new \RuntimeException("Verification of signature failed: ".openssl_error_string());
		}

		return $res ? true : false;
	}

}
