<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SyncroSiteConfig extends DataObjectDecorator {

	public function extraStatics() {
		return array(
			'db'		=> array(
				'SystemID'			=> 'Varchar(128)'
			)
		);
	}

	public function onBeforeWrite() {
		if (!$this->owner->SystemID) {
			$this->owner->SystemID = Uuid::get();
		}
	}

	public function getSyncroIdentifier() {
		if (!$this->owner->SystemID) {
			// write now to make sure ID is set
			$this->owner->write();
		}
		return $this->owner->SystemID;
	}
	
	public function updateCMSFields(FieldSet &$fields) {
		$fields->addFieldToTab('Root.Syncro', new ReadonlyField('SystemID', _t('Syncro.SYSTEM_ID', 'System Identifier')));
	}
}
