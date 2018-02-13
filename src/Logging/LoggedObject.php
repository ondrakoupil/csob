<?php

namespace OndraKoupil\Csob\Logging;

/**
 * Abstract class from request/response objects for logging.
 */
class LoggedObject {

	/**
	 * Return self as array
	 *
	 * @return array
	 */
	function toArray() {
		return get_object_vars($this);
	}

}
