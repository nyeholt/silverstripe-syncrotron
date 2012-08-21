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
					$toSync = $object->forSyncro();
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
					$updateTime = null;
					foreach ($data->response as $item) {
						$this->processUpdatedObject($item);
						if ($item->Title) {
							$this->log->logInfo("Sync'd $item->ClassName #$item->ID '" . $item->Title . "'");
						} else {
							$this->log->logInfo("Sync'd $item->ClassName #$item->ID");
						}
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
					
					if ($updateTime) {
						$node->LastSync = gmdate('Y-m-d H:i:s');
						$node->write();
					}
				}
			}
		}
	}
	
	/**
	 * process updates based on the provided array of data from the remote system
	 * 
	 * @param type $json 
	 */
	public function processUpdatedObject($object) {
		if ($object->ClassName && $object->ContentID && $object->MasterNode) {
			// explicitly use the unchecked call here because we don't want to create a new item if it actually turns 
			// out that it exists and we just don't have write access to it
			$existing = DataObject::get_one($object->ClassName, '"ContentID" = \'' . Convert::raw2sql($object->ContentID).'\' AND "MasterNode" = \'' . Convert::raw2sql($object->MasterNode) .'\'');
			if ($existing && $existing->exists()) {
				if (!$existing->checkPerm('Write')) {
					// should we throw an error here? 
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
			$existing->fromSyncro($object);
			$existing->write();
		} else if ($object->SYNCRODELETE) {
			// find and delete relevant item
			$existing = DataObject::get_one($object->Type, '"ContentID" = \'' . Convert::raw2sql($object->ContentID).'\' AND "MasterNode" = \'' . Convert::raw2sql($object->MasterNode) .'\'');
			if ($existing) {
				$existing->delete();
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
	 */
	public function syncroObject(DataObject $item, $props = null, $hasOne = null) {
		
		$properties = array();
		
		if (!$props) {
			$props = array_keys($item::$db);
		}

		foreach ($props as $name) {
			$properties[$name] = $item->$name;
		}
		
		// store owner as email address
		if ($item->OwnerID) {
			$properties['Restrictable_OwnerEmail'] = $item->Owner()->Email;
		}

		$has_ones = array();
		if (!$hasOne) {
			$hasOne = $item::$has_one;
		}
		foreach ($hasOne as $name => $type) {
			// get the object
			$object = $item->getComponent($name);
			if ($object && $object->exists() && $object instanceof Syncroable) {
				$has_ones[$name] = array('ContentID' => $object->ContentID, 'Type' => $type);
			}
		}

		$properties['has_one'] = $has_ones;
		
		return $properties;
	}
	
	/**
	 * Set properties on an item based on a syncro serialised object
	 * 
	 * @param stdClass $object
	 * @param DataObject $into 
	 */
	public function unsyncroObject($object, $item) {
		
		foreach ($object as $prop => $val) {
			if ($prop == 'has_one' || $prop == 'many_many') {
				continue;
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

		if (isset($object->has_one)) {
			foreach ($object->has_one as $name => $contentProp) {
				$object = DataObject::get_one($contentProp->Type, '"ContentID" = \'' . Convert::raw2sql($contentProp->ContentID) .'\'');
				if ($object && $object->exists()) {
					$propName = $name.'ID';
					$item->$propName = $object->ID;
				}
			}
		}
	}
}
