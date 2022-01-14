<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright © 2022 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\Filesystem\S3\Adapter;

defined('_JEXEC') or die;

use Joomla\Component\Media\Administrator\Adapter\AdapterInterface;

class S3Filesystem implements AdapterInterface
{
	/**
	 * The name of this adapter. Displayed in Media Manager.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $name = '';

	/**
	 * Is this an S3 bucket which serves as the origin for a CloudFront distribution?
	 *
	 * @var   bool
	 * @since 1.0.0
	 */
	private $isCloudFront = false;

	/**
	 * Custom endpoint URL, when using an S3-compatible service
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $customEndpoint = '';

	/**
	 * Access Key
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $accessKey = '';

	/**
	 * Secret Key
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $secretKey = '';

	/**
	 * The base CDN URL for CloudFront distributions
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $cdnUrl = '';

	/**
	 * Should I use the DualStack endpoints? Only if $customEndpoint is empty.
	 *
	 * @var   bool
	 * @since 1.0.0
	 */
	private $dualStack = false;

	/**
	 * Bucket name. Case–sensitive.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $bucket = '';

	/**
	 * Signature method. v2 or v4.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $signature = 'v4';

	/**
	 * Bucket region
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $region = 'us-east-1';

	/**
	 * Should I be using path access? Default false, use virtual hosting access instead.
	 *
	 * @var   bool
	 * @since 1.0.0
	 */
	private $isPathAccess = false;

	/**
	 * Base directory in the bucket WITHOUT leading and trailign slash.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $directory = '';

	/**
	 * Storage class for new objects
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $storageClass = 'STANDARD';

	private function __construct()
	{
		// TODO
	}

	public static function getFromConnection(array $connection): self
	{
		// TODO
	}

	public function copy(string $sourcePath, string $destinationPath, bool $force = false): string
	{
		// TODO: Implement copy() method.
	}

	public function createFile(string $name, string $path, $data): string
	{
		// TODO: Implement createFile() method.
	}

	public function createFolder(string $name, string $path): string
	{
		// TODO: Implement createFolder() method.
	}

	public function delete(string $path)
	{
		// TODO: Implement delete() method.
	}

	public function getAdapterName(): string
	{
		// TODO: Implement getAdapterName() method.
	}

	public function getFile(string $path = '/'): \stdClass
	{
		// TODO: Implement getFile() method.
	}

	public function getFiles(string $path = '/'): array
	{
		// TODO: Implement getFiles() method.
	}

	public function getResource(string $path)
	{
		// TODO: Implement getResource() method.
	}

	public function getUrl(string $path): string
	{
		// TODO: Implement getUrl() method.
	}

	public function move(string $sourcePath, string $destinationPath, bool $force = false): string
	{
		// TODO: Implement move() method.
	}

	public function search(string $path, string $needle, bool $recursive = false): array
	{
		// TODO: Implement search() method.
	}

	public function updateFile(string $name, string $path, $data)
	{
		// TODO: Implement updateFile() method.
	}
}