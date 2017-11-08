<?php

namespace OndraKoupil\Csob\Extensions\EET;

/**
 * Data set for the EET extension.
 *
 * You need to create this object when calling paymentInit.
 *
 * Then, you will receive this object in responses of other methods.
 *
 * When creating the object, remember that $totalPrice is in CZK, not halers (as is in Payment object)
 *
 * See https://github.com/csob/paymentgateway/wiki/Specifikace-API-roz%C5%A1%C3%AD%C5%99en%C3%AD-pro-EET
 * for details on meaning of each property.
 */
class EETData {



	/**
	 * @var number Označení provozovny
	 */
	public $premiseId;

	/**
	 * @var string Označení pokladního zařízení poplatníka
	 */
	public $cashRegisterId;

	/**
	 * @var number Celková částka tržby, v případě nahlášení platby (volání v rámci payment/init, payment/oneclick/init a payment/close) musí být kladné číslo, v případě odhlášení platby (volání v rámci payment/refund) musí být záporné číslo.
	 */
	public $totalPrice;

	/**
	 * @var string DIČ pověřujícího poplatníka
	 */
	public $delegatedVatId;

	/**
	 * @var number Celková částka plnění osvobozených od DPH, ostatních plnění
	 */
	public $priceZeroVat;

	/**
	 * @var number Celkový základ daně se základní sazbou DPH
	 */
	public $priceStandardVat;

	/**
	 * @var number Celková DPH se základní sazbou
	 */
	public $vatStandard;

	/**
	 * @var number Celkový základ daně s první sníženou sazbou DPH
	 */
	public $priceFirstReducedVat;

	/**
	 * @var number Celková DPH s první sníženou sazbou
	 */
	public $vatFirstReduced;

	/**
	 * @var number Celkový základ daně s druhou sníženou sazbou DPH
	 */
	public $priceSecondReducedVat;

	/**
	 * @var number Celková DPH s druhou sníženou sazbou
	 */
	public $vatSecondReduced;

	/**
	 * @var number Celková částka v režimu DPH pro cestovní službu
	 */
	public $priceTravelService;

	/**
	 * @var number Celková částka v režimu DPH pro prodej použitého zboží se základní sazbou
	 */
	public $priceUsedGoodsStandardVat;

	/**
	 * @var number Celková částka v režimu DPH pro prodej použitého zboží s první sníženou sazbou
	 */
	public $priceUsedGoodsFirstReduced;

	/**
	 * @var number Celková částka v režimu DPH pro prodej použitého zboží s druhou sníženou sazbou
	 */
	public $priceUsedGoodsSecondReduced;

	/**
	 * @var number Celková částka plateb určená k následnému čerpání nebo zúčtování
	 */
	public $priceSubsequentSettlement;

	/**
	 * @var number Celková částka plateb, které jsou následným čerpáním nebo zúčtováním platby
	 */
	public $priceUsedSubsequentSettlement;

	/**
	 * @var array Contains raw data as were received from API. Only for responses.
	 */
	public $rawData;

	/**
	 * Constructor allows to set three mandatory properties
	 *
	 * @param number $premiseId
	 * @param string $cashRegisterId
	 * @param number $totalPrice
	 */
	public function __construct($premiseId = null, $cashRegisterId = null, $totalPrice = null) {
		$this->premiseId = $premiseId;
		$this->cashRegisterId = $cashRegisterId;
		$this->totalPrice = $totalPrice;
	}

	/**
	 * Export as array
	 *
	 * @return array
	 */
	public function asArray() {

		$a = array();

		$a['premiseId'] = +$this->premiseId;
		$a['cashRegisterId'] = $this->cashRegisterId;
		$a['totalPrice'] = self::formatPriceValue($this->totalPrice);

		if ($this->delegatedVatId) {
			$a['delegatedVatId'] = $this->delegatedVatId;
		}
		if ($this->priceZeroVat) {
			$a['priceZeroVat'] = self::formatPriceValue($this->priceZeroVat);
		}                
		if ($this->priceStandardVat) {
			$a['priceStandardVat'] = self::formatPriceValue($this->priceStandardVat);
		}
		if ($this->vatStandard) {
			$a['vatStandard'] = self::formatPriceValue($this->vatStandard);
		}
		if ($this->priceFirstReducedVat) {
			$a['priceFirstReducedVat'] = self::formatPriceValue($this->priceFirstReducedVat);
		}
		if ($this->vatFirstReduced) {
			$a['vatFirstReduced'] = self::formatPriceValue($this->vatFirstReduced);
		}
		if ($this->priceSecondReducedVat) {
			$a['priceSecondReducedVat'] = self::formatPriceValue($this->priceSecondReducedVat);
		}
		if ($this->vatSecondReduced) {
			$a['vatSecondReduced'] = self::formatPriceValue($this->vatSecondReduced);
		}
		if ($this->priceTravelService) {
			$a['priceTravelService'] = self::formatPriceValue($this->priceTravelService);
		}
		if ($this->priceUsedGoodsStandardVat) {
			$a['priceUsedGoodsStandardVat'] = self::formatPriceValue($this->priceUsedGoodsStandardVat);
		}
		if ($this->priceUsedGoodsFirstReduced) {
			$a['priceUsedGoodsFirstReduced'] = self::formatPriceValue($this->priceUsedGoodsFirstReduced);
		}
		if ($this->priceUsedGoodsSecondReduced) {
			$a['priceUsedGoodsSecondReduced'] = self::formatPriceValue($this->priceUsedGoodsSecondReduced);
		}
		if ($this->priceSubsequentSettlement) {
			$a['priceSubsequentSettlement'] = self::formatPriceValue($this->priceSubsequentSettlement);
		}
		if ($this->priceUsedSubsequentSettlement) {
			$a['priceUsedSubsequentSettlement'] = self::formatPriceValue($this->priceUsedSubsequentSettlement);
		}

		return $a;
	}


	/**
	 * Format a numeric price for use in EET extension
	 *
	 * @param number $price
	 *
	 * @return number
	 */
	static function formatPriceValue($price) {
		return number_format($price, 2, '.', '');
	}

	static protected $keyNames = array(
		'premiseId',
		'cashRegisterId',
		'totalPrice',
		'delegatedVatId',
		'priceZeroVat',
		'priceStandardVat',
		'vatStandard',
		'priceFirstReducedVat',
		'vatFirstReduced',
		'priceSecondReducedVat',
		'vatSecondReduced',
		'priceTravelService',
		'priceUsedGoodsStandardVat',
		'priceUsedGoodsFirstReduced',
		'priceUsedGoodsSecondReduced',
		'priceSubsequentSettlement',
		'priceUsedSubsequentSettlement',
	);

	/**
	 * Creates EETData object from array received from API
	 *
	 * @param $array
	 *
	 * @return EETData
	 */
	static function fromArray($array) {

		$data = new EETData();

		foreach (self::$keyNames as $key) {
			if (array_key_exists($key, $array)) {
				$data->$key = $array[$key];
			}
		}

		$data->rawData = $array;

		return $data;

	}

	/**
	 * Return part of the string required for building the signature string.
	 *
	 * @return string
	 */
	public function getSignatureBase() {
		$array = $this->asArray();
		return implode('|', $this->asArray());
	}


}
