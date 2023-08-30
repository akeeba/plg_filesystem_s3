# Amazon S3 Filesystem for Joomla

Integrate Amazon S3, CloudFront and Amazon S3–compatible storage with Joomla!'s Media Manager.

🚨🚨🚨 **THIS PLUGIN ONLY WORKS ON JOOMLA! 4 AND 5**. Previous Joomla! versions lack support for third party filesystem providers. 🚨🚨🚨

[Downloads](https://github.com/akeeba/plg_filesystem_s3/releases) • [Documentation](https://github.com/akeeba/plg_filesystem_s3/blob/development/docs/index.md)

## About

This plugin allows you to save your media files to Amazon S3 and third party storage services compatible with the Amazon S3 API (with S3 signatures version 2 or 4).

You can optionally use this plugin with Amazon S3 buckets serving as origins for an Amazon CloudFront distribution. In this case the URLs generated and inserted into your content will be based on the CloudFront CDN URL you have configured, making for very efficient and cost–effective content delivery.

If you use the plugin with raw S3 (and S3-compatible) buckets you will get pre–authorised URLs with a validity of 10 years.

Do note that pre–authorised URLs are much more expensive that using a CloudFront CDN. We strongly recommend using a CloudFront distribution whenever possible.