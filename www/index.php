<?php

// nagios API

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$action = '';

if (isset($_GET['w']) && trim($_GET['w']) != '') {
	$action = strtolower(trim($_GET['w']));
} else if (isset($_POST['w']) && trim($_POST['w']) != '') {
	$action = strtolower(trim($_POST['w']));
}

if (trim($action) == '') {
	die(json_encode(array('error' => 'no action provided')));
}

require_once('dbconn_mongo.php');

$ndb->setSlaveOkay();

if ($action == 'list') {
	// get all hosts and their info, lol
	
	$hosts = array();
	
	if (isset($_GET['sort']) && trim($_GET['sort']) == 'friendly') {
		$sort = array('n' => 1);
	} else {
		$sort = array('h' => 1);
	}
	
	$get_all = $ndb->hosts->find( array(), array('_id' => false) )->sort( $sort );
	foreach ($get_all as $host) {
		$hosts[] = $host;
	}
	
	echo json_encode($hosts);
	
} else if ($action == 'groups') {
	// get list of host groups + check status on each
	
	$return_groups = array();
	
	$groups = $ndb->groups->find()->sort(array('n' => 1));
	if ($groups->count() > 0) {
		foreach ($groups as $group) {
			//print_r($group);
			$new_group = array();
			$new_group['n'] = $group['a'];
			$new_group['id'] = $group['n'];
			$new_group['f'] = $group['f'];
			$any_problems = false;
			$any_broken = false;
			$find_problems = $ndb->hosts->find(array('h' => array('$in' => $group['m']), 'p' => true));
			if ($find_problems->count() > 0) {
				$any_problems = true;
			}
			$find_broken = $ndb->hosts->find(array('h' => array('$in' => $group['m']), 'b' => true));
			if ($find_broken->count() > 0) {
				$any_broken = true;
			}
			$new_group['p'] = $any_problems;
			$new_group['b'] = $any_broken;
			$return_groups[] = $new_group;
		}
		echo json_encode($return_groups);
	} else {
		die(json_encode(array('error' => 'no groups available')));
	}

} else if ($action == 'group') {
	
	if (!isset($_GET['g']) || trim($_GET['g']) == '') {
		die(json_encode(array('error' => 'no group id provided')));
	}
	
	$group = $ndb->groups->findOne( array('n' => trim($_GET['g'])) );
	if (isset($group)) {
		//print_r($group);
		$new_group = array();
		$new_group['n'] = $group['a'];
		$new_group['id'] = $group['n'];
		$new_group['f'] = $group['f'];
		$new_group['m'] = $group['m'];
		$any_problems = false;
		$any_broken = false;
		$find_problems = $ndb->hosts->find(array('h' => array('$in' => $group['m']), 'p' => true));
		if ($find_problems->count() > 0) {
			$any_problems = true;
		}
		$find_broken = $ndb->hosts->find(array('h' => array('$in' => $group['m']), 'b' => true));
		if ($find_broken->count() > 0) {
			$any_broken = true;
		}
		$new_group['p'] = $any_problems;
		$new_group['b'] = $any_broken;
		echo json_encode($new_group);
	} else {
		die(json_encode(array('error' => 'no group found with that ID')));
	}
	
} else if ($action == 'hosts') {
	// get a specific host
	
	$hostlist = '';
	
	if (isset($_POST['h']) && trim($_POST['h']) != '') {
		$hostlist = $_POST['h'];
	}
	
	if (isset($_GET['h']) && trim($_GET['h']) != '') {
		$hostlist = $_GET['h'];
	}
	
	if (trim($hostlist) == '') {
		die(json_encode(array('error' => 'no host provided')));
	}
	
	$hostnames = explode(',', strtolower(trim($hostlist)));
	
	$hosts = $ndb->hosts->find(array('h' => array('$in' => $hostnames) ), array('_id' => false));
	if ($hosts->count() > 0) {
		$hosts_return = array();
		foreach ($hosts as $host) {
			$hosts_return[] = $host;
		}
		echo json_encode($hosts_return);
	} else {
		die(json_encode(array('error' => 'hosts not found')));
	}
	
} else if ($action == 'host') {
	// get a specific host
	
	if (!isset($_GET['h']) || trim($_GET['h']) == '') {
		die(json_encode(array('error' => 'no host provided')));
	}
	
	$hostname = strtolower(trim($_GET['h']));
	
	$host = $ndb->hosts->findOne(array('h' => $hostname), array('_id' => false));
	if (isset($host)) {
		echo json_encode($host);
	} else {
		die(json_encode(array('error' => 'host not found')));
	}
	
} else if ($action == 'problems') {
	// get everything that is having a problem
	
	$hosts = array();
	
	$get_all = $ndb->hosts->find(array('p' => true), array('_id' => false));
	foreach ($get_all as $host) {
		$hosts[] = $host;
	}
	
	echo json_encode($hosts);
	
} else if ($action == 'broken') {
	// get everything that is broken
	
	$hosts = array();
	
	$get_all = $ndb->hosts->find(array('b' => true), array('_id' => false));
	foreach ($get_all as $host) {
		$hosts[] = $host;
	}
	
	echo json_encode($hosts);

} else if ($action == 'stats') {
	// get general stats... how many there are, how many have problems, etc
	
	$hosts_total = $ndb->hosts->count();
	$problem_total = $ndb->hosts->find(array('p' => true))->count();
	$broken_total = $ndb->hosts->find(array('b' => true))->count();
	$last_updated = $ndb->info->findOne(array('w' => 'last_updated'));
	
	$info = array();
	$info['hosts_total'] = $hosts_total;
	$info['hosts_problem'] = $problem_total;
	$info['hosts_broken'] = $broken_total;
	$info['freshness'] = $last_updated['f'];
	
	echo json_encode($info);

} else {
	die(json_encode(array('error' => 'no valid action provided')));
}

?>