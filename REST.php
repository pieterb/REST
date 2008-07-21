<?php

/*·************************************************************************
 * Copyright © 2008 by Pieter van Beek <pieterb@sara.nl>                  *
 **************************************************************************/

###########################
# HANDLE METHOD SPOOFING: #
###########################
if ($_SERVER['REQUEST_METHOD'] == 'POST' and
    isset($_GET['method'])) {
  $_GET['method'] = strtoupper($_GET['method']);
  if ($_GET['method'] == 'PUT') {
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    unset( $_GET['method'] );
  } elseif (in_array( $_GET['method'],
                      array( 'DELETE', 'GET', 'MKCOL' ) ) ) {
    $_SERVER['REQUEST_METHOD'] = $_GET['method'];
    $_GET = $_POST;
    $_POST = array();
  } else
    REST::inst()->fatal(
      'BAD_REQUEST',
      "You tried to spoof an HTTP {$_GET['method']} request in an HTTP {$_SERVER['REQUEST_METHOD']} request."
    );
  $_SERVER['QUERY_STRING'] = http_build_query($_GET);
  $_SERVER['REQUEST_URI'] = substr( $_SERVER['REQUEST_URI'], 0, strpos( $_SERVER['REQUEST_URI'], '?' ) );
  if ($_SERVER['QUERY_STRING'] != '')
    $_SERVER['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
}


##############
# CLASS REST #
##############
/**
 * A singleton to REST-enable your scripts.
 */
final class REST {


/** @var REST */
private static $INSTANCE = null;
/**
 * Singleton factory.
 * @return REST
 */
public static function inst() {
  if (is_null(self::$INSTANCE)) self::$INSTANCE = new REST();
  return self::$INSTANCE;
}
/** Constructor */
private function __construct() {}

/**
 * Cache for http_accept()
 * @var array
 */
private $HTTP_ACCEPT = null;
/**
 * Parsed version of $_SERVER['HTTP_ACCEPT']
 * @return array array( 'mime_type' => array( 'qualifier' => &lt;value&gt;, ...), ... )
 */
public function http_accept() {
  if ($this->HTTP_ACCEPT === null) {
    $this->HTTP_ACCEPT = array();
    // Initialize $this->HTTP_ACCEPT
    if (isset( $_SERVER['HTTP_ACCEPT'] ) &&
        $_SERVER['HTTP_ACCEPT'] !== '') {
      $mime_types = explode(',', $_SERVER['HTTP_ACCEPT']);
      foreach ($mime_types as $value) {
        $value = split(';', $value);
        $mt = array_shift($value);
        $this->HTTP_ACCEPT[$mt]['q'] = 1.0;
        foreach ($value as $v)
          if (preg_match('/^\s*([^\s=]+)\s*=\s*([^;]+?)\s*$/', $v, $matches))
            $this->HTTP_ACCEPT[$mt][$matches[1]] =
              is_numeric($matches[2]) ? (float)$matches[2] : $matches[2];
      }
    }
  }
  return $this->HTTP_ACCEPT;
}

/**
 * Computes the best Content-Type, based on client and server preferences.
 * In parameter <var>$mime_types</var>, the server can specify a list of
 * mime-types it supports. The client should specify its preferences
 * through the HTTP/1.1 'Accept' header.
 *
 * If no acceptable mime-type can be agreed upon, and <var>$fallback</var>
 * parameter isn't set, then an error is returned to the client and this
 * method doesn't return.
 * @param $mime_types array A list of mime-types, with their associated
 * quality, e.g.
 * <code>array('text/plain' => 0.8, 'text/html' => '1.0')</code>
 * @param $fallback string|null The mime-type to use if we can't agree on
 *                  a mime-type supported by both client and server.
 * @return string Please note that if <var>$fallback<var> isn't set, this
 *         method might not return at all.
 */
function best_content_type($mime_types, $fallback = null) {
  $retval = $fallback;
  $best = -1;
  foreach ($this->http_accept() as $key => $value) {
    $regexp = preg_quote( $key, '/' );
    $regexp = str_replace('\\*', '.*', $regexp);
    foreach ($mime_types as $mkey => $mvalue) {
      if (preg_match("/^{$regexp}\$/", $mkey)) {
        $q = (float)($value['q']) * (float)($mvalue);
        if ($q > $best) {
          $best = $q;
          $retval = $key;
        }
      }
    }
  }
  if (is_null($retval)) {
    $this->fatal(
      'NOT_ACCEPTABLE',
      "Sorry, we couldn't agree on a mime-type. I can serve any of the following:\n" .
      join( "\n", array_keys( $mime_types ) )
    );
  }
  return $retval;
}


##########################
# HTTP header generation #
##########################
/**
 * Outputs HTTP/1.1 headers.
 * @param $properties array|string An array of headers to print, e.g.
 * <code>array( 'Content-Language' => 'en-us' )</code> If there's a
 * key «status» in the array, it is used for the 'HTTP/1.X ...'
 * status header, e.g.<code>array(
 *   'status'       => 'CREATED',
 *   'Content-Type' => 'text/plain'
 * )</code> If <var>$properties</var> is a string, it is taken as the
 * Content-Type, e.g.<code>$rest->header('text/plain')</code> is exactly
 * equivalent to
 * <code>$rest->header(array('Content-Type' => 'text/plain'));</code>
 * @return REST $this
 * @see status_code()
 */
public function header($properties) {
  if (is_string($properties))
    $properties = array( 'Content-Type' => $properties );
  if (isset($properties['status'])) {
    header(
      $_SERVER['SERVER_PROTOCOL'] . ' ' .
      $this->status_code($properties['status'])
    );
    if ($this->i_webdav_provider !== null) {
      header(
        'X-WebDAV-Status: ' .
        $this->status_code($properties['status'])
      );
      header( 'X-Dav-Powered-By' . $this->i_webdav_provider );
    }
    unset( $properties['status'] );
  }
  if (isset($properties['Location']))
    $properties['Location'] = $this->path2url($properties['Location']);
  foreach($properties as $key => $value)
    header("$key: $value");
}


/**
 * @deprecated in favor of $urlbase
 */
private $base = null;
/**
 * @deprecated in favor of urlbase()
 */
public function base() {
  if ( is_null( $this->base ) ) {
    $this->base = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $this->base .= $_SERVER['SERVER_NAME'];
    if ( ! isset($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != 80 or
           isset($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != 443 )
      $this->base .= ":{$_SERVER['SERVER_PORT']}";
  }
  return $this->base;
}


/**
 * Cache for urlbase()
 * @var string
 */
private $urlbase = null;
/**
 * Returns the base URI.
 * The base URI is 'protocol://server.name:port'
 * @return string
 */
public function urlbase() {
  if ( is_null( $this->urlbase ) ) {
    $this->urlbase = (@$_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $this->urlbase .= $_SERVER['SERVER_NAME'];
    if ( ! (@$_SERVER['HTTPS'] === 'on') && $_SERVER['SERVER_PORT'] != 80 or
           (@$_SERVER['HTTPS'] === 'on') && $_SERVER['SERVER_PORT'] != 443 )
      $this->urlbase .= ":{$_SERVER['SERVER_PORT']}";
  }
  return $this->urlbase;
}


/**
 * Translate any path into a full URL, taking $_SERVER['SCRIPT_NAME'] as base.
 * @deprecated in favor of path2url()
 * @return string
 */
public function full_path( $path ) {
  if ( preg_match( '/^\\w+:/', $path ) ) # full path:
    return $path;
  if ( substr($path, 0, 1) == '/' ) # absolute path:
    return $this->base() . $path;
  # relative path:
  $dir = dirname($_SERVER['SCRIPT_NAME']);
  foreach (split( '/', $path ) as $value) {
    switch ($value) {
    case '..':
      $dir = dirname($dir);
      break;
    case '.':
      break;
    default:
      if ($dir != '/') $dir .= '/';
      $dir .= $value;
    }
  }
  return $this->base() . $dir;
}


/**
 * Returns $_SERVER['REQUEST_URI'] minus the query string.
 * @return string
 */
public function requestPath() {
  preg_match('/^([^?]*)/', $_SERVER['REQUEST_URI'], $matches);
  return $matches[1];
}


/**
 * Translate any path into a full URL, like a browser would.
 * @param string $p_path
 * @return string
 */
public function path2url( $p_path ) {
  if ( preg_match( '/^\\w+:/', $p_path ) ) # full path:
    return $p_path;
  if ( substr($p_path, 0, 1) == '/' ) # absolute path:
    return $this->urlbase() . $p_path;
  # relative path:
  preg_match('/^([^?]*)/', $_SERVER['REQUEST_URI'], $matches);
  $requestPath = $this->requestPath();
  $dir = substr( $requestPath, 0,
                 strrpos( $requestPath, '/' ) );
  if ($dir == '') $dir = '/';
  foreach (split( '/', $p_path ) as $value) {
    switch ($value) {
    case '..':
      $dir = dirname($dir);
      break;
    case '.':
      break;
    default:
      if ($dir != '/') $dir .= '/';
      $dir .= $value;
    }
  }
  return $this->urlbase() . $dir;
}


/**
 * Sends error code to client
 * @param $status string The status code to send to the client
 * @param $message string The message in the content body
 * @return void
 */
public function error($status, $message = '', $stylesheet = null) {
  $this->header(array(
    'status'       => $status,
    'Content-Type' => 'text/html; charset=utf-8'
  ));
  if ( $message !== '' &&
       ! preg_match( '/^\\s*</', $message ) )
    $message = "<p>$message</p>";
  if (!empty($stylesheet))
    $stylesheet = '<link rel="stylesheet" type="text/css" href="' .
      $this->full_path($stylesheet) . '" />';
  $status_code = $this->status_code($status);
  echo $this->xml_header() . <<<EOS
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
  <head>
    <title>$status_code</title>
    $stylesheet
  </head>
  <body>
    <h1 id="status_code">HTTP/1.1 $status_code</h1>
    $message
  </body>
</html>
EOS;
}


/**
 * Sends error code to client
 * @param $status string The status code to send to the client
 * @param $message string The message in the content body
 * @return void This function never returns.
 */
public function fatal($status, $message = '', $stylesheet = null) {
  $this->error($status, $message, $stylesheet);
  exit;
}


/**
 * The default xml header
 * @return string The xml header with proper version and encoding
 */
public function xml_header($encoding = 'UTF-8', $version = '1.0') {
  return "<?xml version=\"$version\" encoding=\"$encoding\"?>\n";
}


/**
 * An xsl parser directive header
 * @param $url string the url of the xsl stylsheet to use
 * @return string An xsl parser directive, pointing at <code>$url</code>
 */
public function xsl_header($url) {
  $url = htmlentities($this->full_path($url));
  return "<?xml-stylesheet type=\"text/xsl\" href=\"$url\"?>\n";
}

/**
 * WebDAV Provider
 * @var string
 */
private $i_webdav_provider = null;
/**
 * Get/set the WebDAV state.
 * @param string $p_webdav Optionally, the new WebDAV provider name
 * @return string the current WebDAV provider, or null.
 */
public function webdavProvider($p_provider = null) {
  $retval = $this->i_webdav_provider;
  if ( $p_provider !== null ) $this->i_webdav_provider = "$p_provider";
  return $retval;
}

# HTTP Status Codes:
private $STATUS_CODES = array(
  'CONTINUE'                       => '100 Continue',
  'SWITCHING_PROTOCOLS'            => '101 Switching Protocols',
  'PROCESSING'                     => '102 Processing', # A WebDAV extension
  'OK'                             => '200 OK',
  'CREATED'                        => '201 Created',
  'ACCEPTED'                       => '202 Accepted',
  'NON-AUTHORITATIVE_INFORMATION'  => '203 Non-Authoritative Information', # HTTP/1.1 only
  'NO_CONTENT'                     => '204 No Content',
  'RESET_CONTENT'                  => '205 Reset Content',
  'PARTIAL_CONTENT'                => '206 Partial Content',
  'MULTI-STATUS'                   => '207 Multi-Status', # A WebDAV extension
  'MULTIPLE_CHOICES'               => '300 Multiple Choices',
  'MOVED PERMANENTLY'              => '301 Moved Permanently',
  'FOUND'                          => '302 Found',
  'SEE_OTHER'                      => '303 See Other', # HTTP/1.1 only
  'NOT_MODIFIED'                   => '304 Not Modified',
  'USE_PROXY'                      => '305 Use Proxy', # HTTP/1.1 only
  'SWITCH_PROXY'                   => '306 Switch Proxy',
  'TEMPORARY_REDIRECT'             => '307 Temporary Redirect', # HTTP/1.1 only
  'BAD_REQUEST'                    => '400 Bad Request',
  'UNAUTHORIZED'                   => '401 Unauthorized',
  'PAYMENT_REQUIRED'               => '402 Payment Required',
  'FORBIDDEN'                      => '403 Forbidden',
  'NOT_FOUND'                      => '404 Not Found',
  'METHOD_NOT_ALLOWED'             => '405 Method Not Allowed',
  'NOT_ACCEPTABLE'                 => '406 Not Acceptable',
  'PROXY_AUTHENTICATION_REQUIRED'  => '407 Proxy Authentication Required',
  'REQUEST_TIMEOUT'                => '408 Request Timeout',
  'CONFLICT'                       => '409 Conflict',
  'GONE'                           => '410 Gone',
  'LENGTH_REQUIRED'                => '411 Length Required',
  'PRECONDITION_FAILED'            => '412 Precondition Failed',
  'REQUEST_ENTITY_TOO_LARGE'       => '413 Request Entity Too Large',
  'REQUEST-URI_TOO_LONG'           => '414 Request-URI Too Long',
  'UNSUPPORTED_MEDIA_TYPE'         => '415 Unsupported Media Type',
  'REQUESTED_RANGE_NOT_SATISFIABLE'=> '416 Requested Range Not Satisfiable',
  'EXPECTATION_FAILED'             => '417 Expectation Failed',
  'UNPROCESSABLE_ENTITY'           => '422 Unprocessable Entity', # A WebDAV/RFC2518 extension
  'LOCKED'                         => '423 Locked', # A WebDAV/RFC2518 extension
  'FAILED_DEPENDENCY'              => '424 Failed Dependency', # A WebDAV/RFC2518 extension
  'UNORDERED_COLLECTION'           => '425 Unordered Collection',
  'UPGRADE_REQUIRED'               => '426 Upgrade Required', # an RFC2817 extension
  'RETRY_WITH'                     => '449 Retry With', # a Microsoft extension
  'INTERNAL_SERVER_ERROR'          => '500 Internal Server Error',
  'NOT_IMPLEMENTED'                => '501 Not Implemented',
  'BAD_GATEWAY'                    => '502 Bad Gateway',
  'SERVICE_UNAVAILABLE'            => '503 Service Unavailable',
  'GATEWAY_TIMEOUT'                => '504 Gateway Timeout',
  'HTTP_VERSION_NOT_SUPPORTED'     => '505 HTTP Version Not Supported',
  'VARIANT_ALSO_VARIES'            => '506 Variant Also Negotiates', # an RFC2295 extension
  'INSUFFICIENT_STORAGE'           => '507 Insufficient Storage (WebDAV)', # A WebDAV extension
  'BANDWIDTH_LIMIT_EXCEEDED'       => '509 Bandwidth Limit Exceeded',
  'NOT_EXTENDED'                   => '510 Not Extended', # an RFC2774 extension
);
public function status_code($name) {
  if (!isset($this->STATUS_CODES[$name]))
    throw new Exception("Unknown status $name");
  return $this->STATUS_CODES[$name];
}


} # class REST

?>