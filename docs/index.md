# Amazon S3 Filesystem for Joomla

Integrate Amazon S3, CloudFront and Amazon S3–compatible storage with Joomla!'s Media Manager

## Foreword

Joomla 4 introduced the concept of Media Manager adapters which allow you to specify storage for your media files outside of the `images` directory on your site.

Joomla itself comes with a single adapter called “Filesystem - Local”. It implements the standard media files storage on your server's filesystem. By default it only allows access to the `images` folder but it can be configured to support more folders under your site's root as needed.

The true power of Media Manager adapters, though, is that they allow third party developers like us to provide additional adapters for cloud file storage services. This plugin does exactly that, providing integration with [Amazon S3](https://aws.amazon.com/s3/?nc2=h_ql_prod_st_s3) and other third party services which provide an S3–compatible API.

The biggest strength of this plugin, however, lies in the fact that an Amazon S3 bucket can be an _origin_ for an [Amazon CloudFront](https://aws.amazon.com/cloudfront/?nc2=h_ql_prod_nt_cf). The files you upload to the S3 bucket become instantly available to a global Content Delivery Network (CDN). This lets you deliver your media files in an efficient and cost–effective manner to your international audience with minimal cost.

This plugin uses the same Amazon S3 library as our Akeeba Backup Professional and Akeeba Solo Professional series of products. Do note that it has _more_ performance and operational constraints than our backup software since we are limited to what we can do by the architecture of the Joomla Media Manager. Most importantly, managing large files may result in memory exhaustion or timeouts because we can't employ the same tricks and workarounds we do in our backup software.

## Assumptions

This plugin and this documentation is written on the assumption that the user is familiar with the Amazon S3 and, optionally, Amazon CloudFront services. We will not explain basic concepts such as what the services are, how they work together, authentication and ACLs. If you are not already familiar with these concepts please consult Amazon's documentation and one of the countless tutorials you cna find on the Internet.

We also assume that the user has read our [caveats](caveats.md) page which explains some less–than–obvious requirements and side-effects.

## Installation

[Download](https://github.com/akeeba/plg_filesystem_s3/releases) and install the latest version of the plugin.

Go to Extensions, Manage, Plugins and enable the plugin.

Edit the plugin and set up one or more Connections (see below). 

## Configuration

The plugin has two configuration tabs, Plugin and Advanced.

### The Plugin tab (Connections)

In this tab you can define one or more connections. Each connection appears as a separate entry on the Media Manager sidebar, under the heading “Amazon S3”. 

Each Connection has the following options.

**Name**. How you want this connection to be displayed in the Media Manager. If you change this after you have inserted media files to content you might end up unable to see the selected media file when you edit the content. Choose a name _and stick with it_.

**Connection Type**. You can choose one of Amazon S3, Amazon CloudFront or Custom S3-Compatible Storage Provider. This controls which options you see and how public URLs to your media files work:
* Amazon S3. You connect to a bucket stored in Amazon S3 proper. Public URLs will be pre–authorised URLs with a validity period of 10 years. You can use objects stored with non-public ACLs. It's slower and costs more.
* Amazon CloudFront. Use this when your bucket stored in Amazon S3 proper is an origin to an Amazon CloudFront distribution (CDN). Public URLs will be constructed by combining the CDN URL with the relative path of the file in the bucket. You can only use objects stored with public ACLs (this is a CloudFront requirement). Recommended. It's faster and costs less.
* Custom S3-Compatible Storage Provider. Lets you use this plugin with third party services which provide an S3-compatible API. You will need to enter the endpoint URL to the third party service's S3-compatible API. Depending on the service this may be slow and/or expensive; consult the documentation of the third party service.

**Custom Endpoint (URL)**. If you are using a third party (non-Amazon) service you need to enter its Endpoint URL. This MUST be a full URL, including the protocol and path (the latter if applicable). For example `https://s3.example.com` or `http://example.com/s3api`. DO NOT enter just a hostname such as ~`s3.example.com`~. It WILL NOT work.

**Access Key**. The Access Key for your Amazon S3 user. If you have created a user through Amazon IAM please make sure that the user has the rights to list bucket contents, create objects, delete objects, copy objects and get objects. If any permission is missing the plugin will not work properly and you will be the one to blame for it.

**Secret Key**. The Secret Key corresponding to the Access Key you provided.

**CDN URL**. If you are using the Amazon CloudFront connection type you need to enter the **URL** which corresponds to the Bucket and Directory you are configuring here. This is a complete URL with the protocol and path (the latter if applicable), for example `https://example.cloudfront.net`, `https://example.cloudfront.net/someDirectory`, `https://custom-cname.example.com`, or `https://custom-cname.example.com/someDirectory`. Please read the [caveats](caveats.md).

**DualStack (IPv4/IPv6) support**. Amazon S3 has two kinds of endpoints we can use: legacy endpoints which only support IPv4 and the newer DualStack endpoints which support both IPv4 and IPv6. If your server supports IPv6 using the DualStack endpoints makes things a bit faster. If unsure, set this to Yes. If you have a weirdly configured server which can resolve IPv6 DNS entries but cannot talk to IPv6 hosts set this to No and shout at your host for doing something veritably wonky.

**Bucket**. The name of your S3 bucket.

**Signature Method**. The signature method for authorising requests to Amazon S3. For Amazon S3 proper use v4. For third party services consult the service documentation; in most cases it is v2 but some services have started supporting v4.

**Region**. If using v4 signatures you **MUST** provide the region your bucket is created in. This is available in your Amazon Web Services Console. If you are using a region which is not listed here and/or using a third party service select “Custom” to see the next option.

**Custom region**. Enter a custom region name if it was not available in the Region dropdown. You must enter the identified for the region (e.g. `us-east-1`), NOT its human-readable name (e.g. ~`US East (N. Virginia)`~)!

**Bucket Access**. Amazon S3 has two ways to access a bucket: using the bucket name as a subdomain to the endpoint (“Virtual Hosting“) or using it as part of the request's path (“Path Access”). If you are using Amazon S3 proper you should always use Virtual Hosting. If using a third party service you _may_ have to use Path Access; if unsure, ask the third party service.

**Directory**. The directory in your bucket which will be the topmost visible folder (root) in this connection. For the bucket root leave this empty. For subdirectories DO NOT use a leading or trailing slash. If you are using the Amazon CloudFront type you must make sure that the Directory you are entering here corresponds to the CDN URL you have entered. Please read the [caveats](caveats.md) in this case.

**Storage class**. The storage class for uploaded, renamed and copied files. We recommend using Standard or Reduced Redundancy Storage. Please read the [caveats](caveats.md) to understand how this affects performance and cost, as well as why renaming or copying a file will change it to use this storage class.

### Advanced

These options are only meant for _expert users_ who understand what they are doing. If you change these settings you must be ready to accept full responsibility for your actions and understand that you need to be able to fix anything you break.

**Image preview**. Choose whether the Media Manager should display previews of some files.
* Always. Previews will always be displayed.
* CloudFront only. Previews will only be displayed for connections of the Amazon CloudFront type.
* Never. Disable previews.
Previews require transferring all files under a folder from S3 / CloudFront to your device _every time you access that folder through the Media Manager_. This can be slow _and expensive_.

**Preview extensions**. Files with these extensions will have previews displayed in the Media Manager. Leave empty to let Joomla decide which files to preview (by default it's image files, the same list as our default but excluding SVG and PDF). Default: `png,gif,jpg,jpeg,bmp,webp,pdf,svg`

**Use Lambda@Edge resize**. You can have Amazon CloudFront automatically resize images into thumbnails for more efficiently displaying folder listings [using Amazon Lambda@Edge](https://aws.amazon.com/blogs/networking-and-content-delivery/resizing-images-with-amazon-cloudfront-lambdaedge-aws-cdn-blog/). The thumbnails are stored in the same S3 bucket as the original images. This is for expert, hardcore users only. We cannot help you set up Lambda@Edge for your CloudFront distribution.

**Resize dimension (px)**. The thumbnail dimensions when the **Use Lambda@Edge resize** option is enabled. This is in pixels. Using a setting of 100 will create thumbnails up to 100x100 pixels in size; that's the perfect size for Media Manager thumbnails on regular pixel density (96ppi) monitors. If you have a HiDPI monitor set this higher; 200 is great for 192ppi (2x, original Apple Retina) displays, 300 is great for 288ppi (3x) displays, 400 is great for 384ppi (4x) displays. In most cases 200 is fine for use with HiDPI laptops and monitors. Denser displays tend to be mobile phones and tablets where the larger transfer size that comes with sharper images quickly brings you into the realm of the law of diminishing returns (too much memory used, too much data used, slow scrolling, bad experience all around). 