<?php
/**
* Simple memchached dashboard
* A dead simple single file Memchaced stats dashboard.
* @version 0.1.1
* @author Ohad Raz <admin@bainternet.info>
*/
class Simple_memchached_dashboard{
	public $memcache = null;
	public $list     = null;
	public $status   = null;
	public $error    = false;
	public $servers  = array();
	private $users   = array();
	
	
	function __construct($servers = null,$users  = array('admin' => 'admin')){
		session_start();
		$this->users = $users;
		$this->validate_login();
		$this->need_login();
		if (is_string($servers)){
			if ($servers == 'localhost') $this->servers = array(array('127.0.0.1', 11211));
			elseif ($servers == 'session.save_path'){
				$this->servers = explode(',',ini_get('session.save_path'));
				foreach($this->servers as &$server){
					$server = explode(':', $server);
					if (isset($server[1])) $server[1] = (int) $server[1];
				}
			}
		}
		else 
			$this->servers = empty($servers) ? array(array('127.0.0.1', 11211)) : $servers;
		$this->setup();
		$this->dashboard();
	}

	function need_login(){
		if (!$this->is_logged_in()){
			$this->header();
			$this->login_form();
			$this->footer();
			die();
		}
	}

	function login_form(){
		?>
		<div class="row top20">
			<div class="col-md-4 col-md-offset-4">
				<div class="panel panel-default">
					<div class="panel-heading"><h3 class="panel-title"><strong>Sign In </strong></h3></div>
					<div class="panel-body">
						<form class="form-horizontal" action='' method="POST" role="form">
							<?php if($this->error){
								?><div class="alert alert-danger"><?= $this->error; ?></div><?php
							}?>
							<fieldset>
								<div class="form-group">
									<!-- Username -->
									<label class="col-md-3 control-label"  for="username">Username</label>
									<div class="col-xs-6 col-sm-6 col-md-6 col-lg-6">
										<input type="text" id="username" name="username" placeholder="" class="input-xlarge">	
									</div>								
								</div>

								<div class="form-group">
									<!-- Password-->
									<label class="col-md-3 control-label" for="password">Password</label>
									<div class="col-xs-6 col-sm-6 col-md-6 col-lg-6">
										<input type="password" id="password" name="password" placeholder="" class="input-xlarge">
									</div>
								</div>

								<div class="control-group">
									<!-- Button -->
									<div class="controls">
										<input class="btn btn-success" type="submit" name="submit" value="Submit" />
									</div>
								</div>
							</fieldset>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
    }

    function is_logged_in(){
		return (isset($_SESSION['username']) && isset($this->users[$_SESSION['username']]) && $_SESSION['username'] != '');
	}

	function add_user($user_name,$user_pass){
		$this->users[$user_name] = $user_pass;
	}

	function logout(){
		if(isset($_GET['action']) && $_GET['action'] == 'logout') {
			unset($_SESSION['username']);
			session_write_close();
			header('Location:  ' . $_SERVER['PHP_SELF']);
		}
	}

	function validate_login(){
		$this->logout();
		if(isset($_POST['username']) && isset($_POST['password'])) {
			$user = $_POST['username'];
			$pass = $_POST['password'];
			if ($this->user_exists($user)){
				if($pass == $this->get_user_pass($user)) {
					$_SESSION['username'] = $_POST['username'];
				}else {
					$this->error = 'Wrong Password!';
				}
			}else{
				$this->error = 'User Not Found!';
			}
		}
	}

	function user_exists($user){
		return isset($this->users[$user]);
	}

	function get_user_pass($user){
		if ($this->user_exists($user))
			return $this->users[$user];
		else
			return FALSE;
	}

