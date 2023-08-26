<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2022-2023 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Helper\MediaHelper;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Image\Image;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/**
 * Image preview helper.
 *
 * Handles the image previews as per the options in the Advanced tab of the plugin settings.
 *
 * @since  1.0.0
 */
class Preview
{
	/**
	 * Preview images in all connection containers
	 *
	 * @since  1.0.0
	 */
	public const PREVIEW_ALWAYS = 'always';

	/**
	 * Preview images in CloudFront containers only
	 *
	 * @since  1.0.0
	 */
	public const PREVIEW_CLOUDFRONT = 'cloudfront';

	/**
	 * Never preview images
	 *
	 * @since  1.0.0
	 */
	public const PREVIEW_NONE = 'none';

	/**
	 * List of extensions which can be resized
	 *
	 * @since  1.0.0
	 */
	private const RESIZABLE = ['bmp', 'gif', 'ico', 'jpeg', 'jpg', 'png', 'tif', 'tiff', 'webp',];

	/**
	 * Should I enabled Lambda at Edge resizing for the images?
	 *
	 * @var   bool
	 * @since 1.0.0
	 *
	 * @see   https://aws.amazon.com/blogs/networking-and-content-delivery/resizing-images-with-amazon-cloudfront-lambdaedge-aws-cdn-blog/
	 */
	private $lambdaResize = false;

	/**
	 * When should I preview image files?
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $preview = self::PREVIEW_NONE;

	/**
	 * Which extensions should have previews enabled?
	 *
	 * Set to an empty array to use Joomla's MediaHelper instead.
	 *
	 * @var   array
	 * @since 1.0.0
	 */
	private $previewExtensions = [];

	/**
	 * Dimension for resizing images, in pixels.
	 *
	 * @var   int
	 * @since 1.0.0
	 */
	private $resizedDimension = 100;

	/**
	 * Should I create and cache local thumbnails?
	 *
	 * @var   bool
	 * @since 1.0.2
	 */
	private $cacheThumbnails = false;

	/**
	 * Absolute filesystem path to the local thumbnails cache folder.
	 *
	 * @var   string
	 * @since 1.0.02
	 */
	private $cachePath;

	/**
	 * Timestamp of the plugin loading.
	 *
	 * This is used to determine whether there is enough time to generate missing thumbnails without the request taking
	 * too long to be practical or, worse, fail altogether by hitting a server time limit (PHP, web server, maximum CPU
	 * usage, ...).
	 *
	 * @var   int
	 * @since 1.0.2
	 */
	private $startTime = 0;

	/**
	 * Maximum amount of time in seconds, measured from the plugin load, to spend creating thumbnails.
	 *
	 * @var   float
	 * @since 1.0.2
	 */
	private $maxThumbnailTime = 5.0;

