<?php

/**
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class SyncroableFile extends DataExtension {
	public function updateSyncroData(&$properties) {
		$path = $this->owner->getFullPath();
		if (is_file($path)) {
			$properties['RAW_FILE'] = base64_encode(file_get_contents($path));
		}
	}
	
	public function onSyncro($properties) {
		if (isset($properties->RAW_FILE)) {
			$path = $this->owner->getFullPath();
			Filesystem::makeFolder(dirname($path));
			file_put_contents($path, base64_decode($properties->RAW_FILE));
		}
	}
}
