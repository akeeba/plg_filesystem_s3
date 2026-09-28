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
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Response;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\SiteProvisioner;

/**
 * Storage errors reach the Media Manager as a generic message keeping only the S3 error code; the full
 * error goes to the plugin's log file. Only a Super User with Site Debug on sees the raw error, which
 * includes akeeba/s3's "Debug info" dump of the S3 error body (security.md, L1).
 */
class ErrorDisclosureTest extends AbstractE2ETestCase
{
	private const LOG_FILE = 'administrator/logs/plg_filesystem_s3.php';

	/**
	 * Site Debug as provisioned, put back after each test.
	 */
	private ?bool $originalDebug = null;

	protected function tearDown(): void
	{
		if ($this->originalDebug !== null)
		{
			$this->setSiteDebug($this->originalDebug);

			$this->originalDebug = null;
		}

		parent::tearDown();
	}

	public function testASuperUserWithoutSiteDebugGetsOnlyTheErrorCode(): void
	{
		$this->setSiteDebug(false);

		$this->assertSanitised($this->media()->get(SiteProvisioner::ADAPTER_BAD, '/'));

		// Uploads also carry akeeba/s3's "Debug info" dump of the S3 error body.
		$this->assertSanitised(
			$this->media()->createFile(SiteProvisioner::ADAPTER_BAD, '/' . $this->scratch(), 'x.txt', "x\n")
		);
	}

	public function testAnotherUserWithSiteDebugOnGetsOnlyTheErrorCode(): void
	{
		$this->setSiteDebug(true);

		$this->assertSanitised($this->media($this->viewer())->get(SiteProvisioner::ADAPTER_BAD, '/'));
	}

	public function testASuperUserWithSiteDebugOnGetsTheFullError(): void
	{
		$this->setSiteDebug(true);

		$response = $this->media()->get(SiteProvisioner::ADAPTER_BAD, '/');

		// The raw akeeba/s3 message, naming the library method that failed.
		$this->assertFalse($response->json()['success'] ?? true, $response->summary());
		$this->assertStringContainsString('Connector::', $response->json()['message'] ?? '', $response->summary());
	}

	public function testTheFullErrorIsLogged(): void
	{
		$this->media()->get(SiteProvisioner::ADAPTER_BAD, '/');

		[, $log] = (new ContainerCli(static::$config))->run(['cat', self::LOG_FILE]);

		$this->assertStringContainsString('SignatureDoesNotMatch', $log);
		$this->assertStringContainsString('Debug info', $log);
	}

	public function testRoutineNotFoundProbesAreNotLogged(): void
	{
		// An upload HEADs the new key (and key/) to see whether it exists: expected 404s, not errors.
		$before = $this->logSize();

		$this->assertApiSuccess(
			$this->media()->createFile(SiteProvisioner::ADAPTER_V2, '/' . $this->scratch(), 'new.txt', "new\n")
		);

		$this->assertSame($before, $this->logSize());
	}

	public function testAMissingFileNamesNoBucketInternals(): void
	{
		$this->setSiteDebug(false);

		$response = $this->media()->delete(SiteProvisioner::ADAPTER_V2, '/fixtures/no-such-file.png');
		$message  = $response->json()['message'] ?? '';

		$this->assertSame(404, $response->code, $response->summary());
		$this->assertStringNotContainsString($this->bucket()->getName(), $message);
		$this->assertStringNotContainsString('Connector::', $message);
	}

	private function logSize(): int
	{
		[, $size] = (new ContainerCli(static::$config))->run(['sh', '-c', 'wc -c < ' . self::LOG_FILE . ' 2>/dev/null || echo 0']);

		return (int) trim($size);
	}

	private function assertSanitised(Response $response): void
	{
		$json    = $response->json();
		$message = $json['message'] ?? '';

		$this->assertFalse($json['success'] ?? true, $response->summary());
		$this->assertStringContainsString('SignatureDoesNotMatch', $message, 'The S3 error code is kept.');
		$this->assertStringNotContainsString('Debug info', $message);
		$this->assertStringNotContainsString($this->bucket()->getName(), $message);
		$this->assertStringNotContainsString('Akeeba\\S3', $message);
	}

	/**
	 * Switch Site Debug by editing configuration.php in place.
	 *
	 * Not `config:set`: it leaves configuration.php read-only, so the next `config:set` fails.
	 */
	private function setSiteDebug(bool $on): void
	{
		$code = <<<'PHP'
			$file = '/var/www/html/configuration.php';
			$php  = file_get_contents($file);
			preg_match('/public \$debug = (true|false);/', $php, $m) || exit(2);
			echo $m[1];
			chmod($file, 0644);
			file_put_contents($file, preg_replace('/public \$debug = (true|false);/', 'public $debug = %s;', $php));
			function_exists('opcache_invalidate') && opcache_invalidate($file, true);
			PHP;

		[$exitCode, $previous] = (new ContainerCli(static::$config))->php(sprintf($code, $on ? 'true' : 'false'));

		$this->assertSame(0, $exitCode, 'Could not switch Site Debug: ' . $previous);

		$this->originalDebug ??= trim($previous) === 'true';
	}
}
