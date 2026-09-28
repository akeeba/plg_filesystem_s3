<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\IntegrationTest\AbstractE2ETestCase;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\ContainerCli;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\SiteProvisioner;

/**
 * Updating removes the files that older versions shipped and this one no longer does (security.md, L6).
 *
 * Joomla only replaces what the new package contains; files dropped from a folder the manifest lists stay
 * on the site forever unless the installer script deletes them.
 */
class UpdateCleanupTest extends AbstractE2ETestCase
{
	/**
	 * Files older releases shipped, relative to the plugin's folder.
	 */
	private const OBSOLETE = [
		'src/Library/Acl.php',
		'src/Library/Exception/CannotGetFile.php',
		'src/Library/Signature/V4.php',
		'src/Rule/AccessKeyRule.php',
		'src/Rule/SecretKeyRule.php',
	];

	private const PACKAGE_IN_SITE = 'e2e-update-package.zip';

	protected function tearDown(): void
	{
		@unlink(static::$config->getSiteRoot() . '/' . self::PACKAGE_IN_SITE);

		parent::tearDown();
	}

	public function testUpdatingRemovesFilesOlderVersionsShipped(): void
	{
		$pluginRoot = static::$config->getSiteRoot() . '/plugins/filesystem/s3/';

		// What an update from an old release finds on the site
		foreach (self::OBSOLETE as $file)
		{
			@mkdir(\dirname($pluginRoot . $file), 0755, true);
			file_put_contents($pluginRoot . $file, "<?php\n// Left behind by an older release\n");
		}

		$this->installBuiltPackage();

		foreach (self::OBSOLETE as $file)
		{
			$this->assertFileDoesNotExist($pluginRoot . $file);
		}

		$this->assertDirectoryDoesNotExist($pluginRoot . 'src/Library');

		// Nothing current went with them
		$this->assertFileExists($pluginRoot . 'src/Rule/BucketRule.php');
		$this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures'));
	}

	private function installBuiltPackage(): void
	{
		$packages = glob(\dirname(__DIR__, 4) . '/release/plg_filesystem_s3-*.zip') ?: [];

		usort($packages, fn(string $a, string $b) => filemtime($b) <=> filemtime($a));

		$this->assertNotEmpty($packages, 'No package in release/; build it first.');

		copy($packages[0], static::$config->getSiteRoot() . '/' . self::PACKAGE_IN_SITE);

		(new ContainerCli(static::$config))->joomlaOrFail(
			['extension:install', '--path=/var/www/html/' . self::PACKAGE_IN_SITE]
		);
	}
}
