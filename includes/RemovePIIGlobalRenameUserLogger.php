<?php

namespace Miraheze\RemovePII;

use GlobalRenameUserLogger;

class RemovePIIGlobalRenameUserLogger extends GlobalRenameUserLogger {
	public function log() {
		return false;
	}
}
