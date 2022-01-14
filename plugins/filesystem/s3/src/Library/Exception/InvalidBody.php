<?php
/**
 * Akeeba Engine
 *
 * @package   akeebaengine
 * @copyright Copyright (c)2006-2022 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Joomla\Plugin\Filesystem\S3\Library\Exception;

// Protection against direct access
defined('_JEXEC') or die();

use Exception;
use RuntimeException;

/**
 * Invalid response body type
 */
class InvalidBody extends RuntimeException
{
	public function __construct(string $message = "", int $code = 0, Exception $previous = null)
	{
		if (empty($message))
		{
			$message = 'Invalid response body type';
		}

		parent::__construct($message, $code, $previous);
	}

}
