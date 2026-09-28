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
 * New file and folder names are made safe exactly as Joomla's own local adapter makes them safe
 * (LocalAdapter::getSafeName(), built on File::makeSafe()).
 *
 * Names are handed to the browser raw, and core's editor-insert code interpolates them into HTML
 * unescaped, so a name such as `x" onerror="…" y=".png` is stored XSS waiting for an editor to pick it
 * (security.md, M2). The local adapter never lets such a name exist; neither may the S3 adapter.
 *
 * Every expectation is taken from the local adapter on the same site, so these tests follow core if it
 * ever changes its rules.
 */
class FileNameSafetyTest extends AbstractE2ETestCase
{
	private const ADAPTER = SiteProvisioner::ADAPTER_V2;

	private const LOCAL_ADAPTER = 'local-images';

	private const EVENT_HANDLER_NAME = 'x" onerror="alert(document.domain)" y=".png';

	private const TAG_NAME = 'y"><svg onload=alert(1)>.png';

	/**
	 * Scratch folder in the local adapter, removed in tearDown().
	 */
	private ?string $localScratch = null;

	protected function tearDown(): void
	{
		if ($this->localScratch !== null)
		{
			$this->media()->delete(self::LOCAL_ADAPTER, '/' . $this->localScratch);

			$this->localScratch = null;
		}

		parent::tearDown();
	}

	public function testUploadingAFileWithAnEventHandlerInItsNameStoresTheLocalAdaptersSafeName(): void
	{
		$png      = base64_decode(SiteProvisioner::PNG_BASE64);
		$expected = $this->localSafeName(self::EVENT_HANDLER_NAME);

		$data = $this->assertApiSuccess(
			$this->media()->createFile(self::ADAPTER, '/' . $this->scratch(), self::EVENT_HANDLER_NAME, $png)
		);

		$this->assertSame($expected, $data['name']);
		$this->assertSame([$this->scratch() . '/' . $expected], $this->bucket()->keys($this->scratch()));
		$this->assertStringNotContainsString('"', $data['path']);
		$this->assertStringNotContainsString('"', $data['url'] ?? '');
	}

	public function testCreatingAFolderWithAMarkupNameStoresTheLocalAdaptersSafeName(): void
	{
		$name     = 'a"><b onmouseover=alert(1)>';
		$expected = $this->localSafeFolderName($name);

		$data = $this->assertApiSuccess($this->media()->createFolder(self::ADAPTER, '/' . $this->scratch(), $name));

		$this->assertSame($expected, $data['name']);
		$this->assertSame([$this->scratch() . '/' . $expected . '/'], $this->bucket()->keys($this->scratch()));
	}

	public function testCopyingToAMarkupNameStoresTheLocalAdaptersSafeName(): void
	{
		$s        = $this->scratch();
		$expected = $this->localSafeName(self::TAG_NAME);

		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s", 'orig.png', $this->png()));
		$this->assertApiSuccess($this->media()->copy(self::ADAPTER, "/$s/orig.png", "/$s/" . self::TAG_NAME));

		$this->assertSame(["$s/orig.png", "$s/$expected"], $this->bucket()->keys($s));
	}

	public function testRenamingToAMarkupNameIsRefusedLikeTheLocalAdapterRefusesIt(): void
	{
		$s = $this->scratch();

		// Baseline: core refuses to rename to a name it would have to change, rather than changing it.
		$local = $this->localScratch();
		$this->assertApiSuccess($this->media()->createFile(self::LOCAL_ADAPTER, "/$local", 'orig.png', $this->png()));
		$refusal = $this->media()->move(self::LOCAL_ADAPTER, "/$local/orig.png", "/$local/" . self::TAG_NAME);
		$this->assertFalse($refusal->json()['success'] ?? true, 'Baseline: ' . $refusal->summary());

		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s", 'orig.png', $this->png()));

		$this->assertApiRefused(
			$refusal->code, $this->media()->move(self::ADAPTER, "/$s/orig.png", "/$s/" . self::TAG_NAME)
		);
		$this->assertSame(["$s/orig.png"], $this->bucket()->keys($s), 'A refused rename must change nothing.');
	}

	public function testRenamingOnlyChangesTheCaseOfTheExtensionLikeTheLocalAdapter(): void
	{
		$s = $this->scratch();

		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s", 'orig.png', $this->png()));
		$this->assertApiSuccess($this->media()->move(self::ADAPTER, "/$s/orig.png", "/$s/Renamed.PNG"));

		$this->assertSame(["$s/Renamed.png"], $this->bucket()->keys($s));
	}

	public function testEditingAFileWithAnUnsafeNameIsRefusedAndLeavesItAlone(): void
	{
		/**
		 * Like the local adapter: an edit neither renames the file nor writes it. Core runs its upload policy
		 * on the existing name, which refuses names File::makeSafe() would change.
		 */
		$key = $this->scratch() . '/odd "name".txt';
		$this->bucket()->put($key, "before\n");

		$this->assertApiRefused(403, $this->media()->updateFile(self::ADAPTER, '/' . $key, "after\n"));

		$this->assertSame([$key], $this->bucket()->keys($this->scratch()));
		$this->assertSame("before\n", $this->bucket()->get($key)->body);
	}

	public function testRenamingAFolderKeepsTheNamesOfItsContents(): void
	{
		// Like the local adapter, only the folder's own new name is checked; its contents move as they are.
		$s = $this->scratch();
		$this->assertApiSuccess($this->media()->createFolder(self::ADAPTER, "/$s", 'old'));
		$this->bucket()->put("$s/old/odd \"name\".txt", "odd\n");

		$this->assertApiSuccess($this->media()->move(self::ADAPTER, "/$s/old", "/$s/new"));

		$this->assertSame(["$s/new/", "$s/new/odd \"name\".txt"], $this->bucket()->keys($s));
	}

	/**
	 * The name the local adapter gives a file uploaded under this name.
	 */
	private function localSafeName(string $name): string
	{
		$local = $this->localScratch();
		$data  = $this->assertApiSuccess($this->media()->createFile(self::LOCAL_ADAPTER, "/$local", $name, $this->png()));

		return $data['name'];
	}

	/**
	 * The name the local adapter gives a folder created under this name.
	 */
	private function localSafeFolderName(string $name): string
	{
		$local = $this->localScratch();
		$data  = $this->assertApiSuccess($this->media()->createFolder(self::LOCAL_ADAPTER, "/$local", $name));

		return $data['name'];
	}

	private function localScratch(): string
	{
		if ($this->localScratch === null)
		{
			$this->localScratch = 'e2e-' . bin2hex(random_bytes(6));

			$this->assertApiSuccess($this->media()->createFolder(self::LOCAL_ADAPTER, '/', $this->localScratch));
		}

		return $this->localScratch;
	}

	private function png(): string
	{
		return base64_decode(SiteProvisioner::PNG_BASE64);
	}
}
