#! /bin/bash
set -ex

MW_BRANCH=$1
MW_REPO=$2

wget https://github.com/"$MW_REPO"/mediawiki/archive/"$MW_BRANCH".tar.gz -nv

tar -zxf "$MW_BRANCH".tar.gz
mv mediawiki-"$MW_BRANCH" mediawiki

cd mediawiki
composer update --prefer-dist --no-progress
