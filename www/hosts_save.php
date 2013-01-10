<?php

//echo '<pre>'.print_r($_POST, true).'</pre>';

require_once('dbconn_mongo.php');

for ($i = 0; $i < count($_POST['id']); $i++) {
	
	$host_id = new MongoId(trim($_POST['id'][$i]));
	$host_friendly = trim($_POST['n'][$i]);
	
	if ($host_friendly != '') {
		$change = array('$set' => array( 'n' => $host_friendly ) );
	} else {
		$change = array('$unset' => array( 'n' => 1 ) );
	}
	
	try {
		$update = $ndb->hosts->update(array('_id' => $host_id), $change, array('safe' => true));
	} catch(MongoCursorException $e) {
		die('Error with MongoDB: '.$e."\n");
	}
	
	//echo '<div>'.$_POST['id'][$i].' => '.$_POST['n'][$i].'</div>';
}

header('Location: hosts.php?saved=yup');

?>