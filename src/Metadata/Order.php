<?php

namespace OndraKoupil\Csob\Metadata;

use DateTime;
use OndraKoupil\Csob\Tools;
use OndraKoupil\Tools\Strings;

class Order {

	const TYPE_PURCHASE = 'purchase';
	const TYPE_BALANCE = 'balance';
	const TYPE_PREPAID = 'prepaid';
	const TYPE_CASH = 'cash';
	const TYPE_CHECK = 'check';

	const AVAILABILITY_NOW = 'now';
	const AVAILABILITY_PREORDER = 'preorder';

	const DELIVERY_SHIPPING = 'shipping';
	const DELIVERY_SHIPPING_VERIFIED = 'shipping_verified';
	const DELIVERY_INSTORE = 'instore';
	const DELIVERY_DIGITAL = 'digital';
	const DELIVERY_TICKET = 'ticket';
	const DELIVERY_OTHER = 'other';

	const DELIVERY_MODE_ELECTRONIC = 0;
	const DELIVERY_MODE_SAME_DAY = 1;
	const DELIVERY_MODE_NEXT_DAY = 2;
	const DELIVERY_MODE_LATER = 3;

	/**
	 * @var string
	 */
	public $type = '';

	/**
	 * @var string
	 */
	public $availability = '';

	/**
	 * @var string
	 */
	public $delivery = '';

	/**
	 * @var int
	 */
	public $deliveryMode = 0;

	/**
	 * @var string
	 */
	public $deliveryEmail = '';

	/**
	 * @var bool
	 */
	public $nameMatch;

	/**
	 * @var bool
	 */
	public $addressMatch;

	/**
	 * @var Address
	 */
	protected $billing;

	/**
	 * @var Address
	 */
	protected $shipping;

	/**
	 * @var DateTime
	 */
	protected $shippingAddedAt;


	/**
	 * @var bool
	 */
	public $reorder;

	/**
	 * @var GiftCards
	 */
	protected $giftCards;

	/**
	 * @return Address
	 */
	public function getBilling() {
		return $this->billing;
	}

	/**
	 * @param Address $billing
	 *
	 * @return Order
	 */
	public function setBilling($billing) {
		$this->billing = $billing;

		return $this;
	}

	/**
	 * @return Address
	 */
	public function getShipping() {
		return $this->shipping;
	}

	/**
	 * @param Address $shipping
	 *
	 * @return Order
	 */
	public function setShipping($shipping) {
		$this->shipping = $shipping;

		return $this;
	}

	/**
	 * @return DateTime
	 */
	public function getShippingAddedAt() {
		return $this->shippingAddedAt;
	}

	/**
	 * @param DateTime $shippingAddedAt
	 *
	 * @return Order
	 */
	public function setShippingAddedAt($shippingAddedAt) {
		$this->shippingAddedAt = $shippingAddedAt;

		return $this;
	}

	/**
	 * @return GiftCards
	 */
	public function getGiftCards() {
		return $this->giftCards;
	}

	/**
	 * @param GiftCards $giftCards
	 *
	 * @return Order
	 */
	public function setGiftCards($giftCards) {
		$this->giftCards = $giftCards;
		return $this;
	}

	public function export() {
		$a = array(
			'type' => trim($this->type),
			'availability' => trim($this->availability),
			'delivery' => trim($this->delivery),
			'deliveryMode' => +$this->deliveryMode,
			'deliveryEmail' => Strings::shorten($this->deliveryEmail, 100, '', true, true),
			'nameMatch' => !!$this->nameMatch,
			'addressMatch' => !!$this->addressMatch,
			'billing' => $this->billing ? $this->billing->export() : null,
			'shipping' => $this->shipping ? $this->shipping->export() : null,
			'shippingAddedAt' => $this->shippingAddedAt ? $this->shippingAddedAt->format('c') : null,
			'reorder' => !!$this->reorder,
			'giftcards' => $this->giftCards ? $this->giftCards->export() : null,
		);

		return Tools::filterOutEmptyFields($a);
	}


}
