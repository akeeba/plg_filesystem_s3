<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright © 2022 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\Filesystem\S3\Adapter;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Component\Media\Administrator\Adapter\AdapterInterface;
use Joomla\Component\Media\Administrator\Exception\FileNotFoundException;
use Joomla\Plugin\Filesystem\S3\Helper\Preview;
use Joomla\Plugin\Filesystem\S3\Library\Acl;
use Joomla\Plugin\Filesystem\S3\Library\Configuration;
use Joomla\Plugin\Filesystem\S3\Library\Connector;
use Joomla\Plugin\Filesystem\S3\Library\Exception\CannotGetFile;
use Joomla\Plugin\Filesystem\S3\Library\Exception\CannotPutFile;
use Joomla\Plugin\Filesystem\S3\Library\Input;
use Joomla\Plugin\Filesystem\S3\Library\Request;
use Joomla\Plugin\Filesystem\S3\Library\Response\Error;
use Joomla\Plugin\Filesystem\S3\Library\StorageClass;
use RuntimeException;
use stdClass;

class S3Filesystem implements AdapterInterface
{
	/**
	 * Common MIME types based on file extension
	 *
	 * @since 1.0.0
	 * @see   https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types/Common_types
	 */
	private const MIME_TYPES = [
		'aac'    => 'audio/aac',
		'abw'    => 'application/x-abiword',
		'arc'    => 'application/x-freearc',
		'avi'    => 'video/x-msvideo',
		'azw'    => 'application/vnd.amazon.ebook',
		'bin'    => 'application/octet-stream',
		'bmp'    => 'image/bmp',
		'bz'     => 'application/x-bzip',
		'bz2'    => 'application/x-bzip2',
		'cda'    => 'application/x-cdf',
		'csh'    => 'application/x-csh',
		'css'    => 'text/css',
		'csv'    => 'text/csv',
		'doc'    => 'application/msword',
		'docx'   => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'eot'    => 'application/vnd.ms-fontobject',
		'epub'   => 'application/epub+zip',
		'gz'     => 'application/gzip',
		'gif'    => 'image/gif',
		'htm'    => 'text/html',
		'html'   => 'text/html',
		'ico'    => 'image/vnd.microsoft.icon',
		'ics'    => 'text/calendar',
		'jar'    => 'application/java-archive',
		'jpeg'   => 'image/jpeg',
		'jpg'    => 'image/jpeg',
		'js'     => 'text/javascript',
		'json'   => 'application/json',
		'jsonld' => 'application/ld+json',
		'mid'    => 'audio/midi',
		'midi'   => 'audio/midi',
		'mjs'    => 'text/javascript',
		'mp3'    => 'audio/mpeg',
		'mp4'    => 'video/mp4',
		'mpeg'   => 'video/mpeg',
		'mpkg'   => 'application/vnd.apple.installer+xml',
		'odp'    => 'application/vnd.oasis.opendocument.presentation',
		'ods'    => 'application/vnd.oasis.opendocument.spreadsheet',
		'odt'    => 'application/vnd.oasis.opendocument.text',
		'oga'    => 'audio/ogg',
		'ogv'    => 'video/ogg',
		'ogx'    => 'application/ogg',
		'opus'   => 'audio/opus',
		'otf'    => 'font/otf',
		'png'    => 'image/png',
		'pdf'    => 'application/pdf',
		'php'    => 'application/x-httpd-php',
		'ppt'    => 'application/vnd.ms-powerpoint',
		'pptx'   => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'rar'    => 'application/vnd.rar',
		'rtf'    => 'application/rtf',
		'sh'     => 'application/x-sh',
		'svg'    => 'image/svg+xml',
		'swf'    => 'application/x-shockwave-flash',
		'tar'    => 'application/x-tar',
		'tif'    => 'image/tiff',
		'tiff'   => 'image/tiff',
		'ts'     => 'video/mp2t',
		'ttf'    => 'font/ttf',
		'txt'    => 'text/plain',
		'vsd'    => 'application/vnd.visio',
		'wav'    => 'audio/wav',
		'weba'   => 'audio/webm',
		'webm'   => 'video/webm',
		'webp'   => 'image/webp',
		'woff'   => 'font/woff',
		'woff2'  => 'font/woff2',
		'xhtml'  => 'application/xhtml+xml',
		'xls'    => 'application/vnd.ms-excel',
		'xlsx'   => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'xml'    => 'application/xml',
		'xul'    => 'application/vnd.mozilla.xul+xml',
		'zip'    => 'application/zip',
		'3gp'    => 'video/3gpp',
		'3g2'    => 'video/3gpp2',
		'7z'     => 'application/x-7z-compressed',
	];

