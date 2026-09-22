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
 * The per-connection S3 response cache. Two claims, each needing the other to mean anything:
 *
 * 1. It really caches: a change made behind the plugin's back does not show until the cache expires.
 *    (Without this, "the listing is fresh after an upload" would prove nothing.)
 * 2. Every change made THROUGH the plugin invalidates it: the uploader sees their own upload at once.
 */
class CachingTest extends AbstractE2ETestCase
{
	private const CACHED = SiteProvisioner::ADAPTER_CACHED;

	public function testAChangeBehindThePluginsBackIsNotSeenWhileCached(): void
	{
		$s = $this->scratch();
		$this->bucket()->put("$s/first.txt", "1\n");

		$this->assertSame(['first.txt'], $this->names($this->assertApiSuccess($this->media()->get(self::CACHED, "/$s"))));

		$this->bucket()->put("$s/second.txt", "2\n");

		$this->assertSame(
			['first.txt'],
			$this->names($this->assertApiSuccess($this->media()->get(self::CACHED, "/$s"))),
			'The cached connection should still serve the cached listing.'
		);

		// Control: an uncached connection to the same bucket sees the change immediately.
		$this->assertSame(
			['first.txt', 'second.txt'],
			$this->names($this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, "/$s")))
		);
	}

	public function testAnUploadInvalidatesTheFoldersCachedListing(): void
	{
		$s = $this->scratch();
		$this->bucket()->put("$s/first.txt", "1\n");
		$this->assertApiSuccess($this->media()->get(self::CACHED, "/$s"));

		$this->assertApiSuccess($this->media()->createFile(self::CACHED, "/$s", 'uploaded.txt', "u\n"));

		$this->assertSame(
			['first.txt', 'uploaded.txt'],
			$this->names($this->assertApiSuccess($this->media()->get(self::CACHED, "/$s")))
		);
	}

	public function testANewFolderInvalidatesTheParentsCachedListing(): void
	{
		$s = $this->scratch();
		$this->bucket()->put("$s/first.txt", "1\n");
		$this->assertApiSuccess($this->media()->get(self::CACHED, "/$s"));

		$this->assertApiSuccess($this->media()->createFolder(self::CACHED, "/$s", 'made'));

		$this->assertSame(
			['first.txt', 'made'],
			$this->names($this->assertApiSuccess($this->media()->get(self::CACHED, "/$s")))
		);
	}

	public function testADeleteInvalidatesTheFoldersCachedListing(): void
	{
		$s = $this->scratch();
		$this->bucket()->putMany($s, ['a.txt' => "a\n", 'b.txt' => "b\n"]);
		$this->assertApiSuccess($this->media()->get(self::CACHED, "/$s"));

		$this->assertApiSuccess($this->media()->delete(self::CACHED, "/$s/a.txt"));

		$this->assertSame(['b.txt'], $this->names($this->assertApiSuccess($this->media()->get(self::CACHED, "/$s"))));
	}

	public function testAMoveInvalidatesBothFolders(): void
	{
		$s = $this->scratch();
		$this->bucket()->putMany($s, ['src/m.txt' => "m\n", 'dst/other.txt' => "o\n"]);
		$this->assertApiSuccess($this->media()->get(self::CACHED, "/$s/src"));
		$this->assertApiSuccess($this->media()->get(self::CACHED, "/$s/dst"));

		$this->assertApiSuccess($this->media()->move(self::CACHED, "/$s/src/m.txt", "/$s/dst/m.txt"));

		$this->assertSame([], $this->names($this->assertApiSuccess($this->media()->get(self::CACHED, "/$s/src"))));
		$this->assertSame(
			['m.txt', 'other.txt'],
			$this->names($this->assertApiSuccess($this->media()->get(self::CACHED, "/$s/dst")))
		);
	}

	public function testTheCacheLivesInTheSitesCacheFolder(): void
	{
		$this->assertApiSuccess($this->media()->get(self::CACHED, '/fixtures'));

		$this->assertDirectoryExists(static::$config->getSiteRoot() . '/administrator/cache/plg_filesystem_s3');
	}
}
