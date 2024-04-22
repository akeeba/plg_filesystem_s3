🚨🚨🚨 **THIS PLUGIN ONLY WORKS ON JOOMLA! 4 AND 5**. Previous Joomla! versions lack support for third party filesystem providers. 🚨🚨🚨

Integrate Amazon S3, CloudFront and Amazon S3–compatible storage with Joomla!'s Media Manager.

#### Highlights

**✨ Add advanced options for the S3 integration**. You can now control the advanced, internal options of the Amazon S3 API integration. This allows you to connect to third party providers which are S3-compatible but do not behave precisely the way S3 proper does, e.g. Wasabi, Synology C2, etc.

**✨ Automatic MIME type detection**. Files were uploaded to S3 without a MIME type. It was up to Amazon to figure out the correct MIME type when downloading the files. In some cases, files were being downloaded with the generic `application/octet-stream` (raw binary data) MIME type. We now perform MIME type detection with the PHP `finfo` library (if installed), with a fallback on detecting MIME types based on the file extension using the industry-standard `league/mime-type-detection` library.

#### Changelog

* ✨ Add advanced options for the S3 integration
* ✨ Automatic MIME type detection
* 🐞 Can't use files with spaces or some special characters in their name
* 🐞 Cannot save third party S3-compatible services' non-compliant access / secret keys
