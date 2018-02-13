<?php

namespace OndraKoupil\Csob\Logging;

/**
 * Contains various useful information about a response received from API
 */
class Response extends LoggedObject {

	/**
	 * @var number Number of corresponding request
	 */
	public $requestNumber;

	/**
	 * @var string HTTP status that API returned
	 */
	public $httpStatus;

	/**
	 * @var string Raw returned value
	 */
	public $rawResponse;

	/**
	 * @var array Parsed returned value
	 */
	public $response;

	/**
	 * @var bool Was signature correctly verified?
	 */
	public $signatureCorrect;

	/**
	 * @var string Expected signature
	 */
	public $expectedSignature;

	/**
	 * @var string Base of the expected signature
	 */
	public $expectedSignatureBase;

	/**
	 * @var string Result code that API returned
	 */
	public $apiResultCode;

}
