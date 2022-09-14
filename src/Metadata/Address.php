<?php

namespace OndraKoupil\Csob\Metadata;

use OndraKoupil\Csob\Tools;
use OndraKoupil\Tools\Strings;

class Address {

	/**
	 * @var string
	 */
	public $address1;

	/**
	 * @var string
	 */
	public $address2;

	/**
	 * @var string
	 */
	public $address3;

	/**
	 * @var string
	 */
	public $city;

	/**
	 * @var string
	 */
	public $zip;

	/**
	 * @var string
	 */
	public $state;

	/**
	 * @var string
	 */
	public $country;

	/**
	 * @param string $address1
	 * @param string $city
	 * @param string $zip
	 * @param string $country
	 */
	public function __construct($address1, $city, $zip, $country) {
		$this->address1 = $address1;
		$this->city = $city;
		$this->zip = $zip;
		$this->country = $country;
	}

	public function export() {
		$a = array(
			'address1' => Strings::shorten($this->address1, 50, '', true, true),
			'address2' => Strings::shorten($this->address2, 50, '', true, true),
			'address3' => Strings::shorten($this->address3, 50, '', true, true),
			'city' => Strings::shorten($this->city, 50, '', true, true),
			'zip' => Strings::shorten($this->zip, 16, '', true, true),
			'state' => trim($this->state),
			'country' => trim($this->country),
		);

		return Tools::filterOutEmptyFields($a);
	}


}
