<?php

class apache2_userdir_plugin {

	var $plugin_name = 'apache2_userdir_plugin';
	var $class_name = 'apache2_userdir_plugin';


	/*
	 * private variables
	 */
	var $action = '';


	/*
	 * some nice functions which do things we would have to repeat
	 * if we couldn't call them. they do not load themselve but are
	 * called by other functions like the update() or delete() function
	 */

	/*
	 * the vhost handles the whole creating, updating and deleting
	 * of the nginx vhost files
	 */
	function vhost($action, $data, $tpl = '') {
		global $app;

		/*
		 * we create an empty array we can fill with data
		 * and shorten some $vars
		 */
		$userdir_vhosts = '/etc/apache2/userdirs-available';
		$userdir_vhosts_enabled = '/etc/apache2/userdirs-enabled';

		$data['vhost'] = array();


		/*
		 * Create the vhost paths
		 */
		$data['vhost']['file_old'] = escapeshellcmd($userdir_vhosts .'/'. $data['old']['domain'] .'.vhost');
		$data['vhost']['link_old'] = escapeshellcmd($userdir_vhosts_enabled .'/'. $data['old']['domain'] .'.vhost');
		$data['vhost']['file_new'] = escapeshellcmd($userdir_vhosts .'/'. $data['new']['domain'] .'.vhost');
		$data['vhost']['link_new'] = escapeshellcmd($userdir_vhosts_enabled .'/'. $data['new']['domain'] .'.vhost');


		/*
		 * check if the vhost file exists in "/etc/nginx/sites-available"
		 * (or the path you defined) and set to '1' if it does
		 */
		if (is_file($data['vhost']['file_old'])) $data['vhost']['file_old_check'] = 1;
		if (is_file($data['vhost']['file_new'])) $data['vhost']['file_new_check'] = 1;


		/*
		 * check if the vhost file is linked in "/etc/nginx/sites-enabled"
		 * (or the path you defined) and set to '1' if it does
		 */
		if (is_link($data['vhost']['link_old'])) $data['vhost']['link_old_check'] = 1;
		if (is_link($data['vhost']['link_new'])) $data['vhost']['link_new_check'] = 1;


		/*
		 * require the vhost class and run the function
		 */
		require_once 'classes/vhost.php';
		$vhost = new vhost;

		return $data['vhost'] = $vhost->$action($data, $app, $tpl);

	}


	/*
	 * The onInstall() function is called during ISPConfig installation.
	 * Based on your input/selection, it decides if a symlink for the plugin
	 * has to be created in /usr/local/ispconfig/server/plugins-enabled
	 *
	 */
	function onInstall() {
		global $conf;

		/*
		 * The check ifself
		 */
		if ($conf['services']['apache2_userdir'] == true) {
			return true;
		} else {
			return false;
		}

	}


