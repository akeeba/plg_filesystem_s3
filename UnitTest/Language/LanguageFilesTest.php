<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\UnitTest\Language;

defined('_JEXEC') or die;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every shipped language file is valid, quoted the way Joomla expects, and has exactly the en-GB keys.
 *
 * Joomla loads language files with parse_ini_file(…, INI_SCANNER_RAW), which forgives a lot: a value wrapped
 * in curly quotes loads, and the user sees the curly quotes. Strict parsing, and the language debugger, do
 * not forgive it.
 */
class LanguageFilesTest extends TestCase
{
	private const LANGUAGE_ROOT = S3FS_UNITTEST_REPO . '/plugins/filesystem/s3/language';

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provideLanguageFiles(): array
	{
		$files = [];

		foreach (glob(self::LANGUAGE_ROOT . '/*/*.ini') as $file)
		{
			$files[substr($file, \strlen(self::LANGUAGE_ROOT) + 1)] = [$file];
		}

		return $files;
	}

	#[DataProvider('provideLanguageFiles')]
	public function testTheFileParsesStrictly(string $file): void
	{
		$this->assertIsArray(@parse_ini_file($file), 'parse_ini_file() fails: ' . (error_get_last()['message'] ?? ''));
	}

	#[DataProvider('provideLanguageFiles')]
	public function testEveryValueIsWrappedInStraightDoubleQuotes(string $file): void
	{
		foreach ($this->valueLines($file) as $number => $line)
		{
			$this->assertMatchesRegularExpression('/^[A-Z0-9_]+=".*"$/u', $line, "Line $number");
		}
	}

	#[DataProvider('provideLanguageFiles')]
	public function testApostrophesAreNotEscaped(string $file): void
	{
		foreach ($this->valueLines($file) as $number => $line)
		{
			// Neither is an escape: the user would see '' or \' literally.
			$this->assertStringNotContainsString("''", $line, "Line $number");
			$this->assertStringNotContainsString("\\'", $line, "Line $number");
		}
	}

	#[DataProvider('provideLanguageFiles')]
	public function testTheKeysAreTheEnGbKeys(string $file): void
	{
		$reference = self::LANGUAGE_ROOT . '/en-GB/' . basename($file);

		$this->assertFileExists($reference);
		$this->assertSame($this->keys($reference), $this->keys($file));
	}

	/**
	 * @return array<int, string>  Key/value lines, by line number
	 */
	private function valueLines(string $file): array
	{
		$lines = [];

		foreach (file($file, FILE_IGNORE_NEW_LINES) as $index => $line)
		{
			$line = trim($line);

			if ($line === '' || $line[0] === ';' || $line[0] === '[')
			{
				continue;
			}

			$lines[$index + 1] = $line;
		}

		return $lines;
	}

	/**
	 * @return string[]
	 */
	private function keys(string $file): array
	{
		$keys = array_map(fn(string $line) => strstr($line, '=', true), $this->valueLines($file));

		sort($keys);

		return $keys;
	}
}
