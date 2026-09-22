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
 * Creating, updating, copying, moving and deleting through the Media Manager, with every effect checked
 * in the bucket itself.
 */
class FileOperationsTest extends AbstractE2ETestCase
{
	private const ADAPTER = SiteProvisioner::ADAPTER_V2;

	public function testCreatingAFolderWritesAPlaceholderObject(): void
	{
		$data = $this->assertApiSuccess($this->media()->createFolder(self::ADAPTER, '/', $this->scratch()));

		$this->assertSame('dir', $data['type']);
		$this->assertSame(self::ADAPTER . ':/' . $this->scratch(), $data['path']);
		$this->assertSame([$this->scratch() . '/'], $this->bucket()->keys($this->scratch()));

		// The new, empty folder shows up in its parent.
		$root = $this->assertApiSuccess($this->media()->get(self::ADAPTER, '/'));
		$this->assertSame('dir', $this->entry($root, $this->scratch())['type']);
	}

	public function testUploadingAFileStoresItsContentAndHeaders(): void
	{
		$data = $this->assertApiSuccess(
			$this->media()->createFile(self::ADAPTER, '/' . $this->scratch(), 'notes.txt', "Some notes.\n")
		);

		$this->assertSame('notes.txt', $data['name']);
		$this->assertSame(12, $data['size']);

		$key    = $this->scratch() . '/notes.txt';
		$object = $this->bucket()->get($key);

		// Read anonymously: the object is public, as the plugin requires.
		$this->assertSame(200, $object->code, $object->summary());
		$this->assertSame("Some notes.\n", $object->body);
		$this->assertStringStartsWith('text/plain', (string) $object->getHeader('content-type'));
		$this->assertSame('attachment; filename="notes.txt"', $object->getHeader('content-disposition'));
	}

