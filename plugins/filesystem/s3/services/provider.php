<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2022-2023 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Akeeba\Plugin\Filesystem\S3\Extension\S3;

return new class implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   1.0.0
	 */
	public function register(Container $container)
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				defined('AKEEBAENGINE') || define('AKEEBAENGINE', 1);
				require_once __DIR__ . '/../vendor/autoload.php';

				$config  = (array) PluginHelper::getPlugin('filesystem', 's3');
				$subject = $container->get(DispatcherInterface::class);

				$plugin = new S3($subject, $config);

				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};
