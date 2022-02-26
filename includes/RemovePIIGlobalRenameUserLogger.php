<?php

namespace Miraheze\RemovePII;

use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserLogger;

class RemovePIIGlobalRenameUserLogger extends GlobalRenameUserLogger {
	/**
	 * @param string $oldName
	 * @param string $newName
	 * @param array $options
	 */
	public function log( $oldName, $newName, $options ) {
	}
}
