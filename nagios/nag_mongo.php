#!/usr/bin/php
<?php

if (!defined('STDIN') || php_sapi_name() != 'cli' || !empty($_SERVER['REMOTE_ADDR'])) {
	die('only usable via CLI');
}

$debugmode = false;

if (isset($argv[1]) && trim($argv[1]) == '-d') {
	$debugmode = true;
}

/*


	mongo record example
	
	Array (
		'f' => 1347567137,						// freshness (when last updated)
		'h' => 'whatever.emerson.edu',			// host name
		's' => 0,								// current state (see below for what they mean)
		'p' => false,							// problem? (not catastrophic)
		'b' => false,							// broken? (see list of "breaking" conditions below)
		'a' => false,							// problem/broken acknowledged?
		'sv' => Array (							// array of services
			[0] => Array (
				'n' => 'check_ping',			// service name
				's' => 0,						// service current state (see below for what they mean)
				'm' => "CRITICAL: ping timeout" // service error message, if there is one
			), 
			[1] => ...							// more services
		)
	)


*/

/*
	current_state states...
		0 = fine
		1 = warning
		2 = error
		3 = unknown
*/

$path_to_nagios_statusdat = '/usr/local/nagios/var/status.dat';
$path_to_nagios_objectcache = '/usr/local/nagios/var/objects.cache';

// connect to mongo
try {
	$m = new Mongo();
	$ndb = $m->nagios;
} catch (MongoCursorException $e) {
	echo 'error message: '.$e->getMessage()."\n";
    echo 'error code: '.$e->getCode()."\n";
    die();
}

// these will act as one hostname
// 'h' key will be the hostname you can look up via the API
// 'm' key array will be the hostnames on Nagios that make up this one single host
$groups_as_host = array(
	//array('h' => 'example-group', 'm' => array('whatever-1.whatever.com', 'whatever-2.whatever.com', 'whatever-3.whatever.com')),
);

// if there's a bad state for any of these, it sets the host as BROKEN = true
$important_services = array(
	// the basics
	'check_ping', 
	'check_http',
	'check_http_8080',
	'check_https',
	// dns/auth server checks
	'check_dns', 
	'check_http_dns', 
	'check_dns_google', 
	'check_dns_www', 
	'check_radius', 
	// mail server checks
	'check_smtp',
	// "most important shit on the server" checks
	'check_jabber',
	'check_ircd',
);

$script_time_start = microtime(true);

// load stuff
$statusdat = file_get_contents($path_to_nagios_statusdat);
$object_cache = file_get_contents($path_to_nagios_objectcache);

$script_time_file = microtime(true);
$script_time_total = $script_time_file - $script_time_start;
$script_time_total_ms = round($script_time_total * 1000);
if ($debugmode) echo "\n".'after file open, '.$script_time_total_ms.'ms execution time'."\n";

// get hosts

if (preg_match_all('/hoststatus {([^}]+)}/ims', $statusdat, $host_match)) {
	//echo '<pre>'.print_r($host_match[1], true).'</pre>';
	$hosts = array();
	foreach ($host_match[1] as $host) {
		$host_statuses = explode("\n", trim($host));
		$host_attrs = array();
		foreach ($host_statuses as $status) {
			$status_pieces = explode('=', trim($status));
			$host_attrs[$status_pieces[0]] = $status_pieces[1];
			if ($status_pieces[0] == 'last_check') {
				$host_attrs['last_check_ts'] = date('m-d-Y G:i:s', $status_pieces[1]);
			}
		}
		$hosts[$host_attrs['host_name']] = $host_attrs;
		$hosts[$host_attrs['host_name']]['services'] = array();
	}
	unset($host, $status);
} else {
	if ($debugmode) { die('no host statuses'."\n"); }
}

$script_time_hosts = microtime(true);
$script_time_total = $script_time_hosts - $script_time_start;
$script_time_total_ms = round($script_time_total * 1000);
if ($debugmode) echo "\n".'got hosts, '.$script_time_total_ms.'ms execution time'."\n";

// get services