	function setup(){
		$this->memcache = new Memcache();
		foreach($this->servers as $server){
			$this->memcache->addServer($server[0], (isset($server[1]) ? $server[1] : 11211), false, 50);
		}
		$list = array();
		$allSlabs = $this->memcache->getExtendedStats('slabs');
		$items = $this->memcache->getExtendedStats('items');
		foreach($allSlabs as $server => $slabs) {
			foreach($slabs AS $slabId => $slabMeta) {
				if ('active_slabs' == $slabId || "total_malloced" == $slabId) continue;
				$cdump = $this->memcache->getExtendedStats('cachedump',(int)$slabId);
				foreach($cdump AS $server => $entries) {
					if($entries) {
						foreach($entries AS $eName => $eData) {
							$value = $this->memcache->get($eName);
							$type = gettype($value);
							$value = $this->maybe_unserialize($value);
							if (is_object($value)|| is_array($value)){
								$value = is_object($value)? json_decode(json_encode($value), true): $value;
								$value = '<pre class="alert alert-warning">'.print_r($this->array_map_deep( $value,array($this,'maybe_unserialize')),true).'</pre>';
							}
							$list[$eName] = array(
								'key'   => $eName,
								'value' => $value,
								'type'  => $type
							);
						}
					}
				}
			}
		}
		ksort($list);
		$this->list = $list;
		$this->status = $this->memcache->getStats();
	}

	function array_map_deep($array, $callback) {
		$new = array();
		foreach ((array)$array as $key => $val) {
			if (is_array($val)) {
				$new[$key] = $this->array_map_deep($val, $callback);
			} else {
				$new[$key] = call_user_func($callback, $val);
			}
		}
		return $new;
	}

	function maybe_unserialize( $original ) {
		if ( $this->is_serialized( $original ) ) // don't attempt to unserialize data that wasn't serialized going in
			return @unserialize( $original );
		return $original;
	}

	function is_serialized($value, &$result = null){
		// Bit of a give away this one
		if (!is_string($value)){
			return false;
		}
	 
		// Serialized false, return true. unserialize() returns false on an
		// invalid string or it could return false if the string is serialized
		// false, eliminate that possibility.
		if ($value === 'b:0;'){
			$result = false;
			return true;
		}
	 
		$length	= strlen($value);
		$end	= '';
	 	
	 	if (!isset($value[0])) return false;
		switch ($value[0]){
			case 's':
				if ($value[$length - 2] !== '"'){
					return false;
				}
			case 'b':
			case 'i':
			case 'd':
				// This looks odd but it is quicker than isset()ing
				$end .= ';';
			case 'a':
			case 'O':
				$end .= '}';
	 			if ($value[1] !== ':'){
					return false;
				}
	 
				switch ($value[2]){
					case 0:
					case 1:
					case 2:
					case 3:
					case 4:
					case 5:
					case 6:
					case 7:
					case 8:
					case 9:
					break;
	 
					default:
						return false;
				}
			case 'N':
				$end .= ';';
	 			if ($value[$length - 1] !== $end[0]){
					return false;
				}
			break;
	 
			default:
				return false;
		}
	 
		if (($result = @unserialize($value)) === false){
			$result = null;
			return false;
		}
		return true;
	}

	function print_hit_miss_widget(){
		$status = $this->status;
		?>
		<div class="col-xs-12 col-sm-6 col-md-6 col-lg-6">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title">Hit/Miss</h3>
				</div>
				<div class="panel-body">
					<div id="hit_miss_cart" style="height: 250px;"></div>
				</div>
			</div>
			
			<script type="text/javascript">
			jQuery(document).ready(function(){
				Morris.Donut({
					element: 'hit_miss_cart',
					data: [
						{label: "Hit", value: <?= $status["get_hits"] ?>},
						{label: "Miss", value: <?= $status["get_misses"] ?>},
					],
					colors: ['#5cb85c','#d9534f']
				});
			});
		    </script>
		</div>
		<?php
	}

