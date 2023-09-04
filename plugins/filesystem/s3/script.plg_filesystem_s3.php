<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2023 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

\defined('_JEXEC') || die;

use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Installer\Adapter\PluginAdapter;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;

class plgFilesystemS3InstallerScript extends InstallerScript
{
	public function uninstall(InstallerAdapter $adapter): bool
	{
		// Remove the media folder (which is only created on the fly, if local caching is enabled).
		$mediaFolder = JPATH_ROOT . '/media/plg_filesystem_s3';

		if (Folder::exists($mediaFolder))
		{
			Folder::delete($mediaFolder);
		}

		return true;
	}

	/**
	 * @param   string         $type
	 * @param   PluginAdapter  $adapter
	 *
	 *
	 * @since version
	 */
	public function postflight($type, $adapter)
	{
		if ($type === 'uninstall')
		{
			return true;
		}

		if (class_exists(JNamespacePsr4Map::class))
		{
			try
			{
				$nsMap = new JNamespacePsr4Map();

				@clearstatcache(JPATH_CACHE . '/autoload_psr4.php');

				if (function_exists('opcache_invalidate'))
				{
					@opcache_invalidate(JPATH_CACHE . '/autoload_psr4.php');
				}

				@clearstatcache(JPATH_CACHE . '/autoload_psr4.php');
				$nsMap->create();

				if (function_exists('opcache_invalidate'))
				{
					@opcache_invalidate(JPATH_CACHE . '/autoload_psr4.php');
				}

				$nsMap->load();
			}
			catch (\Throwable $e)
			{
				// In case of failure, just try to delete the old autoload_psr4.php file
				if (function_exists('opcache_invalidate'))
				{
					@opcache_invalidate(JPATH_CACHE . '/autoload_psr4.php');
				}

				@unlink(JPATH_CACHE . '/autoload_psr4.php');
				@clearstatcache(JPATH_CACHE . '/autoload_psr4.php');
			}
		}

		return true;
	}
}