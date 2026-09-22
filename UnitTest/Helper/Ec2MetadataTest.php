<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\UnitTest\Helper;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\Helper\Ec2Metadata;
use Joomla\Http\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * IMDSv2 credential retrieval, driven through the programmable HttpFactory fake.
 *
 * The contract that matters: every failure mode — not on EC2, no role, garbage from the metadata
 * service, a network exception — yields null, never an exception and never a half-filled credential
 * set. A null makes the adapter constructor refuse the connection with "no Access Key", which is the
 * documented behaviour off EC2.
 */
#[CoversClass(Ec2Metadata::class)]
class Ec2MetadataTest extends TestCase
{
	private const TOKEN = 'imds-session-token';

	private const ROLE = 'my-role';

	protected function setUp(): void
	{
		HttpFactory::reset();
	}

	protected function tearDown(): void
	{
		HttpFactory::reset();
	}

	public function testReturnsTheRoleCredentialsFromTheThreeStepHandshake(): void
	{
		HttpFactory::queue([200, self::TOKEN . "\n"]);
		HttpFactory::queue([200, self::ROLE]);
		HttpFactory::queue([200, $this->credentialsJson()]);

		$credentials = Ec2Metadata::getCredentials();

		$this->assertSame(
			[
				'access_key' => 'AKIAEXAMPLE',
				'secret_key' => 'secret/example',
				'token'      => 'session-token',
				'expiration' => strtotime('2030-01-01T00:00:00Z'),
			],
			$credentials
		);

		[$tokenRequest, $roleRequest, $credentialsRequest] = HttpFactory::$requests;

		$this->assertSame('PUT', $tokenRequest['method']);
		$this->assertSame('http://169.254.169.254/latest/api/token', $tokenRequest['url']);
		$this->assertArrayHasKey('X-aws-ec2-metadata-token-ttl-seconds', $tokenRequest['headers']);

		// The trimmed session token authenticates both follow-up requests.
		$this->assertSame(self::TOKEN, $roleRequest['headers']['X-aws-ec2-metadata-token']);
		$this->assertSame(self::TOKEN, $credentialsRequest['headers']['X-aws-ec2-metadata-token']);
		$this->assertSame(
			'http://169.254.169.254/latest/meta-data/iam/security-credentials/' . self::ROLE,
			$credentialsRequest['url']
		);
	}

	public function testUrlEncodesTheRoleNameIntoTheCredentialsUrl(): void
	{
		HttpFactory::queue([200, self::TOKEN]);
		HttpFactory::queue([200, 'role/with space']);
		HttpFactory::queue([200, $this->credentialsJson()]);

		Ec2Metadata::getCredentials();

		$this->assertStringEndsWith('/security-credentials/role%2Fwith+space', HttpFactory::$requests[2]['url']);
	}

	public function testReturnsNullWhenTheMetadataServiceIsUnreachable(): void
	{
		HttpFactory::queue(new RuntimeException('Connection timed out'));

		$this->assertNull(Ec2Metadata::getCredentials());
		$this->assertCount(1, HttpFactory::$requests, 'No further requests after the token request fails.');
	}

	public function testReturnsNullWithoutASessionToken(): void
	{
		HttpFactory::queue([401, '']);

		$this->assertNull(Ec2Metadata::getCredentials());
		$this->assertCount(1, HttpFactory::$requests);
	}

	public function testReturnsNullWhenNoRoleIsAttached(): void
	{
		HttpFactory::queue([200, self::TOKEN]);
		HttpFactory::queue([404, '']);

		$this->assertNull(Ec2Metadata::getCredentials());
		$this->assertCount(2, HttpFactory::$requests);
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function provideBadCredentialResponses(): array
	{
		$valid = [
			'AccessKeyId'     => 'AKIAEXAMPLE',
			'SecretAccessKey' => 'secret',
			'Token'           => 'token',
			'Expiration'      => '2030-01-01T00:00:00Z',
		];

		$without = static function (string $key) use ($valid): string {
			unset($valid[$key]);

			return json_encode($valid);
		};

		return [
			'HTTP error'              => [500, json_encode($valid)],
			'empty body'              => [200, ''],
			'not JSON'                => [200, '<html>captive portal</html>'],
			'JSON scalar'             => [200, '"hello"'],
			'no AccessKeyId'          => [200, $without('AccessKeyId')],
			'no SecretAccessKey'      => [200, $without('SecretAccessKey')],
			'no Token'                => [200, $without('Token')],
			'no Expiration'           => [200, $without('Expiration')],
			'unparseable Expiration'  => [200, json_encode(array_merge($valid, ['Expiration' => 'not a date']))],
		];
	}

	#[DataProvider('provideBadCredentialResponses')]
	public function testReturnsNullForAnUnusableCredentialsResponse(int $status, string $body): void
	{
		HttpFactory::queue([200, self::TOKEN]);
		HttpFactory::queue([200, self::ROLE]);
		HttpFactory::queue([$status, $body]);

		$this->assertNull(Ec2Metadata::getCredentials());
	}

	/**
	 * @return array<string, array{0: int, 1: bool}>
	 */
	public static function provideExpirations(): array
	{
		return [
			'already expired'          => [-60, true],
			'expires right now'        => [0, true],
			'inside the 5 min buffer'  => [299, true],
			'at the 5 min buffer'      => [300, true],
			'just outside the buffer'  => [310, false],
			'an hour away'             => [3600, false],
		];
	}

	#[DataProvider('provideExpirations')]
	public function testTreatsCredentialsExpiringWithinFiveMinutesAsExpired(int $offset, bool $expired): void
	{
		$this->assertSame($expired, Ec2Metadata::areCredentialsExpired(time() + $offset));
	}

	private function credentialsJson(): string
	{
		return json_encode(
			[
				'Code'            => 'Success',
				'AccessKeyId'     => 'AKIAEXAMPLE',
				'SecretAccessKey' => 'secret/example',
				'Token'           => 'session-token',
				'Expiration'      => '2030-01-01T00:00:00Z',
			]
		);
	}
}
