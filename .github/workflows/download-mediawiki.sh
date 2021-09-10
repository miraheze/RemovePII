#! /bin/bash
set -ex

MW_BRANCH=$1

wget https://github.com/wikimedia/mediawiki/archive/"$MW_BRANCH".tar.gz -nv

tar -zxf "$MW_BRANCH".tar.gz
mv mediawiki-"$MW_BRANCH" mediawiki

cd mediawiki
git clone https://github.com/wikimedia/mediawiki-extensions-CentralAuth.git --depth=1 --branch="$MW_BRANCH" extensions/CentralAuth
git clone https://github.com/wikimedia/mediawiki-extensions-BlogPage.git --depth=1 extensions/BlogPage
git clone https://github.com/wikimedia/mediawiki-extensions-SocialProfile.git --depth=1 extensions/SocialProfile

git clone https://github.com/Universal-Omega/SimpleBlogPage --depth=1 extensions/SimpleBlogPage

composer update --prefer-dist --no-progress
