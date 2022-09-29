<?php

$dbName = 'sycsoft_restaurants';
$dbUser = 'sycsoft_rest';
$dbPass = '';
$dbHost = 'localhost';
	
error_reporting(E_ALL & ~E_NOTICE);

	try {
		$dbh = new PDO('mysql:host=' . $dbHost . ';dbname=' . $dbName, $dbUser, $dbPass);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$dbh->setAttribute(PDO::ATTR_PERSISTENT, TRUE);
	} catch (PDOException $e) {
		echo '<strong>Error: </strong>' . $e->getMessage();
		die();
	}
?>