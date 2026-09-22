<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\UnitTest\Adapter;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\Adapter\S3Filesystem;
use Akeeba\S3\Configuration;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\Http\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The adapter's pure parts: turning a saved connection into an S3 client configuration, building public
 * URLs, and sanitising names. Nothing here talks to S3 — the constructor builds a Connector but makes no
 * request, and getUrl() only signs (then strips) a URL locally.
 *
 * Everything that needs a bucket — listing, CRUD, caching — is in the end-to-end suite.
 */
#[CoversClass(S3Filesystem::class)]
class S3FilesystemTest extends TestCase
{
	/**
	 * A working connection to an S3-compatible server, as saved by the plugin's subform.
	 */
	private const CUSTOM = [
		'label'          => 'Test',
		'type'           => 'custom',
		'customendpoint' => 'https://storage.example.com',
		'accesskey'      => 'AKIAEXAMPLE',
		'secretkey'      => 'secret',
		'bucket'         => 'my-bucket',
		'signature'      => 'v2',
		'pathaccess'     => 'path',
		'directory'      => '',
	];

	protected function setUp(): void
	{
		HttpFactory::reset();
		$this->resetEc2CredentialsCache();
	}

	protected function tearDown(): void
	{
		HttpFactory::reset();
		$this->resetEc2CredentialsCache();
	}

	public function testTheAdapterIsNamedAfterTheConnectionLabel(): void
	{
		$this->assertSame('Test', $this->adapter()->getAdapterName());
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideMissingSettings(): array
	{
		return [
			'no access key' => ['accesskey', 'Access Key'],
			'no secret key' => ['secretkey', 'Secret Key'],
			'no bucket'     => ['bucket', 'Bucket'],
		];
	}

	#[DataProvider('provideMissingSettings')]
	public function testRefusesAnIncompleteConnection(string $key, string $message): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		$this->adapter([$key => '']);
	}

	public function testDoesNotAskEc2ForCredentialsOnACustomEndpoint(): void
	{
		try
		{
			$this->adapter(['accesskey' => '', 'secretkey' => '']);
			$this->fail('A custom endpoint without keys must be refused.');
		}
		catch (RuntimeException $e)
		{
			$this->assertStringContainsString('Access Key', $e->getMessage());
		}

		$this->assertSame([], HttpFactory::$requests, 'The EC2 metadata service must not be contacted.');
	}

	public function testDoesNotAskEc2ForCredentialsWithV2Signatures(): void
	{
		$this->expectException(RuntimeException::class);

		try
		{
			$this->amazon(['accesskey' => '', 'secretkey' => '', 'signature' => 'v2']);
		}
		finally
		{
			$this->assertSame([], HttpFactory::$requests);
		}
	}

	public function testUsesEc2RoleCredentialsWhenBothKeysAreEmpty(): void
	{
		HttpFactory::queue([200, 'token']);
		HttpFactory::queue([200, 'role']);
		HttpFactory::queue(
			[
				200,
				json_encode(
					[
						'AccessKeyId'     => 'ASIATEMPORARY',
						'SecretAccessKey' => 'temporary-secret',
						'Token'           => 'session-token',
						'Expiration'      => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
					]
				),
			]
		);

		$config = $this->configuration($this->amazon(['accesskey' => '', 'secretkey' => '']));

		$this->assertSame('ASIATEMPORARY', $config->getAccess());
		$this->assertSame('temporary-secret', $config->getSecret());
		$this->assertSame('session-token', $config->getToken());

		// A second adapter in the same page load reuses the cached, unexpired credentials.
		$this->amazon(['accesskey' => '', 'secretkey' => '']);
		$this->assertCount(3, HttpFactory::$requests, 'The metadata service must be asked only once per page load.');
	}

	public function testOnlyOneEmptyKeyIsAConfigurationErrorNotAnEc2Lookup(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Secret Key');

		try
		{
			$this->amazon(['secretkey' => '']);
		}
		finally
		{
			$this->assertSame([], HttpFactory::$requests);
		}
	}

	public function testAnInvalidSignatureMethodFallsBackToV4(): void
	{
		$config = $this->configuration($this->amazon(['signature' => 'v3']));

		$this->assertSame('v4', $config->getSignatureMethod());
	}

	public function testUsesTheSelectedAwsRegion(): void
	{
		$config = $this->configuration($this->amazon(['region' => 'eu-west-3']));

		$this->assertSame('eu-west-3', $config->getRegion());
	}

	/**
	 * The region list's last option is `custom`, which reveals the free-text custom_region field. The
	 * adapter only reads custom_region when region is the empty string — which the list never saves.
	 */
	public function testUsesTheCustomRegionWhenCustomIsSelected(): void
	{
		$this->markTestSkipped(
			'KNOWN BUG: S3Filesystem::getFromConnection() uses custom_region only when region === \'\', but s3.xml '
			. 'saves region="custom"; the literal string "custom" is used as the region and v4 signing fails.'
		);

		$config = $this->configuration($this->amazon(['region' => 'custom', 'custom_region' => 'xx-test-1']));

		$this->assertSame('xx-test-1', $config->getRegion());
	}

