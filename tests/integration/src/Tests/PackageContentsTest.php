<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\IntegrationTest\Tests;

defined('_JEXEC') or die;

use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The installable package ships no development files from its dependencies (security.md, L5).
 *
 * akeeba/s3 is installed from source, where its archive excludes do not apply: its minitest/ harness would
 * run S3 tests against real buckets if a config.php were ever placed next to it, and none of these files has
 * a _JEXEC guard. Checks the package this run built and installed.
 */
class PackageContentsTest extends TestCase
{
	/**
	 * Patterns (regular expressions over ZIP entry names) that must not match anything.
	 */
	private const FORBIDDEN = [
		'#^vendor/akeeba/s3/minitest/#',
		'#^vendor/(.+/)?rector\.php$#',
		'#^vendor/(.+/)?composer\.lock$#',
		'#^vendor/(.+/)?[^/]+\.md$#',
	];

	/**
	 * Entries the plugin needs, to prove the excludes did not go too far.
	 */
	private const REQUIRED = [
		's3.xml',
		'script.plg_filesystem_s3.php',
		'services/provider.php',
		'vendor/autoload.php',
		'vendor/akeeba/s3/src/Request.php',
		'vendor/league/mime-type-detection/LICENSE',
		'vendor/league/mime-type-detection/src/FinfoMimeTypeDetector.php',
	];

	private static array $entries = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$packages = glob(\dirname(__DIR__, 4) . '/release/plg_filesystem_s3-*.zip') ?: [];

		usort($packages, fn(string $a, string $b) => filemtime($b) <=> filemtime($a));

		if ($packages === [])
		{
			self::markTestSkipped('No package in release/; build it first.');
		}

		$zip = new ZipArchive();
		$zip->open($packages[0]);

		for ($i = 0; $i < $zip->numFiles; $i++)
		{
			self::$entries[] = $zip->getNameIndex($i);
		}

		$zip->close();
	}

	public function testNoDependencyDevelopmentFilesShip(): void
	{
		foreach (self::FORBIDDEN as $pattern)
		{
			$this->assertSame([], array_values(preg_grep($pattern, self::$entries)), "The package ships files matching $pattern.");
		}
	}

	public function testEverythingThePluginNeedsShips(): void
	{
		foreach (self::REQUIRED as $entry)
		{
			$this->assertContains($entry, self::$entries);
		}
	}
}
