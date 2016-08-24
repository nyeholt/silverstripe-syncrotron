<?php

/**
 * Add this extension to items that you want to allow other nodes to sync down
 * 
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SyncroableExtension extends DataExtension {

	private static $db = array(
		'MasterNode'				=> 'Varchar(128)',
		'ContentID'					=> 'Varchar(128)',
		'CreatedUTC'				=> 'SS_Datetime',			// create time on master node
		'LastEditedUTC'				=> 'SS_Datetime',			// utc last edited time on master node
		'UpdatedUTC'				=> 'SS_Datetime',			// utc last edited time on any node
		'OriginalID'				=> 'Int',
	);
    
    private static $indexes = array(
        'LastEditedUTC', 'UpdatedUTC', 'ContentID', 'MasterNode',
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

	public function updateCMSFields(FieldList $fields) {

		// Remove the syncrotron fields so they may not be manually changed.

		$fields->removeByName('MasterNode');
		$fields->removeByName('ContentID');
		$fields->removeByName('CreatedUTC');
		$fields->removeByName('LastEditedUTC');
		$fields->removeByName('UpdatedUTC');
		$fields->removeByName('OriginalID');
        
        if ($this->owner->MasterNode != SiteConfig::current_site_config()->SystemID) {
            $source = LiteralField::create('syncromessage', '<p class="message good">' . _t('Syncrotron.OTHER_SOURCE', 'This item is syncronised from '  . $this->owner->MasterNode) . '</p>');
        } else {
//            $source = LiteralField::create('syncromessage', '<p class="message good">' . _t('Syncrotron.ME_AS_SOURCE', 'This item originated in this system '  . SiteConfig::current_site_config()->SystemID) . '</p>');
        }
        $fields->addFieldToTab('Root.Main', $source);
	}

	public function updateFrontendFields(FieldList $fields) {
		$fields->removeByName('MasterNode');
		$fields->removeByName('ContentID');
		$fields->removeByName('OriginalID');
		$fields->removeByName('LastEditedUTC');
	}

	public function onAfterDelete() {
		parent::onAfterDelete();
		// an actual delete vs draft delete
		if (!$this->owner->hasExtension('Versioned')) {
			if ($this->owner->MasterNode == SiteConfig::current_site_config()->SystemID) {
				SyncroDelete::record_delete($this->owner);
			}
		}
	}
	
	public function onAfterUnpublish() {
		if ($this->owner->MasterNode == SiteConfig::current_site_config()->SystemID) {
			SyncroDelete::record_delete($this->owner);
		}
	}
}
