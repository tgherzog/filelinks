<?php

/* This sample program returns meta-data for files on this server within recognized directories
   and file types, via a RESTful approach. This is designed to support the filelinks
   Drupal module, so that the module can display meta-data for files on remote servers.
   
   The API is:

   fileinfo.php?tag=location&path=path/to/file.ext&output=format

   tag and path are requireed; format is optional.

     tag:  a key that indicates the location (i.e., base directory/url) for the requested file.

	 path: path to the file, relative to the directory that maps to the tag parameter.

	 format: the output format: either json, text (plain text) or php (serialized php data). The
	         default is php. This parameter facilites testing; the FileLinks module does not use it
*/

// For security, we only recognize certain file types
$types = '/\.(pdf|txt|zip|png|jpe?g|gif|mp3|docx?|xlsx?|pptx?)$/';

$data = array('status' => 200, 'tag' => $_GET['tag'], 'path' => $_GET['path']);

if( preg_match($types, $_GET['path']) ):

/* replace dirmap with a map to your local file system, where tags in your FileLinks configuration
   are keys to the array, and array values specify the location and url that correponds to each tag
*/

$dirmap = array(
  // 'files' => array('dir' => '/home/myfiles', 'url' => 'http://files.mysite.com'),
);

if( $dir = $dirmap[$_GET['tag']] ) {
  $file = realpath("$dir[dir]/$_GET[path]");
  if( strpos($file, "$dir[dir]/") !== 0 ) {
	$data['status'] = 403;
	output($data);
  }
  elseif( ! file_exists($file) || ! is_file($file) ) {
	$data['status'] = 404;

	output($data);
  }
  else {
	# On this server the directory name happens to match the subdomain
	$data['url'] = "$dir[url]/$_GET[path]";

	$data['attributes'] = array();
	if( ($sz = @filesize($file)) !== false ) {
	  $data['attributes']['size'] = $sz;
	}

	$info = pathinfo($_GET['path']);
	$ext = strtolower($info['extension']);
	if( $ext == 'jpeg' ) $ext = 'jpg';

	$attr = 'get_attributes_' . $ext;
	if( function_exists($attr) ) {
	  $attr = $attr($file);
	  if( is_array($attr) )
		$data['attributes'] = array_merge($data['attributes'], $attr);
	}

	output($data);
  }
}

endif;

$data['status'] = 403;
output($data);



function output($data) {
  if( ! isset($_GET['output']) ) $_GET['output'] = 'php';
  switch($_GET['output']) {
	case 'json':
	  header('Content-type: application/json');
	  print drupal_to_js($data);
	  break;

	case 'text':
	  header('Content-type: text/plain');
	  print_r($data);
	  break;

    case 'php':
	  header('Content-type: text/plain');
	  print serialize($data);
	  break;

    default:
	  header('HTTP/1.1 400 Bad Request');
	  print "<h1>Bad Request</h1>\n";
  }

  exit;
}

function drupal_to_js($var) {
  switch (gettype($var)) {
    case 'boolean':
      return $var ? 'true' : 'false'; // Lowercase necessary!
    case 'integer':
    case 'double':
      return $var;
    case 'resource':
    case 'string':
      return '"'. str_replace(array("\r", "\n", "<", ">", "&"),
                              array('\r', '\n', '\x3c', '\x3e', '\x26'),
                              addslashes($var)) .'"';
    case 'array':
      // Arrays in JSON can't be associative. If the array is empty or if it
      // has sequential whole number keys starting with 0, it's not associative
      // so we can go ahead and convert it as an array.
      if (empty ($var) || array_keys($var) === range(0, sizeof($var) - 1)) {
        $output = array();
        foreach ($var as $v) {
          $output[] = drupal_to_js($v);
        }
        return '[ '. implode(', ', $output) .' ]';
      }
      // Otherwise, fall through to convert the array as an object.
    case 'object':
      $output = array();
      foreach ($var as $k => $v) {
        $output[] = drupal_to_js(strval($k)) .': '. drupal_to_js($v);
      }
      return '{ '. implode(', ', $output) .' }';
    default:
      return 'null';
  }
}

function get_attributes_pdf($f)
{

  $path = '/usr/local/bin/pdfinfo';
  exec(escapeshellcmd($path) . ' ' . escapeshellarg($f), $result);
  foreach($result as $line) {
    if( preg_match('#^Pages:\s+(\d+)#i', $line, $matches) ) {
	  return array('pages' => $matches[1]);
	}
  }
}

function get_attributes_png($file) {

  if( $f = fopen($file, 'rb') ) {
    fseek($f, 16);
	$attr = unpack("Nwidth/Nheight", fread($f, 8));
	fclose($f);
	return $attr;
  }
}
function get_attributes_gif($file) {

  if( $f = fopen($file, 'rb') ) {
    fseek($f, 6);
    $attr = unpack("vwidth/vheight", fread($f, 4));
	fclose($f);
	return $attr;
  }
}

