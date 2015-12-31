<?php

/**
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class RemoteNodeAdmin extends ModelAdmin
{
    
    private static $managed_models = array(
        'RemoteSyncroNode',
        'SyncroData',
    );
    
    private static $menu_title = 'Syncro Nodes';
    private static $url_segment = 'syncrotron';
}
