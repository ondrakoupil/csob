<?php

namespace OndraKoupil\Csob;

use \OndraKoupil\Tools\Strings;
use \OndraKoupil\Tools\Arrays;


class Payment {

	public $merchantId;
	public $orderNo;
	public $totalAmount;
	public $currency;
	public $closePayment;

	public $returnUrl;
	public $returnMethod;

	protected $cart = array();

	public $description;
	protected $merchantData;
	public $customerId;
	public $language;

	public $dttm;
	public $payOperation;
	public $payMethod;

	protected $foreignId;

	private $fieldsInOrder = array(
		"merchantId",
		"orderNo",
		"dttm",
		"payOperation",
		"payMethod",
		"totalAmount",
		"currency",
		"closePayment",
		"returnUrl",
		"returnMethod",
		"cart",
		"description",
		"merchantData",
		"customerId",
		"language"
	);


	function __construct($orderNo) {
		$this->orderNo = $orderNo;
	}

	function addCartItem($name, $quantity, $totalAmount, $description = "") {

		if (count($this->cart) >= 2) {
			throw new \RuntimeException("This version of banks's API supports only up to 2 cart items in single payment, you can't add any more items.");
		}

		if (!is_numeric($quantity) or $quantity < 1) {
			throw new \InvalidArgumentException("Invalid quantity: $quantity. It must be numeric and >= 1");
		}

		$name = Strings::shorten($name, 20, "", true, true);
		$description = Strings::shorten($description, 40, "");

		$this->cart[] = array(
			"name" => $name,
			"quantity" => $quantity,
			"amount" => $totalAmount,
			"description" => $description
		);

		return $this;
	}

	public function setMerchantData($data, $alreadyEncoded = false) {
		if (!$alreadyEncoded) {
			$data = base64_encode($data);
		}
		if (strlen($data) > 255) {
			throw new \InvalidArgumentException("Merchant data can not be longer than 255 characters after base64 encoding.");
		}
		$this->merchantData = $data;
		return $this;
	}

	public function getBankId() {
		return $this->foreignId;
	}

	public function setBankId($id) {
		$this->foreignId = $id;
	}

	function checkAndPrepare(Config $config) {
		if (!$this->merchantId) {
			$this->merchantId = $config->merchantId;
		}

		$this->dttm = date(Client::DATE_FORMAT);

		if (!$this->payOperation) {
			$this->payOperation = "payment";
		}

		if (!$this->payMethod) {
			$this->payMethod = "card";
		}

		if (!$this->currency) {
			$this->currency = "CZK";
		}

		if (!$this->language) {
			$this->language = "CZ";
		}

		if ($this->closePayment === null) {
			$this->closePayment = true;
		}

		if (!$this->returnUrl) {
			$this->returnUrl = $config->returnUrl;
		}
		if (!$this->returnUrl) {
			throw new \RuntimeException("A ReturnUrl must be set - either by setting \$returnUrl property, or by specifying it in Config.");
		}

		if (!$this->returnMethod) {
			$this->returnMethod = $config->returnMethod;
		}

		if (!$this->description) {
			$this->description = $config->shopName.", ".$this->orderNo;
		}
		$this->description = Strings::shorten($this->description, 240, "...");

		if (!$this->cart) {
			throw new \RuntimeException("Cart is empty. Please add one or two items into cart using addCartItem() method.");
		}

		if (!$this->orderNo or !preg_match('~^[0-9]{1,10}$~', $this->orderNo)) {
			throw new \RuntimeException("Invalid orderNo - it must be a non-empty numeric value, 10 characters max.");
		}

		if (!$this->totalAmount) {
			$sumOfItems = array_sum(Arrays::transform($this->cart, true, "amount"));
			$this->totalAmount = $sumOfItems;
		}

		if (!is_numeric($this->totalAmount)) {
			throw new \RuntimeException("Invalid totalAmount - it must be a non-empty numeric value.");
		}

	}

	function signAndExport(Config $config) {
		$arr = array();

		foreach($this->fieldsInOrder as $f) {
			$val = $this->$f;
			if ($val === null) {
				$val = "";
			}
			$arr[$f] = $val;
		}

		$stringToSign = $this->getSignatureString();

		$signed = Crypto::signString($stringToSign, $config->privateKeyFile, $config->privateKeyPassword);
		$arr["signature"] = $signed;

		return $arr;
	}

	function getSignatureString() {
		$parts = array();

		foreach($this->fieldsInOrder as $f) {
			$val = $this->$f;
			if ($val === null) {
				$val = "";
			}
			elseif (is_bool($val)) {
				if ($val) {
					$val = "true";
				} else {
					$val = "false";
				}
			} elseif (is_array($val)) {
				// There are never more than 2 levels, we don't need recursive walk
				$valParts = array();
				foreach($val as $v) {
					if (is_scalar($v)) {
						$valParts[] = $v;
					} else {
						$valParts[] = implode("|", $v);
					}
				}
				$val = implode("|", $valParts);
			}
			$parts[] = $val;
		}

		return implode("|", $parts);
	}


}