	/**
	 * Public constructor
	 *
	 * @param   Registry  $options  The plugin parameters registry
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function __construct(Registry $options)
	{
		$this->startTime         = time();
		$this->lambdaResize      = $options->get('lambdaResize', '0') == '1';
		$this->preview           = $options->get('preview', self::PREVIEW_ALWAYS);
		$this->previewExtensions = $options->get('previewExtensions', 'png,gif,jpg,jpeg,bmp,webp,pdf,svg');
		$this->resizedDimension  = (int) $options->get('resizedDimension', '100');
		$this->cacheThumbnails   = $options->get('cache_thumbnails', 0) == 1;
		$this->cacheThumbnails   = $this->cacheThumbnails && $this->canResizeImages();
		$this->maxThumbnailTime  = min(max($options->get('max_thumbnail_time', 5.0), 1.0), 120.0);
		$this->cachePath         = JPATH_ROOT . '/media/plg_filesystem_s3/cache';

		if ($this->cacheThumbnails && !is_dir($this->cachePath) && !@mkdir($this->cachePath, 0755, true))
		{
			$this->cacheThumbnails = false;
		}

		if ($this->cacheThumbnails && !@is_writable($this->cachePath))
		{
			$this->cacheThumbnails = false;
		}

		// Constrain the preview option
		if (!in_array($this->preview, [self::PREVIEW_NONE, self::PREVIEW_CLOUDFRONT, self::PREVIEW_ALWAYS]))
		{
			$this->preview = self::PREVIEW_NONE;
		}

		// Constrain and quantize the resize dimension
		$this->resizedDimension = min(max(100, $this->resizedDimension), 400);
		$this->resizedDimension = intval(100 * floor($this->resizedDimension / 100));

		// Convert the preview extensions to an array
		if (empty($this->previewExtensions))
		{
			$this->previewExtensions = [];
		}
		elseif (is_string($this->previewExtensions))
		{
			$extensions              = explode(',', $this->previewExtensions);
			$extensions              = array_map(function ($ext) {
				return strtolower(trim($ext, " .\t\n\r\0\x0B"));
			}, $extensions);
			$this->previewExtensions = array_unique(array_filter($extensions, function ($ext) {
				return !empty($ext);
			}));
		}
	}

	/**
	 * Get the Lambda at Edge resized format of the image.
	 *
	 * @param   string     $url               The source image URL
	 * @param   Date|null  $lastModifiedDate  The file's last modified date
	 *
	 * @return  string
	 *
	 * @since   1.0.0
	 */
	public function getResized(string $url, ?Date $lastModifiedDate, CMSApplicationInterface $app): string
	{
		if (!$this->lambdaResize && $this->cacheThumbnails)
		{
			return $this->getResizedLocalUrl($url, $lastModifiedDate, $app);
		}

		if (!$this->lambdaResize)
		{
			return $url;
		}

		$uri      = new Uri($url);
		$fileName = basename($uri->getPath());
		$ext      = strtolower(File::getExt($fileName) ?: '');

		if (!in_array($ext, self::RESIZABLE))
		{
			return $url;
		}

		$dimension = $this->resizedDimension ?: 100;

		$uri->setVar('d', $dimension . 'x' . $dimension);

		return $uri->toString();
	}

	/**
	 * Should image preview be enabled for this image?
	 *
	 * @param   string  $fileName      The filename we check whether we should preview.
	 * @param   bool    $isCloudFront  Is this a CloudFront distribution?
	 *
	 * @return  bool
	 *
	 * @since   1.0.0
	 */
	public function shouldPreview(string $fileName, bool $isCloudFront = false): bool
	{
		// We can only preview images. How this is determined depends on the setting of the previewExtensions.
		if (!$this->isImage($fileName))
		{
			return false;
		}

		switch ($this->preview)
		{
			case self::PREVIEW_ALWAYS:
				return true;
				break;

			case self::PREVIEW_NONE:
			default:
				return false;
				break;

			case self::PREVIEW_CLOUDFRONT:
				return $isCloudFront;
				break;
		}
	}

