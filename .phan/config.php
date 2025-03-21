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
	'PhanAccessMethodInternal',
	'SecurityCheck-LikelyFalsePositive',
	// Issue with backwords compatible code (TODO: To be removed)
	'PhanUndeclaredMethod',
	'PhanDeprecatedFunction',
];

return $cfg;