if (preg_match_all('/servicestatus {([^}]+)}/ims', $statusdat, $service_match)) {
	//echo '<pre>'.print_r($host_match[1], true).'</pre>';
	$services = array();
	foreach ($service_match[1] as $service) {
		$service_statuses = explode("\n", trim($service));
		$service_attrs = array();
		foreach ($service_statuses as $status) {
			$status_pieces = explode('=', trim($status));
			$service_attrs[$status_pieces[0]] = $status_pieces[1];
			if ($status_pieces[0] == 'last_check') {
				$service_attrs['last_check_ts'] = date('m-d-Y G:i:s', $status_pieces[1]);
			}
		}
		$services[] = $service_attrs;
		$hosts[$service_attrs['host_name']]['services'][$service_attrs['service_description']] = $service_attrs;
	}
	unset($service, $status);
} else {
	die('no service statuses'."\n");
}

$script_time_services = microtime(true);
$script_time_total = $script_time_services - $script_time_start;
$script_time_total_ms = round($script_time_total * 1000);
if ($debugmode) echo "\n".'got services, '.$script_time_total_ms.'ms execution time'."\n";

// get hostgroups

$hostgroups = array();

if (preg_match_all('/hostgroup {([^}]+)}/ims', $object_cache, $hostgroup_match)) {
	//echo '<pre>'.print_r($host_match[1], true).'</pre>';
	$hostgroups_so_far = array();
	$last_hostgroup = '';
	foreach ($hostgroup_match[1] as $hostgroup) {
		//echo $hostgroup."\n";
		$hostgroup_lines = explode("\n", $hostgroup);
		foreach ($hostgroup_lines as $hg_line) {
			if (trim($hg_line) == '') {
				continue;
			}
			//echo trim($hg_line)."\n";
			preg_match('/^(\w+)\s+(.+)$/', trim($hg_line), $hg_matches);
			$hg_line_key = trim($hg_matches[1]);
			$hg_line_val = trim($hg_matches[2]);
			if ($hg_line_key == 'hostgroup_name' && !in_array($hg_line_val, $hostgroups_so_far)) {
				if (substr($hg_line_val, 0, 6) != 'stats-') {
					continue 2;
				}
				$hostgroups[$hg_line_val] = array();
				$hostgroups_so_far[] = $hg_line_val;
				$last_hostgroup = $hg_line_val;
			} else {
				if ($hg_line_key == 'alias') {
					$hostgroups[$last_hostgroup]['alias'] = $hg_line_val;
				} else if ($hg_line_key == 'members') {
					$new_members = array();
					$members = explode(',', $hg_line_val);
					foreach ($members as $member_host) {
						$new_members[] = trim($member_host);
					}
					$hostgroups[$last_hostgroup]['members'] = $new_members;
				}
			}
			//echo 'key: "'.$hg_line_key.'", val: "'.$hg_line_val.'"'."\n";
		}
	}
} else {
	die('no hostgroups'."\n");
}

if ($debugmode) {
	print_r($hosts);
	print_r($hostgroups);
}

if ($debugmode) echo "\n";

if ($debugmode) echo count($hosts).' hosts total'."\n";

if ($debugmode) echo "\n";

