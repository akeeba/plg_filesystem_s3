<?php
/**
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2022-2024 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

\defined('_JEXEC') || die;

use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Installer\Adapter\PluginAdapter;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;

class plgFilesystemS3InstallerScript extends InstallerScript
{
	protected $minimumPhp = '7.4.0';

	protected $minimumJoomla = '4.3.0';

	protected $allowDowngrades = true;

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

		$this->invalidateFiles();

		return true;
	}

	private function invalidateFiles()
	{
		$extensionsFromPackage = $this->invF_getExtensionsFromManifest($this->invF_getManifestXML(__CLASS__));

		foreach ($extensionsFromPackage as $element)
		{
			$paths = [];

			if (strpos($element, 'plg_') === 0)
			{
				[$dummy, $folder, $plugin] = explode('_', $element);

				$paths = [
					sprintf('%s/%s/%s/services', JPATH_PLUGINS, $folder, $plugin),
					sprintf('%s/%s/%s/src', JPATH_PLUGINS, $folder, $plugin),
				];
			}
			elseif (strpos($element, 'com_') === 0)
			{
				$paths = [
					sprintf('%s/components/%s/services', JPATH_ADMINISTRATOR, $element),
					sprintf('%s/components/%s/src', JPATH_ADMINISTRATOR, $element),
					sprintf('%s/components/%s/src', JPATH_SITE, $element),
					sprintf('%s/components/%s/src', JPATH_API, $element),
				];
			}
			elseif (strpos($element, 'mod_') === 0)
			{
				$paths = [
					sprintf('%s/modules/%s/services', JPATH_ADMINISTRATOR, $element),
					sprintf('%s/modules/%s/src', JPATH_ADMINISTRATOR, $element),
					sprintf('%s/modules/%s/services', JPATH_SITE, $element),
					sprintf('%s/modules/%s/src', JPATH_SITE, $element),
				];
			}
			else
			{
				continue;
			}

			foreach ($paths as $path)
			{
				$this->invF_recursiveClearCache($path);
			}
		}

		$this->invF_clearFileInOPCache(JPATH_CACHE . '/autoload_psr4.php');
	}

	private function invF_getManifestXML($class): ?SimpleXMLElement
	{
		// Get the package element name
		$myPackage = strtolower(str_replace('InstallerScript', '', $class));

		// Get the package's manifest file
		$filePath = JPATH_MANIFESTS . '/packages/' . $myPackage . '.xml';

		if (!@file_exists($filePath) || !@is_readable($filePath))
		{
			return null;
		}

		$xmlContent = @file_get_contents($filePath);

		if (empty($xmlContent))
		{
			return null;
		}

		return new SimpleXMLElement($xmlContent);
	}

	private function invF_xmlNodeToExtensionName(SimpleXMLElement $fileField): ?string
	{
		$type = (string) $fileField->attributes()->type;
		$id   = (string) $fileField->attributes()->id;

		switch ($type)
		{
			case 'component':
			case 'file':
			case 'library':
				$extension = $id;
				break;

			case 'plugin':
				$group     = (string) $fileField->attributes()->group ?? 'system';
				$extension = 'plg_' . $group . '_' . $id;
				break;

			case 'module':
				$client    = (string) $fileField->attributes()->client ?? 'site';
				$extension = (($client != 'site') ? 'a' : '') . $id;
				break;

			default:
				$extension = null;
				break;
		}

		return $extension;
	}

	private function invF_getExtensionsFromManifest(?SimpleXMLElement $xml): array
	{
		if (empty($xml))
		{
			return [];
		}

		$extensions = [];

		foreach ($xml->xpath('//files/file') as $fileField)
		{
			$extensions[] = $this->invF_xmlNodeToExtensionName($fileField);
		}

		return array_filter($extensions);
	}

	private function invF_clearFileInOPCache(string $file): bool
	{
		static $hasOpCache = null;

		if (is_null($hasOpCache))
		{
			$hasOpCache = ini_get('opcache.enable')
			              && function_exists('opcache_invalidate')
			              && (!ini_get('opcache.restrict_api')
			                  || stripos(
				                     realpath($_SERVER['SCRIPT_FILENAME']), ini_get('opcache.restrict_api')
			                     ) === 0);
		}

		if ($hasOpCache && (strtolower(substr($file, -4)) === '.php'))
		{
			$ret = opcache_invalidate($file, true);

			@clearstatcache($file);

			return $ret;
		}

		return false;
	}

	private function invF_recursiveClearCache(string $path): void
	{
		if (!@is_dir($path))
		{
			return;
		}

		/** @var DirectoryIterator $file */
		foreach (new DirectoryIterator($path) as $file)
		{
			if ($file->isDot() || $file->isLink())
			{
				continue;
			}

			if ($file->isDir())
			{
				$this->invF_recursiveClearCache($file->getPathname());

				continue;
			}

			if (!$file->isFile())
			{
				continue;
			}

			$this->invF_clearFileInOPCache($file->getPathname());
		}
	}
}