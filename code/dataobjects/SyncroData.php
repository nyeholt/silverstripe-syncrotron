<?php

/**
 * Object that represents uploaded syncro data.
 *
 * @author marcus
 */
class SyncroData extends DataObject
{
    private static $db = array(
        'Title'            => 'Varchar(64)',
        'UploadToken'    => 'Varchar(64)',
        'SuppliedToken'    => 'Varchar(64)',
        'Content'        => 'Text',
        
    );
    
    /**
     * @var SyncrotronService 
     */
    public $syncrotronService;

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        
        
        if ($this->Content && $this->UploadToken && $this->UploadToken == $this->SuppliedToken) {
            $this->SuppliedToken = '';
            $this->UploadToken = '';
            
            $data = json_decode($this->Content);
            if ($data && is_array($data->response)) {
                $this->syncrotronService->processUpdateData($data->response);
            }
        }
        
        
        if (strlen($this->UploadToken) === 0) {
            $this->UploadToken = mt_rand(100000, 999999);
        }
    }
    
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('UploadToken');
        if ($this->UploadToken) {
            $fields->addFieldToTab('Root.Main', ReadonlyField::create('TokenValue', 'Enter this token to process sync data', $this->UploadToken));
        } else {
            $fields->removeByName('SuppliedToken');
            $fields->addFieldToTab('Root.Main', ReadonlyField::create('TokenValue', 'You can process the data after saving and entering a process token'));
        }
        
        return $fields;
    }
}