// now go through and update mongodb with hosts
foreach ($hosts as $hostname => $host) {
	
	$updated_record = array();
	
	$host_name = strtolower(trim($hostname));
	$updated_record['h'] = $host_name;
	$host_current_state = (int) $host['current_state'] * 1;
	$host_is_broken = false;
	$broken_services = array();
	$host_has_problem = false;
	$host_problem_acknowledged = false;
	//echo 'hostname: '.$host_name."\n";
	//echo 'current state: '.$host_current_state."\n";
	$updated_record['s'] = $host_current_state;
	
	$service_records = array();
	foreach ($host['services'] as $service_key => $service) {
		$service_name = strtolower(trim($service['service_description']));
		$service_current_state = (int) $service['current_state'] * 1;
		$service_message = '';
		if ($service_current_state >= 1 && $service['current_attempt'] * 1 == $service['max_attempts'] * 1) {
			$host_has_problem = true;
			$service_message = $service['plugin_output'];
			if ($service['problem_has_been_acknowledged'] * 1 >= 1) {
				$host_problem_acknowledged = true;
			}
		}
		if (in_array($service_name, $important_services) && $service_current_state >= 2 && $service['current_attempt'] * 1 == $service['max_attempts'] * 1) {
			$host_is_broken = true;
			$service_failure_time = (int) $service['last_hard_state_change'] * 1;
			$broken_services[] = array( 'n' => $service_name, 'ts' => $service_failure_time );
			$service_message = $service['plugin_output'];
			if ($service['problem_has_been_acknowledged'] * 1 >= 1) {
				$host_problem_acknowledged = true;
			}
		}
		$tmp_service = array('n' => $service_name, 's' => $service_current_state);
		if (trim($service_message) != '') {
			$tmp_service['m'] = $service_message;
		}
		$service_records[] = $tmp_service;
	}
	
	$updated_record['sv'] = $service_records;
	
	$updated_record['b'] = $host_is_broken;
	$updated_record['p'] = $host_has_problem;
	$updated_record['a'] = $host_problem_acknowledged;
	
	$updated_record['f'] = (int) time();
	
	if ($debugmode) { print_r($updated_record); }
	
	try {
		$update = $ndb->hosts->update(array('h' => $host_name), array('$set' => $updated_record), array('safe' => true, 'upsert' => true));
	} catch(MongoCursorException $e) {
		die('Error with MongoDB: '.$e."\n");
	}
	
	// ok now do downtime instances
	
	if ($host_is_broken == true) { // is this host broken? if so, add a downtime instance, if one does not exist
		$check_for_downtime_instance = $ndb->downtimes->findOne( array( 'h' => $host_name, 'tse' => 0 ) );
		if (!isset($check_for_downtime_instance)) { // there isn't, so make one
			$new_downtime_instance = array();
			$new_downtime_instance['h'] = $host_name;
			$new_downtime_instance['tss'] = time();
			$new_downtime_instance['tse'] = 0;
			try {
				$insert = $ndb->downtimes->insert($new_downtime_instance, array('safe' => true));
			} catch(MongoCursorException $e) {
				die('Error with MongoDB: '.$e."\n");
			}
		}
	} else { // not broken -- check for downtime instances. if any, delete them.
		$get_possible_downtimes = $ndb->downtimes->find( array( 'h' => $host_name, 'tse' => 0 ) );
		if ($get_possible_downtimes->count() > 0) {
			foreach ($get_possible_downtimes as $possible_downtime) {
				$downtime_duration = time() - $possible_downtime['tss'];
				$update_downtime_instance = $ndb->downtimes->update( array( '_id' => $possible_downtime['_id'] ), array( '$set' => array( 'tse' => time(), 'hl' => $downtime_duration ) ), array('safe' => true) );
			}
		}
	}
	
	// end downtime instance checking
	
}

// now go through and update mongodb with host groups
if (count($hostgroups) > 0) {
	foreach ($hostgroups as $groupname => $group) {
		if (!isset($group['members']) || count($group['members']) == 0) {
			continue;
		}
		$updated_hostgroup = array();
		$updated_hostgroup['n'] = $groupname;
		$updated_hostgroup['a'] = $group['alias'];
		$updated_hostgroup['m'] = $group['members'];
		$updated_hostgroup['f'] = time();
		try {
			$update = $ndb->groups->update(array('n' => $groupname), array('$set' => $updated_hostgroup), array('safe' => true, 'upsert' => true));
		} catch(MongoCursorException $e) {
			die('Error with MongoDB: '.$e."\n");
		}
	}
}

