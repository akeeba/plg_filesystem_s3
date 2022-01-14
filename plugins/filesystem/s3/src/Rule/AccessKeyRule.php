<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright © 2022 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\Filesystem\S3\Rule;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormRule;

/**
 * Form rule to validate Amazon S3 access keys
 *
 * @since  1.0.0
 * @see    https://aws.amazon.com/blogs/security/a-safer-way-to-distribute-aws-credentials-to-ec2/
 */
class AccessKeyRule extends FormRule
{
	/**
	 * The regular expression to use in testing a form field value.
	 *
	 * @var    string
	 * @since  1.0.0
	 */
	protected $regex = '^(?<![A-Z0-9])[A-Z0-9]{20}(?![A-Z0-9])$';
}