	function print_memory_widget(){
		$status  = $this->status;
		$MBSize  = number_format((real) $status["limit_maxbytes"]/(1024*1024),3);
		$MBSizeU = number_format((real) $status["bytes"]/(1024*1024),3);
		$MBRead  = number_format((real)$status["bytes_read"]/(1024*1024),3);
		$MBWrite = number_format((real) $status["bytes_written"]/(1024*1024),3);
		?>
		<div class="col-xs-12 col-sm-6 col-md-6 col-lg-6">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title">Memory</h3>
				</div>
				<div class="panel-body">
					<div id="memory_cart" style="height: 250px;"></div>
				</div>
			</div>
			    <script type="text/javascript">
			    jQuery(document).ready(function(){
					Morris.Bar({
						element: 'memory_cart',
						data: [
							{type: 'total',v: '<?= $MBSize ?>'},
							{type: 'used', v: '<?= $MBSizeU ?>'},
							{type: 'read', v: '<?= $MBRead ?>'},
							{type: 'Sent', v: '<?= $MBWrite ?>'}
						],
						xkey: 'type',
						ykeys: ['v'],
						labels: ['MB'],
						barColors: function (row, series, type) {
							if (type === 'bar') {
								var colors = ['#f0ad4e', '#5cb85c', '#5bc0de', '#d9534f', '#17BDB8'];
								return colors[row.x];
							}else {
								return '#000';
							}
						},
						barRatio: 0.4,
						xLabelAngle: 35,
						hideHover: 'auto'
					});
				});
			    </script>
			</div>
		<?php
	}

	function print_status_dump_widget(){
		$status = $this->status;
		echo '<pre>'.print_r($status,true).'</pre>';
	}

	function dashboard(){
		//delete
		if (isset($_GET['del'])) {
			$this->memcache->delete($_GET['del']);
			header("Location: " . $_SERVER['PHP_SELF']);
		}
		//flush
		if (isset($_GET['flush'])) {
			$this->memcache->flush();
			header("Location: " . $_SERVER['PHP_SELF']);
		}
		//set
		if (isset($_GET['set'])) {
			$this->memcache->set($_GET['set'], $_GET['value']);
			header("Location: " . $_SERVER['PHP_SELF']);
		}

		//header
		$this->header();
		//server info
		$this->print_server_info();
		//charts
		$this->print_charts();
		//stored data
		$this->stored_data_table();
		//footer
		$this->footer();
	}

