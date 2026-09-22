<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

/**
 * Bootstrap for the end-to-end suite.
 *
 * Deliberately tiny. It loads no Joomla and opens no database connection: the suite observes the site
 * from outside, over HTTP, exactly as a browser would. All it does is register the suite's autoloader
 * and check that the stack — the site AND the S3 server — is actually up, so a forgotten `run.sh`
 * produces one clear message instead of a wall of connection errors.
 */

require_once __DIR__ . '/autoload.php';

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Configuration;

$config = Configuration::getInstance();
$s3     = $config->getS3();

$probes = [
	'The site under test' => $config->getSiteUrl() . '/index.php',
	'The S3 server'       => $s3['publicUrl'] . '/' . $s3['bucket'] . '?list-type=2&max-keys=1',
];

foreach ($probes as $what => $url)
{
	$probe = curl_init($url);
	curl_setopt_array(
		$probe,
		[
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOBODY         => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 10,
		]
	);
	curl_exec($probe);
	$reachable = curl_errno($probe) === 0;
	$error     = curl_error($probe);

	// curl_close() is a no-op since PHP 8.0 and deprecated in 8.5.
	if (version_compare(PHP_VERSION, '8.5.0', 'lt'))
	{
		@curl_close($probe);
	}

	if ($reachable)
	{
		continue;
	}

	fwrite(
		STDERR,
		sprintf(
			"%s is not reachable at %s (%s).\n\n"
			. "Configuration was read from: %s\n\n"
			. "Provision the stack first:\n"
			. "    tests/integration/docker/run.sh --keep-containers\n",
			$what,
			$url,
			$error,
			$config->getSourceFile()
		)
	);

	exit(1);
}
