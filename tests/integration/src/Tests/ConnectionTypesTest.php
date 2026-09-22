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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The connection options that change how the plugin talks to S3: the signature method, a Directory
 * prefix, and credentials that do not work.
 */
class ConnectionTypesTest extends AbstractE2ETestCase
{
	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provideWorkingAdapters(): array
	{
		return [
			'v2 signatures' => [SiteProvisioner::ADAPTER_V2],
			'v4 signatures' => [SiteProvisioner::ADAPTER_V4],
			'CDN'           => [SiteProvisioner::ADAPTER_CDN],
		];
	}

	#[DataProvider('provideWorkingAdapters')]
	public function testAFileMakesTheFullRoundTrip(string $adapter): void
	{
		$s = $this->scratch();

		$this->assertApiSuccess($this->media()->createFile($adapter, "/$s", 'trip.txt', "round\n"));
		$this->assertSame("round\n", $this->bucket()->get("$s/trip.txt")->body);

		$listing = $this->assertApiSuccess($this->media()->get($adapter, "/$s"));
		$this->assertSame(['trip.txt'], $this->names($listing));

		$this->assertApiSuccess($this->media()->copy($adapter, "/$s/trip.txt", "/$s/copy.txt"));
		$this->assertApiSuccess($this->media()->delete($adapter, "/$s/trip.txt"));

		$this->assertSame(["$s/copy.txt"], $this->bucket()->keys($s));
	}

	public function testANestedConnectionSeesOnlyItsDirectory(): void
	{
		$listing = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_NESTED, '/'));

		$this->assertSame(['inside.txt', 'sub'], $this->names($listing));
		$this->assertSame(SiteProvisioner::ADAPTER_NESTED . ':/inside.txt', $this->entry($listing, 'inside.txt')['path']);
		$this->assertNotContains('outside.txt', $this->names($listing));
	}

	public function testANestedConnectionWritesUnderItsDirectory(): void
	{
		$s = $this->scratch();

		$data = $this->assertApiSuccess($this->media()->createFile(SiteProvisioner::ADAPTER_NESTED, "/$s", 'n.txt', "n\n"));

		$this->assertSame(SiteProvisioner::ADAPTER_NESTED . ":/$s/n.txt", $data['path'], 'Paths stay relative to the directory.');
		$this->assertTrue($this->bucket()->exists(SiteProvisioner::NESTED_DIRECTORY . "/$s/n.txt"));
		$this->assertSame([], $this->bucket()->keys($s), 'Nothing may land at the bucket root.');

		$this->bucket()->removePrefix(SiteProvisioner::NESTED_DIRECTORY . "/$s/");
	}

	public function testWrongCredentialsFailLoudlyWithoutLeakingTheSecret(): void
	{
		$response = $this->media()->get(SiteProvisioner::ADAPTER_BAD, '/');
		$json     = $response->json();

		$this->assertNotSame(200, $response->code, 'A listing with a wrong secret must not look like an empty bucket.');
		$this->assertFalse($json['success'] ?? true, $response->summary());
		$this->assertEmpty($json['data'] ?? null);
		$this->assertStringNotContainsString(static::$config->getS3()['secretKey'], $response->body);
		$this->assertStringNotContainsString('this-is-not-the-secret', $response->body);
	}

	public function testABrokenConnectionDoesNotAffectTheOthers(): void
	{
		$this->media()->get(SiteProvisioner::ADAPTER_BAD, '/');

		$listing = $this->assertApiSuccess($this->media()->get(SiteProvisioner::ADAPTER_V2, '/fixtures'));
		$this->assertContains('hello.txt', $this->names($listing));
	}

	public function testAWriteWithWrongCredentialsLeavesNoObject(): void
	{
		$s        = $this->scratch();
		$response = $this->media()->createFile(SiteProvisioner::ADAPTER_BAD, "/$s", 'x.txt', "x\n");

		$this->assertNotSame(200, $response->code, $response->summary());
		$this->assertSame([], $this->bucket()->keys($s));
	}

	public function testAConnectionWithoutABucketIsNotOffered(): void
	{
		$response = $this->media()->get(SiteProvisioner::ADAPTER_NOBUCKET, '/');

		$this->assertNotSame(200, $response->code, $response->summary());
		$this->assertEmpty($response->json()['data'] ?? null);
	}
}