	/*
	 * The onLoad() function is loaded as soon as our plugin get's loaded
	 * We use it, to register the plugin for some site related events
	 */
	function onLoad() {
		global $app;

		/*
		 * Register for those events, the plugin needs to do something
		 * this fills the $event_name var from the functions
		 */

		$app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'insert');
		$app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'update');
		$app->plugins->registerEvent('web_domain_delete', $this->plugin_name, 'delete');

		$app->plugins->registerEvent('client_delete', $this->plugin_name, 'client_delete');

	}


	/*
	 * The insert function is called every time a new site is created
	 */
	function insert($event_name, $data) {
		global $app, $conf;

		/*
		 * Set $action to 'insert' so the plugin knows it should run
		 * code which is defined for the insert function
		 */
		$this->action = 'insert';


		/*
		 * We make it simple and only run the update() function
		 */
		$this->update($event_name, $data);

	}


	/*
	 * The update function is called every time a site gets updated from within ISPConfig
	 * (only on the events we registered above) as well as on creating a new site
	 * (see insert function)
	 */
	function update($event_name, $data) {
		global $app, $conf;

		/*
		 * If $action is not 'insert', let's set it to update
		 */
		if ($this->action != 'insert') $this->action = 'update';


		/*
		 * load the server configuration options
		 */
		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');


		/*
		 * We load the global template engine
		 */
		$app->load('tpl');


		/*
		 * Create a new template and choose which master template to take
		 * the file is located within /usr/local/ispconfig/server/conf/
		 */
		$tpl = new tpl();
		$tpl->newTemplate('apache2_userdir.conf.master');


		/*
		 * Write some values from the array to single variables
		 */
		$vhost_data = $data['new'];
		$client_id = $data['new']['system_group'];
		$web_id = $data['new']['system_user'];


		/*
		 * To have a better overview we split our update function into several parts,
		 * for sites, aliases and subdomains
		 * -> vhost
		 */
		if ($data['new']['type'] == 'vhost') {

			/*
			 * We have collected all data in the $vhost_data array
			 * so we can pass it to the template engine
			 */
			$tpl->setVar($vhost_data);


			/*
			 * if this is an 'insert', we have to create the vhost file
			 */
			if ($this->action == 'insert') {

				$this->vhost('insert', $data, $tpl->grab());

			}


			/*
			 * if this is an 'update', we have to update the vhost file
			 */
			if ($this->action == 'update') {

				$vhost_backup = $this->vhost('update', $data, $tpl->grab());

			}

		}


		/*
		 * restart the apache2 webserver to apply changes
		 */
		if($web_config['check_apache_config'] == 'y') {

			/*
			 *  Test if apache starts with the new configuration file
			 */
			$apache_online_status_before_restart = $this->_checkTcp('localhost', 80);
			$app->log('Apache status is: '. $apache_online_status_before_restart, LOGLEVEL_DEBUG);

			$app->services->restartService('httpd', 'restart');

			/*
			 * wait a few seconds, before we test the apache status again
			 */
			sleep(2);

			/*
			 * Check if apache restarted successfully if it was online before
			 */
			$apache_online_status_after_restart = $this->_checkTcp('localhost', 80);
			$app->log('Apache online status after restart is: '.$apache_online_status_after_restart, LOGLEVEL_DEBUG);

			if($apache_online_status_before_restart && !$apache_online_status_after_restart) {

				$app->log('Apache did not restart after the configuration change for website '. $data['new']['domain'] .' Reverting the configuration. Saved non-working config as '. $vhost_file .'.err',LOGLEVEL_WARN);
				if (isset($vhost_backup)) copy($vhost_backup['file_new'], $vhost_backup['file_new'] .'.err');

				if(is_file($vhost_backup['file_new'] .'~')) {

					/*
					 * Copy back the last backup file
					 */
					copy($vhost_backup['file_new'] .'~', $vhost_backup['file_new']);

				} else {

					/*
					 * There is no backup file, so we create a empty vhost file with a warning message inside
					 */
					file_put_contents($vhost_backup['file_new'], "# Apache did not start after modifying this vhost file.\n# Please check file $vhost_file.err for syntax errors.");

				}

				$app->services->restartService('httpd', 'restart');
			}

		} else {

			/*
			 * We do not check the apache config after changes (is faster)
			 */
			if($apache_chrooted) {

				$app->services->restartServiceDelayed('httpd', 'restart');

			} else {

				/*
				 * request a httpd reload when all records have been processed
				 */
				$app->services->restartServiceDelayed('httpd', 'reload');

			}
		}


		/*
		 * everything went hopefully well, so we can now
		 * delete the vhosts backup
		 */
		if (isset($vhost_backup)) unlink($vhost_backup['file_new'] .'~');
		unset($vhost_backup);


		/*
		 * Unset 'action' to clean it for next processed vhost
		 */
		$this->action = '';

	}


	/*
	 * The delete() function is called every time, a site get's removed
	 */
	function delete($event_name, $data) {
		global $app, $conf;

		/*
		 * We just have to delete the vhost file and link
		 * if we deleted a vhost site
		 */
		if ($data['old']['type'] == 'vhost') $this->vhost('delete', $data);

	}


	/*
	 * The client_delete() function is called every time, a client gets deleted
	 */
	function client_delete($event_name, $data) {
		global $app, $conf;

		/*
		 * load the server configuration options
		 */
		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');


		/*
		 * we run a query to get all domains (not alias- subdomains) which are linked
		 * with the client we want to delete
		 */
		$client_id = intval($data['old']['client_id']);

		$client_vhosts = array();

		$client_vhosts = $app->dbmaster->queryAllRecords('SELECT domain FROM web_domain WHERE sys_userid = '. $client_id .' AND parent_domain_id = 0');

		if (count($client_vhosts) > 0) {

			/*
			 * for every single vhost file the client has,
			 * call the delete function to delete the vhost file and link
			 */
			foreach($client_vhosts as $vhost) {

				$data['old']['domain'] = $vhost['domain'];
				$this->vhost('delete', $data);

				$app->log('Removing vhost file: '. $data['old']['domain'], LOGLEVEL_DEBUG);

			}

		}

	}


	/*
	 * Wrapper for exec function for easier debugging
	 */
	private function _exec($command) {
		global $app;

		$app->log('exec: '. $command, LOGLEVEL_DEBUG);
		exec($command);

	}

	/*
	 * private function to check if TCP is up
	 */
	private function _checkTcp ($host, $port) {

		$fp = @fsockopen ($host, $port, $errno, $errstr, 2);

		if ($fp) {

			fclose($fp);
			return true;

		} else {

			return false;

		}
	}

}