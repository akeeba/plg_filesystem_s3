<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2023 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

\defined('_JEXEC') || die;

use Joomla\CMS\Filesystem\Folder;
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
}