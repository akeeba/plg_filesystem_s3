<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\IntegrationTest\AbstractE2ETestCase;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\ContainerCli;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\SiteProvisioner;

/**
 * The URL the Media Manager inserts into content when someone picks a file. It must be permanent (no
 * pre-signature: the plugin only supports public objects), must name the object, and must be fetchable.
 *
 * URLs point at MinIO as the SITE sees it (http://minio:9000). The host fetches them through the
 * published port instead ({@see \Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Bucket::toHostUrl()}),
 * and one test fetches a URL from inside the site's own container, exactly as handed out.
 */
class PublicUrlTest extends AbstractE2ETestCase
{
	public function testAnS3UrlIsPermanentAndNamesTheObject(): void
	{
		$url = $this->urlOf(SiteProvisioner::ADAPTER_V2, '/fixtures/hello.txt');

		$this->assertStringNotContainsString('?', $url, 'A pre-signed (expiring) URL leaked out.');
		$this->assertSame('/' . $this->bucket()->getName() . '/fixtures/hello.txt', parse_url($url, PHP_URL_PATH));

		$object = $this->guest()->get($this->bucket()->toHostUrl($url));

		$this->assertSame(200, $object->code, $object->summary());
		$this->assertSame("Hello, S3!\n", $object->body);
	}

	public function testAnS3UrlUsesTheEndpointsScheme(): void
	{
		$url = $this->urlOf(SiteProvisioner::ADAPTER_V2, '/fixtures/hello.txt');

		$this->knownBug('url-forced-https');

		$this->assertStringStartsWith(static::$config->getS3()['internalEndpoint'] . '/', $url);
	}

	public function testAV4PathStyleUrlKeepsTheBucketInThePath(): void
	{
		$url = $this->urlOf(SiteProvisioner::ADAPTER_V4, '/fixtures/hello.txt');

		$this->knownBug('v4-url-virtual-host');

		$this->assertSame('minio', parse_url($url, PHP_URL_HOST));
		$this->assertSame('/' . $this->bucket()->getName() . '/fixtures/hello.txt', parse_url($url, PHP_URL_PATH));
	}

	public function testANestedConnectionsUrlIncludesItsDirectory(): void
	{
		$url = $this->urlOf(SiteProvisioner::ADAPTER_NESTED, '/inside.txt');

		$this->assertSame(
			'/' . $this->bucket()->getName() . '/' . SiteProvisioner::NESTED_DIRECTORY . '/inside.txt',
			parse_url($url, PHP_URL_PATH)
		);
	}

	public function testACdnUrlIsTheCdnBaseFollowedByThePath(): void
	{
		$url = $this->urlOf(SiteProvisioner::ADAPTER_CDN, '/fixtures/hello.txt');

		$s3 = static::$config->getS3();
		$this->assertSame($s3['internalEndpoint'] . '/' . $s3['bucket'] . '/fixtures/hello.txt', $url);
	}

	/**
	 * The one URL this suite can fetch exactly as handed out: the site's own container resolves `minio`.
	 */
	public function testACdnUrlIsFetchableFromTheSite(): void
	{
		$url = $this->urlOf(SiteProvisioner::ADAPTER_CDN, '/fixtures/hello.txt');

		[$exitCode, $output] = (new ContainerCli(static::$config))->php(
			// base64: exec() would otherwise swallow the trailing newline of the body.
			'echo base64_encode(file_get_contents(' . var_export($url, true) . '));'
		);

		$this->assertSame(0, $exitCode, $output);
		$this->assertSame("Hello, S3!\n", base64_decode($output));
	}

	public function testUrlsEncodeSpaces(): void
	{
		$s = $this->scratch();
		$this->assertApiSuccess($this->media()->createFile(SiteProvisioner::ADAPTER_CDN, "/$s", 'a b.txt', "ab\n"));

		$url = $this->urlOf(SiteProvisioner::ADAPTER_CDN, "/$s/a b.txt");

		$this->assertStringEndsWith("/$s/a%20b.txt", $url);
		$this->assertSame("ab\n", $this->guest()->get($this->bucket()->toHostUrl($url))->body);
	}

	/**
	 * A file uploaded through the plugin is readable by anyone — which is the whole point of a media
	 * URL, and the reason the plugin requires public objects.
	 */
	public function testAnUploadedFileIsPubliclyReadable(): void
	{
		$s = $this->scratch();
		$this->assertApiSuccess($this->media()->createFile(SiteProvisioner::ADAPTER_V2, "/$s", 'public.txt', "public\n"));

		$url    = $this->urlOf(SiteProvisioner::ADAPTER_V2, "/$s/public.txt");
		$object = $this->guest()->get($this->bucket()->toHostUrl($url));

		$this->assertSame(200, $object->code, $object->summary());
		$this->assertSame("public\n", $object->body);
	}

	private function urlOf(string $adapter, string $path): string
	{
		$data = $this->assertApiSuccess($this->media()->get($adapter, $path, ['url' => 1]));

		$this->assertCount(1, $data);
		$this->assertArrayHasKey('url', $data[0]);

		return $data[0]['url'];
	}
}
