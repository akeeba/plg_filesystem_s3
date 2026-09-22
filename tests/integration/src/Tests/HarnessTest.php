<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\IntegrationTest\AbstractE2ETestCase;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Surfer;

/**
 * The harness itself: the stack is up, the plugin is installed and registered with the Media Manager,
 * and the bucket view the other tests rely on is independent AND read-only.
 *
 * If any of these fail, every other failure in the run is suspect.
 */
class HarnessTest extends AbstractE2ETestCase
{
	public function testTheMediaManagerListsEveryValidConnectionAsAnAdapter(): void
	{
		$page = $this->admin()->get('administrator/index.php?option=com_media');

		$this->assertSame(200, $page->code, $page->summary());
		$this->assertMatchesRegularExpression('/"providers"\s*:/', $page->body, 'No provider list on the Media Manager page.');

		preg_match('/\{"name":"s3","displayName":"[^"]*","adapterNames":(\[[^\]]*\])\}/', $page->body, $match);

		$this->assertNotEmpty($match, 'The s3 provider is not registered with the Media Manager.');
		$this->assertSame(
			['v2path', 'v4path', 'nested', 'cdn', 'cached', 'badsecret'],
			json_decode($match[1], true),
			'Every connection with a bucket is an adapter, in configuration order; "nobucket" is dropped.'
		);
	}

	public function testTheBucketHoldsTheSeedObjects(): void
	{
		$keys = $this->bucket()->keys();

		foreach (static::$fixtures->seedObjects() as $key => $content)
		{
			$this->assertContains($key, $keys);
			$this->assertSame($content, $this->bucket()->get($key)->body, "Seed object $key has the wrong content.");
		}
	}

	/**
	 * The tests prove "the plugin really wrote it" by reading the bucket anonymously. That proof is
	 * worthless if anonymous clients can also WRITE: then a passing test could not tell who wrote what.
	 */
	public function testTheHostsViewOfTheBucketIsReadOnly(): void
	{
		$key    = $this->scratch() . '/anonymous.txt';
		$surfer = new Surfer(static::$config->getS3()['publicUrl']);

		$put = $surfer->request('PUT', $this->bucket()->url($key), 'anonymous write');
		$this->assertSame(403, $put->code, $put->summary());
		$this->assertFalse($this->bucket()->exists($key));

		$delete = $surfer->request('DELETE', $this->bucket()->url('fixtures/hello.txt'));
		$this->assertSame(403, $delete->code, $delete->summary());
		$this->assertTrue($this->bucket()->exists('fixtures/hello.txt'));
	}
}
