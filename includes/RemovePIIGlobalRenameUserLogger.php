<?php

namespace Miraheze\RemovePII;

use GlobalRenameUserLogger;

class RemovePIIGlobalRenameUserLogger extends GlobalRenameUserLogger {
	/**
	 * @return bool
	 */
	public function log() {
		return false;
	}
}
