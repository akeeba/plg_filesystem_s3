<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\UnitTest\Adapter;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\Adapter\S3Filesystem;
use Akeeba\Plugin\Filesystem\S3\UnitTest\Stubs\CurlRecorder;
use Akeeba\S3\Configuration;
use Akeeba\S3\Request;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\Component\Media\Administrator\Exception\InvalidPathException;
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
	/**
	 * The form saves region="custom" for its "Custom" option (known issue #4); older settings may hold ''.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provideCustomRegionChoices(): array
	{
		return [
			'"Custom", as the form saves it' => ['custom'],
			'empty, as older settings hold'  => [''],
		];
	}

	#[DataProvider('provideCustomRegionChoices')]
	public function testUsesTheCustomRegionWhenCustomIsSelected(string $region): void
	{
		$config = $this->configuration($this->amazon(['region' => $region, 'custom_region' => 'xx-test-1']));

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
		// akeeba/s3 once moved the bucket into the hostname here, ignoring path-style access (known issue #2).
		$url = $this->adapter(['signature' => 'v4'])->getUrl('/x.png');

		$this->assertSame('https://storage.example.com/my-bucket/x.png', $url);
	}

	/**
	 * Expectations are LocalAdapter::getSafeName()'s: File::makeSafe(), then a lowercase extension.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideNames(): array
	{
		return [
			'already safe'           => ['photo.png', 'photo.png'],
			'trailing dot'           => ['photo.png.', 'photo.png'],
			'several trailing dots'  => ['notes...', 'notes'],
			'slash'                  => ['a/b.png', 'ab.png'],
			'no extension'           => ['README', 'README'],
			'leading dot'            => ['.hidden', 'hidden'],
			'spaces kept'            => ['my photo.png', 'my photo.png'],
			'double dots'            => ['x..php.png', 'xphp.png'],
			'uppercase extension'    => ['PHOTO.JPG', 'PHOTO.jpg'],
			'event handler'          => ['x" onerror="alert(document.domain)" y=".png', 'x onerroralertdocument.domain y.png'],
			'tag'                    => ['y"><svg onload=alert(1)>.png', 'ysvg onloadalert1.png'],
			'single quotes'          => ["it's.png", 'its.png'],
			'control characters'     => ["a\r\nb\0c.png", 'abc.png'],
		];
	}

	#[DataProvider('provideNames')]
	public function testMakesNamesSafeLikeTheLocalAdapter(string $name, string $expected): void
	{
		$this->assertSame($expected, $this->makeSafeName($name));
	}

	public function testANameWithNothingSafeInItIsRefused(): void
	{
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('COM_MEDIA_ERROR_MAKESAFE');

		$this->makeSafeName('"<>"');
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: string, 2: int}>
	 */
	public static function provideAmazonConnectionsForTls(): array
	{
		return [
			// The form's defaults: virtual-hosted access over the dual-stack endpoint
			'defaults (dual-stack)'        => [['bucket' => 'mybucket'], 'mybucket.s3.dualstack.us-east-1.amazonaws.com', 2],
			'dual-stack off'               => [['bucket' => 'mybucket', 'dualstack' => '0'], 'mybucket.s3.us-east-1.amazonaws.com', 2],
			'China, dual-stack off'        => [['bucket' => 'mybucket', 'region' => 'cn-north-1', 'dualstack' => '0'], 'mybucket.s3.cn-north-1.amazonaws.com.cn', 2],
			'China, dual-stack'            => [['bucket' => 'mybucket', 'region' => 'cn-north-1'], 'mybucket.s3.dualstack.cn-north-1.amazonaws.com.cn', 2],
			// Controls: a dotted bucket name really cannot match Amazon's wildcard certificate
			'dotted bucket, dual-stack'    => [['bucket' => 'my.bucket'], 'my.bucket.s3.dualstack.us-east-1.amazonaws.com', 0],
			'dotted bucket, dual-stack off' => [['bucket' => 'my.bucket', 'dualstack' => '0'], 'my.bucket.s3.us-east-1.amazonaws.com', 0],
		];
	}

	/**
	 * akeeba/s3 must verify the TLS host name of the plugin's default Amazon connections (security.md, M3).
	 *
	 * The options are recorded from the real cURL calls akeeba/s3 makes, so this keeps guarding the plugin if
	 * the library drifts.
	 */
	#[DataProvider('provideAmazonConnectionsForTls')]
	public function testAmazonConnectionsVerifyTheTlsHostName(array $overrides, string $host, int $verifyHost): void
	{
		$adapter = $this->amazon($overrides);
		$request = new Request('HEAD', $this->getPrivate($adapter, 'bucket'), '/x.png', $this->configuration($adapter));

		$this->assertSame($host, $request->getHeaders()['Host'], 'The test is not exercising the intended host.');

		CurlRecorder::start();

		try
		{
			$request->getResponse();
		}
		finally
		{
			CurlRecorder::stop();
		}

		$this->assertSame($verifyHost, CurlRecorder::option(CURLOPT_SSL_VERIFYHOST));
		$this->assertTrue(CurlRecorder::option(CURLOPT_SSL_VERIFYPEER));
	}

	/**
	 * Paths are checked like Joomla's local adapter checks them (Path::check()): any `..` is refused.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provideRelativePaths(): array
	{
		return [
			'parent of the root'   => ['/../outside.png'],
			'parent mid-path'      => ['/a/../../outside.png'],
			'backslash separators' => ['/a\\..\\..\\outside.png'],
			'double dot in a name' => ['/a..b.png'],
		];
	}

	#[DataProvider('provideRelativePaths')]
	public function testPathsWithDoubleDotsAreRefused(string $path): void
	{
		$this->expectException(InvalidPathException::class);

		$this->adapter(['directory' => 'site/images'])->getUrl($path);
	}

	public function testPathsAreCleanedLikeTheLocalAdapterCleansThem(): void
	{
		// Backslashes are separators and repeated separators collapse, as with Path::clean().
		$url = $this->adapter(['directory' => 'site/images'])->getUrl('//a\\\\b//c.png');

		$this->assertSame('https://storage.example.com/my-bucket/site/images/a/b/c.png', $url);
	}

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: bool}>
	 */
	public static function provideErrorAudiences(): array
	{
		return [
			'user, debug off'       => [false, false, false],
			'user, debug on'        => [true, false, false],
			'Super User, debug off' => [false, true, false],
			'Super User, debug on'  => [true, true, true],
		];
	}

	/**
	 * Storage errors show only a generic message, except to a Super User with Site Debug on (security.md, L1).
	 *
	 * No network: the cURL recorder fails every request, which akeeba/s3 reports with its usual message naming
	 * the method, bucket and key.
	 */
	#[DataProvider('provideErrorAudiences')]
	public function testStorageErrorsNameNoInternalsUnlessASuperUserDebugs(bool $debug, bool $superUser, bool $raw): void
	{
		$adapter = S3Filesystem::getFromConnection(array_merge(self::CUSTOM, ['bucket' => 'secret-bucket']), $this->app($debug, $superUser));

		CurlRecorder::start();

		try
		{
			$adapter->getResource('/private/key.png');
			$this->fail('The request cannot succeed without a network.');
		}
		catch (\Akeeba\S3\Exception\CannotGetFile $e)
		{
			// The class is kept, so callers catching it behave the same.
		}
		finally
		{
			CurlRecorder::stop();
		}

		if ($raw)
		{
			$this->assertStringContainsString('secret-bucket', $e->getMessage());

			return;
		}

		$this->assertStringStartsWith('PLG_FILESYSTEM_S3_ERR_STORAGE', $e->getMessage());
		$this->assertStringNotContainsString('secret-bucket', $e->getMessage());
		$this->assertStringNotContainsString('private/key.png', $e->getMessage());
		$this->assertStringNotContainsString('Connector', $e->getMessage());
	}

	/**
	 * A Super User with Site Debug on gets akeeba/s3's dump of the S3 error body, but never the signed request
	 * Amazon echoes back on a signature error (security.md, L1). Guards against akeeba/s3 drifting.
	 */
	public function testTheDebugDumpShowsNoSignedRequest(): void
	{
		$adapter = S3Filesystem::getFromConnection(self::CUSTOM, $this->app(true, true));
		$body    = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SignatureDoesNotMatch</Code>'
			. '<Message>Signature mismatch.</Message><StringToSign>STRING-TO-SIGN-SECRET</StringToSign>'
			. '<SignatureProvided>SIGNATURE-SECRET</SignatureProvided>'
			. '<CanonicalRequest>PUT /x x-amz-security-token:SESSION-TOKEN-SECRET</CanonicalRequest>'
			. '<RequestId>REQ123</RequestId></Error>';

		CurlRecorder::start();
		CurlRecorder::respond(403, ['Content-Type' => 'application/xml'], $body);

		try
		{
			$adapter->createFolder('folder', '/');
			$this->fail('The request cannot succeed.');
		}
		catch (\Akeeba\S3\Exception\CannotPutFile $e)
		{
		}
		finally
		{
			CurlRecorder::stop();
		}

		$this->assertStringContainsString('Debug info', $e->getMessage());
		$this->assertStringContainsString('REQ123', $e->getMessage());

		foreach (['STRING-TO-SIGN-SECRET', 'SIGNATURE-SECRET', 'SESSION-TOKEN-SECRET', 'CanonicalRequest'] as $secret)
		{
			$this->assertStringNotContainsString($secret, $e->getMessage());
		}
	}

	/**
	 * v4 connections to S3-compatible services sign for their region (known issue #12). akeeba/s3 once emptied the
	 * region whenever a custom endpoint was set, and services which check the region refused every request.
	 */
	public function testV4CustomEndpointRequestsAreSignedForTheRegion(): void
	{
		$adapter = $this->adapter(['signature' => 'v4', 'region' => 'eu-central-1']);
		$request = new Request('HEAD', $this->getPrivate($adapter, 'bucket'), '/x.png', $this->configuration($adapter));

		CurlRecorder::start();

		try
		{
			$request->getResponse();
		}
		finally
		{
			CurlRecorder::stop();
		}

		$authorization = array_values(preg_grep('/^Authorization:/i', CurlRecorder::option(CURLOPT_HTTPHEADER) ?? []))[0] ?? '';

		$this->assertMatchesRegularExpression('#Credential=AKIAEXAMPLE/\d{8}/eu-central-1/s3/aws4_request#', $authorization);
	}

	/**
	 * Search works like Joomla's local adapter (known issue #6): the term matches anywhere in the name, and glob
	 * metacharacters in it are literal. com_media filters the term with getCmd() before it gets here; these guard
	 * the adapter itself.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public static function provideSearchTerms(): array
	{
		return [
			'part of a name'      => ['ell', ['hello']],
			'the whole name'      => ['other', ['other']],
			'a literal asterisk'  => ['*', ['h*llo']],
			'a literal question'  => ['h?llo', []],
			'literal brackets'    => ['[h]', []],
		];
	}

	#[DataProvider('provideSearchTerms')]
	public function testSearchMatchesPartOfTheNameLiterally(string $needle, array $expected): void
	{
		// Folders only: they are listed as common prefixes, with no date or thumbnail to work out.
		$listing = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><Name>my-bucket</Name><Prefix>dir/</Prefix>'
			. '<Marker></Marker><MaxKeys>1000</MaxKeys><Delimiter>/</Delimiter><IsTruncated>false</IsTruncated>'
			. '<CommonPrefixes><Prefix>dir/hello/</Prefix></CommonPrefixes>'
			. '<CommonPrefixes><Prefix>dir/h*llo/</Prefix></CommonPrefixes>'
			. '<CommonPrefixes><Prefix>dir/other/</Prefix></CommonPrefixes></ListBucketResult>';

		CurlRecorder::start();
		CurlRecorder::respond(200, ['Content-Type' => 'application/xml'], $listing);

		try
		{
			$found = $this->adapter()->search('/dir', $needle);
		}
		finally
		{
			CurlRecorder::stop();
		}

		$names = array_column($found, 'name');
		sort($names);

		$this->assertSame($expected, $names);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function providePathLikeSearchTerms(): array
	{
		return [
			'slash'      => ['a/b'],
			'backslash'  => ['a\\b'],
			'double dot' => ['..'],
		];
	}

	#[DataProvider('providePathLikeSearchTerms')]
	public function testASearchTermWithAPathIsRefused(string $needle): void
	{
		$this->expectException(InvalidPathException::class);

		CurlRecorder::start();

		try
		{
			$this->adapter()->search('/dir', $needle);
		}
		finally
		{
			CurlRecorder::stop();
		}
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

	private function app(bool $debug = false, bool $superUser = false): CMSApplicationInterface
	{
		return new class($debug, $superUser) implements CMSApplicationInterface {
			public function __construct(private bool $debug, private bool $superUser)
			{
			}

			public function get($name, $default = null)
			{
				return $name === 'debug' ? $this->debug : $default;
			}

			public function getIdentity()
			{
				return new class($this->superUser) {
					public function __construct(private bool $superUser)
					{
					}

					public function authorise($action, $asset = null)
					{
						return $this->superUser && $action === 'core.admin';
					}
				};
			}
		};
	}
}
