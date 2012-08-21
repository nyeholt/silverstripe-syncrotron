<?php

/**
 * Add this extension to items that you want to allow other nodes to sync down
 * 
 * You will probably want to 
 * 
 * ALTER TABLE `DataObjectThisIsAppliedTo` ADD INDEX ( `LastEditedUTC` ) ;
 * ALTER TABLE `DataObjectThisIsAppliedTo` ADD INDEX ( `UpdatedUTC` ) ;
 * 
 * depending on whether you're doing UpdatedUTC updates or LastEditedUTC updates
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SyncroableExtension extends DataExtension {

	public static $db = array(
		'MasterNode'				=> 'Varchar(128)',
		'ContentID'					=> 'Varchar(128)',
		'CreatedUTC'				=> 'SS_Datetime',			// create time on master node
		'LastEditedUTC'				=> 'SS_Datetime',			// utc last edited time on master node
		'UpdatedUTC'				=> 'SS_Datetime',			// utc last edited time on any node
		'OriginalID'				=> 'Int',
	);

	public function onBeforeWrite() {
		$config = SiteConfig::current_site_config();
		if (!$this->owner->MasterNode) {
			$this->owner->MasterNode = $config->getSyncroIdentifier();
		}
		
		if (!$this->owner->ContentID) {
			$uuid = new Uuid();
			$this->owner->ContentID = $uuid->get();
		}
		
		$nowUTC = gmdate('Y-m-d H:i:s');
		$this->owner->UpdatedUTC = $nowUTC;
		
		// if we're updating on the master node, change the lasteditedUTC and created UTC if needbe
		if ($this->owner->MasterNode == $config->getSyncroIdentifier()) {
			$this->owner->LastEditedUTC = $nowUTC;
			if (!$this->owner->CreatedUTC) {
				$this->owner->CreatedUTC = $nowUTC;
			}
		}
	}

	public function onAfterWrite() {
		if (!$this->owner->OriginalID) {
			$this->owner->OriginalID = $this->owner->ID;
			$this->owner->write();
		}
	}

	public function updateFrontendFields(FieldList $fields) {
		$fields->removeByName('MasterNode');
		$fields->removeByName('ContentID');
		$fields->removeByName('OriginalID');
		$fields->removeByName('LastEditedUTC');
	}

	public function onAfterDelete() {
		parent::onAfterDelete();
		if ($this->owner->MasterNode == SiteConfig::current_site_config()->SystemID) {
			SyncroDelete::record_delete($this->owner);
		}
	}
}
