<?php
header('Content-Type: text/html; charset=utf-8');

// #######################################################################
// ####################### SET PHP ENVIRONMENT ###########################
// #######################################################################

#ini_set('display_errors', 1);
date_default_timezone_set('America/Chicago');

include 'includes/db_connect.php';

// #######################################################################
// ########################### FUNCTIONS #################################
// #######################################################################

function getUserByEmailAndPassword($username, $password) {
	global $mysqli;
	$result = $mysqli->query("SELECT * FROM user_account WHERE username = '$username'") or die(mysql_error());
	$no_of_rows = $result->num_rows;
	if ($no_of_rows > 0) {
		$result = $result->fetch_array();
		$salt = $result['password_salt'];
		$stored_hash = $result['password_hash'];
		$hashtest = checkhashSSHA($salt, $password);
		if ($hashtest == $stored_hash) {
			return $result;
		}
	}
	else {
		return false;
	}
}

function checkhashSSHA($salt, $password) {
	$hash = base64_encode(sha1($password . $salt, true) . $salt);
	return $hash;
}

// #######################################################################
// ######################### POST GET ITEMS ##############################
// #######################################################################

$username = $mysqli->real_escape_string($_POST['user_name']);
$password = $mysqli->real_escape_string($_POST['user_password']);
$ip = $_POST['ip'];
$suid = $_POST['stationID'];
$user = getUserByEmailAndPassword($username, $password);

// #######################################################################
// ####################### FINAL GET ID ##################################
// #######################################################################

if ($user != false) {
	if($user['accesslevel'] == "banned") {
		$response['message'] = "Your account has been banned. For further information regarding the ban of your account or to submit a Ban Appeal, contact a member of CSR Staff.";
	} else {
		$response['message'] = "success";
	}
}
else {
	$response['message'] = "Account does not exist or password was incorrect";
}
echo json_encode($response);

// #######################################################################
// ####################### AUTHENTICATION LOGS ###########################
// #######################################################################

$auth_content = '[' . date('m/d/Y h:i:s a') . '] ' . 'Username: ' . $username . ', Station ID: ' . $suid . ', IP: ' . $ip . "\n";

// To enable logging, give the chdir function the absolute path to your webroot directory and uncomment the following two lines.
// Also make sure that your apache2 user owns the webroot directory and has write permissions.

#chdir('WEBROOT_DIRECTORY_GOES_HERE'); // If you're running VM 2.1, chdir should be /srv/www/htdocs
#file_put_contents('logs/auth_log.txt', $auth_content, FILE_APPEND);

die();

// #######################################################################
// ####################### END OF FILE ###################################
// #######################################################################
?>
