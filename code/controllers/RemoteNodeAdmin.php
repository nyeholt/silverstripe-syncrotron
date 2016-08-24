<?php

/**
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class RemoteNodeAdmin extends ModelAdmin {
	
	private static $managed_models = array(
		'RemoteSyncroNode',
		'SyncroData',
	);
	
	private static $menu_title = 'Syncro Nodes';
	private static $url_segment = 'syncrotron';
    
    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);
        
        $grid = $form->Fields()->dataFieldByName('RemoteSyncroNode');
        if ($grid) {
            $detailForm = $grid->getConfig()->getComponentByType('GridFieldDetailForm');
            if ($detailForm) {
                $detailForm->setItemRequestClass('RemoteNodeDetailForm_ItemRequest');
            }
        }
        
        return $form;
    }
    
}

class RemoteNodeDetailForm_ItemRequest extends GridFieldDetailForm_ItemRequest {
    
    private static $allowed_actions = array('syncnow', 'ItemEditForm');
    
    public function ItemEditForm() {
        $form = parent::ItemEditForm();
        $form->Actions()->push(FormAction::create('syncnow', 'Sync'));
        return $form;
    }
    
    public function syncnow($data, $form) {
        $record = $this->getRecord();
        if ($record) {
            singleton('SyncrotronService')->getUpdates($record->ID);
        }
        
        $form->sessionMessage('Sync complete', 'good');
    }
}