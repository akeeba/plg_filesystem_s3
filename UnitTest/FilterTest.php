<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\UnitTest;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\Filter;
use Akeeba\Plugin\Filesystem\S3\Helper\PathNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The connection's Directory option becomes an S3 key prefix AND a URL path component. The filter must
 * leave it with no leading/trailing slash, no empty segments, and nothing URL-significant.
 */
#[CoversClass(Filter::class)]
#[CoversClass(PathNormaliser::class)]
class FilterTest extends TestCase
{
	/**
	 * @return array<string, array{0: ?string, 1: string}>
	 */
	public static function provideDirectories(): array
	{
		return [
			'null'                         => [null, ''],
			'empty'                        => ['', ''],
			'only slashes'                 => ['///', ''],
			'plain'                        => ['images', 'images'],
			'nested'                       => ['a/b/c', 'a/b/c'],
			'leading and trailing slashes' => ['/images/', 'images'],
			'backslashes'                  => ['\\a\\b\\', 'a/b'],
			'doubled slashes'              => ['a//b///c', 'a/b/c'],
			'mixed separators'             => ['/a\\/b/', 'a/b'],
			'fragment marker'              => ['a#b', 'ab'],
			'query marker'                 => ['a?x=1', 'ax=1'],
			'NUL byte'                     => ["a\0b", 'ab'],
			'CR/LF (header injection)'     => ["a\r\nb", 'ab'],
			'slash left dangling by ?'     => ['a/?/b', 'a/b'],
		];
	}

	#[DataProvider('provideDirectories')]
	public function testNormalisesTheDirectory(?string $input, string $expected): void
	{
		$this->assertSame($expected, Filter::filterDirectory($input));
	}

	public function testComposesUnicodeToNfc(): void
	{
		if (!class_exists(\Normalizer::class))
		{
			$this->markTestSkipped('ext-intl is not loaded; PathNormaliser skips NFC normalisation without it.');
		}

		// "e" + COMBINING ACUTE ACCENT (NFD) must become the single code point "é" (NFC).
		$this->assertSame("caf\u{00E9}", Filter::filterDirectory("cafe\u{0301}"));
	}

	public function testTheNormaliserLeavesSlashHandlingToTheCaller(): void
	{
		$this->assertSame('/a//b/', PathNormaliser::normaliseUnicodePath("/a//b/\n"));
	}
}