	public function testUploadingABinaryFileIsByteForByte(): void
	{
		$png = base64_decode(SiteProvisioner::PNG_BASE64);

		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, '/' . $this->scratch(), 'copy.png', $png));

		$object = $this->bucket()->get($this->scratch() . '/copy.png');

		$this->assertSame($png, $object->body);
		$this->assertSame('image/png', $object->getHeader('content-type'));
	}

	public function testUploadingOverAnExistingFileIsRefusedUnlessOverriding(): void
	{
		$dir = '/' . $this->scratch();
		$key = $this->scratch() . '/same.txt';

		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, $dir, 'same.txt', "first\n"));

		$this->assertApiRefused(409, $this->media()->createFile(self::ADAPTER, $dir, 'same.txt', "second\n"));
		$this->assertSame("first\n", $this->bucket()->get($key)->body, 'A refused upload must not overwrite.');

		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, $dir, 'same.txt', "third\n", true));
		$this->assertSame("third\n", $this->bucket()->get($key)->body);
	}

	public function testEditingAFileReplacesItsContent(): void
	{
		$path = '/' . $this->scratch() . '/edit.txt';

		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, '/' . $this->scratch(), 'edit.txt', "before\n"));
		$this->assertApiSuccess($this->media()->updateFile(self::ADAPTER, $path, "after\n"));

		$this->assertSame("after\n", $this->bucket()->get(ltrim($path, '/'))->body);
		$this->assertSame([$this->scratch() . '/edit.txt'], $this->bucket()->keys($this->scratch()));
	}

	public function testCopyingAFileLeavesTheOriginal(): void
	{
		$s = $this->scratch();
		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s", 'orig.txt', "original\n"));

		$data = $this->assertApiSuccess($this->media()->copy(self::ADAPTER, "/$s/orig.txt", "/$s/sub/dupe.txt"));

		$this->assertSame(self::ADAPTER . ":/$s/sub/dupe.txt", $data['path']);
		$this->assertSame("original\n", $this->bucket()->get("$s/orig.txt")->body);
		$this->assertSame("original\n", $this->bucket()->get("$s/sub/dupe.txt")->body);
	}

	public function testMovingAFileRemovesTheOriginal(): void
	{
		$s = $this->scratch();
		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s", 'from.txt', "moving\n"));

		$this->assertApiSuccess($this->media()->move(self::ADAPTER, "/$s/from.txt", "/$s/to.txt"));

		$this->assertSame(["$s/to.txt"], $this->bucket()->keys($s));
		$this->assertSame("moving\n", $this->bucket()->get("$s/to.txt")->body);
	}

	public function testRenamingAFolderMovesEverythingInIt(): void
	{
		$s = $this->scratch();
		$this->assertApiSuccess($this->media()->createFolder(self::ADAPTER, "/$s", 'old'));
		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s/old", 'a.txt', "a\n"));
		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s/old", 'b.txt', "b\n"));

		$this->assertApiSuccess($this->media()->move(self::ADAPTER, "/$s/old", "/$s/new"));

		$this->assertSame(["$s/new/", "$s/new/a.txt", "$s/new/b.txt"], $this->bucket()->keys($s));
		$this->assertSame("b\n", $this->bucket()->get("$s/new/b.txt")->body);
	}

	public function testDeletingAFileRemovesOnlyThatObject(): void
	{
		$s = $this->scratch();
		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s", 'keep.txt', "keep\n"));
		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s", 'drop.txt', "drop\n"));

		$this->assertApiSuccess($this->media()->delete(self::ADAPTER, "/$s/drop.txt"));

		$this->assertSame(["$s/keep.txt"], $this->bucket()->keys($s));
	}

	public function testDeletingAFolderCreatedInJoomlaDeletesItsContents(): void
	{
		$s = $this->scratch();
		$this->assertApiSuccess($this->media()->createFolder(self::ADAPTER, "/$s", 'tree'));
		$this->assertApiSuccess($this->media()->createFolder(self::ADAPTER, "/$s/tree", 'branch'));
		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s/tree/branch", 'leaf.txt', "leaf\n"));
		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s", 'sibling.txt', "sibling\n"));

		$this->assertApiSuccess($this->media()->delete(self::ADAPTER, "/$s/tree"));

		$this->assertSame(["$s/sibling.txt"], $this->bucket()->keys($s));
	}

	/**
	 * Folders uploaded by any other S3 tool have no `dir/` placeholder object. They list and browse fine;
	 * deleting them must work too.
	 */
	public function testDeletingAFolderCreatedOutsideJoomlaDeletesItsContents(): void
	{
		$s = $this->scratch();
		$this->bucket()->putMany("$s/external", ['one.txt' => "1\n", 'deeper/two.txt' => "2\n"]);
		$this->assertFalse($this->bucket()->exists("$s/external/"), 'Precondition: no placeholder object.');

		$this->knownBug('delete-no-placeholder');

		$this->assertApiSuccess($this->media()->delete(self::ADAPTER, "/$s/external"));

		$this->assertSame([], $this->bucket()->keys("$s/external"));
	}

	public function testFileNamesWithSpacesRoundTrip(): void
	{
		$s = $this->scratch();

		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s", 'with space.txt', "spaced\n"));

		$this->assertSame("spaced\n", $this->bucket()->get("$s/with space.txt")->body);

		$listing = $this->assertApiSuccess($this->media()->get(self::ADAPTER, "/$s"));
		$this->assertSame(['with space.txt'], $this->names($listing));
	}

	public function testUploadedFileExtensionsAreLowercased(): void
	{
		$s = $this->scratch();

		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, "/$s", 'SHOUT.TXT', "loud\n"));

		$this->knownBug('extension-case');

		$this->assertSame(["$s/SHOUT.txt"], $this->bucket()->keys($s));
	}

	public function testNoPhpErrorsDuringFileOperations(): void
	{
		$s = $this->scratch();

		$this->media()->createFolder(self::ADAPTER, "/$s", 'x');
		$this->media()->createFile(self::ADAPTER, "/$s/x", 'y.txt', "y\n");
		$this->media()->move(self::ADAPTER, "/$s/x/y.txt", "/$s/z.txt");
		$this->media()->delete(self::ADAPTER, "/$s/x");

		$this->assertNoNewPluginPhpErrors();
	}
}
