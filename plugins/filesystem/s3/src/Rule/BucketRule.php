<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright © 2022 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Joomla\Plugin\Filesystem\S3\Rule;

defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\Registry\Registry;

/**
 * Form rule to validate bucket names
 *
 * @since  1.0.0
 * @see    https://docs.aws.amazon.com/AmazonS3/latest/userguide/bucketnamingrules.html
 */
class BucketRule extends FormRule
{
	/**
	 * Method to test the value.
	 *
	 * @param   \SimpleXMLElement  $element  The SimpleXMLElement object representing the `<field>` tag for the form
	 *                                       field object.
	 * @param   mixed              $value    The form field value to validate.
	 * @param   string             $group    The field name group control value. This acts as as an array container for
	 *                                       the field. For example if the field has name="foo" and the group value is
	 *                                       set to "bar" then the full field name would end up being "bar[foo]".
	 * @param   Registry           $input    An optional Registry object with the entire data set to validate against
	 *                                       the entire form.
	 * @param   Form               $form     The form object for which the field is being tested.
	 *
	 * @return  boolean  True if the value is valid, false otherwise.
	 *
	 * @since   1.0.0
	 */
	public function test(\SimpleXMLElement $element, $value, $group = null, Registry $input = null, Form $form = null)
	{
		// Bucket names must be between 3 and 63 characters long.
		$length = strlen($value);

		if ($length < 3 || $length > 63)
		{
			return false;
		}

		// Bucket names must not start with the prefix xn--
		if ($length > 4 && substr($value, 0, 4) === 'xn--')
		{
			return false;
		}

		// Bucket names must not end with the suffix -s3alias.
		if ($length > 8 && substr($value, -8) === '-s3alias')
		{
			return false;
		}

		/**
		 * Validate the following:
		 * - Bucket names can consist only of lowercase letters, numbers, dots, and hyphens (-).
		 * - For best compatibility, we recommend that you avoid using dots (.) in bucket names
		 * - Bucket names must begin and end with a letter or number.
		 * - Bucket names must not be formatted as an IP address (for example, 192.168.5.4)
		 */
		if (!preg_match('#(^[a-z]{1,2}$)|^([a-z][a-z0-9\-]{1,}[a-z])$#', $value))
		{
			return false;
		}

		return true;
	}

}