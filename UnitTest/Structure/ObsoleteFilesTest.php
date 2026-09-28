<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\UnitTest\Structure;

defined('_JEXEC') or die;

use PHPUnit\Framework\TestCase;

/**
 * Every file ever removed from the plugin is deleted from the site on update, and nothing that still ships
 * is (security.md, L6).
 *
 * Joomla replaces the files a package contains but never removes ones dropped from a folder the manifest
 * lists, so the installer script has to name them: $deleteFiles and $deleteFolders.
 */
class ObsoleteFilesTest extends TestCase
{
	private const PLUGIN = 'plugins/filesystem/s3/';

	public function testEveryFileRemovedFromThePluginIsDeletedOnUpdate(): void
	{
		$removed = $this->filesRemovedInGitHistory();
		$listed  = $this->listedPaths();

		foreach ($removed as $file)
		{
			$covered = array_filter(
				$listed,
				fn(string $path) => $path === '/' . $file || str_starts_with('/' . $file, rtrim($path, '/') . '/')
			);

			$this->assertNotEmpty($covered, "$file was removed from the plugin but the installer never deletes it.");
		}
	}

	public function testNothingThatStillShipsIsDeleted(): void
	{
		$listed = $this->listedPaths();

		$this->assertNotEmpty($listed, 'The installer script lists nothing to delete.');

		foreach ($listed as $path)
		{
			$this->assertFileDoesNotExist(S3FS_UNITTEST_REPO . $path, "The installer would delete $path, which still ships.");
		}
	}

	/**
	 * @return string[]  Paths relative to the site root, with a leading slash, as InstallerScript expects them
	 */
	private function listedPaths(): array
	{
		$script = file_get_contents(S3FS_UNITTEST_REPO . '/' . self::PLUGIN . 'script.plg_filesystem_s3.php');

		preg_match_all("#'(/" . preg_quote(self::PLUGIN, '#') . "[^']+)'#", $script, $matches);

		return $matches[1];
	}

	/**
	 * @return string[]  Repository-relative paths of files deleted from the plugin's own code
	 */
	private function filesRemovedInGitHistory(): array
	{
		if (!is_dir(S3FS_UNITTEST_REPO . '/.git'))
		{
			$this->markTestSkipped('Not a Git checkout.');
		}

		$output = shell_exec(
			'git -C ' . escapeshellarg(S3FS_UNITTEST_REPO) . ' log --diff-filter=D --name-only --format= -- '
			. escapeshellarg(self::PLUGIN) . ' 2>/dev/null'
		);

		if (!\is_string($output))
		{
			$this->markTestSkipped('Git is not available.');
		}

		$files = array_filter(
			array_map('trim', explode("\n", $output)),
			fn(string $file) => $file !== '' && !str_starts_with($file, self::PLUGIN . 'vendor/')
				// A file removed and later restored still ships.
				&& !file_exists(S3FS_UNITTEST_REPO . '/' . $file)
		);

		$this->assertNotEmpty($files, 'Git history lists no removed files; the test is not looking at the right thing.');

		return array_values(array_unique($files));
	}
}
