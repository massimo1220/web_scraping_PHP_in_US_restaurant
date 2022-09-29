<?php


$datetime = '2018-08-07 18:00';
$party_size = 2;
#$slug = 'cinco-mexican-cantina-atlanta-atlanta';
$slug = 'kevin-rathbun-steak-atlanta';
$minutes_before = 60;
$minutes_after = 60;

$email_address = 'zubinake@banit.me';
$phone_number = '5404279049';
$first_name = 'Zack';
$last_name = 'Ubinake';

#$reservation_id = '08b37691-ed57-4dc4-9b47-325b0f8f287b';
#print getStatus($reservation_id);
#Values: CANCELED, UNCONFIRMED

attemptBooking($slug, $datetime, $minutes_before, $minutes_after, $party_size, $email_address, $phone_number, $first_name, $last_name);

function attemptBooking($slug, $datetime, $minutes_before, $minutes_after, $party_size, $email_address, $phone_number, $first_name, $last_name) {
	print "Attempting booking at $slug\n";

	$time_radius = max($minutes_before, $minutes_after) * 2;
	if ($time_radius < 120) {
		$time_radius = 120;
	}
	
	$info = getRestaurantInfo($slug, $datetime, $time_radius, $party_size);
	print_r($info);

	$venue_id = $info->data[0]->id;
	$account_id = $info->data[0]->account_id;
	$available_slots = $info->data[0]->slots[0]->times;

	$slot = getSlot($available_slots, $datetime, $minutes_before, $minutes_after);
	print_r($slot);

	if ($slot) {
		print "Found a Time Slot\n";
		$reservation = getReservationInfo($slug, $datetime, $party_size, $slot, $venue_id);
		$reservation_id = $reservation->data->id;
		$venue_id = $reservation->data->venue_id;
		if ($reservation_id) {
			print "Reservation ID: $reservation_id \n";
 			$submit_result = submitReservation($account_id, $venue_id, $email_address, $phone_number, $first_name, $last_name);
			$guest_id = $submit_result->data->id;
			$result1 = putReservation1($reservation_id, $guest_id);
			$result2 = putReservation2($reservation_id, $guest_id);
		} else {
			print "No potential Reservation ID\n";
		}
	} else {
		print "NO TIMES AVAILABLE\n";
	}
	print "done\n";
}

function getSlot($times, $datetime, $minutes_before, $minutes_after) {
	$datetime = new DateTime($datetime);
	$request_time = $datetime->format('Hi');
	$min_time = $datetime->modify("-$minutes_before minutes")->format('Hi');
	$max_time = $datetime->modify("+$minutes_after minutes")->format('Hi');


	$min_slot = null;
	$exact_slot = null;
	$max_slot = null;
	for($i = 0; $i < sizeof($times); $i++) {
		$slot = $times[$i];
		if ($slot->shift_id && $slot->time != -1) {
			if ($slot->time == $request_time) {
				$exact_shift_id = $slot;
			}
			if ($slow->time < $request_time && $slot->time >= $min_time) {
				$min_slot = $slot;
			}
			if ($slow->time > $request_time && $slot->time <= $max_time) {
				if ($max_slot == null) {
					$max_slot = $slot;
				}
			}
		}
	}

	if ($exact_slot) { return $exact_slot; }
	if ($min_slot) { return $min_slot; }
	if ($max_slot) { return $max_slot; }
}

function submitReservation($account_id, $venue_id, $email_address, $phone_number, $first_name, $last_name) {
	$url = "https://api.reserve.com/api/v1/public/guests?force=true";
	$data = array(
		'account_id' => $account_id,
		'emails' => array(array('primary' => true, 'type' => 'PERSONAL', 'value' => $email_address)),
		'first_name' => $first_name,
		'last_name' => $last_name,
		'phone_numbers' => array(array('primary' => true, 'type' => 'MOBILE', 'value' => $phone_number)),
		'venue_preferences' => array(array('venue_id' => $venue_id))
	);

	$data_string = json_encode($data);

	$ch = curl_init($url);                                                                      
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
	    'Content-Type: application/json',                                                                                
	    'Content-Length: ' . strlen($data_string))                                                                       
	);                                                                                                                   
															     
	$result = curl_exec($ch);
	$result_json = json_decode($result);
	return $result_json;
}

