# Amazon S3 Filesystem for Joomla

Integrate Amazon S3, CloudFront and Amazon S3–compatible storage with Joomla!'s Media Manager.

[Downloads](https://github.com/akeeba/plg_filesystem_s3/releases) • [Documentation](https://github.com/akeeba/plg_filesystem_s3/blob/development/docs/index.md)

## About

> ⚠️ By default this plugin uploads files with Public Read ACL. You can change the uploaded object ACL per connection, but any non-public ACL requires a CDN-enabled connection with a configured CDN URL. Direct bucket URLs generated without a CDN will not work for non-public objects.

This plugin allows you to save your media files to Amazon S3 and third party storage services compatible with the Amazon S3 API (with S3 signatures version 2 or 4).

You can optionally use this plugin with Amazon S3 buckets serving as origins for an Amazon CloudFront distribution. In this case the URLs generated and inserted into your content will be based on the CloudFront CDN URL you have configured, making for very efficient and cost-effective content delivery.

## Compatibility

We only develop and test on the latest published Joomla! version, and the latest publicly available Long Term Support (LTS) Joomla version. That's the items you see in https://downloads.joomla.org/latest

We only develop and test on the supported (non-EOL) versions of PHP. That's the versions you see in orange and green background in https://www.php.net/supported-versions
