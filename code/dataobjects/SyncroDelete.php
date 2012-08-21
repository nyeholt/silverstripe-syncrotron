<?php

/**
 * Used to capture the deletion of an object
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SyncroDelete extends DataObject {
	
	public static $db = array(
		'ContentID'			=> 'Varchar(128)',
		'Type'				=> 'Varchar(128)',
		'Deleted'			=> 'SS_Datetime',
	);
	
	public static function record_delete($object) {
		$delete = new SyncroDelete();
		$delete->ContentID = $object->ContentID;
		$delete->Type = $object->ClassName;
		$delete->Deleted = gmdate('Y-m-d H:i:s');
		$delete->write();
	}
}
