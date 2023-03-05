<?php

namespace OndraKoupil\Csob;

class Tools {

	public static function linearizeForSigning($input) {
		if ($input === null) {
			return '';
		}
		if (is_bool($input)) {
			return $input ? 'true' : 'false';
		}
		if (is_array($input)) {
			$parts = array();
			foreach ($input as $inputItem) {
				$parts[] = self::linearizeForSigning($inputItem);
			}

			return implode('|', $parts);
		}

		return $input;
	}

	public static function filterOutEmptyFields(array $input) {
		return array_filter(
			$input,
			function($value) {
				return ($value !== null and $value !== '');
			}
		);
	}

}