// now go through $host_groups, check the members, and if any have problems, mark the whole set as having problem...
foreach ($groups_as_host as $group) {

	$new_host = array();
	
	$new_host['a'] = false;
	$new_host['b'] = false;
	$new_host['p'] = false;
	$new_host['s'] = 0;
	$new_host['f'] = time();
	$new_host['h'] = $group['h'];
	$new_host['sv'] = array();
	
	foreach ($group['m'] as $group_member) {
		if (isset($hosts[$group_member])) {
			$this_member = $hosts[$group_member];
			$new_service = array();
			$new_service['n'] = $group_member;
			$new_service['s'] = (int) $this_member['current_state'] * 1;
			foreach ($this_member['services'] as $service_key => $service) {
				$service_current_state = (int) $service['current_state'] * 1;
				if ($service_current_state >= 1 && $service['current_attempt'] * 1 == $service['max_attempts'] * 1) {
					$new_host['p'] = true;
					if ($service['problem_has_been_acknowledged'] * 1 >= 1) {
						$new_host['a'] = true;
					}
				}
				if (in_array($service_name, $important_services) && $service_current_state >= 2 && $service['current_attempt'] * 1 == $service['max_attempts'] * 1) {
					$new_host['b'] = true;
					if ($service['problem_has_been_acknowledged'] * 1 >= 1) {
						$new_host['a'] = true;
					}
				}
			}
			$new_host['sv'][] = $new_service;
		}
	}
	
	try {
		$update = $ndb->hosts->update(array('h' => $new_host['h']), array('$set' => $new_host), array('safe' => true, 'upsert' => true));
	} catch(MongoCursorException $e) {
		die('Error with MongoDB: '.$e."\n");
	}
	
	if ($new_host['b'] == true) { // is this host broken? if so, add a downtime instance, if one does not exist
		$check_for_downtime_instance = $ndb->downtimes->findOne( array( 'h' => $new_host['h'], 'tse' => 0 ) );
		if (!isset($check_for_downtime_instance)) { // there isn't, so make one
			$new_downtime_instance = array();
			$new_downtime_instance['h'] = $new_host['h'];
			$new_downtime_instance['tss'] = time();
			$new_downtime_instance['tse'] = 0;
			try {
				$insert = $ndb->downtimes->insert($new_downtime_instance, array('safe' => true));
			} catch(MongoCursorException $e) {
				die('Error with MongoDB: '.$e."\n");
			}
		}
	} else { // not broken -- check for downtime instances. if any, delete them.
		$get_possible_downtimes = $ndb->downtimes->find( array( 'h' => $new_host['h'], 'tse' => 0 ) );
		if ($get_possible_downtimes->count() > 0) {
			foreach ($get_possible_downtimes as $possible_downtime) {
				$downtime_duration = time() - $possible_downtime['tss'];
				$update_downtime_instance = $ndb->downtimes->update( array( '_id' => $possible_downtime['_id'] ), array( '$set' => array( 'tse' => time(), 'hl' => $downtime_duration ) ), array('safe' => true) );
			}
		}
	}
}

// ok now go through and recalculate uptime percentages
$downtime_host_totals = array();
$get_downtime_instances = $ndb->downtimes->find();
if ($get_downtime_instances->count() > 0) {
	foreach ($get_downtime_instances as $downtime_instance) {
		if (isset($downtime_instance['hl'])) {
			$how_long = $downtime_instance['hl'] * 1;
		} else {
			$how_long = time() - $downtime_instance['tss'];
		}
		if (isset($downtime_host_totals[$downtime_instance['h']])) {
			$downtime_host_totals[$downtime_instance['h']] += $how_long;
		} else {
			$downtime_host_totals[$downtime_instance['h']] = $how_long;
		}
	}
	foreach ($downtime_host_totals as $downtime_host => $downtime_total) {
		$update_downtime = $ndb->hosts->update( array('h' => $downtime_host), array('$set' => array( 'dt' => $downtime_total ) ), array('safe' => true) );
	}
}


// ok now go through and delete any old crap
$how_old_to_delete = time() - (60 * 60 * 24 * 1); // delete anything that has a freshness of a day old or later
try {
	$delete_old_hosts = $ndb->hosts->remove( array('f' => array( '$lte' => $how_old_to_delete ) ), array( 'safe' => true ) );
} catch(MongoCursorException $e) {
	die('Error with MongoDB: '.$e."\n");
}


// done with that, now update general stats info
try {
	$update = $ndb->info->update(array('w' => 'last_updated'), array('w' => 'last_updated', 'f' => time()), array('safe' => true, 'upsert' => true));
} catch(MongoCursorException $e) {
	die('Error with MongoDB: '.$e."\n");
}

$script_time_end = microtime(true);
$script_time_total = $script_time_end - $script_time_start;
$script_time_total_ms = round($script_time_total * 1000);
if ($debugmode) echo "\n".'total '.$script_time_total_ms.'ms execution time'."\n";

?>