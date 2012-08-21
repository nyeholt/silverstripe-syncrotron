<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SyncrotronJob extends AbstractQueuedJob {
	
	const SYNC_TIME = '300';
	
	public function __construct() {
		$this->totalSteps = 1;
	}
	
	public function getTitle() {
		return 'Syncronise data from remote nodes';
	}
	
	public function process() {
		singleton('SyncrotronService')->getUpdates();
		singleton('QueuedJobService')->queueJob(new SyncrotronJob(), date('Y-m-d H:i:s', time() + self::SYNC_TIME));
		
		$this->currentStep = $this->totalSteps;
		$this->isComplete = true;
		
		
	}
}
