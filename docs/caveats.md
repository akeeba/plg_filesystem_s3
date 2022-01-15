# Caveats

This page documents requirements and side-effects which are not immediately obvious.

Before assuming you have found a bug please read this and make sure you have not, in fact, hit a caveat.

## Server environment

### The PHP cURL must be installed

Your host needs to have installed and enabled the `curl` PHP extension. This is used to communicate with the Amazon S3 service. 

While Joomla allows communicating with remote servers either via the `curl` extension or the PHP fopen() handlers, the complexity of the Amazon S3 API only really allows us to use `curl`. You can check that in your server's `phpinfo()` output, as displayed in System, System Information in Joomla itself.

The `curl` extension must be built with support for the `http` and `https` handlers.

Moreover, you need to have a cache of the root Certification Authorities' certificates (`cacert.pem`). This is provided by Joomla itself. If you have not messed with it you will have no problem.

### Your host may prevent or corrupt requests to remote hosts

Some hosts use a proxy or firewall for outbound requests, i.e. requests originating from your server to external servers. If such a proxy or firewall declines or corrupts the request this plugin will malfunction.

Typical symptoms include not being able to list any contents of your S3 buckets, being unable to upload anything, your uploads and/or downloads having zero size, or uplaods claiming to be successful but nothing is actually uploaded. 

This is not a bug, it's something you have to address with your host.

As a side note, this plugin is using the Amazon S3 connector we wrote for Akeeba Backup Professional. It's being used to transfer _dozens of millions of files_ every year across hundreds of thousands of sites around the world. It's _robust_. It works.

## Configuration

### Access and Secret Key validation

