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
 * The site's upload policy (Media options: forbidden extensions, MIME checks, XSS check, restricted
 * uploads) applies to S3 connections exactly as it does to Joomla's own local adapter.
 *
 * com_media does not enforce that policy itself: it only checks the last extension against the media
 * type lists. Core's local adapter calls MediaHelper::canUpload() inside createFile() / updateFile(), so
 * any adapter which does not do the same silently skips the policy (security.md, M1).
 */
class UploadPolicyTest extends AbstractE2ETestCase
{
	private const ADAPTER = SiteProvisioner::ADAPTER_V2;

	/**
	 * Core's own local adapter, the baseline: it refuses every payload below.
	 */
	private const LOCAL_ADAPTER = 'local-images';

	private const HTML = "<!DOCTYPE html>\n<html><body><script>alert(document.domain)</script></body></html>\n";

	private const SVG = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1">'
		. '<script>alert(document.domain)</script></svg>' . "\n";

	public static function provideForbiddenUploads(): array
	{
		return [
			'PHP extension buried before .jpg' => ['x.php.jpg', self::HTML],
			'HTML disguised as text'            => ['page.txt', self::HTML],
			'HTML disguised as PNG'             => ['pic.png', self::HTML],
			'Scripted SVG disguised as JPEG'    => ['img.jpg', self::SVG],
		];
	}

	#[DataProvider('provideForbiddenUploads')]
	public function testTheLocalAdapterRefusesThePayload(string $name, string $content): void
	{
		// Guards the test itself: if core ever stops refusing a payload, the S3 expectation is moot.
		$this->assertApiRefused(403, $this->media()->createFile(self::LOCAL_ADAPTER, '/', $name, $content));
	}

	#[DataProvider('provideForbiddenUploads')]
	public function testUploadingAForbiddenFileIsRefused(string $name, string $content): void
	{
		$this->assertApiRefused(
			403, $this->media()->createFile(self::ADAPTER, '/' . $this->scratch(), $name, $content)
		);

		$this->assertSame([], $this->bucket()->keys($this->scratch()), 'A refused upload must not store anything.');
	}

	#[DataProvider('provideForbiddenUploads')]
	public function testEditingAFileIntoAForbiddenOneIsRefused(string $name, string $content): void
	{
		$key = $this->scratch() . '/' . $name;

		// Planted behind the plugin's back: the check must apply to the new content, not the file's history.
		$this->bucket()->put($key, 'harmless');

		$this->assertApiRefused(403, $this->media()->updateFile(self::ADAPTER, '/' . $key, $content));

		$this->assertSame('harmless', $this->bucket()->get($key)->body, 'A refused edit must not overwrite.');
	}

	public function testAnAllowedUploadStillWorks(): void
	{
		// Positive control: the policy check must not refuse legitimate media.
		$png = base64_decode(SiteProvisioner::PNG_BASE64);

		$this->assertApiSuccess($this->media()->createFile(self::ADAPTER, '/' . $this->scratch(), 'ok.png', $png));

		$this->assertSame($png, $this->bucket()->get($this->scratch() . '/ok.png')->body);
	}
}
