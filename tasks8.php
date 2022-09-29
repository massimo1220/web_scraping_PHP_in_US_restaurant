<?php
error_reporting(E_ALL);
ob_implicit_flush(1);
require 'db.php';
$settings_file = 'settings.json';
$debug = 1;

	$json = file_get_contents($settings_file);
	$settings = json_decode($json, true);

	if (isset($settings['timezone'])) {
		date_default_timezone_set($settings['timezone']);
	}

	$rate = 60;

	if (isset($settings['rate']) && is_numeric($settings['rate']) && ($settings['rate'] <= 60 && $settings['rate'] >= 5)) {
		$rate = $settings['rate'];
	}

	$runs = ceil(60 / $rate);

	if ($debug) { echo "\n<br>version 8<br>\n"; }

	foreach (range(1, $runs) as $run) {
		$run_time = time();
		if ($debug) echo "\n<br>check #$run<br>\n";
		$start = time();
		$cancelled = array();
		$sth = $dbh->prepare("SELECT DATE_FORMAT(tasks.datetime, '%Y-%m-%dT%k:%i:00') as tdate, tasks.*,restaurants.name AS rname FROM tasks,restaurants WHERE active=1 and tasks.restaurant_id = restaurants.id and restaurants.platform = 'opentable.com'");
		$sth->execute();
//        $tasks = [
//            [
//                'id' => 1,
//                'tries' => 0,
//                'user_id' => 1,
//                'restaurant_id' => 2067,
//                'persons' => 2,
//                'rangemin' => '3',
//                'rangemax' => '9',
//                'tdate' => '2022-05-3T19:00:00',
//                'rname' => 'Wildfire'
//            ]
//        ];
//        foreach($tasks as $task) {
        while ($task = $sth->fetch()) {
			$id = $task['id'];

			if ($task['tries'] > 5) {
				if ($debug) echo "[error limit exceeded]";
				close_task($id, 'Error');
				continue;
			}

			if (in_array($id, $cancelled)) {
				continue;
			}

			$user_id = $task['user_id'];

			$datetime = $task['tdate'];
			$restaurant = $task['rname'];

			if ($debug) echo "\n<br>checking $restaurant @ $datetime ";

			$now = new DateTime();
			$nowtime = $now->format('Y-m-d\TH:i:s');

			$diff = datediff($datetime, $nowtime);
			$mstime = date('Y-m-d H:i', strtotime($datetime));


			if (($diff * -1) < intval($settings['deadline'])) {
				if ($debug) echo "[expired]";
				close_task($id, 'Expired');
				continue;
			}
# availability check
			$result = check_date($id, $user_id, $task['restaurant_id'], $task['persons'], $datetime, $task['rangemin'], $task['rangemax']);

# check for crossing dates
			if ($result) {
				$mstime = date('Y-m-d H:i', strtotime($datetime));

				$uth = $dbh->prepare("SELECT id FROM tasks WHERE HOUR(TIMEDIFF(datetime, :mstime)) < 12 AND user_id = :uid AND id <> :id AND active=1;");
				$uth->execute(array(
					':mstime' => $mstime,
					':uid' => $user_id,
					':id' => $id
				));
				$ids = $uth->fetchAll(PDO::FETCH_COLUMN, 0);

				if (!empty($ids)) {
					foreach ($ids as $id) {
						close_task($id, 'Cancelled');
						array_push($cancelled, $id);
					}
				}
			}
		}

		$end = time();
		$pause = $rate - ($end - $start);
		if ($pause > 0 && $run < $runs) {
			if ($debug) echo "\n<br>\n<br>pause $pause<br>\n";
			sleep($pause);
		}
	}
	if ($debug) echo "\n<br>\n<br>finished.";


function check_date($taskid, $userid, $rid, $persons, $date, $rangemin, $rangemax) {
global $debug, $dbh, $settings;

	$headers_gen = array(
		'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
		'Accept-language: en-US,en;q=1',
	);

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_USERAGENT, $settings['uagent']);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_ENCODING , 'gzip');
	curl_setopt($ch, CURLOPT_COOKIEFILE, '');
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_gen);

#	if ($debug) echo "<a target='_blank' href='$url'>URL</a>  ";


	curl_setopt($ch, CURLOPT_URL, 'https://www.opentable.com/restaurant/profile/'.$rid);
	$content = curl_exec($ch);

	$url = 'https://www.opentable.com/restaurant/profile/'.$rid.'/search';
	$post_str = '{"covers":'.$persons.',"dateTime":"'.$date.'","isRedesign":true}';

	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $post_str);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
	    'Accept: application/json, text/plain, */*',
	    'Accept-Language: en-US,en;q=1',
	    'Content-Type: application/json;charset=UTF-8',
		'Origin: https://www.opentable.com',
	));

	$content = curl_exec($ch);
