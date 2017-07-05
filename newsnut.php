<?php

// Big thanks to github.com/marcushat the RollingCurlX repo
// https://github.com/marcushat/RollingCurlX
include_once dirname(__FILE__) . '/lib/rollingcurlx.class.php';
$conf_path = dirname(__FILE__) . '/conf/newsnut.ini';
$INI = parse_ini_file($conf_path, TRUE);

function register_ini_settings() {
	global $INI, $LOCAL_TIMEZONE, $CUTOFF_TIME, $LAST_BUILD, $SLEEP_DURATION,
		$STREAM_SPEED, $MAX_WIDTH, $DEFAULT_FORMAT, $DEFAULT_TIMEZONE;

	if (!isset($INI['TIME_FORMAT']['_DEFAULT_']) || !isset($INI['SPECIAL_FEED_TIMEZONE']['_DEFAULT_'])) {
		die("Please append the following in $conf_path
	[TIME_FORMAT]
	_DEFAULT_='D, d M Y H:i:s e'

	[SPECIAL_FEED_TIMEZONE]
	_DEFAULT_=\"+0000\"");
	}

	if (isset($INI['MAIN']['DEFAULT_TIMEZONE'])) {
		date_default_timezone_set($INI['MAIN']['DEFAULT_TIMEZONE']);
		$LOCAL_TIMEZONE = new DateTimeZone($INI['MAIN']['DEFAULT_TIMEZONE']);
	}
	elseif(ini_get('date.timezone')) {
		$LOCAL_TIMEZONE = new DateTimeZone(date_default_timezone_get());
	}
	else {
		$LOCAL_TIMEZONE = new DateTimeZone("Europe/London");
	}

	if (!isset($INI['RSS_FEED'])) {
		echo "\e[92m" . "There are no RSS feeds configured in $conf_path" . "\e[0m";
		var_dump($INI);
		die;
	}

	$hrs = isset($INI['MAIN']['NEWS_SINCE']) ? (int) $INI['MAIN']['NEWS_SINCE'] : 5;
	$CUTOFF_TIME = time() - ($hrs * 60 * 60);
	$LAST_BUILD = array();
	$SLEEP_DURATION = isset($INI['MAIN']['FREQ']) ? (int) $INI['MAIN']['FREQ'] : 120;
	$STREAM_SPEED = isset($INI['MAIN']['STREAM_SPEED']) ? $INI['MAIN']['STREAM_SPEED'] * 1000000 : (0.05 * 1000000);
	$MAX_WIDTH = isset($INI['MAIN']['MAX_WIDTH']) ? (int) $INI['MAIN']['MAX_WIDTH'] : 60;

	$DEFAULT_FORMAT = $INI['TIME_FORMAT']['_DEFAULT_'];
	$DEFAULT_TIMEZONE = $INI['SPECIAL_FEED_TIMEZONE']['_DEFAULT_'];
}

function check_special_timeformat($ref) {
	global $INI, $DEFAULT_FORMAT, $DEFAULT_TIMEZONE;

	if (count($INI['TIME_FORMAT']) > 1) {
		foreach ($INI['TIME_FORMAT'] as $k => $v) {
			if (strpos($ref, $k) !== false) {
				$DEFAULT_FORMAT = $v;
			}
		}
	}

	if (count($INI['SPECIAL_FEED_TIMEZONE']) > 1) {
		foreach ($INI['SPECIAL_FEED_TIMEZONE'] as $k => $v) {
			if (strpos($ref, $k) !== false) {
				$DEFAULT_TIMEZONE = $v;
			}
		}
	}

	return array($DEFAULT_FORMAT, $DEFAULT_TIMEZONE);
}