	/**
	 * Access Key
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $accessKey = '';

	/**
	 * Bucket name. Case–sensitive.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $bucket = '';

	/**
	 * The base CDN URL for CloudFront distributions
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $cdnUrl = '';

	/**
	 * The S3 connector object
	 *
	 * @var   Connector|null
	 * @since 1.0.0
	 */
	private $connector = null;

	/**
	 * Custom endpoint URL, when using an S3-compatible service
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $customEndpoint = '';

	/**
	 * Base directory in the bucket WITHOUT leading and trailign slash.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $directory = '';

	/**
	 * Should I use the DualStack endpoints? Only if $customEndpoint is empty.
	 *
	 * @var   bool
	 * @since 1.0.0
	 */
	private $dualStack = false;

	/**
	 * Is this an S3 bucket which serves as the origin for a CloudFront distribution?
	 *
	 * @var   bool
	 * @since 1.0.0
	 */
	private $isCloudFront = false;

	/**
	 * Should I be using path access? Default false, use virtual hosting access instead.
	 *
	 * @var   bool
	 * @since 1.0.0
	 */
	private $isPathAccess = false;

	/**
	 * The name of this adapter. Displayed in Media Manager.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $name = '';

	/**
	 * The Preview helper
	 *
	 * @var   Preview
	 * @since 1.0.0
	 */
	private $preview;

	/**
	 * Bucket region
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $region = 'us-east-1';

	/**
	 * Secret Key
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $secretKey = '';

	/**
	 * Signature method. v2 or v4.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $signature = 'v4';

	/**
	 * Storage class for new objects
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $storageClass = 'STANDARD';

	/**
	 * Temporary files created via getFile().
	 *
	 * These files will be removed when the adapter object is destroyed.
	 *
	 * @var   array
	 * @since 1.0.0
	 */
	private $tempFiles = [];

