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
 * The Media Manager's search box. The yardstick is Joomla's own local adapter, which matches the term
 * anywhere in the name (`*term*`, glob metacharacters escaped).
 */
class SearchTest extends AbstractE2ETestCase
{
	private const ADAPTER = SiteProvisioner::ADAPTER_V2;

	public function testSearchFindsAFileByItsFullName(): void
	{
		$found = $this->assertApiSuccess(
			$this->media()->get(self::ADAPTER, '/fixtures', ['search' => 'hello.txt', 'recursive' => 0])
		);

		$this->assertSame(['hello.txt'], $this->names($found));
	}

	public function testRecursiveSearchLooksIntoSubfolders(): void
	{
		$found = $this->assertApiSuccess(
			$this->media()->get(self::ADAPTER, '/fixtures', ['search' => 'readme.txt', 'recursive' => 1])
		);

		$this->assertSame(['readme.txt'], $this->names($found));
		$this->assertSame(self::ADAPTER . ':/fixtures/docs/readme.txt', $found[0]['path']);
	}

	public function testSearchMatchesPartOfTheName(): void
	{
		$this->knownBug('search-exact-name');

		$found = $this->assertApiSuccess(
			$this->media()->get(self::ADAPTER, '/fixtures', ['search' => 'hello', 'recursive' => 0])
		);

		$this->assertSame(['hello.txt'], $this->names($found));
	}

	/**
	 * Searching pages through the folder 1,000 keys at a time. Before known issue #1 was fixed, the next page
	 * started after the last MATCH: with no match on a full page it re-fetched the first page forever (the
	 * client gave up after 60 seconds, PHP carried on until max_execution_time), and with one, pages
	 * overlapped.
	 */
	public function testSearchingALargeFolderFinishes(): void
	{
		$objects = ['zz-needle.txt' => "needle\n"];

		for ($i = 1; $i <= 1005; $i++)
		{
			$objects[sprintf('file-%04d.txt', $i)] = "$i\n";
		}

		$this->bucket()->putMany($this->scratch() . '/big', $objects);

		// A match on the last page, on the first page, and no match at all: each found once, never looping.
		foreach (['zz-needle.txt' => ['zz-needle.txt'], 'file-0005.txt' => ['file-0005.txt'], 'nothing.txt' => []] as $needle => $expected)
		{
			$started = microtime(true);
			$found   = $this->assertApiSuccess(
				$this->media()->get(self::ADAPTER, '/' . $this->scratch() . '/big', ['search' => $needle, 'recursive' => 0])
			);

			$this->assertSame($expected, $this->names($found), "Searching for $needle");
			$this->assertLessThan(30, microtime(true) - $started, "Searching for $needle");
		}
	}
}
