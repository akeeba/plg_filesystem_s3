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
* Storage Class: Standard (recommended)
* Use HTTP Date header instead of X-Amz-Date header: Yes
* Force bucket name in pre-signed URL: No

## CloudFlare R2

Known caveats:
* The endpoint domain name is given as a URL in CloudFlare's interface; you need to adjust it.
* It only supports v4 signatures.
* The Region is only indirectly visible in the CloudFlare interface.

To get the necessary Access Key and Secret Key go to R2, Overview, Manage R2 Tokens and click on Create API Token. You need the Access and Secret shown further down the page, _not_ the "value" shown at the top.

To get the Region and endpoint, you need to go to R2, Overview, and click on your bucket's name, then click on the Settings tab. You will see a line similar to this:
```text
**Location:** Eastern Europe (EEUR)
```
The stuff between the parantheses is the region, BUT you have to make it lowercase. In our example above, the Region would be `eeur`.

A bit further below that you will see a line like this:
```text
**S3 API:** https://0123456789abdef0123456789abdef.r2.cloudflarestorage.com/something
```
Remove the `https://` prefix and the `/something` (where `something` is your bucket's name) from the URL to get your Custom Endpoint. In this example, it would be `0123456789abdef0123456789abdef.r2.cloudflarestorage.com`.

Your configuration in the plugin must be as follows:

* Connection type: Custom S3-compatible storage provider
* Custom endpoint: see above
* Access key: see above
* Secret key: see above
* Bucket: your bucket's name
* Signature method: V4
* Region: `(custom)`
* Custom Region: see above
* Bucket access: Virtual Hosting (recommended)
* Directory: as per the main documentation
* Storage Class: Standard (recommended)
* Use HTTP Date header instead of X-Amz-Date header: No
* Force bucket name in pre-signed URL: No