	/**
	 * Private constructor
	 *
	 * @param   array  $setup
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	private function __construct(array $setup)
	{
		foreach ($setup as $k => $v)
		{
			if (!property_exists($this, $k))
			{
				continue;
			}

			$this->{$k} = $v;
		}

		$this->bucket = str_replace('/', '', $this->bucket);

		// Makes sure the custom endpoint has no protocol and no trailing slash
		$customEndpoint = trim($this->customEndpoint);
		$useSSL         = true;

		if (!empty($customEndpoint))
		{
			$protoPos = strpos($customEndpoint, ':\\');

			if ($protoPos !== false)
			{
				$protocol       = substr($customEndpoint, 0, $protoPos);
				$useSSL         = strtolower($protocol) === 'https';
				$customEndpoint = substr($customEndpoint, $protoPos + 3);
			}

			$customEndpoint = rtrim($customEndpoint, '/');
		}

		// Sanity checks
		if (!function_exists('curl_init'))
		{
			throw new RuntimeException('cURL is not enabled, please enable it in order to post-process your archives');
		}

		if (empty($this->accessKey))
		{
			throw new RuntimeException('You have not set up your Access Key');
		}

		if (empty($this->secretKey))
		{
			throw new RuntimeException('You have not set up your Secret Key');
		}

		if (empty($this->bucket))
		{
			throw new RuntimeException('You have not set up your Bucket');
		}

		// Prepare the configuration
		$configuration = new Configuration($this->accessKey, $this->secretKey, $this->signature, $this->region);
		$configuration->setSSL($useSSL ?? true);
		$configuration->setUseDualstackUrl($this->dualStack);

		if ($customEndpoint)
		{
			$configuration->setEndpoint($customEndpoint);
		}

		// Set path-style vs virtual hosting style access
		$configuration->setUseLegacyPathStyle($this->isPathAccess);

		// Return the new S3 client instance
		$this->connector = new Connector($configuration);
	}

	/**
	 * Create an adapter from the plugin's connection configuration information
	 *
	 * @param   array  $connection
	 *
	 * @return  static
	 *
	 * @since   1.0.0
	 */
	public static function getFromConnection(array $connection): self
	{
		$type         = $connection['type'] ?? 's3';
		$cdnUrl       = trim($connection['cdn_url'] ?? '');
		$isCloudFront = ($type === 'cloudfront') && !empty($cdnUrl);
		$signature    = $connection['signature'] ?? 'v4';
		$region       = $connection['region'] ?? 'us-east-1';
		$customRegion = $connection['$region'] ?? '';
		$setup        = [
			'accessKey'      => $connection['accesskey'] ?? '',
			'bucket'         => $connection['bucket'] ?? '',
			'cdnUrl'         => $isCloudFront ? ($cdnUrl) : null,
			'customEndpoint' => $type === 'custom' ? $connection['customendpoint'] : null,
			'directory'      => $connection['directory'] ?? '',
			'dualStack'      => ($connection['dualstack'] ?? '1') === '1',
			'isCloudFront'   => $isCloudFront,
			'isPathAccess'   => ($connection['pathaccess'] ?? '') === 'path',
			'name'           => $connection['label'] ?? null,
			'region'         => $region === 'custom' ? $customRegion : $region,
			'secretKey'      => $connection['secretkey'] ?? '',
			'signature'      => in_array($signature, ['v2', 'v4']) ? $signature : 'v4',
			'storageClass'   => $connection['storage_class'] ?? 'STANDARD',
		];

		return new self($setup);
	}

	/**
	 * Destructor.
	 *
	 * Cleans up the temporary files created by this adapter.
	 *
	 * @since   1.0.0
	 */
	public function __destruct()
	{
		foreach ($this->tempFiles as $filePath)
		{
			if (@file_exists($filePath) && @is_file($filePath))
			{
				@unlink($filePath);
			}
		}
	}

	/**
	 * Copies a file or folder from source to destination.
	 *
	 * It returns the new destination path. This allows the implementation classes to normalise the file name.
	 *
	 * @param   string  $sourcePath       The source path
	 * @param   string  $destinationPath  The destination path
	 * @param   bool    $force            Force to overwrite
	 *
	 * @return  string
	 *
	 * @throws  Exception
	 * @since   1.0.0
	 */
	public function copy(string $sourcePath, string $destinationPath, bool $force = false): string
	{
		$sourceInfo      = $this->getFile($sourcePath);
		$sourcePath      = trim($sourcePath, '/');
		$destinationPath = trim($destinationPath, '/');

		$parts           = explode('/', $destinationPath);
		$filename        = array_pop($parts);
		$directory       = empty($parts) ? '' : implode('/', $parts);
		$filename        = $this->makeSafeName($filename);
		$destinationPath = $directory . (empty($directory) ? '' : '/') . $filename;

		$dirPrefix               = $this->directory . (empty($this->directory) ? '' : '/');
		$sourcePathAbsolute      = $dirPrefix . $sourcePath;
		$destinationPathAbsolute = $dirPrefix . $destinationPath;

		if ($sourceInfo->type === 'dir')
		{
			$sourcePathAbsolute      .= '/';
			$destinationPathAbsolute .= '/';
		}

		$this->copyObject($this->bucket, $sourcePathAbsolute, $destinationPathAbsolute, Acl::ACL_PUBLIC_READ, $this->storageClass);

		return $destinationPath;
	}