#	file_put_contents('1.html', $content);
	curl_setopt($ch, CURLOPT_POST, false);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_gen);

	$data = json_decode($content, true);
	if (!$data) die('response error');

	if (isset($data['availability'])) {
		$avail = $data['availability'];
	} elseif (isset($data['sameDayAvailability'])){
		$avail = $data['sameDayAvailability'];
	} else {
		die('unexpected response');
	}

	$found = $values = array();
	foreach ($avail['times'] as $time) {
		$d = $time['dateTime'];
		$d = preg_replace('#\.000Z$#', '', $d);
		$time_str = $time['timeString'];
		$url = $time['url'];
		if (preg_match('#^//#', $url)) $url = 'https:'.$url;

		$attr = $time['attributes'];
    $custom_attr = '';

		if (in_array('default', $attr)) {
      $url.= '&tc=default';
		} elseif (in_array('outdoor', $attr)) {
      $url.= '&tc=outdoor';
      $custom_attr = 'outdoor';
		} else {
      continue;
    }

		$diff = datediff($date, $d);

		if ($diff < 0 && $diff < -$rangemin) continue;
		if ($diff > 0 && $diff > $rangemax) continue;

		$found[$diff] = $d;
		$values[$diff] = $url;
	}


	if ($found) {
		$run_time = time();

		$diff = (isset($found['0'])) ? '0' : getClosest(0, array_keys($found));
		$date_found = $found[$diff];
		if ($debug) echo "[FOUND $date_found (diff $diff min), reserving]<br>\n";
		$sth = $dbh->prepare("SELECT * FROM users WHERE id=$userid");
		$sth->execute();
		$u = $sth->fetch();
//        $u = [
//            'first_name' => 'Jack',
//            'last_name' => 'Bauer',
//            'phone_number' => '3312015841',
//            'email' => 'jacktest@gmail.com',
//        ];

		$url = $values[$diff];
		curl_setopt($ch, CURLOPT_URL, $url);
		$content = curl_exec($ch);
		$info = curl_getinfo($ch);
		$action_url = $info['url'];

		$state = '';
		if (preg_match_all('#<script>\(function\(w\) \{(.*?;)\}\)\(window\);#', $content, $m)) {
			foreach ($m[1] as $s) {
				if (preg_match('#CSRF_TOKEN#', $s)) $state = $s;
			}
		}

		$csrftoken = preg_match("#w\.__CSRF_TOKEN__='([^']*?)'#", $state, $m) ? $m[1] : '';
		$state = preg_match("#w\.__INITIAL_STATE__ = (\{.*?\});#", $state, $m) ? $m[1] : '';
		$sdata = json_decode($state, true);

		if (!$csrftoken || !$sdata) die("order form error!");


//   file_put_contents('pages/'.$run_time.'-pre-card.html', $content);

// card part
		$card_params = array();
		if (preg_match('#>\s*Credit card required\s*<#si', $content, $matches) || stristr($action_url, 'creditCardRequired=true')) {
			if (!$u['ccnum'] || !$u['cccvc']) {
				if ($debug) echo "card required<br>\n";
				close_task($taskid, 'Card required');
				return;
			}

			$pk = preg_match('#"publishableKey":"([^"]*?)"#s', $content, $m) ? $m[1] : '';
			if (!$pk) die('card form error');

			$expmonth = $u['ccexpmonth'];
			$args = array(
				'time_on_page' => rand(5000, 100000),
				'pasted_fields' => 'number',
				'key' => $pk,
				'payment_user_agent' => 'stripe.js/d67f6c85; stripe-js-v3/d67f6c85',
				'card[name]' => $u['first_name'].' '.$u['last_name'],
				'card[number]' => $u['ccnum'],
				'card[cvc]' => $u['cccvc'],
				'card[exp_month]' => sprintf('%02d', $expmonth),
				'card[exp_year]' => $u['ccexpyear'],
//				'card[address_zip]' => '',
			);

            if ($u['cczip']) {
                $args['card[address_zip]'] = $u['cczip'];
            }

			$post_str = http_build_query($args);
//	        curl_setopt($ch, CURLOPT_VERBOSE, true);

			curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/tokens');
	        curl_setopt($ch, CURLOPT_POST, true);
	        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_str);
			$content = curl_exec($ch);
#			file_put_contents('pages/'.$run_time.'-card1.html', $content);

	        curl_setopt($ch, CURLOPT_POST, false);

			$data = json_decode($content, true);
			if (!$data || !isset($data['id'])) {
				file_put_contents('pages/'.$run_time.'-card1-failed.html', $content);
				echo 'card error';
				$dbh->exec("UPDATE `tasks` SET tries=tries+1 WHERE id=$taskid;");
				return;
			}

			$card_params = array(
				'creditCardLast4' => $data['card']['last4'],
				'creditCardToken' => $data['id'],
				'creditCardMMYY'  => sprintf('%02d', $expmonth).substr($u['ccexpyear'], -2)
			);

//        file_put_contents('pages/'.$run_time.'-post-card.html', var_export($data, true)."\n\n".var_export($card_params, true));
		}


		$post_tmpl = '{"phoneNumberCountryId":"US","firstName":"","lastName":"","katakanaFirstName":"","katakanaLastName":"","email":"","phoneNumber":"",'.
					'"country":"US","restaurantId":"","reservationDateTime":"","partySize":"","slotAvailabilityToken":"","correlationId":"","reservationType":"Standard",'.
					'"slotLockId":"","slotHash":"","reservationAttribute":"default","attributionToken":"","confirmPoints":false}';

		$post = json_decode($post_tmpl, true);

		if ($custom_attr) {
			$post['reservationAttribute'] = $custom_attr;
		}

		$phone = preg_replace('#[\D]#', '', $u['phone_number']);

		$post['firstName']    = $u['first_name'];
		$post['lastName']     = $u['last_name'];
		$post['phoneNumber']  = $phone;
		$post['email']        = $u['email'];
        $post['pointsType']   = 'POP';

		if(isset($sdata['bookDetails'])) {
			$details = $sdata['bookDetails'];
			$details2 = $sdata['bookDetailsData']['variables'];
			$countryId = $sdata['bookDetailsData']['data']['restaurant']['country']['countryId'];
		} else {
			$details = $sdata['pageData'];
			$details['cookieId'] = $sdata['networkFlowParams']['cookieId'];
			$details2 = [
				'rid' => $sdata["restaurant"]["restaurantId"],
				'dateTime' => $sdata['reservationDateTime']['reservationDate'].'T'.$sdata["reservationDateTime"]["reservationTime"],
				'partySize' => $sdata['partySize']
			];
			$countryId = $sdata['restaurant']['country']['countryId'];
		}

		$post['restaurantId']          = $details2['rid'];
		$post['reservationDateTime']   = $details2['dateTime'];
		$post['partySize']             = $details2['partySize'];
		$post['slotAvailabilityToken'] = $sdata['timeSlot']['slotAvailabilityToken'];
		$post['correlationId']         = $details['correlationId'];
		$post['reservationType']       = $sdata['timeSlot']['reservationType'];
		$post['slotHash']              = $sdata['timeSlot']['slotHash'];
		//$post['reservationAttribute']  = $details['resoAttribute'];
