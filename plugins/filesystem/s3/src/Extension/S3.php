<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright © 2022 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\Filesystem\S3\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Media\Administrator\Adapter\AdapterInterface;
use Joomla\Component\Media\Administrator\Event\MediaProviderEvent;
use Joomla\Component\Media\Administrator\Provider\ProviderInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\Filesystem\S3\Adapter\S3Filesystem;
use Joomla\Plugin\Filesystem\S3\Helper\Preview;

class S3 extends CMSPlugin implements SubscriberInterface, ProviderInterface
{
	/**
	 * Load the language files automatically.
	 *
	 * @var    boolean
	 * @since  1.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   1.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onSetupProviders' => 'setupProviders',
		];
	}

	/**
	 * Returns an array of Media Manager adapters
	 *
	 * @return  AdapterInterface[]
	 *
	 * @since   1.0.0
	 */
	public function getAdapters()
	{
		$preview = new Preview($this->params);
		$adapters = [];

		try
		{
			$connections = $this->params->get('connections');
			$connections = is_string($connections) ? @json_decode($connections) : $connections;
		}
		catch (\Exception $e)
		{
			$connections = [];
		}

		if (empty($connections))
		{
			return $adapters;
		}

		foreach ($connections as $connection)
		{
			try
			{
				$adapter = S3Filesystem::getFromConnection((array) $connection);
				$adapter->setPreview($preview);
			}
			catch (\Exception $e)
			{
				continue;
			}

			$adapters[$adapter->getAdapterName()] = $adapter;
		}

		return $adapters;
	}

	/**
	 * Returns the display name of the provider
	 *
	 * @return  string
	 *
	 * @since   1.0.0
	 */
	public function getDisplayName()
	{
		return Text::_('PLG_FILESYSTEM_S3_DEFAULT_NAME');
	}

	/**
	 * Returns the ID of the provider
	 *
	 * @return  string
	 *
	 * @since   1.0.0
	 */
	public function getID()
	{
		return $this->_name;
	}

	/**
	 * Handles the MediaProviderEvent which lets us register ourselves as a Media Manager adapter provider.
	 *
	 * @param   MediaProviderEvent  $event
	 *
	 * @since   1.0.0
	 */
	public function setupProviders(MediaProviderEvent $event)
	{
		$event->getProviderManager()->registerProvider($this);
	}
}