<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

/**
 * Provision the fixtures against an already-running stack.
 *
 * docker/run.sh calls this after installing the plugin. You can also run it by hand against a stack
 * left up with --keep-containers, to put the plugin's settings and the bucket back the way they started:
 *
 *     php tests/integration/provision.php
 */

require_once __DIR__ . '/autoload.php';

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Configuration;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\SiteProvisioner;

$config = Configuration::getInstance();

fwrite(STDOUT, sprintf("Provisioning fixtures (config: %s)\n", $config->getSourceFile()));

try
{
	$manifest = (new SiteProvisioner($config))->provision();
}
catch (Throwable $e)
{
	fwrite(STDERR, $e->getMessage() . "\n");

	exit(1);
}

fwrite(
	STDOUT,
	sprintf(
		"  %d connections, %d seed objects (Joomla %s, PHP %s)\n",
		$manifest['connections'],
		$manifest['objects'],
		$manifest['joomla'],
		$manifest['php']
	)
);

exit(0);