//		$post['cookieId']              = $details['cookieId'];
		$post['attributionToken']      = $details['attributionToken'];
		$post['country']               = $countryId;

		if (!$sdata['slotLock']['slotLockId']) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          'Accept-Language: en-US,en;q=1',
          'Accept-Encoding: gzip, deflate',
          'Content-Type: application/json',
          "x-csrf-token: $csrftoken",
          'ot-referringservice: consumer-frontend',
          'Origin: https://www.opentable.com',
          "Referer: $action_url",
        ));

        curl_setopt($ch, CURLOPT_URL, 'https://www.opentable.com/dapi/fe/gql');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
          'operationName' => 'BookDetailsStandardSlotLock',
          'variables'     =>
            [
              'slotLockInput' =>
                [
                  'restaurantId'        => $details2['rid'],
                  'seatingOption'       => 'DEFAULT',
                  'reservationDateTime' => $details2['dateTime'],
                  'partySize'           => $details2['partySize'],
                  'databaseRegion'      => 'NA',
                  'slotHash'            => $sdata['timeSlot']['slotHash'],
                  'reservationType'     => strtoupper($sdata['timeSlot']['reservationType']),
                ],
            ],
          'extensions'    =>
            [
              'persistedQuery' =>
                [
                  'version'    => 1,
                  'sha256Hash' => 'a6fd6be7ff85e181d024d9e73333898fe9c7645740102ab64fd0eb30e79f6639',
                ],
            ],
        ]));
        $content = json_decode(curl_exec($ch));

        $sdata['slotLock']['slotLockId'] = $content->data->lockSlot->slotLock->slotLockId;

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_gen);
        curl_setopt($ch, CURLOPT_POST, false);
		}

    if (!$sdata['slotLock']['slotLockId']) {
      echo "no slot lock\r\n";
      return;
    }

    $post['slotLockId'] = $sdata['slotLock']['slotLockId'];

		if ($card_params) $post = array_merge($post, $card_params);
