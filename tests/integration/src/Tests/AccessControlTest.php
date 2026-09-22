<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\IntegrationTest\AbstractE2ETestCase;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\MediaApi;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\SiteProvisioner;

/**
 * The plugin holds S3 credentials with full write access to the bucket. The only thing standing between
 * a web request and those credentials is Joomla's Media Manager: authentication, ACL and the anti-CSRF
 * token. These tests prove each gate holds for the S3 adapters, and that a refused request leaves the
 * bucket untouched — a refusal message alone proves nothing.
 *
 * Each refusal is paired with a positive control: the same request, legitimately made, succeeds. That
 * is what shows the refusal comes from the gate under test and not from a broken request.
 */
class AccessControlTest extends AbstractE2ETestCase
{
	private const ADAPTER = SiteProvisioner::ADAPTER_V2;

	public function testAGuestCannotListABucket(): void
	{
		$response = $this->media($this->guest())->get(self::ADAPTER, '/fixtures');

		$this->assertSame(403, $response->code, $response->summary());
		$this->assertStringNotContainsString('hello.txt', $response->body, 'A guest must not see the listing.');
	}

	public function testAGuestCannotDeleteFromABucket(): void
	{
		$response = $this->guest()->request(
			'DELETE',
			'administrator/index.php?option=com_media&task=api.files&format=json&mediatypes=0,1,2,3&path='
			. urlencode(self::ADAPTER . ':/fixtures/hello.txt')
		);

		$this->assertSame(403, $response->code, $response->summary());
		$this->assertTrue($this->bucket()->exists('fixtures/hello.txt'));
	}

	public function testAnUploadWithoutTheTokenIsRefused(): void
	{
		$key = $this->scratch() . '/csrf.txt';

		$this->assertApiRefused(
			403,
			$this->media()->createFile(self::ADAPTER, '/' . $this->scratch(), 'csrf.txt', "x\n", false, MediaApi::NO_TOKEN)
		);
		$this->assertFalse($this->bucket()->exists($key));

		// Positive control: the same upload with the session's token goes through.
		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, '/' . $this->scratch(), 'csrf.txt', "x\n"));
		$this->assertTrue($this->bucket()->exists($key));
	}

	public function testAnUploadWithAWrongTokenIsRefused(): void
	{
		$media = $this->media();
		$wrong = $this->admin()->corruptToken($media->token());

		$this->assertApiRefused(
			403,
			$media->createFile(self::ADAPTER, '/' . $this->scratch(), 'forged.txt', "x\n", false, $wrong)
		);
		$this->assertFalse($this->bucket()->exists($this->scratch() . '/forged.txt'));
	}

	public function testADeleteWithoutTheTokenIsRefused(): void
	{
		$this->assertApiRefused(403, $this->media()->delete(self::ADAPTER, '/fixtures/hello.txt', MediaApi::NO_TOKEN));
		$this->assertTrue($this->bucket()->exists('fixtures/hello.txt'));
	}

	public function testAMoveWithoutTheTokenIsRefused(): void
	{
		$this->assertApiRefused(
			403, $this->media()->move(self::ADAPTER, '/fixtures/hello.txt', '/fixtures/moved.txt', MediaApi::NO_TOKEN)
		);
		$this->assertTrue($this->bucket()->exists('fixtures/hello.txt'));
		$this->assertFalse($this->bucket()->exists('fixtures/moved.txt'));
	}

	public function testAUserWithoutCreatePermissionCanBrowseButNotUpload(): void
	{
		$media = $this->media($this->viewer());

		// Positive control for the account itself: it can reach the Media Manager and read the bucket.
		$listing = $this->assertApiSuccess($media->get(self::ADAPTER, '/fixtures'));
		$this->assertContains('hello.txt', $this->names($listing));

		$this->assertApiRefused(403, $media->createFile(self::ADAPTER, '/' . $this->scratch(), 'nope.txt', "x\n"));
		$this->assertApiRefused(403, $media->createFolder(self::ADAPTER, '/', $this->scratch()));
		$this->assertSame([], $this->bucket()->keys($this->scratch()));
	}

	public function testAUserWithoutEditPermissionCannotChangeOrMoveFiles(): void
	{
		$media = $this->media($this->viewer());

		$this->assertApiRefused(403, $media->updateFile(self::ADAPTER, '/fixtures/hello.txt', "defaced\n"));
		$this->assertApiRefused(403, $media->move(self::ADAPTER, '/fixtures/hello.txt', '/fixtures/moved.txt'));

		$this->assertSame("Hello, S3!\n", $this->bucket()->get('fixtures/hello.txt')->body);
		$this->assertFalse($this->bucket()->exists('fixtures/moved.txt'));
	}

	public function testAUserWithoutDeletePermissionCannotDelete(): void
	{
		$this->assertApiRefused(403, $this->media($this->viewer())->delete(self::ADAPTER, '/fixtures/hello.txt'));
		$this->assertTrue($this->bucket()->exists('fixtures/hello.txt'));
	}
}