	/**
	 * Creates a file with the given name in the given path with the data.
	 *
	 * It returns the new file name. This allows the implementation classes to normalise the file name.
	 *
	 * @param   string  $name  The name
	 * @param   string  $path  The folder
	 * @param   string  $data  The binary file data
	 *
	 * @return  string
	 *
	 * @throws  Exception
	 * @since   1.0.0
	 */
	public function createFile(string $name, string $path, $data): string
	{
		$input = new Input();
		$input->assignData($data);

		$endpoint                       = $this->connector->getConfiguration()->getEndpoint();
		$headers                        = $this->getStorageTypeHeaders($this->storageClass, $endpoint);
		$headers['Content-Disposition'] = sprintf('attachment; filename="%s"', basename($name));

		$name      = $this->makeSafeName($name);
		$path      = trim($path, '/');
		$directory = $this->directory . (empty($this->directory) ? '' : '/');
		$directory .= $path . (empty($path) ? '' : '/');

		$this->connector->putObject($input, $this->bucket, $directory . $name, Acl::ACL_PUBLIC_READ, $headers);

		return $name;
	}

	/**
	 * Creates a folder with the given name in the given path.
	 *
	 * It returns the new folder name. This allows the implementation classes to normalise the file name.
	 *
	 * @param   string  $name  The name
	 * @param   string  $path  The folder
	 *
	 * @return  string
	 *
	 * @throws  Exception
	 * @since   1.0.0
	 */
	public function createFolder(string $name, string $path): string
	{
		// Amazon S3 does not have folders. Creating an empty key whose name ends in "/" works as an empty folder.
		$dummy = '';
		$input = Input::createFromData($dummy);

		$name      = $this->makeSafeName($name);
		$path      = trim($path, '/');
		$directory = $this->directory . (empty($this->directory) ? '' : '/');
		$directory .= $path . (empty($path) ? '' : '/');

		$this->connector->putObject($input, $this->bucket, $directory . $name . '/', Acl::ACL_PUBLIC_READ);

		return $name;
	}

	/**
	 * Deletes the folder or file of the given path.
	 *
	 * @param   string  $path  The path to the file or folder
	 *
	 * @return  void
	 *
	 * @throws  Exception
	 * @since   1.0.0
	 */
	public function delete(string $path)
	{
		$info = $this->getFile($path);

		// Recursively delete a directory. Get ready for some funky timeouts, LOL
		if ($info->type === 'dir')
		{
			$files = $this->getFiles($path);

			foreach ($files as $file)
			{
				$filePath = rtrim($path, '/') . '/' . $file->name;
				try
				{
					$this->delete($filePath);
				}
				catch (FileNotFoundException $e)
				{
					// No worries...
				}
			}
		}

		$dirPrefix = $this->directory . (empty($this->directory) ? '' : '/');
		$path      = $dirPrefix . ltrim($path, '/');

		if ($info->type === 'dir')
		{
			$path .= '/';
		}

		$this->connector->deleteObject($this->bucket, $path);
	}

	/**
	 * Returns the name of the adapter. It will be shown in the Media Manager
	 *
	 * @return  string
	 *
	 * @since   1.0.0
	 */
	public function getAdapterName(): string
	{
		return $this->name;
	}

