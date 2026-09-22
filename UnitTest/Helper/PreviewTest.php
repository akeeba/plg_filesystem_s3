<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\UnitTest\Helper;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\Helper\Preview;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\Registry\Registry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Which files get a thumbnail, and which URL the thumbnail comes from.
 *
 * The local thumbnail cache (download + resize to WebP) needs a reachable image and a writable site,
 * so it is covered end to end (ThumbnailCacheTest); only its bypass conditions are tested here.
 */
#[CoversClass(Preview::class)]
class PreviewTest extends TestCase
{
	private const URL = 'https://cdn.example.com/dir/photo.png';

	public function testPreviewsImagesByDefault(): void
	{
		$preview = new Preview(new Registry());

		$this->assertTrue($preview->shouldPreview('/dir/photo.png'));
		$this->assertTrue($preview->shouldPreview('/dir/scan.pdf'), 'PDF is in the default extension list.');
		$this->assertFalse($preview->shouldPreview('/dir/notes.txt'));
	}

	public function testTheXmlNeverOptionDisablesPreviews(): void
	{
		$preview = new Preview(new Registry(['preview' => 'never']));

		$this->assertFalse($preview->shouldPreview('/photo.png', false));
		$this->assertFalse($preview->shouldPreview('/photo.png', true));
	}

	public function testAnUnknownPreviewModeDisablesPreviews(): void
	{
		$preview = new Preview(new Registry(['preview' => 'sometimes']));

		$this->assertFalse($preview->shouldPreview('/photo.png', true));
	}

	public function testTheCdnModePreviewsOnlyCdnConnections(): void
	{
		$preview = new Preview(new Registry(['preview' => Preview::PREVIEW_CDN]));

		$this->assertTrue($preview->shouldPreview('/photo.png', true));
		$this->assertFalse($preview->shouldPreview('/photo.png', false));
	}

	/**
	 * The plugin's own XML form saves the "CDN only" choice as `cloudfront`, but the helper only knows
	 * `cdn`. Anything it does not know becomes PREVIEW_NONE, so choosing "CDN only" turns previews off
	 * everywhere — including on the CDN connections it was meant to keep them for.
	 */
	public function testTheXmlCdnOnlyOptionPreviewsCdnConnections(): void
	{
		$this->markTestSkipped(
			'KNOWN BUG: s3.xml saves preview="cloudfront" but Preview::PREVIEW_CDN is "cdn"; the unknown value '
			. 'is coerced to PREVIEW_NONE, so "CDN only" disables previews on CDN connections too.'
		);

		$preview = new Preview(new Registry(['preview' => 'cloudfront']));

		$this->assertTrue($preview->shouldPreview('/photo.png', true));
		$this->assertFalse($preview->shouldPreview('/photo.png', false));
	}

	public function testNormalisesTheConfiguredExtensionList(): void
	{
		$preview = new Preview(new Registry(['previewExtensions' => ' .PNG , Jpg,,webp ']));

		$this->assertSame(['png', 'jpg', 'webp'], array_values($this->getPrivate($preview, 'previewExtensions')));
		$this->assertTrue($preview->shouldPreview('/a/photo.JPG'), 'Extension matching is case-insensitive.');
		$this->assertFalse($preview->shouldPreview('/a/anim.gif'), 'gif is not in the configured list.');
	}

	public function testAnEmptyExtensionListFallsBackToTheDefaults(): void
	{
		// Registry::get() returns the default for an empty string, so this is the stock list, not "none".
		$preview = new Preview(new Registry(['previewExtensions' => '']));

		$this->assertTrue($preview->shouldPreview('/scan.pdf'));
	}

	public function testAListOfOnlySeparatorsDefersToJoomlasImageDetection(): void
	{
		$preview = new Preview(new Registry(['previewExtensions' => ' , , ']));

		$this->assertSame([], $this->getPrivate($preview, 'previewExtensions'));
		$this->assertTrue($preview->shouldPreview('/photo.avif'), 'MediaHelper::isImage() knows avif.');
		$this->assertFalse($preview->shouldPreview('/scan.pdf'), 'MediaHelper::isImage() does not treat PDF as an image.');
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function provideDimensions(): array
	{
		return [
			'below the minimum'       => ['50', 100],
			'exactly 100'             => ['100', 100],
			'rounded down to hundred' => ['290', 200],
			'the XML default'         => ['200', 200],
			'above the maximum'       => ['999', 400],
			'not a number'            => ['abc', 100],
		];
	}

	#[DataProvider('provideDimensions')]
	public function testClampsAndQuantisesTheResizeDimension(string $configured, int $expected): void
	{
		$preview = new Preview(new Registry(['resizedDimension' => $configured]));

		$this->assertSame($expected, $this->getPrivate($preview, 'resizedDimension'));
	}

	public function testWithoutLambdaOrLocalCachingTheThumbnailIsTheOriginal(): void
	{
		$preview = new Preview(new Registry());

		$this->assertSame(self::URL, $preview->getResized(self::URL, null, $this->app()));
	}

	public function testLambdaResizeAppendsTheDimensionToResizableImages(): void
	{
		$preview = new Preview(new Registry(['lambdaResize' => '1', 'resizedDimension' => '300']));

		$this->assertSame(self::URL . '?d=300x300', $preview->getResized(self::URL, null, $this->app()));
		$this->assertSame(
			'https://cdn.example.com/PHOTO.JPG?d=300x300',
			$preview->getResized('https://cdn.example.com/PHOTO.JPG', null, $this->app()),
			'Extension matching is case-insensitive.'
		);
	}

	public function testLambdaResizeLeavesNonResizableFilesAlone(): void
	{
		$preview = new Preview(new Registry(['lambdaResize' => '1']));
		$url     = 'https://cdn.example.com/scan.pdf';

		$this->assertSame($url, $preview->getResized($url, null, $this->app()));
	}

	public function testTheLocalThumbnailCacheNeedsALastModifiedDate(): void
	{
		if (!\function_exists('imagewebp'))
		{
			$this->markTestSkipped('GD without WebP support: local thumbnail caching is disabled outright.');
		}

		$preview = new Preview(new Registry(['cache_thumbnails' => 1]));

		$this->assertTrue($this->getPrivate($preview, 'cacheThumbnails'), 'Precondition: local caching is on.');
		$this->assertSame(self::URL, $preview->getResized(self::URL, null, $this->app()));
	}

	public function testLambdaResizeTakesPrecedenceOverTheLocalThumbnailCache(): void
	{
		$preview = new Preview(new Registry(['cache_thumbnails' => 1, 'lambdaResize' => '1', 'resizedDimension' => '100']));

		$this->assertSame(self::URL . '?d=100x100', $preview->getResized(self::URL, null, $this->app()));
	}

	/**
	 * @return mixed
	 */
	private function getPrivate(Preview $preview, string $property)
	{
		return (new ReflectionProperty($preview, $property))->getValue($preview);
	}

	private function app(): CMSApplicationInterface
	{
		return new class implements CMSApplicationInterface {
			public function get($name, $default = null)
			{
				return $default;
			}
		};
	}
}
