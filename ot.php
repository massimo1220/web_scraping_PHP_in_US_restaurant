<?php 
echo "hi"
$id = 270451;
$ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/74.0.3729.169 Safari/537.36';
$ch = curl_init();
curl_setopt($ch, CURLOPT_USERAGENT, $ua);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_COOKIEFILE, '');
curl_setopt($ch, CURLOPT_URL, "http://www.opentable.com/restaurant/profile/$id?rid=$id");
curl_exec($ch);


$redirectUrl = curl_getinfo($ch)['redirect_url'];
while($redirectUrl) {
	echo "redirect";
       $url = preg_replace('|^https?://www.opentable.com//www.opentable.com|', 'https://www.opentable.com', $redirectUrl);
       $ch = curl_init();
       curl_setopt($ch, CURLOPT_USERAGENT, $ua);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
       curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
       curl_setopt($ch, CURLOPT_COOKIEFILE, '');
       curl_setopt($ch, CURLOPT_URL, $url);
       $content = curl_exec($ch);
       $redirectUrl = curl_getinfo($ch)['redirect_url'];
}

//$name = (preg_match('/<[^>]*itemprop="name"[^>]*>\s*(.*?)\s*<\//s', $content, $matches)) ? $matches[1] : '';
$name = (preg_match('/<h1[^>]*itemprop="name"[^>]*>\s*(.*?)\s*<\//s', $content, $matches)) ? $matches[1] : '';
$address = (preg_match('/<[^>]*itemprop="streetAddress"[^>]*>\s*(.*?)\s*<\//s', $content, $matches)) ? $matches[1] : '';
$address = preg_replace('/\s*<br[^>]*>\s*/s', '; ', $address);

echo $name;
echo $address;
?>