	/**
	 * Returns the requested file or folder. The returned object
	 * has the following properties available:
	 * - type:          The type can be file or dir
	 * - name:          The name of the file
	 * - path:          The relative path to the root
	 * - extension:     The file extension
	 * - size:          The size of the file
	 * - create_date:   The date created
	 * - modified_date: The date modified
	 * - mime_type:     The mime type
	 * - width:         The width, when available
	 * - height:        The height, when available
	 *
	 * If the path doesn't exist a FileNotFoundException is thrown.
	 *
	 * @param   string  $path  The path to the file or folder
	 *
	 * @return  stdClass
	 *
	 * @throws  Exception
	 * @since   1.0.0
	 */
	public function getFile(string $path = '/'): stdClass
	{
		/**
		 * Joomla's Media Manager has the single most inefficient, nonsensical adapter design I have even seen — and I
		 * have written plugins for WordPress!
		 *
		 * You'd think that the Adapter having two methods getFiles and getFile would really mean that the former is
		 * used for directory listings and the latter for getting file metadata, right?
		 *
		 * Wrong.
		 *
		 * Joomla will use getFiles to get the metadata of a single file(!) and will also use getFile to find out if a
		 * directory(!) exists. This is bonkers.
		 *
		 * There are exactly ZERO (0) remote storage provider APIs which will use the same API call to list directory
		 * contents AND return file information. Zero. None. Zip. Zilch. Nada.
		 *
		 * This nonsensical design means that getFiles() needs to do TWO requests, first to make sure that the given
		 * path is not a file — or if it is a file fall back to getFile() — then another one to actually list the
		 * directory contents. This means that listing directories is dead slow.
		 *
		 * Moreover, getFile() will need to perform one request assuming the path is a file. If it's not found, it will
		 * have to make YET ANOTHER request assuming the path is a folder.
		 *
		 * Whoever designed the Media Manager API and Adapter has clearly never tried to implement a remote storage
		 * provide integration in any software at all, ever.
		 */

		$dirPrefix = $this->directory . (empty($this->directory) ? '' : '/');
		$path      = ltrim($path, '/');
		$path      = $dirPrefix . $path;

		$isDir = substr($path, -1) === '/';
		$found = false;

		try
		{
			$meta  = $this->connector->headObject($this->bucket, $path);
			$found = true;
		}
		catch (CannotGetFile $e)
		{

		}

		// Since Joomla doesn't know folders need a trailing slash we'll fall back to searching for a folder.
		if (!$found)
		{
			try
			{
				$meta  = $this->connector->headObject($this->bucket, $path . '/');
				$isDir = true;
			}
			catch (CannotGetFile $e)
			{
				throw new FileNotFoundException($e->getMessage(), 404, $e);
			}
		}

		$nameKey = $isDir ? 'prefix' : 'name';

		$ext          = File::getExt(basename(rtrim($path, '/')));
		$meta['type'] = ($meta['type'] ?? null) === 'application/octet-stream' ? null : ($meta['type'] ?? null);
		$x            = $this->dirListingToJoomlaObject([
			$nameKey => rtrim($path, '/'),
			'time'   => $meta['time'] ?? time(),
			'hash'   => $meta['hash'] ?? null,
			'type'   => $meta['type'] ?? self::MIME_TYPES[$ext] ?? 'application/octet-stream',
			'size'   => $meta['size'] ?? 0,
		], $dirPrefix);

		return $x;
	}

	/**
	 * Returns the folders and files for the given path. The returned objects
	 * have the following properties available:
	 * - type:          The type can be file or dir
	 * - name:          The name of the file
	 * - path:          The relative path to the root
	 * - extension:     The file extension
	 * - size:          The size of the file
	 * - create_date:   The date created
	 * - modified_date: The date modified
	 * - mime_type:     The mime type
	 * - width:         The width, when available
	 * - height:        The height, when available
	 *
	 * If the path doesn't exist a FileNotFoundException is thrown.
	 *
	 * @param   string  $path  The folder
	 *
	 * @return  stdClass[]
	 *
	 * @throws  Exception
	 * @since   1.0.0
	 */
	public function getFiles(string $path = '/'): array
	{
		/**
		 * Joomla's Media Manager has the single most inefficient, nonsensical adapter design I have even seen — and I
		 * have written plugins for WordPress!
		 *
		 * You'd think that the Adapter having two methods getFiles and getFile would really mean that the former is
		 * used for directory listings and the latter for getting file metadata, right?
		 *
		 * Wrong.
		 *
		 * Joomla will use getFiles to get the metadata of a single file(!) and will also use getFile to find out if a
		 * directory(!) exists. This is bonkers.
		 *
		 * There are exactly ZERO (0) remote storage provider APIs which will use the same API call to list directory
		 * contents AND return file information. Zero. None. Zip. Zilch. Nada.
		 *
		 * This nonsensical design means that getFiles() needs to do TWO requests, first to make sure that the given
		 * path is not a file — or if it is a file fall back to getFile() — then another one to actually list the
		 * directory contents. This means that listing directories is dead slow.
		 *
		 * Moreover, getFile() will need to perform one request assuming the path is a file. If it's not found, it will
		 * have to make YET ANOTHER request assuming the path is a folder.
		 *
		 * Whoever designed the Media Manager API and Adapter has clearly never tried to implement a remote storage
		 * provide integration in any software at all, ever.
		 */

		if (!empty(trim($path, '/')))
		{
			$joomlaStupid = $this->getFile($path);

			if ($joomlaStupid->type === 'file')
			{
				return [$joomlaStupid];
			}
		}

		$dirPrefix = $this->directory . (empty($this->directory) ? '' : '/');
		$path      = trim($path, '/');
		$path      = $dirPrefix . $path . '/';
		$path      = substr($path, -2) === '//' ? substr($path, 0, -1) : $path;
		$path      = ($path === '/') ? null : $path;
		$marker    = null;
		$listing   = [];

		do
		{
			$sublisting = $this->connector->getBucket($this->bucket, $path, $marker, 1000, '/', true);

			if (empty($sublisting))
			{
				break;
			}

			$listing = array_merge($listing, array_map(function ($raw) use ($dirPrefix) {
				return $this->dirListingToJoomlaObject($raw, $dirPrefix);
			}, $sublisting));

			if (count($sublisting) < 1000)
			{
				break;
			}

			$filenames = array_keys($sublisting);
			$marker    = array_pop($filenames);
		} while (true);

		file_put_contents(JPATH_SITE . '/debug.txt', print_r([
			'path'    => $path,
			'listing' => array_values($listing),
		], true));

		return array_values($listing);
	}