	public function testStripsTheProtocolFromTheCustomEndpointAndHonoursItForSsl(): void
	{
		$https = $this->configuration($this->adapter(['customendpoint' => 'https://storage.example.com/']));
		$http  = $this->configuration($this->adapter(['customendpoint' => 'http://minio:9000']));

		$this->assertSame('storage.example.com', $https->getEndpoint());
		$this->assertTrue($https->isSSL());
		$this->assertSame('minio:9000', $http->getEndpoint());
		$this->assertFalse($http->isSSL());
	}

	public function testTheCustomEndpointIsIgnoredForAmazonConnections(): void
	{
		$config = $this->configuration($this->amazon(['customendpoint' => 'http://evil.example.com']));

		$this->assertSame('s3.amazonaws.com', $config->getEndpoint());
	}

	public function testMapsPathStyleAndDualStackOntoTheClient(): void
	{
		$config = $this->configuration($this->amazon(['pathaccess' => 'path', 'dualstack' => '0']));

		$this->assertTrue($config->getUseLegacyPathStyle());
		$this->assertFalse($config->getDualstackUrl());

		$defaults = $this->configuration($this->amazon());

		$this->assertFalse($defaults->getUseLegacyPathStyle(), 'Virtual-hosted access is the default.');
		$this->assertTrue($defaults->getDualstackUrl(), 'DualStack is on by default.');
	}

	public function testTheHttpDateHeaderOptionOnlyAppliesToNonAmazonConnections(): void
	{
		$this->assertTrue($this->configuration($this->adapter(['useHTTPDateHeader' => '1']))->getUseHTTPDateHeader());
		$this->assertFalse($this->configuration($this->amazon(['useHTTPDateHeader' => '1']))->getUseHTTPDateHeader());
	}

