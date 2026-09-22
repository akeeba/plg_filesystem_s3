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
	 * Without the fix this request loops server-side until PHP's max_execution_time; the client gives up
	 * after 60 seconds. It is skipped BEFORE the objects are uploaded and the request is made, so a known
	 * bug costs nothing.
	 */
	public function testSearchingALargeFolderFinishes(): void
	{
		$this->knownBug('search-pagination');

		$objects = ['zz-needle.txt' => "needle\n"];

		for ($i = 1; $i <= 1005; $i++)
		{
			$objects[sprintf('file-%04d.txt', $i)] = "$i\n";
		}

		$this->bucket()->putMany($this->scratch() . '/big', $objects);

		$started = microtime(true);
		$found   = $this->assertApiSuccess(
			$this->media()->get(self::ADAPTER, '/' . $this->scratch() . '/big', ['search' => 'zz-needle.txt', 'recursive' => 0])
		);

		$this->assertSame(['zz-needle.txt'], $this->names($found));
		$this->assertLessThan(30, microtime(true) - $started);
	}
}
