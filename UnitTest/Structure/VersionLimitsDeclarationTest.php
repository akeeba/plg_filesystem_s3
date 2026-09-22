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
 * The supported PHP and Joomla range is declared in composer.json and enforced by the installer script.
 * `extra.akcompat.locations` lists the installer's copies so tooling can rewrite them together; this
 * test fails if they drift apart — and with them the E2E harness, which reads its bounds from the
 * installer script too.
 */
class VersionLimitsDeclarationTest extends TestCase
{
	public function testTheInstallerEnforcesTheRangeComposerJsonDeclares(): void
	{
		$composer = json_decode(file_get_contents(S3FS_UNITTEST_REPO . '/composer.json'), true);

		$declared = [
			'minimumPhp'    => $this->bound($composer['require']['php'], '>='),
			'maximumPhp'    => $this->bound($composer['require']['php'], '<'),
			'minimumJoomla' => $this->bound($composer['extra']['akcompat']['limit'], '>='),
			'maximumJoomla' => $this->bound($composer['extra']['akcompat']['limit'], '<'),
		];

		foreach ($declared as $variable => $value)
		{
			$installer = $this->installerVariable($variable);

			$this->assertTrue(
				version_compare($this->pad($installer), $this->pad($value), 'eq'),
				sprintf('script.plg_filesystem_s3.php $%s is %s; composer.json declares %s.', $variable, $installer, $value)
			);
		}
	}

	public function testComposerPlatformIsTheDeclaredPhpFloor(): void
	{
		$composer = json_decode(file_get_contents(S3FS_UNITTEST_REPO . '/composer.json'), true);
		$floor    = $this->bound($composer['require']['php'], '>=');
		$platform = $composer['config']['platform']['php'];

		$this->assertSame(
			implode('.', \array_slice(explode('.', $floor), 0, 2)),
			implode('.', \array_slice(explode('.', $platform), 0, 2)),
			'config.platform.php must pin dependency resolution to the minimum supported PHP minor version.'
		);
	}

	private function bound(string $constraint, string $operator): string
	{
		$this->assertMatchesRegularExpression(
			'/' . preg_quote($operator, '/') . '\s*([0-9.]+)/',
			$constraint,
			"No $operator bound in '$constraint'."
		);

		preg_match('/' . preg_quote($operator, '/') . '\s*([0-9.]+)/', $constraint, $match);

		return $match[1];
	}

	private function installerVariable(string $name): string
	{
		$source = file_get_contents(S3FS_UNITTEST_REPO . '/plugins/filesystem/s3/script.plg_filesystem_s3.php');

		$this->assertMatchesRegularExpression("/\\\$$name\s*=\s*'([0-9.]+)'/", $source, "\$$name not found.");

		preg_match("/\\\$$name\s*=\s*'([0-9.]+)'/", $source, $match);

		return $match[1];
	}

	private function pad(string $version): string
	{
		return implode('.', array_pad(explode('.', $version), 3, '0'));
	}
}
