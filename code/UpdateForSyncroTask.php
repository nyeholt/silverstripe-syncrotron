<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class UpdateForSyncroTask extends BuildTask {
	
	public function run($request) {
		// get all sync objects and make sure they have a contentID
		$typesToSync = ClassInfo::implementorsOf('Syncroable');
		foreach ($typesToSync as $type) {
			if ($type == 'SyncroTestObject') {
				continue;
			}
			$objs = DataObject::get($type, '"ContentID" IS NULL');
			if ($objs) {
				foreach ($objs as $obj) {
					echo "Setting content ID for " . $obj->Title." ... ";
					$obj->write();
					echo $obj->ContentID . "<br/>\n";
				}
			}
		}
	}
}
