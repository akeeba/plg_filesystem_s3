<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\UnitTest\Structure;

defined('_JEXEC') or die;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every PHP file the plugin ships (bar its Composer vendor tree) carries the direct-access guard.
 *
 * The codebase uses two spellings — `defined('_JEXEC') or die;` and `defined('_JEXEC') || die;`, the
 * latter optionally with a leading backslash — and both are accepted. What is not accepted is a file
 * without either.
 */
class JexecGuardTest extends TestCase
{
	private const GUARD = "/\\\\?defined\\('_JEXEC'\\)\\s*(?:or|\\|\\|)\\s*die\\s*;/";

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function providePhpFiles(): array
	{
		$root  = S3FS_UNITTEST_REPO . '/plugins/filesystem/s3';
		$cases = [];

		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

		foreach ($iterator as $file)
		{
			$relative = 'plugins/filesystem/s3' . str_replace('\\', '/', substr($file->getPathname(), \strlen($root)));

			if (!$file->isFile() || strtolower($file->getExtension()) !== 'php' || str_starts_with($relative, 'plugins/filesystem/s3/vendor/'))
			{
				continue;
			}

			$cases[$relative] = [$relative];
		}

		ksort($cases);

		return $cases;
	}

	#[DataProvider('providePhpFiles')]
	public function testThePhpFileCarriesTheJexecGuard(string $relativePath): void
	{
		$this->assertMatchesRegularExpression(
			self::GUARD,
			file_get_contents(S3FS_UNITTEST_REPO . '/' . $relativePath),
			"$relativePath is missing the `defined('_JEXEC') or die;` guard."
		);
	}

	/**
	 * Guards the guard: if the scan stopped finding files, every test above would silently vanish.
	 */
	public function testTheScanCoversTheWholePlugin(): void
	{
		$this->assertGreaterThanOrEqual(9, \count(self::providePhpFiles()));
	}
}