function get_attributes_jpg($file) {

  if( $f = fopen($file, 'rb') ) {
    while( !feof($f) ) {
      $tag = unpack("nsize", fread($f, 2));
      $tag = $tag['size'];
      if( $tag == 0xFFD8 ) {
        // for some reason this tag has no length
		$len = 0;
      }
      else {
        $len = unpack("nlen", fread($f, 2));
		$len = $len['len'];
		$len -= 2;
      }

      if( $tag == 65472 || $tag == 65474 ) {
        $attr = unpack("cdummy/nheight/nwidth", fread($f, 6));
		unset($attr['dummy']);
		fclose($f);
		return $attr;
      }

      // advance to next tag
      fseek($f, $len, SEEK_CUR);
    }

	fclose($f);
  }
}

function get_attributes_mp3($file) {
  static $bitrates = array(
	1 => array(
	  /* Layer III */ 1 => array(0, 32, 40, 48,  56,  64,  80,  96, 112, 128, 160, 192, 224, 256, 320),
	  /* Layer II */  2 => array(0, 32, 48, 56,  64,  80,  96, 112, 128, 160, 192, 224, 256, 320, 384),
      /* Layer I */   3 => array(0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448),
	),
	0 => array(
	  /* Layer III */ 1 => array(0,  8, 16, 24,  32,  40,  48,  56,  64,  80,  96, 112, 128, 144, 160), // same as layer II
	  /* Layer II */  2 => array(0,  8, 16, 24,  32,  40,  48,  56,  64,  80,  96, 112, 128, 144, 160),
	  /* Layer I */   3 => array(0, 32, 48, 56,  64,  80,  96, 112, 128, 144, 160, 176, 192, 224, 256),
	),
  );

  static $freqrates = array(
    /* mpeg1 */   3  => array(44100, 48000, 32000),
	/* mpeg2 */   2  => array(22050, 24000, 16000),
	/* mpeg2.5 */ 0 => array(11025, 12000,  8000),
  );

  if( ! ($fh = fopen($file, 'rb')) ) {
    return;
  }

  # find end of stream: EOF, possibly less 128 bytes
  fseek($fh, 0, SEEK_END);
  $end = ftell($fh);
  fseek($fh, -128, SEEK_END);
  if( ($buf = fread($fh, 3)) === FALSE ) {
	fclose($fh);
    return;
  }

  if( $buf == 'TAG' ) {
    $end -= 128;
  }

  # go to beginning, find the first legitimate tag
  fseek($fh, 0);
  $buf = fread($fh, min($end, 8192));
  $len = strlen($buf);
  for($i=0;$i<$len;$i++) {
	if( ord(substr($buf, $i, 1)) == 0xFF ) {
	  list($h) = array_values(unpack('N', substr($buf, $i, 4)));
	  $hdr = array('value' => $h,
	    'framesync' => ($h>>21),
		'id' => ($h>>19) & 3, 			// mpeg audio version ID (if & 1, v1, else v2)
		'layer' => ($h>>17) & 3,		// layer description: 1: III, 2: II, 3: I
		'protect' => ($h>>16) & 1,		// 1 if unprotected
		'bitidx' => ($h>>12) & 15,		// bit rate index: use id and layer to lookup
		'sampidx' => ($h>>10) & 3,		// sampling frequency index: use id to lookup
		'padbit'   => ($h>>9) & 1,		// pad bit
		'channel' => ($h>>6) & 3,		// channel (mono, stereo)
		'mode_ext' => ($h>>4) & 3,		// channel mode extension
		'copy'    => ($h>>3) & 1,		// 1: copyrighted
		'orig'    => ($h>>2) & 1,		// 1: original (not duplicate)
		'emphasis' => ($h) & 3,			// emphasis
	  );

	  $hdr['bitrate'] = $bitrates[$hdr['id'] & 1][$hdr['layer']][$hdr['bitidx']];
	  $hdr['samplefreq'] = $freqrates[$hdr['id']][$hdr['sampidx']];

	  # sanity check. Is this a valid header?
	  if( $hdr['framesync'] == -1 &&		// must be all bits on
		$hdr['id'] != 1 && // reserved
		$hdr['bitidx'] != 0 && $hdr['bitidx'] != 15 && // reserved or bogus
		$hdr['sampidx'] != 3 &&	 // reserved
		$hdr['bitrate'] && $hdr['samplefreq'] &&
		$hdr['layer'] != 0 && 		// reserved
		$hdr['emphasis'] != 2 &&	// not sure what this means
		( ($hdr['id']&1)!=1 || $hdr['layer']!=3 || $hdr['protect']!= 1) // in v1, layer I is not allowed to be unprotected(?)
		) break;
	}
  }

  if( $i == $len ) {
    fclose($fh);
	return;
  }

  /* Note that in the rare case that this is a variable bitrate file, we are not
     actually yet at the start of the data stream, we may be short by as much as 152
	 bytes. This is just lazy, but since we only are estimating the playlength we
	 don't care. Even at low bitrates, 152 bytes means we're only off 4/100ths of a
	 second, so it's not worth the extra code.
  */

  return array('playlength' => round((($end-$i) * 8 / $hdr['bitrate']/10) / 100));
}
/* Must return:
   array(
	 'status' => 200, 403 or 404
     'url' => 'http://www.mysite.com/path/to/file.ext',
	 'path' => '/path/to/file.ext',
	 'attributes' => array(
	   'size' => filesize,
	   'pages' => pagecount (PDFs only),
	   'playlength' => play length (MP3s only),
	   'width' => width in pixels (images only),
	   'height' => height in pixels (images only),
	 ),
   );
*/
