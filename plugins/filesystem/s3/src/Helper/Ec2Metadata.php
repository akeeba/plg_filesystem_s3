<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;

/**
 * Helper class to retrieve temporary credentials from EC2 instance metadata service (IMDSv2)
 *
 * @since 1.3.0
 */
class Ec2Metadata
{
	/**
	 * EC2 metadata service endpoint
	 */
	private const METADATA_ENDPOINT = 'http://169.254.169.254';

	/**
	 * IMDSv2 token TTL in seconds
	 */
	private const TOKEN_TTL = 21600;

	/**
	 * Timeout for metadata service requests in seconds
	 */
	private const REQUEST_TIMEOUT = 2;

	/**
	 * Get temporary credentials from EC2 instance metadata service
	 *
	 * @return  array|null  Array with keys: access_key, secret_key, token, expiration (timestamp) or null on failure
	 *
	 * @since   1.3.0
	 */
	public static function getCredentials(): ?array
	{
		try
		{
			// Step 1: Get IMDSv2 session token
			$token = self::getSessionToken();

			if (empty($token))
			{
				return null;
			}

			// Step 2: Get IAM role name
			$roleName = self::getIamRoleName($token);

			if (empty($roleName))
			{
				return null;
			}

			// Step 3: Get temporary credentials for the role
			return self::getRoleCredentials($token, $roleName);
		}
		catch (\Exception $e)
		{
			// Silently fail if we're not on EC2 or something goes wrong
			return null;
		}
	}

	/**
	 * Check if credentials have expired or are about to expire
	 *
	 * @param   int  $expiration  The expiration timestamp
	 *
	 * @return  bool  True if expired or expiring within 5 minutes
	 *
	 * @since   1.3.0
	 */
	public static function areCredentialsExpired(int $expiration): bool
	{
		// Consider credentials expired if they expire within 5 minutes
		return (time() + 300) >= $expiration;
	}

	/**
	 * Get an IMDSv2 session token
	 *
	 * @return  string|null  The session token or null on failure
	 *
	 * @since   1.3.0
	 */
	private static function getSessionToken(): ?string
	{
		$url = self::METADATA_ENDPOINT . '/latest/api/token';

		try
		{
			$http     = HttpFactory::getHttp();
			$response = $http->put(
				$url,
				'',
				[
					'X-aws-ec2-metadata-token-ttl-seconds' => self::TOKEN_TTL,
				],
				self::REQUEST_TIMEOUT
			);

			$body = trim((string) ($response->getBody() ?: '') ?: '');

			if ($response->getStatusCode() !== 200 || empty($body))
			{
				return null;
			}

			return $body;
		}
		catch (\Exception $e)
		{
			return null;
		}
	}

	/**
	 * Get the IAM role name attached to the EC2 instance
	 *
	 * @param   string  $token  The IMDSv2 session token
	 *
	 * @return  string|null  The role name or null if no role is attached
	 *
	 * @since   1.3.0
	 */
	private static function getIamRoleName(string $token): ?string
	{
		$url = self::METADATA_ENDPOINT . '/latest/meta-data/iam/security-credentials/';

		try
		{
			$http     = HttpFactory::getHttp();
			$response = $http->get(
				$url,
				[
					'X-aws-ec2-metadata-token' => $token,
				],
				self::REQUEST_TIMEOUT
			);

			$body = trim((string) ($response->getBody() ?: '') ?: '');

			if ($response->getStatusCode() !== 200 || empty($body))
			{
				return null;
			}


			return $body;
		}
		catch (\Exception $e)
		{
			return null;
		}
	}

	/**
	 * Get temporary credentials for the specified IAM role
	 *
	 * @param   string  $token     The IMDSv2 session token
	 * @param   string  $roleName  The IAM role name
	 *
	 * @return  array|null  Array with keys: access_key, secret_key, token, expiration or null on failure
	 *
	 * @since   1.3.0
	 */
	private static function getRoleCredentials(string $token, string $roleName): ?array
	{
		$url = self::METADATA_ENDPOINT . '/latest/meta-data/iam/security-credentials/' . urlencode($roleName);

		try
		{
			$http     = HttpFactory::getHttp();
			$response = $http->get(
				$url,
				[
					'X-aws-ec2-metadata-token' => $token,
				],
				self::REQUEST_TIMEOUT
			);

			$body = trim((string) ($response->getBody() ?: '') ?: '');

			if ($response->getStatusCode() !== 200 || empty($body))
			{
				return null;
			}

			$data = json_decode($body, true);

			if (
				!is_array($data)
				|| empty($data['AccessKeyId'])
				|| empty($data['SecretAccessKey'])
				|| empty($data['Token'])
				|| empty($data['Expiration'])
			)
			{
				return null;
			}

			// Convert expiration from ISO 8601 to Unix timestamp
			$expiration = strtotime($data['Expiration']);

			if ($expiration === false)
			{
				return null;
			}

			return [
				'access_key' => $data['AccessKeyId'],
				'secret_key' => $data['SecretAccessKey'],
				'token'      => $data['Token'],
				'expiration' => $expiration,
			];
		}
		catch (\Exception $e)
		{
			return null;
		}
	}
}
