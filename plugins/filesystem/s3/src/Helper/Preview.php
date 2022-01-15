<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright © 2022 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\Filesystem\S3\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Helper\MediaHelper;
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
	 * Public constructor
	 *
	 * @param   Registry  $options  The plugin parameters registry
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function __construct(Registry $options)
	{
		$this->lambdaResize      = $options->get('lambdaResize', '0') == '1';
		$this->preview           = $options->get('preview', self::PREVIEW_ALWAYS);
		$this->previewExtensions = $options->get('previewExtensions', 'png,gif,jpg,jpeg,bmp,webp,pdf,svg');
		$this->resizedDimension  = (int) $options->get('resizedDimension', '100');

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
	 * @param   string  $url
	 *
	 * @return  string
	 *
	 * @since   1.0.0
	 */
	public function getResized(string $url): string
	{
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
}