The Access and Secret Key you provide are validated according to [Amazon's security guidelines](https://aws.amazon.com/blogs/security/a-safer-way-to-distribute-aws-credentials-to-ec2/). If you are using a third party service which issues Access and Secret Keys which do not conform to Amazon's guidelines they will not be accepted in the plugin's configuration. Sorry, we will not remove the validation check. It's a security feature.  

The validation is passive, meaning that we only check that the _format_ of the keys, not their content. If you enter them wrong or if they do not grant you read and write access to the bucket and directory you have specified this plugin will not work properly. This is not a bug, it's a user error. Always test your access and secret key with a third party application, such as CyberDuck, to ensure they allow you to list the bucket's contents **AND** write, copy, delete and overwrite objects _in the same bucket and directory_ you configured in the plugin. 99% of your problems stem from this.

### No support for EC2–provided credentials

Even though Akeeba Backup Professional allows you to leave the Access and Secret Key blank on sites running on Amazon EC2 instances and retrieve the credentials from the EC2 instance itself we decided to _not_ implement this feature for this plugin.

This is a deliberate design choice which we will not change.

The utility of EC2-provided credentials is very limited in the use cases covered by the Media Manager Adapter plugins. These use cases are very, _very_ different from storing backup archives for safekeeping.

### Bucket naming

Bucket names are validated against the [constraints described by Amazon itself](https://docs.aws.amazon.com/AmazonS3/latest/userguide/bucketnamingrules.html).

Notably, bucket names can ONLY use lowercase letters. Even though the Amazon Web Services Console lets you create buckets in UPPERCASE or MixedCase they will **not** be accepted in the plugin's configuration, nor will they work.

Furthermore, you cannot use dots in your bucket names even though the Amazon Web Services Console lets you create buckets with dots in their names.

These are not arbitrary requirements. They stem from how the Amazon S3 API _actually works_ using the subdomain access method Amazon recommends. In fact, the subdomain method is the only one accepted in regions which were created after Amazon introduced the S3 V4 signature method. Allowing you to use bucket names in MixedCase or with.dots would cause an instant and non-obvious failure which would be hard or impossible to debug. 

### Bucket permissions and constraints

Buckets may be configured in S3 to always have private access or constrain the names of the files you can upload. Any such limitations and constraints will cause the plugin to not work properly.

Once again, this is not a bug — it's a limitation imposed by your actions, therefore we treat it as a user error.

### Bucket lifecycle policies

You can set up lifecyle policies on Amazon S3 buckets. These policies will change the storage class of the objects (files) stored in the bucket and/or delete them.

Kindly note that some storage classes, namely Intelligent Tiering, Glacier and Glacier Deep Archive are _fundamentally incompatible_ with the Joomla Media Manager use case. This is explained further below. If you have a lifecycle policy which migrates an object to such a storage class this plugin will not work properly. This is not a bug, it's a user error.

It should go without saying that if an object is automatically deleted it is no longer available. Obviously, in this case you will get broken links and “missing” files. This is not a bug, it's a user error.

### v4 signature and regions

The Amazon S3 API requires all requests to be authenticated. Authentication is performed by _signing_ the request. There are two signature algorithms, v2 (legacy) and v4 (recommended).

The v2 signature only works in Amazon S3 regions which were launched a long time ago. As a rule of thumb, only regions available prior to 2013 support the v2 signatures. Moreover, v2 signatures are used by most third party services providing an S3-compatible API. 

If you are using a third party service which _does not_ mention a region: you should use the v2 signature API. If you are using Amazon S3 proper; or a third party service which _does_ mention a region: use the v4 signature API.

When using the v4 API you *must* provide the region your bucket is in. You can find this information in the Amazon Web Services Console. It's the region you selected when creating the bucket. If you provide the wrong region this plugin will not work properly. This is not a bug, it's a user error.

If you use the wrong signature method, e.g. v2 signature for Amazon S3 EU (Frankfurt) which only supports v4 signature, or v4 signatures for a third party service which only supports v2 signatures this plugin will not work properly. This is not a bug, it's a user error.

### Your CloudFront CDN URL must match your bucket and directory

Amazon CloudFront allows a single CloudFront distribution to have [multiple origins](https://advancedweb.hu/how-to-route-to-multiple-origins-with-cloudfront/). In fact it can get so convoluted that there is no _definitive_ way to know which bucket, bucket directory and CDN path correspond to each other. Therefore the onus is on you, the human operator, to provide a CDN URL which matches the bucket and directory you have configured.

Let's take the simplest case where you have a single origin CloudFront distribution. The distribution URL is `https://example.cloudfront.net` and your bucket name is `example`. The root of the distribution corresponds to the root of the bucket. You want to set up a Media Manager connection for the files under the directory `foobar` of your bucket. Your connection needs the following configuration:

* CloudFront URL: `https://example.cloudfront.net/foobar`
* Bucket: `example`
* Directory: `foobar`

Pretty straightforward _as long as_ you remember that your CloudFront URL _must_ include the directory name.

Let's take a more complicated case where the distribution URL `https://example.cloudfront.net/foobar` corresponds to the _root_ of the bucket `example`.

Your connection needs the following configuration:

* CloudFront URL: `https://example.cloudfront.net/foobar`
* Bucket: `example`
* Directory: `` (leave empty)

In this more complicated case there is no direct connection between the path in the CloudFront URL and the bucket's Directory. And this, kids, is why we let you configure a CloudFront _URL_ (which includes a path), not just a hostname (which does not include a path).

### Storage classes

Storage classes control how long it takes for an object to load, how much it costs to store it and how much it costs to retrieve it. Do remember that for the Media Manager use case you need to consider the following factors:

* Your media will be used in content. If it takes a long time to load your site's loading speed will suffer greatly, completely negating the benefits of using cloud storage for your media files.
* Using the Media Manager to view a folder on Amazon S3 requires loading _all image (or, generally, ‘previewable’) files in that folder_ for the previews (thumbnails) to display. If a folder contains 1000 files you will be transferring all these 1000 files every time you access the folder through the Media Manager — it does not cache the thumbnails, that's how Joomla itself is designed. If your files are on a slow tier with expensive data transfer the page will take forever to load and you will be paying through the nose.

The Standard and Reduced Redundancy Storage classes are fast and relatively cost efficient. That's what we recommend you use.

The Standard – Infrequent Access and One Zone – Infrequent Access storage classes are slow and may result in higher transfer charges. You are advised **AGAINST** using them for the Media Manager use case.

The Intelligent Tiering, Glacier and Glacier Deep Archive storage classes are _fundamentally incompatible_ with the Media Manager use case. When Intelligent Tiering falls back to an archive state it takes anywhere between a few seconds to a few hours for the file to transfer. Same goes for Glacier and Glacier Deep Archive. These storage classes only make sense for archived material which is NOT going to be displayed on a site. That's why these storage classes are not even made available to you in the configuration options.

### CloudFront vs S3 and cost control

When you are using Amazon S3 or a compatible service _without_ the bucket as a CDN origin your access to media files goes through a pre-authorised URL. That's a URL to the bucket itself with a special signature which allows access for the next ten years. Yes, this works and does make your files accessible over the web. However, it has two downsides:

* It's slow. Your data is only present in the region or availability zone the bucket was created in. This may be a long distance away from the client. Moreover, the S3 service itself is limited in bandwidth for transferring files due to its internal workings. In the end of the day, this method may actually be _slower_ than storing files directly on your server!
* It's expensive. Amazon S3 is designed with the goal of high resilience, not fast data transfer. In fact, it's assumed that it will only be used for occasional data transfer. As a result data transfer from S3 to the Internet is rather expensive. If you have high traffic media files or use the Media Manager a lot you wil incur significant charges. 

On the other hand, you can use an Amazon S3 bucket as an origin for a CloudFront distribution. CloudFront is a global Content Delivery Network (CDN) with edge nodes dispersed around the world. The first time a client asks for a media file from a specific node — or after that file is considered “stale” by the node — you will be charged for data transfer from the S3 bucket to the node. Any subsequent requests for that file from that node before it's considered stale cost much, much lower than transferring it from S3 to the Internet. It's also far faster because these nodes have a ton of bandwidth and are geographically proximate to your clients.

We **VERY STRONGLY** recommend using this plugin with S3 buckets configured as origins for a CloudFront distribution, telling the plugin this is the case and provide it with the CloudFront distribution URL (either the one generated by Amazon or a CNAME you have set up). Your Media Manager experience will be far faster and the charges you will incur from data transfer will be rather minimal.

### Object access control (ACL)

This plugin is designed with just the Media Manager use case in mind: public media, accessible to all. As a result we always use the ‘Public’ ACL for newly uploaded files i.e. everyone can read them but only the owner can modify them. This is deliberate and won't change. You cannot upload private files with this media manager adapter plugin.

Some of your existing files _may_ have difference ACLs. These won't work if you use a CloudFront distribution (CloudFront requires your files to have public read permissions) but _will_ work in the regular Amazon S3 mode since we create pre-authorised URLs.

Do note that Amazon S3 does not have an atomic rename / move operation. Renaming a file in the Media Manager creates a copy of the file and deletes the original. The new file will have Public ACL _regardless of what was the ACL of the original file_. Again, this is deliberate and will not change.

**All new files or copied / moved / renamed files WILL get Public permissions. Do not use this plugin if you are not OK with every media file being accessible to everyone who has the URL to that file.**

## Media Manager

### File naming

Amazon S3 forbids file names which end in a dot such as `example.`. If you try to upload such a file the trailing dot _will be removed_.

Amazon S3 forbids using forward slashes (`/`) in file names; they are used as folder delimiters in the file path. If you use a forward slash it will be converted to an underscore (`_`).

All other characters will be allowed, even though some of them may be problematic in some Operating Systems (mainly Windows).

### Large folders will lead to timeouts

Even though Joomla ostensibly has different methods for retrieving file listings and metadata for a single file, it uses both method interchangeably. That is to say, the file listing method may be used to retrieve metadata for a single file and the single metadata method may be used to retrieve metadata for a folder.

The Amazon S3 API is **NOT** designed to operate this way. In fact, it does not even have the concept of folders. It merely stores binary data and metadata using a key (equivalent to a file path). There is an implicit assumption that forward slashes (`/`) in a file name are to be treated as folder names.

This discrepancy between what the Media Manager expects and how Amazon S3 works means that we need to perform many more requests than we'd like to for the internal operations of the adapter.

When you are retrieving listings of very large ‘folders’ (hundreds to thousands of files) or when you are renaming or delete them you MAY end up with a timeout. Sorry, there's nothing we can do about it and we do not consider it a bug.

Furthermore, Joomla requires us to produce a full listing of all files and ‘folders’ when retrieving the file list of a ‘folder’. The Amazon S3 API only allows up to 1000 files and prefixes (‘folders’) to be listed at once. This means that we will need to perform a number of requests and use up a lot of memory to produce a complete file listing.

For these reasons we **VERY STRONGLY** recommend that you keep the number of files per folder to no more than a couple of hundred files. Use subdirectories to organise your files and avoid renaming folders.

### Uploads and PHP memory

The Media Manager Adapter API requires the contents of all files being uploaded to be sent to the adapter as a string. This means that the file MUST fit in the available PHP memory.

We try to use efficient memory management using pass-by-reference when uploading to avoid requiring even more memory. It is, however, possible that you try to upload a very large file (e.g. video) which is larger than the free PHP memory. This will cause an error. This is not a bug, it's a server configuration issue or an artefact of how Joomla works.

If we were to design the adapter interface we'd be instead passing a stream resource. This would allow uploading files larger than the available PHP memory limit. That's what we do in our backup software where backup archives are usually many times bigger than the PHP memory limit. Unfortunately, the Joomla Media Manager Adapter API was designed by people who do not have the real world experience in implementing large file transfers to remote servers. Admittedly, this is a very rare kind of experience. Even the authors of Guzzle, the go-to PHP library for implementing APIs, used by Amazon itself, do not have that experience meaning that the official Amazon S3 API for PHP would fail to transfer large files — that's why we wrote our own...

If you need to upload files bigger than a couple of MiB we **STRONGLY RECOMMEND** using the Amazon Web Services Console or an external application such as CyberDuck _instead of_ the Media Manager. It's the same with files being uploaded to your server through Media Manager for pretty much the same reasons (how the Media Manager is designed).

### Uploads and timeouts

As mentioned earlier, Amazon S3 is designed with resiliency in mind, not a high rate of data transfer. The bigger the file you upload the longer it will take to transfer it. Because of the network conditions and the constrained bandwidth of S3 itself you will see transfer rates of _at most_ 1 MiB per second. 

This means that even if you have plenty of PHP memory available to transfer large files — such as video files or PDFs in the tens or hundreds of MiB — you may experience a timeout error.

The way we deal with that in our backup software is by using multipart uploads: uploading 5 MiB chunks of large files at a time and resuming the transfer in a new pageload if we are reaching the timeout limit of PHP or the server itself. Unfortunately, the Media Manager Adapter API **DOES NOT** have such a provision for multipart / resumable uploads. As a result transfers of large files may fail. This is not a bug, it's a design issue with Joomla itself.

If you need to upload files bigger than a couple of MiB we **STRONGLY RECOMMEND** using the Amazon Web Services Console or an external application such as CyberDuck _instead of_ the Media Manager. It's the same with files being uploaded to your server through Media Manager for pretty much the same reasons (how the Media Manager is designed).

### Downloads, temporary files and timeouts

When downloading files the API requires us to provide a file resource. 

There are two ways we could implement that. One is downloading the entire file in memory and providing a memory stream resource. If we did that the maximum size of a file you could download would be constrained by the available PHP memory.

The second option, the one we used, is to download the file from Amazon S3 to a temporary file and provide a local file resource to Joomla. It uses that resource to send the file from your server to your browser. The temporary file is deleted automatically at the end of the download _as long as_ there is no fatal PHP error.

This is an extremely inefficient approach, owning to the design of Joomla itself.

If you have very large files you might get a timeout either while downloading the file from S3 (which, as we already said, is _slow_) OR while Joomla is sending the file data from your server to you. In this case the temporary file _may_ not be removed.

The way we are dealing with that in our backup software is performing the download directly from S3 to your device using a pre-authorised URL. Unfortunately, the design of Media Manager DOES NOT allow us to implement this far more efficient approach.

We recommend that you instead use the Get Share URL feature in Media Manager and visit that URL from a new browser tab. This will initiate the download _directly from Amazon S3_, therefore supporting arbitrarily large files (up to 5 GiB, the maximum file size supported by Amazon S3 itself).

### Metadata

Amazon S3 does not have the concept of a separate created and modified date. There's only one. Therefore you will always see the same date as Created and Modified in the Media Manager.

As noted above, Amazon S3 does not have the concept of moving / renaming files. Whenever you rename a file or a parent folder the file is copied with the new name and the original is deleted. As a result, the renamed file will show a created / modified date equal to the time it was “renamed”. This is not a bug, that's how Amazon S3 works.

Determining the dimensions of an image requires downloading it first. This information needs to be provided while listing the files of a folder. If we did that on a folder with more than a dozen or so files it would take so long that it would become unusable. Therefore we never report the image size in a folder listing. However, when you _edit_ an image you will of course see its dimensions since the entire image is downloaded for editing!

Likewise, accurately determining the MIME Type would require downloading the entire file which is impractical for the same reasons. Instead of that, we provide a MIME type based on the _file extension_. This may not be entirely accurate but it's better than nothing. 

### Renaming folders

Amazon S3 does not have a way to rename a folder because it does not have the concept of folders. Instead of a folder called `example` you have a bunch of files with names like this:
* `example/file1.jpg`
* `example/file2.jpg`
* `example/inner/file3.jpg`
* `example/inner/file4.jpg`

On a typical filesystem this would be equivalent to the following structure:
```text
example [FOLDER]
   +-- inner [FOLDER]
   |      +-- file3.jpg
   |      +-- file4.jpg
   +-- file1.jpg
   +-- file2.jpg
```
You could rename the `example` folder in a single operation without caring about its contents.

This is not the case with Amazon S3.

When you rename the ‘folder‘ `example` to `foobar` you have to actually renamed **four files**:

* `example/file1.jpg` to `foobar/file1.jpg`
* `example/file2.jpg` to `foobar/file2.jpg`
* `example/inner/file3.jpg` to `foobar/inner/file3.jpg`
* `example/inner/file4.jpg` to `foobar/inner/file4.jpg`

Moreover, Amazon S3 does not have the concept of renaming. We have to copy the file to the new name and delete the original. Since renaming a file or a folder behaves differently we also need to do one more request to find out if this is a file or a folder. If it's a folder we need to do one more request to list its files.

Therefore renaming a folder requires 4 requests per folder and 3 requests per file. In our example with two folders (`example` and `inner`) and four files we need to do 20 requests to Amazon S3.

**RENAMING FOLDERS IS EXTREMELY SLOW, PROPORTIONAL TO HOW MANY SUBFOLDERS AND FILES THEY HAVE UNDER THEM**. As a result we STRONGLY ADVISE AGAINST RENAMING FOLDERS.

If you get a timeout renaming folders it's not a bug, it's how Amazon S3 works.

### Deleting folders

Amazon S3 does not have a way to delete a folder because it does not have the concept of folders. See the section on renaming folders above.

Deleting a folder requires recursively deleting each and every file inside it and in its subfolders. In fact we need 3 requests per folder and 2 requests per file.

In the example we provided in “Renaming folders”, if we tried to delete the `example` folder we'd need to do 14 requests to Amazon S3.

**DELETING FOLDERS IS EXTREMELY SLOW, PROPORTIONAL TO HOW MANY SUBFOLDERS AND FILES THEY HAVE UNDER THEM**. As a result we STRONGLY ADVISE AGAINST DELETING FOLDERS.

If you get a timeout deleting folders it's not a bug, it's how Amazon S3 works.

