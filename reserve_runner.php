<?php
require 'reserve_driver.php';
require 'db.php';

$debug = 1;

$datetime = '2018-08-09 23:00';
$party_size = 2;
#$slug = 'cinco-mexican-cantina-atlanta-atlanta';
$slug = 'kevin-rathbun-steak-atlanta';
$minutes_before = 60;
$minutes_after = 60;

$email_address = 'bufy@banit.club';
$phone_number = '5404279049';
$first_name = 'Bufy';
$last_name = 'Banit';

#$reservation_id = '08b37691-ed57-4dc4-9b47-325b0f8f287b';
#print getStatus($reservation_id);
#Values: CANCELED, UNCONFIRMED


$reserve = new Reserve();
$sth = $dbh->prepare("
	SELECT
		DATE_FORMAT(t.datetime, '%m/%d/%Y %l:%i:%s %p') as tdate, 
		t.id,
		t.persons,
		t.rangemin,
		t.rangemax,
		u.first_name,
		u.last_name,
		u.email,
		u.phone_number,
		r.name AS rname,
		r.identifier AS identifier 
	FROM tasks t
	JOIN restaurants r ON (r.id = t.restaurant_id)
	JOIN users u ON (u.id = t.user_id)
	WHERE t.active = 1 
	  AND t.restaurant_id = r.id
	  AND r.platform = 'reserve.com'
");
$sth->execute();
while ($task = $sth->fetch()) {
	$id = $task['id'];
	$user_id = $task['user_id'];
	$datetime = $task['tdate'];
	$restaurant = $task['rname'];
	$identifier = $task['identifier'];
	$party_size = (int)$task['persons'];
	$minutes_before = $task['rangemin'];
	$minutes_after = $task['rangemax'];
	$email_address = $task['email'];
	$phone_number = $task['phone_number'];
	$first_name = $task['first_name'];
	$last_name = $task['last_name'];

	$reservation_id = $reserve->attemptBooking($identifier, $datetime, $minutes_before, $minutes_after, $party_size, $email_address, $phone_number, $first_name, $last_name);
	if ($reservation_id) {
 		$dbh->exec("UPDATE `tasks` SET `status` = 'UNCONFIRMED',`active` = 0, `reservation_id` = '$reservation_id' WHERE id=$id;");
	}
	
	print "---- RESULT ---\n";
	print_r($reservation_id);

	//if ($debug) echo "\n<br>checking $restaurant @ $datetime ";

}



?>
