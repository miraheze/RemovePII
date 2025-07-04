<?php

namespace Miraheze\RemovePII;

use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserLogger;

class RemovePIIGlobalRenameUserLogger extends GlobalRenameUserLogger {

	/**
	 * @param string $oldName @phan-unused-param
	 * @param string $newName @phan-unused-param
	 * @param array $options @phan-unused-param
	 */
	public function log( $oldName, $newName, $options ) {
	}
}
