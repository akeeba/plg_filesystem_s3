<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

/**
 * Bootstrap for the unit test suite.
 *
 * The suite boots no Joomla, opens no network connection and talks to no S3 server. It registers a
 * PSR-4 autoloader for the plugin's namespace, loads the plugin's own Composer autoloader (akeeba/s3
 * and league/mime-type-detection are runtime dependencies the adapter cannot be constructed without),
 * and a small set of guarded Joomla symbol stubs.
 *
 * The plugin's vendor folder is not tracked in git: run `composer install` once before the first run.
 *
 * @see UnitTest/Stubs/joomla-stubs.php for what is stubbed, and why the list is deliberately short.
 */

// Required by every plugin file, each of which guards against direct web access.
define('_JEXEC', 1);

// Required by every akeeba/s3 file.
define('AKEEBAENGINE', 1);

$repositoryRoot = \dirname(__DIR__);
$pluginRoot     = $repositoryRoot . '/plugins/filesystem/s3';

/**
 * A disposable, per-run fake site root.
 *
 * Preview creates JPATH_ROOT/media/plg_filesystem_s3/cache when local thumbnail caching is enabled.
 * That must never land in the checkout.
 */
$fakeSiteRoot = sys_get_temp_dir() . '/plg_filesystem_s3-unittest-' . getmypid();

define('JPATH_ROOT', $fakeSiteRoot);
define('JPATH_BASE', $fakeSiteRoot);
define('JPATH_SITE', $fakeSiteRoot);
define('JPATH_CACHE', $fakeSiteRoot . '/cache');

// Where the repository lives, for the structural tests that read the shipped source tree.
define('S3FS_UNITTEST_REPO', $repositoryRoot);

@mkdir(JPATH_CACHE, 0777, true);

register_shutdown_function(
	static function () use ($fakeSiteRoot): void {
		if (!is_dir($fakeSiteRoot))
		{
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($fakeSiteRoot, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $file)
		{
			$file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
		}

		@rmdir($fakeSiteRoot);
	}
);

if (!is_file($pluginRoot . '/vendor/autoload.php'))
{
	fwrite(STDERR, "The plugin's Composer dependencies are missing. Run `composer install` in the repository root first.\n");

	exit(1);
}

require_once $pluginRoot . '/vendor/autoload.php';

spl_autoload_register(
	static function (string $class) use ($repositoryRoot, $pluginRoot): void {
		static $prefixes = null;

		$prefixes ??= [
			'Akeeba\\Plugin\\Filesystem\\S3\\UnitTest\\' => $repositoryRoot . '/UnitTest',
			'Akeeba\\Plugin\\Filesystem\\S3\\'           => $pluginRoot . '/src',
		];

		foreach ($prefixes as $prefix => $directory)
		{
			if (strncmp($class, $prefix, \strlen($prefix)) !== 0)
			{
				continue;
			}

			$file = $directory . '/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

			if (is_file($file))
			{
				require_once $file;
			}

			return;
		}
	}
);

// Minimal Joomla symbol stubs, so plugin classes that extend or reference the CMS can be loaded.
require_once __DIR__ . '/Stubs/joomla-stubs.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

@date_default_timezone_set('UTC');
