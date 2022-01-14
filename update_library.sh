#!/usr/bin/env bash

pushd plugins/filesystem/s3/src/Library

cp -r ~/Projects/akeeba/s3/src/*

find `pwd` -type f -name '*.php' -print0 | xargs -0 sed -i '.bak' -e 's#Akeeba\\Engine\\Postproc\\Connector\\S3v4#Joomla\\Plugin\\Filesystem\\S3\\Library#g'
find `pwd` -type f -name '*.php.bak' -delete

find `pwd` -type f -name '*.php' -print0 | xargs -0 sed -i '.bak' -e "s#defined('AKEEBAENGINE')#defined('_JEXEC')#g"
find `pwd` -type f -name '*.php.bak' -delete

popd