//		print_r($post);
		$post_str = json_encode($post);
//		echo "$post_str\r\n";

		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Accept-Language: en-US,en;q=1',
			'Accept-Encoding: gzip, deflate',
			'Content-Type: application/json',
			"x-csrf-token: $csrftoken",
			'ot-referringservice: consumer-frontend',
			'Origin: https://www.opentable.com',
			"Referer: $action_url",
		));

//		file_put_contents('21.html', $post_str);

		curl_setopt($ch, CURLOPT_URL, 'https://www.opentable.com/dapi/booking/make-reservation');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_str);
		$content = curl_exec($ch);

//		file_put_contents('22.html', $content);

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_gen);
        curl_setopt($ch, CURLOPT_POST, false);

		if (!preg_match('#"reservationId"#', $content, $m)) {
#			exit;
			file_put_contents('pages/'.$run_time.'-final1-failed.html', $content);
			$err = $m[1];
			if ($debug) echo "error reserving\r\n";
			$dbh->exec("UPDATE `tasks` SET tries=tries+1 WHERE id=$taskid;");
			return;
		}

		$data = json_decode($content, true);
		$query = array(
		    'confnumber' => $data['confirmationNumber'],
		    'reservationId' => $data['reservationId'],
		    'token' => $data['securityToken'],
		    'hash' => $data['reservationHash'],
		    'points' => $data['points'],
		    'd' => $details2['dateTime'],
		    'sd' => $details2['dateTime'],
		    'dateTime' => $details2['dateTime'],
		    'rid' => $data['restaurantId'],
		    'p' => $data['partySize'],
		    'ui' => 'new',
		    'corrid' => $details['correlationId'],
		    'user' => '1',
		    'anon' => '1',
		    'conv' => '1',
		    'reso' => '1',
		);

		$query_str = http_build_query($query, null, '&', PHP_QUERY_RFC3986);
		$url = 'https://www.opentable.com/book/view?'.$query_str;

		curl_setopt($ch, CURLOPT_URL, $url);
		$content = curl_exec($ch);

		$fname = $u['first_name'];
		$lname = $u['last_name'];

		if (preg_match('/Your Reservation is Confirmed/si', $content)|| preg_match('/Reservation confirmed/si', $content)) {
			if ($debug) echo "reserved for $fname $lname<br>\n";
			close_task($taskid, 'Reserved');
			return 1;
		} elseif (preg_match('/the email address provided has been used already/si', $content)) {
			if ($debug) echo "crossing with another reservation, cancelling<br>\n";
			close_task($taskid, 'Cancelled');
		} else {
			if ($debug) echo "unknown error reserving";
			file_put_contents('pages/'.$run_time.'-final2-failed.html', $content);
			$dbh->exec("UPDATE `tasks` SET tries=tries+1 WHERE id=$taskid;");
		}
		return;
	}
	if ($debug) echo "[not available]";
}


function datediff($d1, $d2) {
	$a = new DateTime($d1);
	$b = new DateTime($d2);
	$interval = $a->diff($b);

	$d = $interval->format("%d");
	$h = $interval->format("%h");
	$i = $interval->format("%i");
	$h+=$d*24;

	$diff = $h * 60 + $i;

	if ($interval->invert)
		$diff*=-1;

	return $diff;
}

function getClosest($target, $possible) {
    rsort($possible);
    $index = 0;
    foreach ($possible as $i => $value) {
		if ($value < $target) {
			break;
		} else {
			$index = $i;
		}
    }
    return $possible[$index];
}


function url_params($url) {
	$url_parts = parse_url($url);
	$querybits = explode('&', $url_parts['query']);
	$answer = array();
	foreach ($querybits as $pair) {
		list($name, $value) = explode('=', $pair);
		$answer[urldecode($name)] = urldecode($value);
	}
	return $answer;
}

function close_task($id, $status) {
global $dbh;
	$dbh->exec("UPDATE `tasks` SET `status` = '$status',`active` = 0 WHERE id=$id;");
}

