<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\IntegrationTest\AbstractE2ETestCase;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Response;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\SiteProvisioner;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Paths containing `..` are refused with 400, as Joomla's local adapter refuses them (Path::check()), and
 * nothing outside the connection's Directory is touched.
 *
 * com_media passes paths to the adapter raw. Before this was checked, containment rested on an accident:
 * libcurl removes dot segments AFTER the request was signed, so S3 refused the request with a signature
 * mismatch (security.md, L2).
 */
class PathContainmentTest extends AbstractE2ETestCase
{
	/**
	 * Directory = nested/root, so `..` points at nested/.
	 */
	private const ADAPTER = SiteProvisioner::ADAPTER_NESTED;

	private const LOCAL_ADAPTER = 'local-images';

	private string $canary = '';

	/**
	 * An existing file inside the S3 adapter's root, to copy or move from.
	 */
	private string $source = '';

	protected function setUp(): void
	{
		parent::setUp();

		// Just outside the connection's root: what a `..` would reach.
		$this->canary = 'nested/l2-canary-' . bin2hex(random_bytes(6)) . '.txt';
		$this->bucket()->put($this->canary, "canary\n");

		// Inside the nested connection's root: nested/root/<scratch>/source.png
		$this->source = '/' . $this->scratch() . '/source.png';
		$this->bucket()->put('nested/root' . $this->source, base64_decode(SiteProvisioner::PNG_BASE64));
	}

	protected function tearDown(): void
	{
		$this->bucket()->removePrefix($this->canary);
		$this->bucket()->removePrefix('nested/root/' . $this->scratch());

		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provideOperations(): array
	{
		return [
			'read a file'     => ['getFile'],
			'list a folder'   => ['list'],
			'delete'          => ['delete'],
			'upload into'     => ['upload'],
			'create a folder' => ['createFolder'],
			'edit'            => ['edit'],
			'copy to'         => ['copyTo'],
			'move to'         => ['moveTo'],
		];
	}

	#[DataProvider('provideOperations')]
	public function testTheLocalAdapterRefusesDoubleDots(string $operation): void
	{
		// Guards the test itself: the S3 expectation is what core does.
		$this->assertApiRefused(400, $this->attempt(self::LOCAL_ADAPTER, $operation, 'joomla_black.png'));
	}

	#[DataProvider('provideOperations')]
	public function testDoubleDotsAreRefusedAndNothingOutsideTheRootChanges(string $operation): void
	{
		$before = $this->bucket()->keys('nested/');

		$this->assertApiRefused(400, $this->attempt(self::ADAPTER, $operation, basename($this->canary)));

		$this->assertSame($before, $this->bucket()->keys('nested/'), 'Nothing may be created or removed.');
		$this->assertSame("canary\n", $this->bucket()->get($this->canary)->body);
	}

	/**
	 * Run one Media Manager operation with a path that climbs out of the adapter's root.
	 *
	 * @param   string  $outside  A file name just outside the root (it exists in the S3 case)
	 */
	private function attempt(string $adapter, string $operation, string $outside): Response
	{
		$media = $this->media();
		$png   = base64_decode(SiteProvisioner::PNG_BASE64);

		switch ($operation)
		{
			case 'getFile':
				return $media->get($adapter, '/../' . $outside);

			case 'list':
				return $media->get($adapter, '/..');

			case 'delete':
				return $media->delete($adapter, '/../' . $outside);

			case 'upload':
				return $media->createFile($adapter, '/..', 'l2-upload.png', $png);

			case 'createFolder':
				return $media->createFolder($adapter, '/..', 'l2-folder');

			case 'edit':
				return $media->updateFile($adapter, '/../' . $outside, "overwritten\n");

			case 'copyTo':
				return $media->copy($adapter, $this->sourceIn($adapter), '/../l2-copy.png');

			case 'moveTo':
				return $media->move($adapter, $this->sourceIn($adapter), '/../l2-moved.png');
		}

		$this->fail("Unknown operation $operation");
	}

	private function sourceIn(string $adapter): string
	{
		// images/joomla_black.png ships with every Joomla site; the refusal comes before any copy or move.
		return $adapter === self::LOCAL_ADAPTER ? '/joomla_black.png' : $this->source;
	}
}
