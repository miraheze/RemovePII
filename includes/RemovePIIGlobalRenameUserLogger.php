<?php

namespace Miraheze\RemovePII;

use GlobalRenameUserLogger;

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
