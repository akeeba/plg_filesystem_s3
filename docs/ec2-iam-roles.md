# EC2 IAM Role Authentication

When running your Joomla site on an Amazon EC2 instance, you can use IAM role-based authentication instead of manually providing Access and Secret keys. This is the recommended approach for EC2-hosted sites as it provides better security through automatic credential rotation and eliminates the need to store long-term credentials in your Joomla configuration.

## How It Works

When both the **Access Key** and **Secret Key** fields are left empty in the plugin configuration, the plugin will automatically:

1. Query the EC2 instance metadata service (IMDSv2) to check if an IAM role is attached
2. Retrieve temporary credentials from the metadata service
3. Use these credentials to authenticate all S3 requests
4. Cache the credentials for the page load duration
5. Automatically refresh credentials when they expire (typically every 6 hours)

## Requirements

All five of these requirements must be met for EC2 IAM role authentication to work:

### 1. Amazon S3 Only

You must use one of these connection types:
- **Amazon S3** (type: `s3`)
- **Amazon CloudFront** (type: `cloudfront`)

Custom S3-compatible endpoints are **not supported** with EC2 temporary credentials because only Amazon S3 accepts the security tokens issued by EC2 instances.

### 2. IMDSv2 Enabled

Your EC2 instance must have Instance Metadata Service Version 2 (IMDSv2) enabled. This is a security requirement that prevents certain types of attacks on the metadata service.

**To enable IMDSv2:**

1. Open the Amazon EC2 console
2. Select your instance
3. Choose **Actions** → **Instance settings** → **Modify instance metadata options**
4. Set **IMDSv2** to **Required**
5. Save changes

For more information, see [AWS documentation on configuring IMDSv2](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/configuring-instance-metadata-service.html).

### 3. v4 Signature Method

You must select **v4** as the signature method in the plugin configuration.

The older v2 signature method does not support the security tokens that come with EC2 temporary credentials. Only v4 signatures can include the `X-Amz-Security-Token` header required by AWS.

### 4. EC2 Hosting Required

Your Joomla site must be running on an Amazon EC2 instance. This feature will not work on:
- On-premises servers
- Other cloud providers (even if they have S3-compatible storage)
- AWS Lambda, ECS, or other AWS compute services (they require different credential retrieval methods)

### 5. IAM Role Attached

Your EC2 instance must have an IAM role attached with the necessary S3 permissions.

**To create and attach an IAM role:**

1. **Create an IAM policy** with the required S3 permissions (see example below)
2. **Create an IAM role** for EC2 with this policy attached
3. **Attach the role** to your EC2 instance

For detailed instructions, see [AWS documentation on IAM roles for EC2](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/iam-roles-for-amazon-ec2.html).

## Example IAM Policy

This policy grants the minimum permissions needed for the plugin to function:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ListBucket",
      "Effect": "Allow",
      "Action": [
        "s3:ListBucket",
        "s3:GetBucketLocation"
      ],
      "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME"
    },
    {
      "Sid": "ManageObjects",
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:PutObject",
        "s3:DeleteObject",
        "s3:PutObjectAcl"
      ],
      "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*"
    }
  ]
}
```

**Important**: Replace `YOUR-BUCKET-NAME` with your actual S3 bucket name.

### Policy Explanation

- **ListBucket**: Required to view folder contents in Media Manager
- **GetBucketLocation**: Required to determine the bucket's region
- **GetObject**: Required to download/view files
- **PutObject**: Required to upload files
- **DeleteObject**: Required to delete files and folders
- **PutObjectAcl**: Required to set files to public-read ACL (this plugin only works with public files)

## Configuration

1. In your plugin configuration, create or edit a connection
2. Set **Connection Type** to either **Amazon S3** or **Amazon CloudFront**
3. Leave **Access Key** empty
4. Leave **Secret Key** empty
5. Set **Signature Method** to **v4**
6. Select the appropriate **Region** for your bucket
7. Configure other settings (bucket name, directory, etc.) as normal
8. Save the configuration

If all requirements are met, the plugin will automatically use EC2 IAM role credentials. If any requirement is not met, the plugin will fail with an error message about missing Access Key or Secret Key.

## Troubleshooting

### "You have not set up your Access Key" Error

This error occurs when the plugin cannot retrieve EC2 credentials. Check:

1. Are you using the correct connection type (Amazon S3 or CloudFront)?
2. Is the signature method set to v4?
3. Is your site actually running on an EC2 instance?
4. Is IMDSv2 enabled on your instance?
5. Is an IAM role attached to your instance?

### Testing EC2 Metadata Access

You can verify that your EC2 instance can access the metadata service by running these commands via SSH:

```bash
# Get IMDSv2 token
TOKEN=`curl -X PUT "http://169.254.169.254/latest/api/token" -H "X-aws-ec2-metadata-token-ttl-seconds: 21600"`

# Check for attached IAM role
curl -H "X-aws-ec2-metadata-token: $TOKEN" http://169.254.169.254/latest/meta-data/iam/security-credentials/

# Get credentials (replace ROLE_NAME with the output from previous command)
curl -H "X-aws-ec2-metadata-token: $TOKEN" http://169.254.169.254/latest/meta-data/iam/security-credentials/ROLE_NAME
```

If any of these commands fail or return errors, you need to fix your EC2 instance configuration before the plugin can use IAM role authentication.

## Security Benefits

Using EC2 IAM roles provides several security advantages over static credentials:

1. **No stored credentials**: Access and secret keys are never stored in your Joomla database or configuration files
2. **Automatic rotation**: AWS automatically rotates the temporary credentials every 6 hours
3. **Limited scope**: You can precisely control which S3 buckets and operations the role can access
4. **Audit trail**: AWS CloudTrail logs show which EC2 instance made which S3 requests
5. **Revocable access**: Detaching the IAM role immediately revokes all access

## Performance Considerations

The plugin caches EC2 credentials for the duration of each page load. The first S3 operation on each page requires:

1. One request to get the IMDSv2 session token (~10ms)
2. One request to list IAM roles (~10ms)
3. One request to get the credentials (~10ms)

Total overhead: approximately 30ms on the first S3 operation per page load. Subsequent operations on the same page use cached credentials with no additional overhead.

The plugin also supports S3 request caching, which can be enabled in the connection configuration to further reduce API calls and improve performance.
