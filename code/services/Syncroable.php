<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
interface Syncroable {
	
	/**
	 * Return a map (array) of properties that will be sent across the wire when syncronising 
	 */
	public function forSyncro();
	
	/**
	 * Take in an array of properties and update when syncing from a remote source
	 */
	public function fromSyncro($properties);
}
