# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Joomla 4/5 plugin that integrates Amazon S3 and S3-compatible storage services with Joomla's Media Manager. It allows users to store media files on Amazon S3 and optionally serve them through Amazon CloudFront CDN.

**Important constraint**: As of version 1.2.0, this plugin only works with files stored with Public ACLs. Files with private ACLs are not supported.

## Build System

The project uses Phing as its build system. Build configuration is imported from a shared buildfiles repository.

**Build commands**:
- `phing git` - Default target (defined in common.xml from buildfiles)
- `phing package-pkg` - Creates the installation package (depends on new-release, setup-properties, package-plugins)
- `phing release` - Creates a GitHub release

The build process creates a ZIP package in the `build/release` directory.

## Development Commands

**Composer**:
- `composer install` - Install dependencies (vendors are placed in `plugins/filesystem/s3/vendor/`)
- Composer platform target: PHP 7.4.0
- Dependencies include `akeeba/s3` (custom S3 library) and `league/mime-type-detection`

**Note**: There are no standard test commands visible in the repository.

## Architecture Overview

### Plugin Structure

This is a Joomla filesystem plugin (`group="filesystem"`) that implements Joomla's Media Manager adapter interface. The plugin architecture follows:

1. **Main Plugin Class** (`src/Extension/S3.php`):
   - Implements `SubscriberInterface` and `ProviderInterface`
   - Subscribes to `onSetupProviders` event
   - Returns multiple adapters based on configured connections
   - Each connection appears as a separate entry in Media Manager

2. **Adapter Layer** (`src/Adapter/S3Filesystem.php`):
   - Implements `AdapterInterface` from Joomla's Media component
   - Main class that handles all file operations (CRUD, copy, move, search)
   - Contains caching logic for S3 requests to improve performance
   - Includes extensive workarounds for inefficiencies in Joomla's Media Manager API

3. **S3 Communication Layer** (`vendor/akeeba/s3/`):
   - Custom S3 library (also used in Akeeba Backup)
   - Handles S3 signatures (v2 and v4), requests, and responses
   - Supports both Amazon S3 and S3-compatible services

### Key Design Patterns

**Connection Configuration**: The plugin supports multiple connections through a subform configuration. Each connection creates a separate adapter instance with:
- Access credentials (access key, secret key)
- Bucket and region information
- Optional CDN URL for CloudFront or custom CDN
- Storage class, caching settings, and other options

**Caching Strategy**: The adapter implements optional caching of S3 API responses using Joomla's cache system to reduce API calls and improve performance. Cache can be enabled per-connection with configurable lifetime (10s to 1 year).

**Joomla API Workarounds**: The code contains detailed comments (lines 677-701, 787-812 in S3Filesystem.php) explaining significant inefficiencies in Joomla's Media Manager adapter design:
- `getFile()` is used for both files AND directories
- `getFiles()` is used for both directory listings AND single file metadata
- This results in multiple unnecessary API calls for each operation

### File Operations

All file operations go through the S3Filesystem adapter:
- **Directory listings**: Use `getBucket()` with delimiter for non-recursive, without for recursive
- **File metadata**: Use `headObject()` to get file information
- **File upload**: Use `putObject()` with Input class, always sets Public Read ACL
- **File download**: Use `getObject()` to download to temp file, return file handle
- **Copy/Move**: S3 has no atomic move, so copy then delete source
- **Delete**: Recursive for directories (can timeout on large directories)

### URL Generation

The adapter generates two types of URLs:
1. **CDN URLs**: When `isCDN` is true, returns the configured CDN URL + path
2. **S3 URLs**: Returns authenticated (signed) S3 URLs with query string removed (because only public files are supported)

## Compatibility

- PHP: ^7.2 || ^8.0 (platform target: 7.4.0)
- Joomla: Latest release + latest LTS (currently 4.x and 5.x)
- Only tests on supported (non-EOL) PHP versions

## Important Implementation Notes

1. **All uploaded files use Public Read ACL** - This is hardcoded throughout the adapter
2. **Storage classes**: STANDARD, REDUCED_REDUNDANCY, STANDARD_IA, ONEZONE_IA are supported
3. **No signed URLs**: The plugin strips query strings from authenticated URLs because it expects public files
4. **Temporary files**: The adapter tracks temporary files created during operations and cleans them up in the destructor
5. **MIME type detection**: Uses league/mime-type-detection with fallback to a built-in map of common extensions
6. **Path safety**: File names are sanitized to prevent issues with S3 (no trailing dots, slashes converted to underscores, lowercase extensions)
7. **EC2 IAM Role Support** (v1.3.0+): When both Access Key and Secret Key are left empty, the plugin automatically retrieves temporary credentials from EC2 instance metadata service (IMDSv2). Requirements: Amazon S3 only, v4 signatures, EC2 hosting, IMDSv2 enabled, IAM role attached. See `docs/ec2-iam-roles.md` for details.
