<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright © 2022 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\Filesystem\S3;

defined('_JEXEC') or die;

/**
 * XML form filters for the plugin's options
 *
 * @since       1.0.0
 */
final class Filter
{
	/**
	 * Filters the directory argument in a connection definition.
	 *
	 * @param   string|null  $directory
	 *
	 * @return  string
	 *
	 * @since   1.0.0
	 */
	public static function filterDirectory(?string $directory): string
	{
		if (empty($directory))
		{
			return '';
		}

		$directory = str_replace('\\', '/', trim($directory, '/\\'));

		while (strpos($directory, '//') !== false)
		{
			$directory = str_replace('//', '/', $directory);
		}

		return trim($directory, '/');
	}
}