	/**
	 * Returns a resource for the given path.
	 *
	 * @param   string  $path  The path
	 *
	 * @return  resource
	 *
	 * @throws  Exception
	 * @since   1.0.0
	 */
	public function getResource(string $path)
	{
		$tempPath          = Factory::getApplication()->get('tmp_path', sys_get_temp_dir());
		$tempName          = tempnam($tempPath, 'jmes3_');
		$this->tempFiles[] = $tempName;

		$dirPrefix = $this->directory . (empty($this->directory) ? '' : '/');

		$this->connector->getObject($this->bucket, $dirPrefix . ltrim($path, '/'), $tempName);

		return @fopen($tempName, 'r');
	}

	/**
	 * Returns a public url for the given path. This function can be used by the cloud
	 * adapter to publish the media file and create a permanent publicly accessible
	 * url.
	 *
	 * @param   string  $path  The path to file
	 *
	 * @return  string
	 *
	 * @since   1.0.0
	 */
	public function getUrl(string $path): string
	{
		if ($this->isCloudFront)
		{
			return rtrim($this->cdnUrl, '/') . '/' . $this->getEncodedPath(ltrim($path, '/'));
		}

		$dirPrefix = $this->directory . (empty($this->directory) ? '' : '/');
		$path      = trim($path, '/');

		return $this->connector->getAuthenticatedURL($this->bucket, $dirPrefix . $path, 315360000, true);
	}

	/**
	 * Moves a file or folder from source to destination.
	 *
	 * It returns the new destination path. This allows the implementation classes to normalise the file name.
	 *
	 * @param   string  $sourcePath       The source path
	 * @param   string  $destinationPath  The destination path
	 * @param   bool    $force            Force to overwrite
	 *
	 * @return  string
	 *
	 * @throws  Exception
	 * @since   1.0.0
	 */
	public function move(string $sourcePath, string $destinationPath, bool $force = false): string
	{
		// Detect directories. Note that the fake `dirname/` zero length file may NOT exist. Hence the exception catch.
		$skipActualSource = false;

		try
		{
			$sourceInfo = $this->getFile($sourcePath);
			$isDir      = $sourceInfo->type === 'dir';
		}
		catch (FileNotFoundException $e)
		{
			$isDir            = true;
			$skipActualSource = true;
		}

		// Recursively move the files of a folder
		if ($isDir)
		{
			$files = $this->getFiles($sourcePath);

			foreach ($files as $file)
			{
				$fileSourcePath = $file->path;
				$fileDestPath   = rtrim($destinationPath, '/') . '/' . trim(substr($file->path, strlen($sourcePath)), '/');
				$fileDestPath   = rtrim($fileDestPath, '/');

				if ($fileSourcePath === $sourcePath)
				{
					continue;
				}

				$this->move($fileSourcePath, $fileDestPath, $force);
			}
		}

		if ($skipActualSource)
		{
			return basename($this->makeSafeName(rtrim($destinationPath, '/')));
		}

		// Amazon S3 doesn't have an atomic move/rename operation. We copy, then delete the source.
		$newName = $this->copy($sourcePath, $destinationPath, $force);

		if (!empty($newName) && $newName != $sourcePath)
		{
			$this->delete($sourcePath);
		}

		return $newName;
	}