function getRestaurantInfo($slug, $date, $time_radius, $party_size) {
	$date = new DateTime($date);
	$day = $date->format('Y-m-d');
	$time = (int)$date->format('Hi');

	$url = 'https://api.reserve.com/api/v1/public/venues/search';

	$data = array(
		'party_size'  => $party_size,
		'platform'    => 'WEB',
		'start_date'  => $day,
		'end_date'    => $day,
		'time'        => $time,
		'time_radius' => $time_radius,
		'search_type' => 'SLUG',
		'slugs'   => [$slug]
	);

	$data_string = json_encode($data);
															     
	$ch = curl_init($url);                                                                      
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
	    'Content-Type: application/json',                                                                                
	    'Content-Length: ' . strlen($data_string))                                                                       
	);                                                                                                                   
															     
	$result = curl_exec($ch);
	$result_json = json_decode($result);
	return $result_json;
}

function getReservationInfo($slug, $datetime, $party_size, $slot, $venue_id) {
	$url = "https://api.reserve.com/api/v1/public/reservations/?concierge=0";

	$datetime = new DateTime($datetime);
	$day = $datetime->format('Y-m-d');
	$shift_id = $slot->shift_id;
	$time = $slot->time;

	$data = array(
		'date'             => $day,
		'guest_id'         => null,
		'lock_in_progress' => true,
		'metadata'         => array('referral' => "reserve.com/r/$slug"),
		'origin'           => 'WEBSITE',
		'party_size'       => $party_size,
		'shift_id'         => $shift_id,
		'source'           => 'WEBSITE',
		'time'             => $time,
		'venue_id'         => $venue_id
	);

	$data_string = json_encode($data);

	$ch = curl_init($url);                                                                      
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
	    'Content-Type: application/json',                                                                                
	    'Content-Length: ' . strlen($data_string))                                                                       
	);                                                                                                                   
															     
	$result = curl_exec($ch);
	$result_json = json_decode($result);
	return $result_json;
}

function getStatus($reservation_id) {
	$url = "https://api.reserve.com/api/v1/public/reservations/$reservation_id";

	$ch = curl_init($url);                                                                      
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");                                                                     
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
	    'Content-Type: application/json',                                                                                
	    'Content-Length: ' . strlen($data_string))                                                                       
	);                                                                                                                   
															     
	$result = curl_exec($ch);
	$result_json = json_decode($result);
	print_r($result_json);
	$status = $result_json->data->status->name;
	return $status;
}

function getAccountId($venue_id) {
	$url = "https://api.reserve.com/api/v1/public/venues/$venue_id";
	#66f3aeff-c128-4880-89e5-63fce0b1d011

	$ch = curl_init($url);                                                                      
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");                                                                     
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
	    'Content-Type: application/json',                                                                                
	    'Content-Length: ' . strlen($data_string))                                                                       
	);                                                                                                                   
															     
	$result = curl_exec($ch);
	$result_json = json_decode($result);
	$account_id = $result_json->data->account_id;
	return $account_id;
}

function putReservation1($reservation_id, $guest_id) {
	$url = "https://api.reserve.com/api/v1/public/reservations/$reservation_id";
	$data = array(
		'guest_id' => $guest_id,
		'lock_in_progress' => true
	);

	$data_string = json_encode($data);

	$ch = curl_init($url);                                                                      
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");                                                                     
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
	    'Content-Type: application/json',                                                                                
	    'Content-Length: ' . strlen($data_string))                                                                       
	);                                                                                                                   
															     
	$result = curl_exec($ch);
	$result_json = json_decode($result);
	return $result_json;
}

function putReservation2($reservation_id, $guest_id) {
	$url = "https://api.reserve.com/api/v1/public/reservations/$reservation_id";
	$data = array(
		'guest_id' => $guest_id,
		'lock_in_progress' => false
	);

	$data_string = json_encode($data);

	$ch = curl_init($url);                                                                      
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");                                                                     
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
	    'Content-Type: application/json',                                                                                
	    'Content-Length: ' . strlen($data_string))                                                                       
	);                                                                                                                   
															     
	$result = curl_exec($ch);
	$result_json = json_decode($result);
	return $result_json;
}

?>
