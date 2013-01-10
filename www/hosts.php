<?php

require_once('dbconn_mongo.php');

$hosts = array();
$get_all = $ndb->hosts->find(  )->sort( array('h' => 1) );
foreach ($get_all as $host) {
	$hosts[] = $host;
}

?>
<html>
	<body>
		<?php
		if (isset($_GET['saved']) && $_GET['saved'] == 'yup') {
			echo '<p>Saved!</p>'."\n";
		}
		?>
		<form action="hosts_save.php" method="post">
		<table>
			<thead>
				<tr><th>Hostname</th><th>Friendly Name</th><th>Freshness</th></tr>
			</thead>
			<tbody>
				<?php
				foreach ($hosts as $host) {
					echo '<tr>';
					echo '<td><input type="hidden" name="id[]" value="'.$host['_id'].'" />'.$host['h'].'</td>';
					echo '<td><input style="width:200px;" type="text" name="n[]" value="'.((isset($host['n'])) ? $host['n'] : '').'" /></td>';
					echo '<td>'.date('m-d-Y h:ia', $host['f']).'</td>';
					echo '</tr>';
				}
				?>
			</tbody>
		</table>
		<div><input type="submit" value="save changes" />
		</form>
	</body>
</html>