	/**
	 * Search for a pattern in a given path
	 *
	 * @param   string  $path       The base path for the search
	 * @param   string  $needle     The path to file
	 * @param   bool    $recursive  Do a recursive search
	 *
	 * @return  stdClass[]
	 *
	 * @since   1.0.0
	 */
	public function search(string $path, string $needle, bool $recursive = false): array
	{
		$dirPrefix = $this->directory . (empty($this->directory) ? '' : '/');
		$path      = trim($path, '/');
		$path      = $dirPrefix . $path . '/';
		$path      = substr($path, -2) === '//' ? substr($path, 0, -1) : $path;
		$path      = ($path === '/') ? null : $path;
		$marker    = null;
		$listing   = [];

		$delimiter = $recursive ? '' : '/';

		do
		{
			$sublisting = $this->connector->getBucket($this->bucket, $path, $marker, 1000, $delimiter, true);

			if (empty($sublisting))
			{
				break;
			}

			$count      = count($sublisting);
			$sublisting = array_map(function ($raw) use ($dirPrefix) {
				return $this->dirListingToJoomlaObject($raw, $dirPrefix);
			}, $sublisting);
			$sublisting = array_filter($sublisting, function ($item) use ($needle) {
				return fnmatch($needle, $item->name);
			});

			$listing = array_merge($listing, $sublisting);

			if ($count < 1000)
			{
				break;
			}

			$filenames = array_keys($sublisting);
			$marker    = array_pop($filenames);
		} while (true);

		return $listing;
	}

	public function setPreview(Preview $preview)
	{
		$this->preview = $preview;
	}

	/**
	 * Updates the file with the given name in the given path with the data.
	 *
	 * @param   string  $name  The name
	 * @param   string  $path  The folder
	 * @param   string  $data  The binary data
	 *
	 * @return  void
	 *
	 * @throws  Exception
	 * @since   1.0.0
	 */
	public function updateFile(string $name, string $path, $data)
	{
		// Updating and creating an object is the same in S3.
		$newName = $this->createFile($name, $path, $data);

		// If the name changed delete the old file
		if ($newName != $name)
		{
			$fullPath = trim($path, '/');
			$fullPath .= empty($fullPath) ? '' : '/';
			$fullPath .= $name;

			$this->delete($fullPath);
		}
	}

	/**
	 * Copy an object
	 *
	 * @param   string  $bucket  Bucket name
	 * @param   string  $from    Source object URI
	 * @param   string  $to      Destination object URI
	 * @param   string  $acl     ACL for the new object
	 *
	 * @return  void
	 * @since   1.0.0
	 *
	 * @see     https://docs.aws.amazon.com/AmazonS3/latest/API/API_CopyObject.html
	 */
	private function copyObject(string $bucket, string $from, string $to, string $acl = Acl::ACL_PRIVATE, $storageClass = StorageClass::STANDARD): void
	{
		$request = new Request('PUT', $bucket, $to, $this->connector->getConfiguration());
		$request->setAmzHeader('x-amz-copy-source', $bucket . '/' . $from);
		$request->setAmzHeader('x-amz-acl', $acl);
		$request->setAmzHeader('x-amz-storage-class', $storageClass);
		$response = $request->getResponse();

		if (!$response->error->isError() && ($response->code !== 200))
		{
			$response->error = new Error(
				$response->code,
				"Unexpected HTTP status {$response->code}"
			);
		}

		if ($response->error->isError())
		{
			throw new CannotPutFile(
				sprintf(__METHOD__ . "({$bucket}, {$from}, {$to}): [%s] %s",
					$response->error->getCode(), $response->error->getMessage()),
				$response->error->getCode()
			);
		}
	}

