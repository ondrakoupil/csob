<?php

namespace OndraKoupil\Csob\Extensions\EET;

use DateTime;

/**
 * Represents a result of payment/status call with EET extension activated.
 *
 * You will receive this object from responses, but won't ever need to create it manually.
 */
class EETReport {

	/**
	 * @var number stav nahlášení platby, viz životní cyklus tržby
	 * @see https://github.com/csob/paymentgateway/wiki/%C5%BDivotn%C3%AD-cyklus-tr%C5%BEby
	 */
	public $eetStatus;

	/**
	 * @var EETData
	 */
	public $data;

	/**
	 * @var boolean Příznak ověřovacího módu odesílání
	 */
	public $verificationMode;

	/**
	 * @var string DIČ poplatníka
	 */
	public $vatId;

	/**
	 * @var string Pořadové číslo účtenky, formát YYYYMMRXXXXXXXXXX, kde YYYY je rok, MM měsíc, R znak identifikující nahlášení platby, XXXXXXXXXX pořadové číslo účtenky, např. 201701R0000000004
	 */
	public $receiptNumber;

	/**
	 * @var DateTime|null Datum a čas přijetí tržby
	 */
	public $receiptTime;

	/**
	 * @var number Režim platby, platební brána podporuje pouze běžný režim, bude vrácena hodnota 0
	 */
	public $evidenceMode;

	/**
	 * @var string UUID datové zprávy evidované tržby
	 */
	public $uuid;

	/**
	 * @var DateTime|null Datum a čas odeslání zprávy z platební brány
	 */
	public $sendTime;

	/**
	 * @var DateTime|null Datum a čas přijetí zprávy na FS
	 */
	public $acceptTime;

	/**
	 * @var string Bezpečnostní kód poplatníka
	 */
	public $bkp;

	/**
	 * @var string Podpisový kód poplatníka
	 */
	public $pkp;

	/**
	 * @var string Fiskální identifikační kód
	 */
	public $fik;

	/**
	 * @var DateTime|null Datum a čas odmítnutí zprávy na FS
	 */
	public $rejectTime;

	/**
	 * @var EETError|null error zpracování na FS, viz popis objektu error
	 */
	public $error;

	/**
	 * @var EETWarning[] Seznam případných varování z FS, viz popis objektu warning
	 */
	public $warning = array();

	/**
	 * @var array
	 */
	public $rawData = array();

	/**
	 * Creates an EETStatus object from received data array
	 *
	 * @param array $array
	 *
	 * @return EETReport
	 */
	static public function fromArray($array) {

		$status = new EETReport();

		$status->rawData = $array;

		if (array_key_exists('eetStatus', $array)) {
			$status->eetStatus = $array['eetStatus'];
		}
		if (array_key_exists('data', $array)) {
			$status->data = EETData::fromArray($array['data']);
		}
		if (array_key_exists('verificationMode', $array)) {
			$status->verificationMode = $array['verificationMode'] ? true : false;
		}
		if (array_key_exists('vatId', $array)) {
			$status->vatId = $array['vatId'];
		}
		if (array_key_exists('receiptNumber', $array)) {
			$status->receiptNumber = $array['receiptNumber'];
		}
		if (array_key_exists('receiptTime', $array)) {
			$status->receiptTime = new DateTime($array['receiptTime']);
		}
		if (array_key_exists('evidenceMode', $array)) {
			$status->evidenceMode = $array['evidenceMode'];
		}
		if (array_key_exists('uuid', $array)) {
			$status->uuid = $array['uuid'];
		}
		if (array_key_exists('sendTime', $array)) {
			$status->sendTime = new DateTime($array['sendTime']);
		}
		if (array_key_exists('acceptTime', $array)) {
			$status->acceptTime = new DateTime($array['acceptTime']);
		}
		if (array_key_exists('bkp', $array)) {
			$status->bkp = $array['bkp'];
		}
		if (array_key_exists('pkp', $array)) {
			$status->pkp = $array['pkp'];
		}
		if (array_key_exists('fik', $array)) {
			$status->fik = $array['fik'];
		}
		if (array_key_exists('rejectTime', $array)) {
			$status->rejectTime = new DateTime($array['rejectTime']);
		}

		if (array_key_exists('error', $array) and $array['error']) {
			$status->error = new EETError($array['error']['code'], $array['error']['desc']);
		}

		if (array_key_exists('warning', $array) and is_array($array['warning'])) {
			foreach ($array['warning'] as $warningData) {
				$status->warning[] = new EETWarning($warningData['code'], $warningData['desc']);
			}
		}

		return $status;

	}

	/**
	 * @inheritdoc
	 *
	 * @return string
	 */
	public function getSignatureBase() {
		$fields = array();

		if ($this->eetStatus !== null) {
			$fields[] = $this->eetStatus;
		}
		if ($this->data) {
			$fields[] = $this->data->getSignatureBase();
		}
		if ($this->verificationMode !== null) {
			$fields[] = $this->verificationMode ? 'true' : 'false';
		}
		if ($this->vatId) {
			$fields[] = $this->vatId;
		}
		if ($this->receiptNumber) {
			$fields[] = $this->receiptNumber;
		}
		if ($this->receiptTime) {
			if (isset($this->rawData['receiptTime']) and $this->rawData['receiptTime']) {
				$fields[] = $this->rawData['receiptTime'];
			} else {
				$fields[] = self::formatTime($this->receiptTime);
			}
		}
		if ($this->evidenceMode !== null) {
			$fields[] = $this->evidenceMode;
		}
		if ($this->uuid !== null) {
			$fields[] = $this->uuid;
		}
		if ($this->sendTime) {
			if (isset($this->rawData['sendTime']) and $this->rawData['sendTime']) {
				$fields[] = $this->rawData['sendTime'];
			} else {
				$fields[] = self::formatTime($this->sendTime);
			}
		}
		if ($this->acceptTime) {
			if (isset($this->rawData['acceptTime']) and $this->rawData['acceptTime']) {
				$fields[] = $this->rawData['acceptTime'];
			} else {
				$fields[] = self::formatTime($this->acceptTime);
			}
		}
		if ($this->bkp) {
			$fields[] = $this->bkp;
		}
		if ($this->pkp) {
			$fields[] = $this->pkp;
		}
		if ($this->fik) {
			$fields[] = $this->fik;
		}
		if ($this->rejectTime) {
			if (isset($this->rawData['rejectTime']) and $this->rawData['rejectTime']) {
				$fields[] = $this->rawData['rejectTime'];
			} else {
				$fields[] = self::formatTime($this->rejectTime);
			}
		}
		if ($this->error) {
			$fields[] = $this->error->getSignatureBase();
		}
		if ($this->warning) {
			foreach ($this->warning as $w) {
				$fields[] = $w->getSignatureBase();
			}
		}

		return implode('|', $fields);
	}

	/**
	 * Formats DateTime to format used in API
	 *
	 * @param DateTime $dt
	 *
	 * @return string
	 */
	static function formatTime(DateTime $dt) {
		return $dt->format('c');
	}

}