function parse_rss_result($response, $url, $request_info, $rss_ref_code, $time) {
	global $INI, $NEWS, $CUTOFF_TIME, $LOCAL_TIMEZONE, $LAST_BUILD;

	$xml = @simplexml_load_string($response);
	if (!$xml) {
		echo "ERROR: Could not load RSS feed $rss_ref_code";
		// var_dump($response);
	}

	if (isset($xml->channel->lastBuildDate)) {
		if (isset($LAST_BUILD[$rss_ref_code])) {
			// This means that the lastBuildDate in the RSS hasn't changed, therefore no reason to parse response
			if ($LAST_BUILD[$rss_ref_code] === (string) $xml->channel->lastBuildDate) {
				return null;
			}
		}
		else {
			$LAST_BUILD[$rss_ref_code] = (string) $xml->channel->lastBuildDate;
		}
	}

	$channel_title = (isset($xml->channel->title)) ? $xml->channel->title : '';
	$item_array = (isset($xml->channel->item)) ? $xml->channel->item : array();

	foreach ($item_array as $item) {
		$date_str = $item->pubDate; // Sat, 01 Jul 2017 12:34:21 GMT
		if ($date_str) {
			if (!isset($date_format[$rss_ref_code]) || !isset($timezone[$rss_ref_code])) {
				list($date_format[$rss_ref_code], $timezone[$rss_ref_code]) = check_special_timeformat($rss_ref_code);
			}

			// Get date details from the string based on INI specification for the RSS feed
			$d = date_parse_from_format($date_format[$rss_ref_code], $date_str);
			// Create new date time object with the default/configured timezone. Convert it to local time and get the UNIX timestamp
			$d2 = new DateTime("{$d["month"]}/{$d["day"]}/{$d["year"]} {$d["hour"]}:{$d["minute"]}:{$d["second"]}", new DateTimezone($timezone[$rss_ref_code]));
			$d2->setTimeZone($LOCAL_TIMEZONE);
			$t = (integer) $d2->format("U");
		}
		else {
			$t = 0;
		}

		if ($t >= $CUTOFF_TIME) {
			// Incase different RSS publish news at the same time, store using different key. Sort it later!
			$get_ukey = true;
			$t *= 100;
			do {
				if (isset($NEWS[$t])) $t += 1;
				else $get_ukey = false;
			} while ($get_ukey);

			$msg = "\e[96m$channel_title\e[0m [{$d2->format('D, d M Y H:i:s')}] [$rss_ref_code]" . PHP_EOL
			. "\e[91m" . "## {$item->title} ## " . "\e[0m" . PHP_EOL;
			$desc = clean_string($item->description);
			$msg .=  !empty($desc) ? "\t $desc" . PHP_EOL . PHP_EOL : null;
			$link = isset($item->guid) ? $item->guid : (isset($item->link) ? $item->link : null);
			if ($link) {
				$link = "\e[2m" . $link . "\e[0m";
			}
			$trim_text = trim($link);
			$msg .= !empty($trim_text) ? $trim_text . PHP_EOL : null;
			$NEWS[$t] = $msg;
		}
	}
}

function print_news($news) {
	global $MAX_WIDTH, $STREAM_SPEED;
	ksort($news);
	foreach($news as $n) {
		$arr = explode(PHP_EOL, $n);
		foreach ($arr as $key => $line) {
			// Do not trim the info line or the headline that starts with ##
			$trim_line = ($key === 0 || strpos($line, '##') !== false) ? false : true;
			$words = explode(' ', $line);
			$length = 0;
			echo "\e[1m";
			foreach ($words as $k => $w) {
				$l = str_split($w, 1);
				foreach ($l as $i) {
					if ($i == '') continue;
					echo ($i == "\t") ? '     ' : $i;
					$length += ($i == "\t" ? 5 : 1);
					usleep($STREAM_SPEED);
				}
				if ($trim_line) {
					$next_word_length = isset($words[$k+1]) ? strlen($words[$k+1]) : 0;
					// echo ' <' . $length . ' + 1 + ' . $next_word_length . ' "' . $words[$k+1] . '">' . PHP_EOL; var_dump($words[$k+1]);
					// Length of current word, a whitespace in between, and the next
					if ($length + 1 + $next_word_length >= $MAX_WIDTH) {
						echo PHP_EOL;
						$length = 0;
					}
				}
				// $length = 0 would mean that the cursor is in a new line,
				// if so, do not start the newline with a space
				if ($length != 0) {
					echo ' ';
					$length += 1;					
					usleep($STREAM_SPEED);
				}
			}
			echo "\e[0m";
			echo PHP_EOL;
		}
	}
}

function clean_string($s) {
	$s = (string) $s;
	// ’ non unicode character is messed up in older terminals
	$s = strToHex($s);
	$s = str_replace('E28099', '27', $s);
	$s = hexToStr($s);

    $s = preg_replace('@<(\w+)\b.*?>.*?</\1>@si', '', $s);
    $s = trim(strip_tags($s));

	$arr = explode(PHP_EOL, $s);
	$arr = array_filter($arr);	// After stripping any HTML tags, remove "new lines"

	$s = implode(PHP_EOL, $arr);
	return $s;
}

register_ini_settings();

$rcx = new RollingCurlX(10);

while (true) {
	foreach ($INI['RSS_FEED'] as $src => $link) {
		$rcx->addRequest($link, null, 'parse_rss_result', $src, null, null);
	}

	$rcx->execute();
	
	if ($NEWS) {
		print_news($NEWS);
		$CUTOFF_TIME = time();
		$NEWS = null;
	}
	sleep($SLEEP_DURATION);
}

// Thanks to stackoverflow.com/users/160092/boomla
function strToHex($string){
    $hex = '';
    for ($i=0; $i<strlen($string); $i++){
        $ord = ord($string[$i]);
        $hexCode = dechex($ord);
        $hex .= substr('0'.$hexCode, -2);
    }
    return strToUpper($hex);
}

function hexToStr($hex){
    $string='';
    for ($i=0; $i < strlen($hex)-1; $i+=2){
        $string .= chr(hexdec($hex[$i].$hex[$i+1]));
    }
    return $string;
}
