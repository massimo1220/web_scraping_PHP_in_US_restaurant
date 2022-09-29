<?php
//echo date('Y-m-d');
$public_url = 'https://reserve.com/r/cinco-mexican-cantina-atlanta-atlanta';
$parts = explode('?', $public_url);
$url = $parts[0];
$parts = explode('/', $url);
print end($parts);
//print time()
//print max(30, 60);
//$date = new DateTime('2018-08-17 18:30:00');
//print_r($date->format('Hi'));
//print_r($date->modify('+90 minutes')->format('Hi'));


//$datetime = new DateTime('tomorrow');
//print $datetime->format('Y-m-d');


?>
