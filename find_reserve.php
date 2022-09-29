<?php
getReserveRestaurantInfo('https://reserve.com/r/frontera-grill-chicago');
function getReserveRestaurantInfo($public_url) {
        $datetime = new DateTime('tomorrow');
        $date = $datetime->modify('next month')->format('Y-m-d');

        $time = 1700;
        $party_size = 2;

        $parts = explode('?', $public_url);
        $url = $parts[0];
        $parts = explode('/', $url);
        $slug = end($parts);

        $url = 'https://api.reserve.com/api/v1/public/venues/search';

        $data = array(
                'party_size'  => $party_size,
                'platform'    => 'WEB',
                'start_date'  => $date,
                'end_date'    => $date,
                'time'        => $time,
                'time_radius' => 120,
                'search_type' => 'SLUG',
                'slugs'   => [$slug]
        );
        $data_string = json_encode($data);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch,CURLOPT_USERAGENT,'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string))
        );

        $result = curl_exec($ch);
        $result_json = json_decode($result);
	print_r($result_json);
        return $result_json;
}

?>