	/**
	 * Converts the raw Amazon S3 directory listing entry to a Joomla directory listing entry object
	 *
	 * @param   array   $raw        The raw Amazon S3 directory listing entry
	 * @param   string  $dirPrefix  The configured connection directory
	 *
	 * @return  object Joomla directory listing entry object
	 *
	 * @since   1.0.0
	 */
	private function dirListingToJoomlaObject(array $raw, string $dirPrefix = ''): object
	{
		$type     = isset($raw['prefix']) ? 'dir' : 'file';
		$filePath = $raw['prefix'] ?? $raw['name'];
		$fileName = basename($filePath);

		$path = $filePath;

		if (!empty($dirPrefix) && strpos($filePath, $dirPrefix) === 0)
		{
			$path = substr($path, strlen($dirPrefix));
		}

		$path = '/' . trim($path, '/');

		if ($type === 'file')
		{
			$date = new Date(sprintf('%0.5f', (float) $raw['time']));
		}

		$dateIso       = isset($date) ? $date->toISO8601() : '';
		$dateFormatted = isset($date) ? HTMLHelper::_('date', $date, Text::_('DATE_FORMAT_LC5')) : '';

		$extension = $type === 'dir' ? '' : File::getExt($fileName);

		/**
		 * Get an object with the basic information about the file or folder.
		 *
		 * Yup, the *_formatted timestamps are undocumented in Joomla. SURPRISE!
		 */
		$obj = (object) [
			'type'                    => $type,
			'name'                    => $fileName,
			'path'                    => $path,
			'extension'               => $extension,
			'size'                    => $raw['size'] ?? '',
			'create_date'             => $dateIso,
			'create_date_formatted'   => $dateFormatted,
			'modified_date'           => $dateIso,
			'modified_date_formatted' => $dateFormatted,
			'mime_type'               => ($type === 'dir') ? '' : $raw['type'] ?? (self::MIME_TYPES[$extension] ?? 'application/octet-stream'),
			'width'                   => 0,
			'height'                  => 0,
		];


		/**
		 * Yes, this is undocumented in Joomla.
		 *
		 * The actual thumbnail path needs to be provided in a completely undocumented object parameter.
		 */
		if (($type === 'file') && $this->preview->shouldPreview($obj->path, $this->isCloudFront))
		{
			$obj->thumb_path = $this->preview->getResized($this->getUrl($obj->path));
		}

		return $obj;
	}

	/**
	 * Replace spaces on a path with %20
	 *
	 * @param   string  $path  The Path to be encoded
	 *
	 * @return  string
	 *
	 * @since   1.0.0
	 */
	private function getEncodedPath(string $path): string
	{
		return str_replace(" ", "%20", $path);
	}

	/**
	 * Get the Amazon request headers required to set the storage type of an upload to the specified class.
	 *
	 * @param   string  $storageClass  The storage class.
	 * @param   string  $endpoint      The API endpoint. Determine whether it's Amazon or 3rd party service.
	 *
	 * @return  array  The headers
	 * @since   1.0.0
	 */
	private function getStorageTypeHeaders(string $storageClass = 'STANDARD', string $endpoint = 's3.amazonaws.com'): array
	{
		$headers = [];

		if (!in_array($endpoint, ['s3.amazonaws.com', 'amazonaws.com.cn']))
		{
			return $headers;
		}

		$headers['X-Amz-Storage-Class'] = $storageClass;

		return $headers;
	}

	/**
	 * Make a file name safe for use with Amazon S3
	 *
	 * @param   string  $name
	 *
	 * @return  string
	 *
	 * @since   1.0.0
	 */
	private function makeSafeName(string $name): string
	{
		// File names cannot end in a dot
		$name = rtrim($name, '.');

		// Convert forward slashes to underscores; they are path separators
		$name = str_replace('/', '_', $name);

		// Lowercase the extension
		$ext = File::getExt($name);

		if (!empty($ext))
		{
			$name = substr($name, 0, -strlen($ext)) . $ext;
		}

		return $name;
	}

}