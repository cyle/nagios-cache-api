<?php

try {
	$m = new Mongo();
	$ndb = $m->nagios;
} catch (MongoCursorException $e) {
	echo 'error message: '.$e->getMessage()."\n";
    echo 'error code: '.$e->getCode()."\n";
    die();
}

?>