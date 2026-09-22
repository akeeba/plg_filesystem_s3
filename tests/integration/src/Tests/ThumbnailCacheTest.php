<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\IntegrationTest\AbstractE2ETestCase;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\SiteProvisioner;

/**
 * The local thumbnail cache (Advanced → "Cache thumbnails"): the site downloads each image once,
 * resizes it to WebP under media/plg_filesystem_s3/cache, and hands that out as the thumbnail.
 */
class ThumbnailCacheTest extends AbstractE2ETestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->setPluginParams(['cache_thumbnails' => '1', 'resizedDimension' => '100', 'lambdaResize' => '0']);
	}

	public function testAnImageGetsALocalWebpThumbnail(): void
	{
		$thumb = $this->thumbnailOf(SiteProvisioner::ADAPTER_CDN);

		$this->assertStringStartsWith(
			static::$config->getSiteUrl() . '/media/plg_filesystem_s3/cache/', $thumb, 'The thumbnail is served by the site.'
		);
		$this->assertStringEndsWith('.webp', $thumb);

		$image = $this->guest()->get($thumb);

		$this->assertSame(200, $image->code, $image->summary());
		$this->assertSame('RIFF', substr($image->body, 0, 4));
		$this->assertSame('WEBP', substr($image->body, 8, 4));

		$size = getimagesizefromstring($image->body);

		$this->assertNotFalse($size, 'The thumbnail is not a readable image.');
		$this->assertLessThanOrEqual(100, max($size[0], $size[1]), 'Thumbnails fit the resize dimension.');
	}

	public function testTheThumbnailIsReusedOnTheNextListing(): void
	{
		$first = $this->thumbnailOf(SiteProvisioner::ADAPTER_CDN);
		$file  = static::$config->getSiteRoot() . parse_url($first, PHP_URL_PATH);

		$this->assertFileExists($file);
		clearstatcache();
		$mtime = filemtime($file);

		sleep(1);

		$this->assertSame($first, $this->thumbnailOf(SiteProvisioner::ADAPTER_CDN));
		clearstatcache();
		$this->assertSame($mtime, filemtime($file), 'The cached thumbnail must not be regenerated.');
	}

	public function testANonImageGetsNoThumbnail(): void
	{
		$listing = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_CDN, '/fixtures'));

		$this->assertArrayNotHasKey('thumb_path', $this->entry($listing, 'hello.txt'));
	}

	public function testAPlainS3ConnectionGetsLocalThumbnails(): void
	{
		$thumb = $this->thumbnailOf(SiteProvisioner::ADAPTER_V2);

		$this->knownBug('url-forced-https');

		$this->assertStringStartsWith(static::$config->getSiteUrl() . '/media/plg_filesystem_s3/cache/', $thumb);
	}

	/**
	 * A download that fails (here: the plain S3 connection's unreachable https:// URL) must degrade
	 * quietly to the original URL.
	 */
	public function testAFailedThumbnailFallsBackToTheOriginalUrl(): void
	{
		$listing = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures'));
		$pixel   = $this->entry($listing, 'pixel.png');

		$this->assertSame($this->urlOf('/fixtures/pixel.png'), $pixel['thumb_path']);
	}

	public function testAFailedThumbnailRaisesNoPhpErrors(): void
	{
		$this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures'));

		$this->knownBug('thumb-marker-warning');

		$this->assertNoNewPluginPhpErrors();
	}

	private function thumbnailOf(string $adapter): string
	{
		$listing = $this->assertApiSuccess($this->media()->get($adapter, '/fixtures'));
		$pixel   = $this->entry($listing, 'pixel.png');

		$this->assertArrayHasKey('thumb_path', $pixel);

		return $pixel['thumb_path'];
	}

	private function urlOf(string $path): string
	{
		return $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, $path, ['url' => 1]))[0]['url'];
	}
}
