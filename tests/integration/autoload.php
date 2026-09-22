<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

/**
 * Autoloader for the end-to-end suite's own classes.
 *
 * Shared by bootstrap-e2e.php and provision.php. No Joomla is loaded here, and none is needed: the
 * suite talks to the site over HTTP, to its database over PDO (fixtures only), and to the bucket over
 * MinIO's anonymous, read-only HTTP view — exactly as an outside observer would. Anything it could
 * only see by loading the site's code in-process is not something a real request could see either.
 */

defined('_JEXEC') || define('_JEXEC', 1);

spl_autoload_register(
	static function (string $class): void {
		$prefix = 'Akeeba\\Plugin\\Filesystem\\S3\\IntegrationTest\\';

		if (strncmp($class, $prefix, strlen($prefix)) !== 0)
		{
			return;
		}

		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

		if (is_file($file))
		{
			require_once $file;
		}
	}
);
