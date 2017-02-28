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

	/**
	 * Vytvoří z array (i víceúrovňového) string pro výpočet podpisu.
	 *
	 * @param array $array
	 * @param bool $returnAsArray
	 *
	 * @return string|array
	 */
	static function createSignatureBaseFromArray($array, $returnAsArray = false) {
		$linearizedArray = self::createSignatureBaseRecursion($array);
		if ($returnAsArray) {
			return $linearizedArray;
		}
		return implode('|', $linearizedArray);
	}

	protected static function createSignatureBaseRecursion($array, $depthCheck = 0) {
		if ($depthCheck > 10) {
			return array();
		}
		$ret = array();
		foreach ($array as $val) {
			if (is_array($val)) {
				$ret = array_merge(
					$ret,
					self::createSignatureBaseRecursion($val, $depthCheck + 1)
				);
			} else {
				$ret[] = $val;
			}
		}
		return $ret;
	}

	/**
	 * Generická implementace linearizace pole s dopředu zadaným požadovaným pořadím.
	 *
	 * V $order by mělo být požadované pořadí položek formou stringových "keypath".
	 * Keypath je název klíče v poli $data, pokud je víceúrovňové, klíče jsou spojeny tečkou.
	 *
	 * Pokud keypath začíná znakem otazník, považuje se za nepovinnou a není-li taková
	 * položka nalezena, z výsledku se vynechá. V opačném případě se vloží prázdný řetězec.
	 *
	 * Pokud keypath odkazuje na další array, to se vloží postupně položka po položce.
	 *
	 * Příklad:
	 *
	 * ```php
	 * $data = array(
	 *    'foo' => 'bar',
	 *    'arr' => array(
	 *        'a' => 'A',
	 *        'b' => 'B'
	 *    )
	 * );
	 *
	 * $order = array(
	 *    'foo',
	 *    'arr.a',
	 *    'somethingRequired',
	 *    '?somethingOptional',
	 *    'foo',
	 *    'arr.x',
	 *    'foo',
	 *    'arr'
	 * );
	 *
	 * $result = Crypto::createSignatureBaseWithOrder($data, $order, false);
	 *
	 * $result == array('bar', 'A', '', 'bar', '', 'bar', 'A', 'B');
	 * ```
	 *
	 * @param array $data Pole s daty
	 * @param array $order Požadované pořadí položek.
	 * @param bool $returnAsArray
	 *
	 * @return array
	 */
	static function createSignatureBaseWithOrder($data, $order, $returnAsArray = false) {

		$result = array();

		foreach ($order as $key) {
			$optional = false;
			if ($key[0] == '?') {
				$optional = true;
				$key = substr($key, 1);
			}
			$keyPath = explode('.', $key);

			$pos = $data;
			$found = true;
			foreach ($keyPath as $keyPathComponent) {
				if (array_key_exists($keyPathComponent, $pos)) {
					$pos = $pos[$keyPathComponent];
				} else {
					$found = false;
					break;
				}
			}

			if ($found) {
				if (is_array($pos)) {
					$result = array_merge($result, self::createSignatureBaseFromArray($pos, true));
				} else {
					$result[] = $pos;
				}
			} else {
				if (!$optional) {
					$result[] = '';
				}
			}
		}

		if ($returnAsArray) {
			return $result;
		}

		return implode('|', $result);

	}

}
