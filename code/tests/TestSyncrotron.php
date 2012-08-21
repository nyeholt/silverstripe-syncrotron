<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class TestSyncrotron extends SapphireTest {
	
	public $extraDataObjects = array(
		'SyncroTestObject',
	);
	
	public function testProcessUpdate() {
		
		$this->logInWithPermission('ADMIN');
		
		$json = $this->updateJSON();
		
		$data = json_decode($json);
		
		foreach ($data->response as $item) {
			singleton('SyncrotronService')->processUpdatedObject($item);
		}
	}
	
	protected function updateJSON() {
		$json = <<<JSON
{"response": [{ "Title": "Syncable company","MasterNode": "d78bff86-83e7-45b3-bf3a-d3715124194f", "ContentID": "9a95df08-f463-4848-9765-ea615d37e230","ClassName": "SyncroTestObject","LastEditedUTC": "2012-04-30 05:45:48","OriginalID": "1" }]}
JSON;
		return $json;
	}
	
	public function testDeleteRecording() {
		$obj = new SyncroTestObject();
		$obj->Title = 'Whatever';
		$obj->write();
		$this->assertTrue($obj->ID > 0);
		
		$obj->delete();
		
		$this->assertTrue($obj->ID == 0);
		
		$delete = DataObject::get_one('SyncroDelete', '"ContentID" = \'' . $obj->ContentID .'\'');
		$this->assertNotNull($delete);
		$this->assertTrue($delete->ID > 0);
	}
}

class SyncroTestObject extends DataObject implements TestOnly, Syncroable {
	public static $db = array(
		'Title'			=> 'Varchar',
	);
	
	public static $extensions = array(
		'SyncroableExtension'
	);
	
	public function forSyncro() {
		$props = singleton('SyncrotronService')->syncroObject($this);
		return $props;
	}

	public function fromSyncro($properties) {
		singleton('SyncrotronService')->unsyncroObject($properties, $this);
	}
}
