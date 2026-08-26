<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\Helper;

defined('_JEXEC') or die;

use Normalizer;

/**
 * Normalises a directory path for safe use as an S3 key prefix and URL component.
 *
 * @since  1.3.2
 */
final class PathNormaliser
{
	/**
	 * Strip NUL/CR/LF, drop URL-significant characters (#, ?), and apply
	 * Unicode NFC normalisation when ext-intl is available.
	 *
	 * The result is safe to embed in an S3 key, in a URI path component, and
	 * in HTTP headers. Path-component concatenation (slash handling, leading
	 * and trailing slashes) is the caller's responsibility — see
	 * Akeeba\Plugin\Filesystem\S3\Filter::filterDirectory().
	 *
	 * @param   string  $path  The user-supplied path segment.
	 *
	 * @return  string  The normalised path.
	 *
	 * @since   1.3.2
	 */
	public static function normaliseUnicodePath(string $path): string
	{
		// Strip NUL and CR/LF. These are forbidden in HTTP header values and
		// would break URL serialisation when the directory is later
		// concatenated into a CDN URL.
		$path = str_replace(["\0", "\r", "\n"], '', $path);

		// Drop URL-significant characters. The directory is later concatenated
		// into URLs in getUrl(); an embedded '#' would create a fragment and
		// an embedded '?' would create a query string that overrides the
		// actual path.
		$path = str_replace(['#', '?'], '', $path);

		// Apply Unicode NFC normalisation when ext-intl is available. This
		// collapses visually identical sequences (e.g. NFD 'e' + combining
		// acute → NFC 'é') into a single canonical form, defending against
		// homoglyph-based directory collisions.
		if (class_exists(Normalizer::class))
		{
			$normalised = Normalizer::normalize($path, Normalizer::FORM_C);

			if (is_string($normalised))
			{
				$path = $normalised;
			}
		}

		return $path;
	}
}