<?php

namespace OndraKoupil\Csob\Logging;

/**
 * Contains various useful information about a request sent to API
 */
class Request extends LoggedObject {

	/**
	 * @var string HTTP method that was used
	 */
	public $httpMethod;

	/**
	 * @var string Name of bank's API method
	 */
	public $apiMethod;

	/**
	 * @var string URL that was called
	 */
	public $url;

	/**
	 * @var string Request payload as PHP variables
	 */
	public $payload;

	/**
	 * @var string Request payload as raw value
	 */
	public $encodedPayload;

	/**
	 * @var string Base for request's signature
	 */
	public $signatureBase;

	/**
	 * @var number Request number
	 */
	public $requestNumber;

	/**
	 * @var bool
	 */
	public $successfullySent;

	/**
	 * @var number
	 */
	public $sendingErrorCode;

	/**
	 * @var string
	 */
	public $sendingErrorText;

}
