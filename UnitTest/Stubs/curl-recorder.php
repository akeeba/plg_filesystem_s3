<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

/**
 * Records the cURL options akeeba/s3 really sets for a request, without any network traffic.
 *
 * akeeba/s3's Request lives in the Akeeba\S3 namespace and calls curl_setopt() / curl_exec() unqualified,
 * so PHP looks for Akeeba\S3\curl_setopt() before the global function. These shadows delegate to the real
 * functions, except while CurlRecorder is recording: then every option is recorded and curl_exec() fails
 * at once instead of connecting anywhere.
 *
 * Testing the options actually set, rather than a private method of the library, means these tests keep
 * guarding the plugin's connections however akeeba/s3 is refactored upstream.
 *
 * Loaded by the bootstrap before any akeeba/s3 code runs: PHP caches the resolution of an unqualified
 * function call.
 */

namespace Akeeba\Plugin\Filesystem\S3\UnitTest\Stubs
{
	defined('_JEXEC') or die;

	final class CurlRecorder
	{
		/** @var bool */
		public static $recording = false;

		/** @var array<int, mixed> */
		public static $options = [];

		public static function start(): void
		{
			self::$recording = true;
			self::$options   = [];
		}

		public static function stop(): void
		{
			self::$recording = false;
		}

		/**
		 * @return mixed
		 */
		public static function option(int $option)
		{
			return self::$options[$option] ?? null;
		}
	}
}

namespace Akeeba\S3
{
	defined('_JEXEC') or die;

	use Akeeba\Plugin\Filesystem\S3\UnitTest\Stubs\CurlRecorder;

	if (!\function_exists(__NAMESPACE__ . '\\curl_setopt'))
	{
		function curl_setopt($handle, int $option, $value): bool
		{
			if (CurlRecorder::$recording)
			{
				CurlRecorder::$options[$option] = $value;

				return true;
			}

			return \curl_setopt($handle, $option, $value);
		}
	}

	if (!\function_exists(__NAMESPACE__ . '\\curl_exec'))
	{
		/**
		 * @return string|bool
		 */
		function curl_exec($handle)
		{
			return CurlRecorder::$recording ? false : \curl_exec($handle);
		}
	}
}
