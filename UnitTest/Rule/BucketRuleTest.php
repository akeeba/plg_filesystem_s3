<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\UnitTest\Rule;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\Rule\BucketRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The bucket field validates against AWS's bucket naming rules, minus dots (which the plugin forbids
 * outright because they break virtual-hosted access over TLS).
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/userguide/bucketnamingrules.html
 */
#[CoversClass(BucketRule::class)]
class BucketRuleTest extends TestCase
{
	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provideValidNames(): array
	{
		return [
			'minimum length'          => ['abc'],
			'maximum length'          => [str_repeat('a', 63)],
			'digits and hyphens'      => ['my-bucket-01'],
			'starts with a digit'     => ['1bucket'],
			'prefix-like, not prefix' => ['axn--bucket'],
			'suffix-like, not suffix' => ['bucket-s3aliasx'],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provideInvalidNames(): array
	{
		return [
			'too short'                      => ['ab'],
			'too long'                       => [str_repeat('a', 64)],
			'empty'                          => [''],
			'upper case'                     => ['MyBucket'],
			'dot'                            => ['my.bucket'],
			'IP address'                     => ['192.168.5.4'],
			'underscore'                     => ['my_bucket'],
			'space'                          => ['my bucket'],
			'leading hyphen'                 => ['-bucket'],
			'trailing hyphen'                => ['bucket-'],
			'xn-- prefix'                    => ['xn--bucket'],
			'sthree- prefix'                 => ['sthree-bucket'],
			'sthree-configurator prefix'     => ['sthree-configurator-x'],
			'amzn-s3-demo- prefix'           => ['amzn-s3-demo-bucket'],
			'-s3alias suffix'                => ['bucket-s3alias'],
			'--ol-s3 suffix'                 => ['bucket--ol-s3'],
			'--x-s3 suffix'                  => ['bucket--x-s3'],
			'non-ASCII letter'               => ['bücket'],
			'slash (path smuggled as name)'  => ['bucket/dir'],
		];
	}

	#[DataProvider('provideValidNames')]
	public function testAcceptsAValidBucketName(string $name): void
	{
		$this->assertTrue($this->validate($name), "'$name' should be accepted.");
	}

	#[DataProvider('provideInvalidNames')]
	public function testRejectsAnInvalidBucketName(string $name): void
	{
		$this->assertFalse($this->validate($name), "'$name' should be rejected.");
	}

	private function validate(string $name): bool
	{
		return (new BucketRule())->test(new SimpleXMLElement('<field name="bucket" />'), $name);
	}
}
