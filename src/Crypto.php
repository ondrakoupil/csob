<?php

namespace OndraKoupil\Csob;

/**
 * Helper class for signing and signature verification
 *
 * @see https://github.com/csob/paymentgateway/blob/master/eshop-integration/eAPI/v1/php/example/crypto.php
 */
class Crypto {

	const DEFAULT_HASH_METHOD = OPENSSL_ALGO_SHA1;

	const HASH_SHA1 = OPENSSL_ALGO_SHA1;
	const HASH_SHA256 = OPENSSL_ALGO_SHA256;

	/**
	 * Signs a string
	 *
	 * @param string $string
	 * @param KeyProvider $privateKeyProvider Path to file with your private key (the .key file from https://iplatebnibrana.csob.cz/keygen/ )
	 * @param string $privateKeyPassword Password to the key, if it was generated with one. Leave empty if you created the key at https://iplatebnibrana.csob.cz/keygen/
	 * @param int $hashMethod One of OPENSSL_HASH_* constants
	 * @return string Signature encoded with Base64
	 * @throws CryptoException When signing fails or key file path is not valid
	 */
	static function signString($string, $privateKeyProvider, $privateKeyPassword = "", $hashMethod = self::DEFAULT_HASH_METHOD) {

		if (!function_exists("openssl_get_privatekey")) {
			throw new CryptoException("OpenSSL extension in PHP is required. Please install or enable it.");
		}

		$keyAsString = $privateKeyProvider->getKey();

		$privateKeyId = openssl_get_privatekey($keyAsString, $privateKeyPassword);
		if (!$privateKeyId) {
			throw new CryptoException("Private key could not be loaded. Please make sure that the key provider {$privateKeyProvider->__toString()} contains valid private key in PEM format.");
		}

		$ok = openssl_sign($string, $signature, $privateKeyId, $hashMethod);
		if (!$ok) {
			throw new CryptoException("Signing failed.");
		}
		$signature = base64_encode ($signature);
		if (version_compare(PHP_VERSION, '8.0', '<')) {
			// https://github.com/ondrakoupil/csob/issues/33
			openssl_free_key ($privateKeyId);
		}

		return $signature;
	}


	/**
	 * Verifies signature of a string
	 *
	 * @param string $textToVerify The text that was signed
	 * @param string $signatureInBase64 The signature encoded with Base64
	 * @param KeyProvider $publicKeyProvider Provider of bank's public key
	 * (you can obtain it from bank's app https://iposman.iplatebnibrana.csob.cz/posmerchant
	 * or from their package on GitHub)
	 * @param int $hashMethod One of OPENSSL_HASH_* constants
	 *
	 * @return bool True if signature is correct
	 */
	static function verifySignature($textToVerify, $signatureInBase64, $publicKeyProvider, $hashMethod = self::DEFAULT_HASH_METHOD) {

		if (!function_exists("openssl_get_privatekey")) {
			throw new CryptoException("OpenSSL extension in PHP is required. Please install or enable it.");
		}

		$keyAsString = $publicKeyProvider->getKey();
		$publicKeyId = openssl_get_publickey($keyAsString);

		$signature = base64_decode($signatureInBase64);

		$res = openssl_verify($textToVerify, $signature, $publicKeyId, $hashMethod);
		if (version_compare(PHP_VERSION, '8.0', '<')) {
			// https://github.com/ondrakoupil/csob/issues/33
			openssl_free_key($publicKeyId);
		}

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
			} elseif (is_bool($val)) {
				$ret[] = $val ? 'true' : 'false';
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
				// NULLs are not included in signature as well
				if (array_key_exists($keyPathComponent, $pos) and $pos[$keyPathComponent] !== null) {
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
					if (is_bool($pos)) {
						$result[] = $pos ? 'true' : 'false';
					} else {
						$result[] = $pos;
					}
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
