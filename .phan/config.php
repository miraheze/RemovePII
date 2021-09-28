<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'], [
		'../../extensions/CentralAuth',
		'../../extensions/SocialProfile',
		'../../extensions/SimpleBlogPage',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [
		'../../extensions/CentralAuth',
		'../../extensions/SocialProfile',
		'../../extensions/SimpleBlogPage',
	]
);

$cfg['suppress_issue_types'] = [
	'PhanTypeMismatchArgumentNullable',
	'PhanAccessMethodInternal',
	'SecurityCheck-LikelyFalsePositive',
];

return $cfg;
