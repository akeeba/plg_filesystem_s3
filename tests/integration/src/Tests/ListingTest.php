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
 * Browsing a bucket. This is where the Media Manager's adapter design bites hardest: getFiles() is
 * called for both folder listings AND single-file metadata, and getFile() for both files AND folders
 * (see the comments in S3Filesystem), so every one of those shapes is exercised.
 *
 * The seed folders have no placeholder objects, exactly like a bucket filled by any other S3 tool.
 */
class ListingTest extends AbstractE2ETestCase
{
	public function testTheRootListsTopLevelFoldersAsDirectories(): void
	{
		$listing = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/'));

		// Other tests' scratch folders (t-…) may live at the root too; they are not part of the fixture.
		$names = array_values(array_filter($this->names($listing), static fn(string $n): bool => !str_starts_with($n, 't-')));

		$this->assertSame(['fixtures', 'nested'], $names);

		$fixtures = $this->entry($listing, 'fixtures');
		$this->assertSame('dir', $fixtures['type']);
		$this->assertSame(SiteProvisioner::ADAPTER_V2 . ':/fixtures', $fixtures['path']);
		$this->assertSame(SiteProvisioner::ADAPTER_V2, $fixtures['adapter']);
	}

	public function testAFolderListsItsFilesAndSubfoldersButNotDeeperObjects(): void
	{
		$listing = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures'));

		$this->assertSame(['docs', 'hello.txt', 'pixel.png'], $this->names($listing));
		$this->assertSame('dir', $this->entry($listing, 'docs')['type']);
		$this->assertNotContains('readme.txt', $this->names($listing), 'fixtures/docs/readme.txt belongs to docs.');
	}

	public function testAFileCarriesItsMetadata(): void
	{
		$listing = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures'));
		$hello   = $this->entry($listing, 'hello.txt');
		$pixel   = $this->entry($listing, 'pixel.png');

		$this->assertSame('file', $hello['type']);
		$this->assertSame(SiteProvisioner::ADAPTER_V2 . ':/fixtures/hello.txt', $hello['path']);
		$this->assertSame('txt', $hello['extension']);
		$this->assertSame(\strlen("Hello, S3!\n"), $hello['size']);
		$this->assertSame('text/plain', $hello['mime_type']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $hello['modified_date']);
		$this->assertArrayNotHasKey('thumb_path', $hello, 'A text file gets no thumbnail.');

		$this->assertSame('image/png', $pixel['mime_type']);
		$this->assertArrayHasKey('thumb_path', $pixel, 'An image gets a thumbnail (preview is "always").');
	}

	/**
	 * GET on a file path is how the Media Manager fetches a single file's details — through getFiles().
	 */
	public function testAskingForAFileDescribesJustThatFile(): void
	{
		$data = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures/hello.txt'));

		$this->assertCount(1, $data);
		$this->assertSame('hello.txt', $data[0]['name']);
		$this->assertSame(11, $data[0]['size']);
	}

	public function testAFolderWithoutAPlaceholderObjectCanBeListed(): void
	{
		$this->assertFalse($this->bucket()->exists('fixtures/docs/'), 'Precondition: no placeholder object.');

		$listing = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures/docs'));

		$this->assertSame(['readme.txt'], $this->names($listing));
	}

	public function testAMissingFolderIsAnEmptyListing(): void
	{
		// S3 has no folders: a prefix with no objects under it is indistinguishable from an empty folder.
		$listing = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/no-such-folder'));

		$this->assertSame([], $listing);
	}

	public function testAMissingFileCannotBeFetchedWithUrl(): void
	{
		// With url=1 the Media Manager asks for the file itself, which must not be conjured up.
		$response = $this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures/nope.txt', ['url' => 1]);
		$json     = $response->json();

		$this->assertTrue(\in_array($response->code, [200, 404], true), $response->summary());
		$this->assertEmpty($json['data'] ?? null, 'No data may be returned for a file that does not exist.');
	}

	/**
	 * Listing is paginated at 1,000 keys per S3 request; the adapter must follow the continuation.
	 */
	public function testAFolderWithMoreThanAThousandObjectsIsListedCompletely(): void
	{
		$objects = [];

		for ($i = 1; $i <= 1005; $i++)
		{
			$objects[sprintf('file-%04d.txt', $i)] = "$i\n";
		}

		$this->bucket()->putMany($this->scratch() . '/big', $objects);

		$listing = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/' . $this->scratch() . '/big'));
		$names   = $this->names($listing);

		$this->assertCount(1005, $names);
		$this->assertSame(array_keys($objects), $names, 'Every object exactly once, none repeated.');
	}

	public function testNoPhpErrorsWhileBrowsing(): void
	{
		$this->media()->get(SiteProvisioner::ADAPTER_V2, '/');
		$this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures');
		$this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures/hello.txt', ['url' => 1]);

		$this->assertNoNewPluginPhpErrors();
	}
}
