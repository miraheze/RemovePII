<?php

namespace Miraheze\RemovePII;

use GlobalRenameUserLogger;

// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
@class_alias(
	'MediaWiki\\Extension\\CentralAuth\\GlobalRename\\GlobalRenameUserLogger',
	'GlobalRenameUserLogger'
);

class RemovePIIGlobalRenameUserLogger extends GlobalRenameUserLogger {
	/**
	 * @param string $oldName
	 * @param string $newName
	 * @param array $options
	 */
	public function log( $oldName, $newName, $options ) {
	}
}
