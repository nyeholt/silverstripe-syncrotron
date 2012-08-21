<?php

/**
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class RemoteNodeAdmin extends ModelAdmin {
	
	public static $managed_models = array(
		'RemoteSyncroNode',
	);
	
	public static $menu_title = 'Syncro Nodes';
	public static $url_segment = 'syncrotron';
	
}
