<?php

Object::add_extension('SiteConfig', 'SyncroSiteConfig');

include_once dirname(__FILE__).'/code/thirdparty/klogger/src/KLogger.php';

if (!defined('SYNCROTRON_LOGDIR')) {
	define('SYNCROTRON_LOGDIR', dirname(__FILE__).'/sync-logs');
}
if (!file_exists(SYNCROTRON_LOGDIR)) {
	mkdir(SYNCROTRON_LOGDIR);
	@chmod(SYNCROTRON_LOGDIR, 0775);
}