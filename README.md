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

## Regulatory status (EU Cyber Resilience Act)

"Amazon S3 Filesystem for Joomla" is free and open-source software released under the GPLv3 license. It is developed and distributed on a purely non-commercial basis: there is no charge for the software or any version of it, no paid tier or edition, no bundled or gated services, and no plan to monetize it in the future. It is not tied to, bundled with, or a dependency of any commercial product or service offered by Akeeba Ltd or any other party. On this basis, it falls outside the scope of Regulation (EU) 2024/2847 (the Cyber Resilience Act), which exempts free and open-source software supplied outside the course of a commercial activity. This statement reflects our assessment as of 27 August 2026 and will be revisited if the project's distribution model changes.