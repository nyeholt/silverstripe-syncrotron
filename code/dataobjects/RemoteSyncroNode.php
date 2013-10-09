<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class RemoteSyncroNode extends DataObject {
	
	public static $db = array(
		'Title'					=> 'Varchar(64)',
		'RemoteNodeID'			=> 'Varchar(128)',
		'NodeURL'				=> 'Varchar(128)',
		'APIToken'				=> 'Varchar(128)',
		'LastSync'				=> 'SS_Datetime',
	);

	public static $summary_fields = array(
		'NodeURL',
		'LastSync',
	);
	
	public static $searchable_fields = array(
		'NodeURL',
	);

	public function requireDefaultRecords() {
		parent::requireDefaultRecords();

		// Make sure the syncrotron identifier is initially set.

		$configuration = SiteConfig::current_site_config();
		$configuration->getSyncroIdentifier();
	}

}