	function stored_data_table(){
		?>
		<a name="stored_data">&nbsp;</a>
		<div class="panel panel-default top20">
			<div class="panel-heading">
				<h3 class="panel-title">Stored Keys</h3>
			</div>
			<div class="panel-body">
				<div class="btn-group btn-group-justified">
					<div class="btn-group">
						<a class="btn btn-info" href="<?= $_SERVER['PHP_SELF'] ?>" onclick="">Refresh</a>
					</div>
					<div class="btn-group">
						<a class="btn btn-primary" href="#" onclick="memcachedSet()">SET</a>
					</div>
					<div class="btn-group">
						<a class="btn btn-danger" href="#" onclick="flush()">FLUSH</a>
					</div>
				</div>
				<div class="table-responsive">
					<table id="stored_keys" class="table table-bordered table-hover table-striped" style="table-layout: fixed;">
						<thead>
							<tr>
								<th class="one_t">key</th>
								<th class="one_h">value</th>
								<th>type</th>
								<th>delete</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach($this->list as $i): ?>
								<tr>
									<td class="one_t"><span class="key_scroll"><?= $i['key'] ?></span></td>
									<td class="one_h"><?= $i['value'] ?></td>
									<td><?= $i['type'] ?></td>
									<td><a class="btn btn-danger" onclick="deleteKey('<?= $i['key'] ?>')" href="#">X</a>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	function print_charts(){
		?>
		<a name="charts">&nbsp;</a>
		<div class="row top20">
			<?php 
			$this->print_hit_miss_widget();
			$this->print_memory_widget();
			?>
		</div>
		<?php
	}

	function print_server_info(){ 
		$status = $this->status;
		?>
		<a name="info"></a>
		<div class="panel panel-default top20">
			<div class="panel-heading">
				<h3 class="panel-title">Server Info</h3>
			</div>
			<div class="panel-body">
			<?php
				echo "<table class='table'>"; 
				echo "<tr><td>Memcache Server version:</td><td> ".$status ["version"]."</td></tr>"; 
				echo "<tr><td>Process id of this server process </td><td>".$status ["pid"]."</td></tr>"; 
				echo "<tr><td>Server Uptime </td><td>".gmdate("H:i:s", $status["uptime"])."</td></tr>"; 
				echo "<tr><td>Total number of items stored by this server ever since it started </td><td>".$status ["total_items"]."</td></tr>"; 
				echo "<tr><td>Number of open connections </td><td>".$status ["curr_connections"]."</td></tr>"; 
				echo "<tr><td>Total number of connections opened since the server started running </td><td>".$status ["total_connections"]."</td></tr>"; 
				echo "<tr><td>Number of connection structures allocated by the server </td><td>".$status ["connection_structures"]."</td></tr>"; 
				echo "<tr><td>Cumulative number of retrieval requests </td><td>".$status ["cmd_get"]."</td></tr>"; 
				echo "<tr><td> Cumulative number of storage requests </td><td>".$status ["cmd_set"]."</td></tr>"; 

				if ((real)$status ["cmd_get"] != 0)
					$percCacheHit=((real)$status ["get_hits"]/ (real)$status ["cmd_get"] *100);
				else
					$percCacheHit=0;
				$percCacheHit=round($percCacheHit,3); 
				$percCacheMiss=100-$percCacheHit; 

				echo "<tr><td>Number of keys that have been requested and found present </td><td>".$status ["get_hits"]." ($percCacheHit%)</td></tr>"; 
				echo "<tr><td>Number of items that have been requested and not found </td><td>".$status ["get_misses"]."($percCacheMiss%)</td></tr>"; 

				$MBRead= (real)$status["bytes_read"]/(1024*1024); 
				echo "<tr><td>Total number of bytes read by this server from network </td><td>".$MBRead." Mega Bytes</td></tr>"; 
				$MBWrite=(real) $status["bytes_written"]/(1024*1024) ; 
				echo "<tr><td>Total number of bytes sent by this server to network </td><td>".$MBWrite." Mega Bytes</td></tr>";
				$MBSize=(real) $status["limit_maxbytes"]/(1024*1024) ; 
				echo "<tr><td>Current number of bytes used.</td><td>".$status['bytes']."</td></tr>"; 
				echo "<tr><td>Number of bytes this server is allowed to use for storage.</td><td>".$MBSize." Mega Bytes</td></tr>"; 
				echo "<tr><td>Number of valid items removed from cache to free memory for new items.</td><td>".$status ["evictions"]."</td></tr>"; 
				echo "</table>";
			?>
			</div>
		</div>	
		<?php
	}

	function header(){
		?><!DOCTYPE html>
		<html lang="">
		<head>
			<meta charset="utf-8">
			<meta http-equiv="X-UA-Compatible" content="IE=edge">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<link href="//cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.2.0/css/bootstrap.min.css" rel="stylesheet">
			<link rel="stylesheet" href="http://cdn.oesmith.co.uk/morris-0.5.1.css">
			<link rel="stylesheet" href="//cdn.datatables.net/plug-ins/a5734b29083/integration/bootstrap/3/dataTables.bootstrap.css">
			<link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/alertify.js/0.3.11/alertify.core.min.css">
			<link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/alertify.js/0.3.11/alertify.default.min.css">
			<link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/alertify.js/0.3.11/alertify.bootstrap.min.css">
			<script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.0/jquery.min.js"></script>
			<style type="text/css">
			pre {overflow: auto;width: 100%;}
			.key_scroll{overflow: auto;width: 50%;word-wrap: break-word;margin: 0;float: left;}
			.one_t{width: 30%;}
			.one_h{width: 50%;}
			.top20{margin-top: 30px;}
			</style>
		</head>
		<body>
		<nav class="navbar navbar-default navbar-fixed-top" role="navigation">
			<div class="container">
			<!-- Brand and toggle get grouped for better mobile display -->
				<div class="navbar-header">
					<button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1">
						<span class="sr-only">Toggle navigation</span>
						<span class="icon-bar"></span>
						<span class="icon-bar"></span>
						<span class="icon-bar"></span>
					</button>
					<a class="navbar-brand" href="<?= $_SERVER['PHP_SELF'] ?>">Memcached Dashboard</a>
				</div>
			<?php 
			if (!empty($this->servers)){	
				?><p class="navbar-text">Servers: <?
					$serversList = array();
					foreach($this->servers as $serverArray){
						$serversList[] = implode(':', $serverArray);
					}
					echo implode(', ', $serversList); 
				?></p><?php
			}
			if ($this->is_logged_in()){
				?>
				<!-- Collect the nav links, forms, and other content for toggling -->
				<div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
					<ul class="nav navbar-nav navbar-right">
						<li class="dropdown">
							<a href="#" class="dropdown-toggle" data-toggle="dropdown">Menu <span class="caret"></span></a>
							<ul class="dropdown-menu" role="menu">
								<li><a href="#">Server Info</a></li>
								<li><a href="#charts">Charts</a></li>
								<li><a href="#stored_data">Stored Data</a></li>
								<li class="divider"></li>
								<li><a href="<?= $_SERVER['PHP_SELF']; ?>?action=logout">logout</a></li>
							</ul>
						</li>
					</ul>
				</div><!-- /.navbar-collapse -->
				<?php
			}?>    
		  </div><!-- /.container-fluid -->
		</nav>
		<div class="container top20">
    	<?php
	}

	function footer(){
		?>
		<script src="//cdnjs.cloudflare.com/ajax/libs/raphael/2.1.0/raphael-min.js"></script>
		<script src="http://cdn.oesmith.co.uk/morris-0.5.1.min.js"></script>
		<script type="text/javascript" src="//cdn.datatables.net/1.10.2/js/jquery.dataTables.min.js"></script>
		<script type="text/javascript" src="//cdn.datatables.net/plug-ins/a5734b29083/integration/bootstrap/3/dataTables.bootstrap.js"></script>
		<script type="text/javascript" src="//cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.2.0/js/bootstrap.min.js"></script>
		<script type="text/javascript" src="//cdnjs.cloudflare.com/ajax/libs/alertify.js/0.3.11/alertify.min.js"></script>
		<script type="text/javascript">
			jQuery(document).ready(function(){
				$("#stored_keys").dataTable({
					"bFilter":true,
					"bSort":true,
					"dom": '<"top"ilf>rt<"bottom"p><"clear">'
				});
			});

			function memcachedSet() {
				alertify.prompt("Set Key", function (e, key) {
					// key is the input text
					if (e) {
						alertify.prompt("Set Value", function (e, value) {
							// value is the input text
							if (e) {
								window.location.href = "<?= $_SERVER['PHP_SELF'] ?>?set="+ key +"&value=" + value;
							} else {
							// user clicked "cancel"
							}
						}, "Some Value");
					} else {
						// user clicked "cancel"
					}
				}, "SomeKey");
			}
			function deleteKey(key){
				alertify.confirm("Are you sure?", function (e) {
					if (e) {
						window.location.href = "<?= $_SERVER['PHP_SELF'] ?>?del="+key;
					} else {
					// user clicked "cancel"
					}
				});
			}
			function flush(){
				alertify.confirm("Are you sure?", function (e) {
					if (e) {
						window.location.href = "<?= $_SERVER['PHP_SELF'] ?>?flush=1";
					} else {
					// user clicked "cancel"
					}
				});
			}
		</script>
		</body>
		</html>
		<?php
	}
}//end class
new Simple_memchached_dashboard();