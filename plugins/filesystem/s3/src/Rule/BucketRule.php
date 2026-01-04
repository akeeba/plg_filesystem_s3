<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\Rule;

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
	 * @link    https://docs.aws.amazon.com/AmazonS3/latest/userguide/bucketnamingrules.html
	 *
	 * @since   1.0.0
	 */
	public function test(\SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
	{
		// Bucket names must be between 3 and 63 characters long.
		$length = strlen($value);

		if ($length < 3 || $length > 63)
		{
			return false;
		}

		/**
		 * - Bucket names can consist only of lowercase letters, numbers, dots (.), and hyphens (-).
		 * - Recommendation: Avoid using dots (.) in bucket names
		 *
		 * Therefore: Bucket names can consist only of lowercase letters, numbers, and hyphens (-).
		 */
		if (!preg_match('#^[a-z0-9-]+$#', $value))
		{
			return false;
		}

		// Bucket names must begin and end with a letter or number.
		if (!preg_match('#^[a-z0-9].*[a-z0-9]$#', $value))
		{
			return false;
		}

		/**
		 * ⚠️ NO-OP for these rules
		 *
		 * 1. Bucket names must not contain two adjacent periods
		 *
		 * Reasoning: We do not allow dots.
		 *
		 * 2. Bucket names must not be formatted as an IP address (for example, 192.168.5.4).
		 *
		 * Reasoning: We do not allow dots.
		 */

		/**
		 * - Bucket names must not start with the prefix xn--.
		 * - Bucket names must not start with the prefix sthree-.
		 * - Bucket names must not start with the prefix sthree-configurator.
		 * - Bucket names must not start with the prefix amzn-s3-demo-.
		 */
		$forbiddenPrefixes = ['xn--', 'sthree-', 'sthree-configurator', 'amzn-s3-demo-'];

		foreach ($forbiddenPrefixes as $prefix)
		{
			if (
				strlen($value) > strlen($prefix)
				&& substr($value, 0, strlen($prefix)) === $prefix)
			{
				return false;
			}
		}

		/**
		 * - Bucket names must not end with the suffix -s3alias. This suffix is reserved for access point alias names. For more information, see Using a bucket-style alias for your S3 bucket access point.
		 * - Bucket names must not end with the suffix --ol-s3. This suffix is reserved for Object Lambda Access Point alias names. For more information, see How to use a bucket-style alias for your S3 bucket Object Lambda Access Point.
		 * - Bucket names must not end with the suffix .mrap. This suffix is reserved for Multi-Region Access Point names. For more information, see Rules for naming Amazon S3 Multi-Region Access Points.
		 * - Bucket names must not end with the suffix --x-s3. This suffix is reserved for directory buckets. For more information, see Directory bucket naming rules.
		 */
		$forbiddenSuffix = ['-s3alias', '--ol-s3', '.mrap', '--x-s3'];

		foreach ($forbiddenSuffix as $suffix)
		{
			if (
				strlen($value) > strlen($suffix)
				&& substr($value, -strlen($suffix)) === $suffix)
			{
				return false;
			}
		}

		/**
		 * ⚠️ NO-OP for these rules
		 *
		 * 1. Bucket names must be unique across all AWS accounts in all the AWS Regions within a partition.
		 *
		 * Reasoning: This is ensured by AWS on bucket creation. Setting up our software postdates bucket creation.
		 *
		 * 2. A bucket name cannot be used by another AWS account in the same partition until the bucket is deleted.
		 *
		 * Reasoning: This is ensured by AWS on bucket creation. Setting up our software postdates bucket creation.
		 *
		 * 3. Buckets used with Amazon S3 Transfer Acceleration can't have dots (.) in their names.
		 *
		 * Reasoning: a. Irrelevant to our use case; and b. We already don't allow dots in bucket names.
		 */

		return true;
	}

}