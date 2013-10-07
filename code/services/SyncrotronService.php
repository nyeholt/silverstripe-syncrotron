<?php

/**
 * Service layer for syncrotron interactions. Largely, this is responsible for 
 * publishing the list of changes on this node for the relevant nodes that might dial in
 * 
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SyncrotronService {
	
	const SERVICE_URL = 'jsonservice/Syncrotron/listUpdates';
	
	const PUSH_URL = '/jsonservice/Syncrotron/receiveChangeset';
	
	public static $dependencies = array(
		'dataService'		=> '%$DataService',
	);
	
	/**
	 * Do we require strict permission checking? 
	 * 
	 * If set to true, then the user requires the 'Syncro' permission to the object to be able to display 
	 * the item
	 * 
	 * @var boolean
	 */
	private $strictAccess = true;
	
	/**
	 * Which date field is used to filter the list of updates to send? 
	 * 
	 * If this is set to LastEditedUTC, then only updates on nodes this server is the
	 * master for will be sent across the wire. This is because any updates this node receives from 
	 * other servers will always be in the PAST compared to the passed in 'since' value.
	 * 
	 * Having it set to 'UpdatedUTC' means any updates retrieved by this server from remote sources will 
	 * also be included in the data sent on to any listeners, thus propagating data forwards. We rely on
	 * the MasterNode field to prevent circular updates! 
	 * 
	 * @var type 
	 */
	private $filterDate = 'LastEditedUTC';

	/**
	 * @var DataService
	 */
	public $dataService;
	
	/**
	 * Do we create new members who own data in the remote system but don't exist in this system yet? 
	 * 
	 * @var boolean
	 */
	public $createMembers = true;
	
	/**
	 * Logger 
	 * @var klogger 
	 */
	private $log;
	
	public function __construct() {
		$this->log = new KLogger(SYNCROTRON_LOGDIR, KLogger::INFO);
	}

	/**
	 * The list of methods accessible as webservices. 
	 * 
	 * @return array
	 */
	public function webEnabledMethods() {
		return array(
			'listUpdates'			=> 'GET',
			'receiveChangeset'		=> 'POST',
		);
	}
	
	/**
	 * Set strict access
	 * 
	 * @param boolean $v 
	 */
	public function setStrictAccess($v) {
		$this->strictAccess = $v;
	}
	
	/**
	 * Set the filter date value
	 * @param string $d 
	 */
	public function setFilterDate($d) {
		if ($d == 'UpdatedUTC' || $d == 'LastEditedUTC') {
			$this->filterDate = $d;
		}
	}
	
	/**
	 * Push a content changeset to a syncro node
	 * 
	 * @param ContentChangeset $changeset
	 * @param RemoteSyncroNode $node
	 * 
	 */
	public function pushChangeset($changeset, $node) {
		$cs = $changeset->ChangesetItems();
		
		$update = array('changes' => array(), 'deletes' => array(), 'rels' => array());
		foreach ($cs as $changesetItem) {
			$record = null;
			// if it's a delete, we create a syncro delete, otherwise get the versioned item
			if ($changesetItem->ChangeType == 'Draft Deleted') {
				// find the syncrodelete record
				$record = DataList::create('SyncroDelete')->filter(array('ContentID' => $changesetItem->OtherContentID))->first();
				$del = array(
					'SYNCRODELETE'	=> 'DELETE',
					'ContentID'		=> $record->ContentID,
					'Type'			=> $record->Type,
					'MasterNode'	=> SiteConfig::current_site_config()->SystemID,
				);
				$update['deletes'][] = $del;
			} else {
				$record = Versioned::get_version($changesetItem->OtherClass, $changesetItem->OtherID, $changesetItem->ContentVersion);
				$syncd = $this->syncroObject($record);
				$syncd['ClassName'] = $record->ClassName;
				
				if (count($syncd['has_one']) || count($syncd['many_many'])) {
					$relInfo = new stdClass();
					$relInfo->SYNCROREL = true;
					$relInfo->Type = $record->ClassName;
					$relInfo->ContentID = $record->ContentID;
					$relInfo->MasterNode = SiteConfig::current_site_config()->SystemID;
					$relInfo->has_one = $syncd['has_one'];
					$relInfo->many_many = $syncd['many_many'];
					
					$update['rels'][] = $relInfo;
				}
				
				unset($syncd['has_one']); unset($syncd['many_many']);
				
				$update['changes'][] = $syncd;
				
			}
			
		}

		if ($update && $node) {
			
			$url = $node->NodeURL;
			$service = new RestfulService($url, -1);
			$params = array(
				'changes' => $update['changes'],
				'deletes' => $update['deletes'],
				'rels' => $update['rels'],
			);
			
			$headers = array(
				'X-Auth-Token: ' . $node->APIToken,
				'Content-Type: application/json',
			);
			$body = json_encode($params);

			$response = $service->request(self::PUSH_URL, 'POST', $body, $headers); //, $headers, $curlOptions);
			if ($response && $response->isError()) {
				$body = $response->getBody();
				if ($body) {
					$this->log->logError($body);
				}
				return array(false, "Deployment failed to {$url}: status {$response->getStatusCode()}");
			}

			$body = $response->getBody();
			if ($body) {
				return array(true, 'Deployment successful');
			}
		}
	}

	public function receiveChangeset($changes, $deletes, $rels) {
//		$changes = $changes ? Convert::json2obj($changes) : array();
//		$deletes = $deletes ? Convert::json2obj($deletes) : array();
//		$rels = $rels ? Convert::json2obj($rels) : array();
		
		if ($changes) {
			$all = array_merge($changes, $deletes, $rels);
			$obj = Convert::array2json($all);
			$obj = Convert::json2obj($obj);
			$this->processUpdateData($obj);
			return $all;
		}

		return '';
	}

	/**
	 * Lists all updated data objects since a particular date that the caller would be interested in
	 * 
	 * @param date $since 
	 * 
	 * @return DataObjectSet
	 *				The list of data objects that have been created/changed since 
	 * 
	 */
	public function listUpdates($since, $system) {
		if (!preg_match('/\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d/', $since)) {
			throw new Exception("Invalid date");
		}

		$since = Convert::raw2sql($since);
		$system = Convert::raw2sql($system);
		$typesToSync = ClassInfo::implementorsOf('Syncroable');
		
		if (count($typesToSync) == 1 && $typesToSync[0] == 'SyncroTestObject') {
			$typesToSync = array('Page', 'File');
		}

		// restrict to only those items we have been granted sync rights to
		$requiredPerm = $this->strictAccess ? 'Syncro' : 'View';

		// we only get objects edited SINCE a certain time
		// and that didn't originate in the remote server

		$filter = '"' . Convert::raw2sql($this->filterDate) .'" >= \'' . $since . '\' AND "MasterNode" <> \''. $system .'\'';

		$allUpdates = array();
		foreach ($typesToSync as $type) {
			if ($type == 'SyncroTestObject') {
				continue;
			}
			$objects = $this->dataService->getAll($type, $filter, '"LastEditedUTC" ASC', "", "", $requiredPerm);
			if ($objects && $objects->count()) {
				foreach ($objects as $object) {
					if ($object->hasMethod('forSyncro')) {
						$toSync = $object->forSyncro();
					} else {
						$toSync = $this->syncroObject($object);
					}

					// some that we force
					// note that UpdatedUTC is not sent as it is always a local node specific date
					$toSync['MasterNode']		= $object->MasterNode;
					$toSync['ContentID']		= $object->ContentID;
					$toSync['ClassName']		= $object->ClassName;
					$toSync['LastEditedUTC']	= $object->LastEditedUTC;
					$toSync['CreatedUTC']		= $object->CreatedUTC;
					$toSync['OriginalID']		= $object->OriginalID;
					
					$allUpdates[] = $toSync;
				}
			}
		}

		$deletes = DataObject::get('SyncroDelete', '"Deleted" >= \'' . $since . '\'');
		if ($deletes && $deletes->count()) {
			foreach ($deletes as $del) {
				$del = array(
					'SYNCRODELETE'	=> 'DELETE',
					'ContentID'		=> $del->ContentID,
					'Type'			=> $del->Type,
					'MasterNode'	=> SiteConfig::current_site_config()->SystemID,
				);
				$allUpdates[] = $del;
			}
		}

		return $allUpdates;
	}
	
	/**
	 * Get updates from the remote systems 
	 */
	public function getUpdates() {
		$config = SiteConfig::current_site_config();
		$systemId = $config->getSyncroIdentifier();
		$nodes = $this->dataService->getAll('RemoteSyncroNode');
		foreach ($nodes as $node) {
			$url = $node->NodeURL .'/' . self::SERVICE_URL;
			$lastSync = $node->LastSync ? $node->LastSync : '2012-01-01 00:00:00';

			$params = array(
				'token'			=> $node->APIToken,
				'since'			=> $lastSync,
				'system'		=> $systemId,
				'rand'			=> mt_rand(0, 22)
			);

			$svc = new RestfulService($url);
			$svc->setQueryString($params);
			$response = $svc->request();
			if ($response->isError()) {
				// log and proceed
				return; 
				throw new Exception("Request failed to $url");
			}

			
			$response = $response->getBody();

			if (is_string($response) && strlen($response)) {
				
				$data = json_decode($response);
				if ($data && is_array($data->response)) {
					$this->log->logInfo("Loading " . count($data->response) . " objects from " . $node->NodeURL);
					$this->processUpdateData($data->response, $node);
				}
			}
		}
	}

	/**
	 * Process an array of 
	 * 
	 * @param array $data
	 * @param RemoteSyncroNode $node
	 */
	protected function processUpdateData($data, $node = null) {
		$updateTime = null;
		foreach ($data as $item) {
			$this->processUpdatedObject($item);
			if (isset($item->Title) && isset($item->ID)) {
				$this->log->logInfo("Sync'd $item->ClassName #$item->ID '" . $item->Title . "'");
			} else {
				$this->log->logInfo("Sync'd $item->ContentID");
			}
			if (isset($item->LastEditedUTC) && isset($item->UpdatedUTC)) {
				if ($item->LastEditedUTC || $item->UpdatedUTC) {
					$timeInt = strtotime($item->LastEditedUTC);
					if ($timeInt > $updateTime) {
						$updateTime = $timeInt;
					}
					$timeInt = strtotime($item->UpdatedUTC);
					if ($timeInt > $updateTime) {
						$updateTime = $timeInt;
					}
				}
			}
		}

		if ($updateTime && $node) {
			$node->LastSync = gmdate('Y-m-d H:i:s');
			$node->write();
		}
	}
	
	/**
	 * process updates based on the provided array of data from the remote system
	 * 
	 * @param type $json 
	 */
	public function processUpdatedObject($object) {
		if (isset($object->ClassName) && isset($object->ContentID) && isset($object->MasterNode)) {
			// explicitly use the unchecked call here because we don't want to create a new item if it actually turns 
			// out that it exists and we just don't have write access to it
			$existing = DataObject::get_one($object->ClassName, '"ContentID" = \'' . Convert::raw2sql($object->ContentID).'\' AND "MasterNode" = \'' . Convert::raw2sql($object->MasterNode) .'\'');
			if ($existing && $existing->exists()) {
				if ($existing->hasMethod('checkPerm') && !$existing->checkPerm('Write')) {
					// should we throw an error here? 
					return;
				} else if (!$existing->canEdit()) {
					return;
				}
			} else {
				$cls = $object->ClassName;
				$existing = $cls::create();
				if (isset($object->Title)) {
					$existing->Title = $object->Title;
				}
				// need to initially write, because we're going to totally change the values afterwards
				$existing->write();

				$existing->ContentID = $object->ContentID;
				$existing->MasterNode = $object->MasterNode;
				$existing->OriginalID = $object->OriginalID;
				$existing->CreatedUTC = $object->CreatedUTC;

				// need to write again because further syncro will need to lookup these values if there are circular
				// refereces. 
				$existing->write();
			}

			$existing->LastEditedUTC = $object->LastEditedUTC;
			
			if ($existing instanceof Syncroable) {
				$existing->fromSyncro($object);
			} else {
				$this->unsyncroObject($object, $existing);
			}
			
			
			$existing->write();
		} else if (isset($object->SYNCRODELETE)) {
			// find and delete relevant item
			$existing = DataObject::get_one($object->Type, '"ContentID" = \'' . Convert::raw2sql($object->ContentID).'\' AND "MasterNode" = \'' . Convert::raw2sql($object->MasterNode) .'\'');
			if ($existing) {
				$existing->delete();
			}
		} else if (isset($object->SYNCROREL)) {
			$existing = DataObject::get_one($object->Type, '"ContentID" = \'' . Convert::raw2sql($object->ContentID).'\' AND "MasterNode" = \'' . Convert::raw2sql($object->MasterNode) .'\'');
			if ($existing) {
				$this->unsyncroRelationship($existing, $object);
			}
		}
	}

	/**
	 * Converts an object into a serialised form used for sending over the wire
	 * 
	 * By default, takes the properties defined directly on it and any has_ones and 
	 * converts to a format readable by the unsyncroObject method
	 * 
	 * @param DataObject $item 
	 * @param array $props 
	 *				the list of properties to convert
	 * @param array $hasOne
	 *				the list of has_one relationship names to convert
	 * @param array $manies
	 *				the list of many_many relationship items to convert
	 */
	public function syncroObject(DataObject $item, $props = null, $hasOne = null, $manies = null) {
		
		$properties = array();
		$ignore = array('Created', 'ClassName', 'ID', 'Version');
		
		if (!$props) {
			$props = Config::inst()->get(get_class($item), 'db');
			foreach ($ignore as $unset) {
				unset($props[$unset]);
			}
			$props = array_keys($props);
		}

		foreach ($props as $name) {
			// check for multivalue fields explicitly
			$obj = $item->dbObject($name);
			if ($obj instanceof MultiValueField) {
				$v = $obj->getValues();
				if (is_array($v)) {
					$properties[$name] = $v;
				}
			} else {
				$properties[$name] = $item->$name;
			}
		}
		
		// store owner as email address
		if ($item->OwnerID) {
			$properties['Restrictable_OwnerEmail'] = $item->Owner()->Email;
		}

		$has_ones = array();
		if (!$hasOne) {
			$hasOne = Config::inst()->get(get_class($item), 'has_one');
		}
		foreach ($hasOne as $name => $type) {
			// get the object
			$object = $item->getComponent($name);
			if ($object && $object->exists() && $object->hasExtension('SyncroableExtension')) {
				$has_ones[$name] = array('ContentID' => $object->ContentID, 'Type' => $type);
			}
		}

		$properties['has_one'] = $has_ones;
		
		$many_many = array();
		if (!$manies) {
			$manies = Config::inst()->get(get_class($item), 'many_many');
		}
		if ($manies) {
			foreach ($manies as $name => $type) {
				$rel = $item->getManyManyComponents($name);
				$many_many[$name] = array();
				foreach ($rel as $object) {
					if ($object && $object->exists() && $object->hasExtension('SyncroableExtension')) {
						$many_many[$name][] = array('ContentID' => $object->ContentID, 'Type' => $type);
					}
				}
			}	
		}

		$properties['many_many'] = $many_many;
		
		$item->extend('updateSyncroData', $properties);

		return $properties;
	}
	
	/**
	 * Set properties on an item based on a syncro serialised object
	 * 
	 * @param stdClass $object
	 *				the data being unserialised
	 * @param $item $into 
	 *				the item to set values on
	 */
	public function unsyncroObject($object, $item) {
		foreach ($object as $prop => $val) {
			if ($prop == 'has_one' || $prop == 'many_many') {
				continue;
			}
			
			if ($val instanceof stdClass) {
				$obj = Convert::raw2json($val);
				$val = Convert::json2array($obj);
			}
			$item->$prop = $val;
		}

		// set the owner of the object if it exists
		if (isset($object->Restrictable_OwnerEmail)) {
			$owner = DataObject::get_one('Member', '"Email" = \'' . Convert::raw2sql($object->Restrictable_OwnerEmail) .'\'');
			if ($owner && $owner->exists()) {
				$item->OwnerID = $owner->ID;
			} else if ($this->createMembers) {
				$member = Member::create();
				$member->Email = $object->Restrictable_OwnerEmail;
				$member->write();
				$item->OwnerID = $member->ID;
			}
		}
		
		$item->extend('onSyncro', $object);
	}
	
	/**
	 * Un syncronise relationship data for a given object
	 * 
	 * @param type $item
	 * @param type $object
	 */
	public function unsyncroRelationship($item, $object) {
		
		if (isset($object->has_one)) {
			foreach ($object->has_one as $name => $contentProp) {
				$existing = DataObject::get_one($contentProp->Type, '"ContentID" = \'' . Convert::raw2sql($contentProp->ContentID) .'\'');
				if ($existing && $existing->exists()) {
					$propName = $name.'ID';
					$item->$propName = $existing->ID;
				}
			}
			$item->write();
		}

		if (isset($object->many_many)) {
			foreach ($object->many_many as $name => $relItems) {
				$item->$name()->removeAll();
				foreach ($relItems as $type => $contentProp) {
					$existing = DataObject::get_one($contentProp->Type, '"ContentID" = \'' . Convert::raw2sql($contentProp->ContentID) .'\'');
					if ($existing && $existing->exists()) {
						$item->$name()->add($existing);
					}
				}
			}
		}
	}
}