	/**
	 * Is the file / URL an image file, based on its extension?
	 *
	 * Goes through Joomla's MediaHelper unless there is a list of preview extensions.
	 *
	 * @param   string  $filePathOrUrl  The file path or URL to check
	 *
	 * @return  bool
	 *
	 * @since   1.0.0
	 */
	private function isImage(string $filePathOrUrl)
	{
		$fileName = basename($filePathOrUrl);

		if (empty($this->previewExtensions) && !MediaHelper::isImage($fileName))
		{
			return false;
		}

		if (!empty($this->previewExtensions))
		{
			$ext = strtolower(File::getExt($fileName) ?: '');

			if (!in_array($ext, $this->previewExtensions))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Can I resize images locally?
	 *
	 * @return  bool
	 *
	 * @since   1.0.2
	 */
	private function canResizeImages()
	{
		return array_reduce(
			[
				'getimagesize',
				'imagecolorallocatealpha',
				'imagecolortransparent',
				'imagecreatetruecolor',
				'imagealphablending',
				'imagesavealpha',
				'imagefill',
				'imagecopyresized',
				'imagecopyresampled',
				'imagewebp',
				'imagecreatefromgif',
				'imagecreatefromjpeg',
				'imagecreatefrompng',
				'imagecreatefromwebp',
			],
			function ($carry, $x) {
				return $carry && function_exists($x);
			},
			true
		);
	}

	/**
	 * Get a (cached) local thumbnail URL.
	 *
	 * If the local thumbnail does not exist we will try to create it form the original image, as long as there is
	 * enough time for us to process it.
	 *
	 * @throws  \Exception
	 * @since   1.0.2
	 */
	private function getResizedLocalUrl(string $url, ?Date $lastModifiedDate, CMSApplicationInterface $app): string
	{
		// I need the last modified date.
		if (!$lastModifiedDate instanceof Date)
		{
			return $url;
		}

		// Make sure it's a supported file extension
		$parts     = explode('.', $url);
		$extension = strtolower(array_pop($parts));

		if (!in_array($extension, ['gif', 'jpg', 'jpeg', 'png', 'webp']))
		{
			return $url;
		}

		// Get the local filename
		$localHash      = md5($url . '::' . $lastModifiedDate->toRFC822());
		$localBaseName  = $localHash . '.webp';
		$localDirToRoot = 'media/plg_filesystem_s3/cache/' . $this->distributeToSubdirectories($localHash);
		$localPathName  = JPATH_ROOT . '/' . $localDirToRoot . '/' . $localBaseName;
		$localUrl       = Uri::root() . $localDirToRoot . '/' . $localBaseName;

		// Return the local path if it's already cached
		if (@file_exists($localPathName))
		{
			$localModTime = filemtime($localPathName);

			if ($localModTime !== false && $localModTime >= $lastModifiedDate->getTimestamp())
			{
				$filesize = @filesize($localPathName);

				// If the filesize is 0 we couldn't create a thumbnail. Don't waste any more time.
				if ($filesize !== false && $filesize > 0)
				{
					return $localUrl;
				}

				return $url;
			}
		}

		// If I've run out of time: return original image URL
		if (time() - $this->startTime > $this->maxThumbnailTime)
		{
			return $url;
		}

		// If the temp directory doesn't exist or can't be written to: no can do.
		$tempDir = $app->get('tmp_path', sys_get_temp_dir());

		if (!@is_dir($tempDir) || !@is_writable($tempDir))
		{
			return $url;
		}

		// Download the original image into temp storage.
		try
		{
			$http     = HttpFactory::getHttp();
			$response = $http->get($url, [], $this->maxThumbnailTime);

			if ($response->getStatusCode() != 200)
			{
				return $url;
			}

			$tempFile = tempnam($tempDir, 'plgs3_');
			file_put_contents($tempFile, $response->getBody());
			unset($response);
		}
		catch (\Throwable $e)
		{
			file_put_contents($localPathName, '');

			return $url;
		}

		// Resize and return
		try
		{
			// Make sure the local cache path exists
			if (!@is_dir(dirname($localPathName)) && !@mkdir(dirname($localPathName), 0755, true))
			{
				return $url;
			}

			$image = new Image($tempFile);

			$image = $image->resize($this->resizedDimension, $this->resizedDimension, false);

			if (!$image->toFile($localPathName, IMAGETYPE_WEBP))
			{
				file_put_contents($localPathName, '');

				return $url;
			}

			return $localUrl;
		}
		catch (\Exception $e)
		{
			file_put_contents($localPathName, '');

			return $url;
		}
		finally
		{
			if (isset($image))
			{
				unset($image);
			}

			@unlink($tempFile);
		}
	}

	/**
	 * Distribute an MD5 hash to subdirectories.
	 *
	 * For example, `0a1b2c3d4e5f67890a1b2c3d4e5f6789` with 3 levels and 2 characters per level will be distributed to
	 * subdirectory `0a/1b/2c`.
	 *
	 * @param   string  $hash           The hash to distribute
	 * @param   int     $levels         How many directory levels?
	 * @param   int     $charsPerLevel  How many characters per directory level?
	 *
	 * @return  string
	 *
	 * @since        1.0.2
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function distributeToSubdirectories(string $hash, int $levels = 3, int $charsPerLevel = 2): string
	{
		$chunks    = str_split($hash, $charsPerLevel);
		$pathParts = [];

		for ($i = 0; $i < $levels; $i++)
		{
			$pathParts[] = $chunks[$i];
		}

		return implode('/', $pathParts);
	}
}