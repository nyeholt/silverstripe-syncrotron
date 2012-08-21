<?php

/**
 * Permissions for syncrotron usage
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SyncrotronPermissions implements PermissionDefiner {
	
	public function definePermissions() {
		return array(
			'Syncro'
		);
	}
}
