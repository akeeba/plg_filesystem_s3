# Special Configuration for Third Party Services

Some third party, S3-compatible services _are not_ 100% compatible with the Amazon S3 API, or need a configuration that deviates from their official documentation. We try to collect this information and collate it here. 

The information below is based on our experience with them. We cannot guarantee it is accurate, or that it remains accurate in the future.

## DreamHost (DreamObjects)

Known caveats:
* The endpoint URL they provide is NOT the one they state as preferred in their documentation.
* It only supports v2 signatures.
* It requires "Use HTTP Date header instead of X-Amz-Date header"

To configure this integration, go to your DreamHost control panel, Cloud Services, DreamObjects.

In the main area make sure you have a user, and an S3 key for it. If not, add one now.

Next to the username you will see a hostname like `objects-us-east-1.dream.io`. This is your **Endpoint URL**.

The visible key under the Keys label is your **Access Key**. Click on Show Secret Key to its right to display your **Secret Key**.

Under buckets you see a list of your buckets. The label you see there is your **Bucket**.

⚠️ IMPORTANT! Do NOT use the endpoint domain name you see in the bucket's options. That endpoint includes the bucket name and will NOT work with this plugin!

Your configuration in the plugin must be as follows:

* Connection type: Custom S3-compatible storage provider
* Custom endpoint: see above, must not include the bucket name, e.g. `objects-us-east-1.dream.io`
* Access key: see above
* Secret key: see above
* Bucket: see above
* Signature method: V2 (Legacy)
* Bucket access: Path Access (legacy)
* Directory: as per the main documentation
* Storage Class: Standard
* Use HTTP Date header instead of X-Amz-Date header: Yes
* Force bucket name in pre-signed URL: No