	public function testRemovesSlashesFromTheBucketName(): void
	{
		$this->assertSame('mybucket', $this->getPrivate($this->adapter(['bucket' => '/my/bucket/']), 'bucket'));
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideAcls(): array
	{
		return [
			'public-read'               => ['public-read', 'public-read'],
			'private'                   => ['private', 'private'],
			'authenticated-read'        => ['authenticated-read', 'authenticated-read'],
			'bucket-owner-read'         => ['bucket-owner-read', 'bucket-owner-read'],
			'bucket-owner-full-control' => ['bucket-owner-full-control', 'bucket-owner-full-control'],
			'unknown'                   => ['public-read-write', 'public-read'],
			'wrong case'                => ['PRIVATE', 'public-read'],
			'empty'                     => ['', 'public-read'],
		];
	}

	#[DataProvider('provideAcls')]
	public function testOnlyAcceptsTheSupportedCannedAcls(string $configured, string $expected): void
	{
		$this->assertSame($expected, $this->getPrivate($this->adapter(['acl' => $configured]), 'acl'));
	}

	public function testDefaultsToPublicReadWhenNoAclIsSaved(): void
	{
		$this->assertSame('public-read', $this->getPrivate($this->adapter(), 'acl'));
	}

	/**
	 * @return array<string, array{0: array, 1: bool, 2: int}>
	 */
	public static function provideCacheSettings(): array
	{
		return [
			'off by default'          => [[], false, 300],
			'on'                      => [['caching' => '1', 'cache_time' => '60'], true, 60],
			'on, zero lifetime'       => [['caching' => '1', 'cache_time' => '0'], false, 0],
			'on, negative lifetime'   => [['caching' => '1', 'cache_time' => '-5'], false, 0],
			'on, lifetime over 1 yr'  => [['caching' => '1', 'cache_time' => '99999999999'], true, 31536000],
		];
	}

	#[DataProvider('provideCacheSettings')]
	public function testDerivesTheCacheSettings(array $settings, bool $enabled, int $lifetime): void
	{
		$adapter = $this->adapter($settings);

		$this->assertSame($enabled, $this->getPrivate($adapter, 'cachingEnabled'));
		$this->assertEquals($lifetime, $this->getPrivate($adapter, 'cacheLifetime'));
	}

	public function testACdnUrlIsTheCdnBaseFollowedByThePath(): void
	{
		$adapter = $this->adapter(
			['type' => 'customcdn', 'cdn_url' => 'https://cdn.example.com/media/', 'directory' => 'media']
		);

		// The CDN URL already corresponds to the connection's Directory (docs/caveats.md), so it is not
		// prefixed again.
		$this->assertSame('https://cdn.example.com/media/a/b%20c.png', $adapter->getUrl('/a/b c.png'));
	}

	public function testACdnTypeWithoutACdnUrlFallsBackToS3Urls(): void
	{
		$adapter = $this->adapter(['type' => 'customcdn', 'cdn_url' => '  ']);

		$this->assertFalse($this->getPrivate($adapter, 'isCDN'));
		$this->assertStringStartsWith('https://storage.example.com/my-bucket/', $adapter->getUrl('/x.png'));
	}

	public function testAnS3UrlIsUnsignedAndCarriesTheDirectoryPrefix(): void
	{
		$url = $this->adapter(['directory' => 'site/images'])->getUrl('/a/b c.png');

		$this->assertSame('https://storage.example.com/my-bucket/site/images/a/b%20c.png', $url);
		$this->assertStringNotContainsString('?', $url, 'The pre-signed query string must be stripped.');
	}

	public function testAnAmazonUrlIsUnsigned(): void
	{
		$url = $this->amazon()->getUrl('/photo.png');

		$this->assertSame('https://my-bucket.s3.amazonaws.com/photo.png', $url);
	}

	/**
	 * A plain-HTTP endpoint (a LAN MinIO, say) has no TLS listener, so an https:// URL to it is dead.
	 */
	public function testThePublicUrlUsesTheCustomEndpointsScheme(): void
	{
		$this->markTestSkipped(
			'KNOWN BUG: S3Filesystem::getUrl() always calls getAuthenticatedURL(..., $https = true), so an '
			. 'http:// custom endpoint gets https:// public URLs, which cannot be fetched.'
		);

		$url = $this->adapter(['customendpoint' => 'http://minio:9000'])->getUrl('/x.png');

		$this->assertStringStartsWith('http://minio:9000/', $url);
	}

	/**
	 * With "path" access selected the bucket must stay in the path: self-hosted S3 servers rarely have
	 * wildcard DNS for bucket.host names.
	 */
	public function testThePublicUrlHonoursPathStyleAccessWithV4Signatures(): void
	{
		$this->markTestSkipped(
			'KNOWN BUG: with v4 signatures, getUrl() builds a pre-signed URL, and akeeba/s3 V4::getAuthenticatedURL() '
			. 'always moves a valid bucket name into the hostname (bucket.endpoint), ignoring path-style access.'
		);

		$url = $this->adapter(['signature' => 'v4'])->getUrl('/x.png');

		$this->assertSame('https://storage.example.com/my-bucket/x.png', $url);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideNames(): array
	{
		return [
			'already safe'           => ['photo.png', 'photo.png'],
			'trailing dot'           => ['photo.png.', 'photo.png'],
			'several trailing dots'  => ['notes...', 'notes'],
			'slash'                  => ['a/b.png', 'a_b.png'],
			'no extension'           => ['README', 'README'],
			'leading dot kept'       => ['.hidden', '.hidden'],
		];
	}

	#[DataProvider('provideNames')]
	public function testMakesNamesSafeForS3(string $name, string $expected): void
	{
		$this->assertSame($expected, $this->makeSafeName($name));
	}

	public function testMakingANameSafeLowercasesItsExtension(): void
	{
		$this->markTestSkipped(
			'KNOWN BUG: S3Filesystem::makeSafeName() claims to lowercase the extension but re-appends it unchanged '
			. '(substr($name, 0, -strlen($ext)) . $ext); strtolower() is missing.'
		);

		$this->assertSame('PHOTO.jpg', $this->makeSafeName('PHOTO.JPG'));
	}

	public function testSetsTheStorageClassHeaderOnlyForAmazon(): void
	{
		$method = new ReflectionMethod(S3Filesystem::class, 'getStorageTypeHeaders');
		$adapter = $this->adapter();

		$this->assertSame(
			['X-Amz-Storage-Class' => 'STANDARD_IA'],
			$method->invoke($adapter, 'STANDARD_IA', 's3.amazonaws.com')
		);
		$this->assertSame(
			['X-Amz-Storage-Class' => 'STANDARD'],
			$method->invoke($adapter, 'STANDARD', 'amazonaws.com.cn')
		);
		$this->assertSame([], $method->invoke($adapter, 'STANDARD_IA', 'storage.example.com'));
	}

	private function adapter(array $overrides = []): S3Filesystem
	{
		return S3Filesystem::getFromConnection(array_merge(self::CUSTOM, $overrides), $this->app());
	}

	private function amazon(array $overrides = []): S3Filesystem
	{
		return $this->adapter(
			array_merge(
				['type' => 's3', 'customendpoint' => '', 'signature' => 'v4', 'region' => 'us-east-1', 'pathaccess' => 'virtualhost'],
				$overrides
			)
		);
	}

	private function configuration(S3Filesystem $adapter): Configuration
	{
		return $this->getPrivate($adapter, 'connector')->getConfiguration();
	}

	private function makeSafeName(string $name): string
	{
		return (new ReflectionMethod(S3Filesystem::class, 'makeSafeName'))->invoke($this->adapter(), $name);
	}

	/**
	 * @return mixed
	 */
	private function getPrivate(object $object, string $property)
	{
		return (new ReflectionProperty($object, $property))->getValue($object);
	}

	private function resetEc2CredentialsCache(): void
	{
		(new ReflectionProperty(S3Filesystem::class, 'ec2Credentials'))->setValue(null, null);
	}

	private function app(): CMSApplicationInterface
	{
		return new class implements CMSApplicationInterface {
			public function get($name, $default = null)
			{
				return $default;
			}
		};
	}
}
