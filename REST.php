<?php

/*·************************************************************************
 * Copyright © 2008 by Pieter van Beek <pieterb@sara.nl>                  *
 **************************************************************************/

###########################
# HANDLE METHOD SPOOFING: #
###########################
if ($_SERVER['REQUEST_METHOD'] == 'POST' and
    isset($_GET['http_method'])) {
  $http_method = strtoupper( $_GET['http_method'] );
  unset( $_GET['http_method'] );
  if ($http_method === 'GET' &&
      @$_SERVER['CONTENT_TYPE'] === 'application/x-www-form-urlencoded') {
    $_GET = $_POST;
    $_POST = array();
  }
  $_SERVER['QUERY_STRING'] = http_build_query($_GET);
  $_SERVER['REQUEST_URI'] =
    substr( $_SERVER['REQUEST_URI'], 0,
            strpos( $_SERVER['REQUEST_URI'], '?' ) );
  if ($_SERVER['QUERY_STRING'] != '')
    $_SERVER['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
  $_SERVER['REQUEST_METHOD'] = $http_method;
}


##############
# CLASS REST #
##############
/**
 * A singleton to REST-enable your scripts.
 */
class REST {


  /**
   * Require certain HTTP method(s).
   * This method takes a variable number of arguments.
   *
   * On failure, this method sends an HTTP/1.1 Method Not Allowed,
   * and doesn't return!
   * @return void
   */
  public static function require_method() {
    foreach (func_get_args() as $value)
      if ($_SERVER['REQUEST_METHOD'] === $value)
        return;
    self::fatal(self::HTTP_METHOD_NOT_ALLOWED);
  }
  
  
  /**
   * @var resource
   */
  private static $inputhandle = null;
  /**
   * @return resource filehandle
   */
  public static function inputhandle() {
    if (self::$inputhandle === null) {
      self::$inputhandle = tmpfile();
      $input = fopen('php://input', 'r');
      if ( isset( $_SERVER['CONTENT_LENGTH'] ) ) {
        $contentlength = $_SERVER['CONTENT_LENGTH'];
        while ( !feof($input) && $contentlength ) {
          $block = fread( $input, $contentlength );
          $contentlength -= strlen( $block );
          fwrite( self::$inputhandle, $block );
        }
      }
      elseif ( $_SERVER['HTTP_TRANSFER_ENCODING'] == 'chunked' ) {
        while ( !feof($input) )
          fwrite( self::$inputhandle, fgetc( $input ) );
      }
      fclose( $input );
      fseek(self::$inputhandle, 0);
    }
    return self::$inputhandle;
  }
  
  
  
  /**
   * Returns an HTTP date as per HTTP/1.1 definition.
   * @param int $timestamp A unix timestamp
   * @return string
   */
  public static function http_date($timestamp) {
    return gmdate( 'D, d M Y H:i:s \\G\\M\\T', $timestamp );
  }
  
  
  /**
   * Check the If-Modified-Since request header.
   * @param $timestamp int Unix timestamp of the last modification of the current
   *        resource.
   * @return bool TRUE if the current resource has been modified, otherwise FALSE.
   */
  public static function check_if_modified_since( $timestamp ) {
    if (empty($_SERVER['HTTP_IF_MODIFIED_SINCE']))
      return true;
    return strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) < $timestamp;
  }
  
  
  /**
   * Parsed version of $_SERVER['HTTP_ACCEPT'].
   * @var array
   */
  private static $HTTP_ACCEPT = null;
  /**
   * Parsed version of $_SERVER['HTTP_ACCEPT']
   * @return array array( 'mime_type' => array( 'qualifier' => &lt;value&gt;, ...), ... )
   */
  public static function http_accept() {
    if (self::$HTTP_ACCEPT === null) {
      self::$HTTP_ACCEPT = array();
      // Initialize self::$HTTP_ACCEPT
      if (!empty($_SERVER['HTTP_ACCEPT'])) {
        $mime_types = explode(',', $_SERVER['HTTP_ACCEPT']);
        foreach ($mime_types as $value) {
          $value = split(';', $value);
          $mt = trim(array_shift($value));
          self::$HTTP_ACCEPT[$mt]['q'] = 1.0;
          foreach ($value as $v)
            if (preg_match('/^\s*([^\s=]+)\s*=\s*([^;]+?)\s*$/', $v, $matches))
              self::$HTTP_ACCEPT[$mt][$matches[1]] =
                is_numeric($matches[2]) ? (float)$matches[2] : $matches[2];
        }
      }
    }
    return self::$HTTP_ACCEPT;
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
  public static function best_content_type($mime_types, $fallback = null) {
    $retval = $fallback;
    $best = -1;
    foreach (self::http_accept() as $key => $value) {
      $regexp = preg_quote( $key, '|' );
      $regexp = str_replace('\\*', '[^/]*', $regexp);
      foreach ($mime_types as $mkey => $mvalue) {
        if (preg_match("|^{$regexp}(?:\\s*;.*)?\$|", $mkey)) {
          $q = (float)($value['q']) * (float)($mvalue);
          if ($q > $best) {
            $best = $q;
            $retval = $mkey;
          }
        }
      }
    }
    if (is_null($retval)) {
      self::fatal(
        'NOT_ACCEPTABLE',
        '<p>Sorry, we couldn\'t agree on a mime-type. I can serve any of the following:</p><ul><li>' .
        join( '</li><li>', array_keys( $mime_types ) ) . '</li></ul>'
      );
    }
    return $retval;
  }
  
  
  public static function best_xhtml_type() {
    return (strstr(@$_SERVER['HTTP_USER_AGENT'], 'MSIE') === false) ?
      'application/xhtml+xml' : 'text/html';
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
  public static function header($properties) {
    if (is_string($properties))
      $properties = array( 'Content-Type' => $properties );
    if (isset($properties['status'])) {
      header(
        $_SERVER['SERVER_PROTOCOL'] . ' ' .
        self::status_code($properties['status'])
      );
      unset( $properties['status'] );
    }
    if (isset($properties['Location']))
      $properties['Location'] = self::rel2url($properties['Location']);
    foreach($properties as $key => $value)
      header("$key: $value");
  }
  
  
  /**
   * Cache for urlbase().
   * @var string
   */
  private static $URLBASE = null;
  /**
   * Returns the base URI.
   * The base URI is 'protocol://server.name:port'
   * @return string
   */
  public static function urlbase() {
    if ( is_null( self::$URLBASE ) ) {
      //DAV::debug('$_SERVER: ' . var_export($_SERVER, true));
      self::$URLBASE = empty($_SERVER['HTTPS']) ?
        'http://' : 'https://';
      self::$URLBASE .= $_SERVER['SERVER_NAME'];
      if ( !empty($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != 443 or
            empty($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != 80 )
        self::$URLBASE .= ":{$_SERVER['SERVER_PORT']}";
    }
    return self::$URLBASE;
  }
  
  
  /**
   * Yet another URL encoder.
   * See the code for details.
   * @param string $url
   * @return string
   */
  public static function urlencode($url) {
    $newurl = '';
    for ($i = 0; $i < strlen($url); $i++) {
      $ord = ord($url[$i]);
      if ( $ord >= ord('a') && $ord <= ord('z') ||
           $ord >= ord('A') && $ord <= ord('Z') ||
           $ord >= ord('0') && $ord <= ord('9') ||
           strpos( '/-_.!~*\'()', $url[$i] ) !== false )
           // Strictly spoken, the tilde ~ should be encoded as well, but I
           // don't do that. This makes sure URL's like http://some.com/~user/
           // don't get mangled, at the risk of problems during transport.
        $newurl .= $url[$i];
      else
        $newurl .= sprintf('%%%2X', $ord);
    }
    return $newurl;
  }
  
  
  /**
   * Translate an relative URL to a full URL.
   * @param string $relativeURL
   * @return string
   */
  public static function rel2url( $relativeURL ) {
    if (!preg_match('/^\\w+:/', $relativeURL))
      $relativeURL = self::urlbase() . $relativeURL;
    return $relativeURL;
  }
  
  
  /**
   * Sends error code to client
   * @param $status string The status code to send to the client
   * @param $message string The message in the content body
   * @return void
   */
  public static function error($status, $message = '', $stylesheet = null) {
    global $REST_STYLESHEET;
    if ($stylesheet === null) $stylesheet = @$REST_STYLESHEET;
    self::header(array(
      'status'       => $status,
      'Content-Type' => self::best_xhtml_type() . '; charset=UTF-8'
    ));
    if ($status >= 500)
      mail( $_SERVER['SERVER_ADMIN'], 'Portal error',
            "$message\n\n" . var_export(debug_backtrace(), true) . "\n\n" .
            var_export($_SERVER, true) );
    if (!preg_match('/^\\s*</s', $message))
    	$message = "<p id=\"message\">$message</p>";
    if (!empty($stylesheet))
      $stylesheet = '<link rel="stylesheet" type="text/css" href="' .
        $stylesheet . '" />';
    $status_code = self::status_code($status);
    echo self::xml_header() . <<<EOS
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
  <head>
    <title>$status_code</title>$stylesheet
  </head>
  <body>
    <h1 id="status_code">HTTP/1.1 $status_code</h1>
    $message
  </body>
</html>
EOS;
  }
  
  
  /**
   * Calls error() and exits.
   * @return void This function never returns.
   */
  public static function fatal() {
    $args = func_get_args();
    call_user_func_array( array('REST', 'error'), $args );
    exit;
  }
  
  
  /**
   * The default xml header
   * @return string The xml header with proper version and encoding
   */
  public static function xml_header($encoding = 'UTF-8', $version = '1.0') {
    return "<?xml version=\"$version\" encoding=\"$encoding\"?>\n";
  }
  
  
  public static function isValidURI($uri) {
    return preg_match('@^[a-z]+:(?:%[a-fA-F0-9]{2}|[-\\w.~:/?#\\[\\]\\@!$&\'()*+,;=]+)+$@', $uri);
  }
  
  
  /**
   * An xsl parser directive header
   * @param $url string URL of the stylesheet.
   * @return string An xsl parser directive, pointing at <code>$url</code>
   */
  public static function xsl_header($url) {
    $url = htmlentities(self::rel2url($url));
    return "<?xml-stylesheet type=\"text/xsl\" href=\"$url\"?>\n";
  }
  
  
  const HTTP_CONTINUE                        = 100;
  const HTTP_SWITCHING_PROTOCOLS             = 101;
  const HTTP_PROCESSING                      = 102;
  const HTTP_OK                              = 200;
  const HTTP_CREATED                         = 201;
  const HTTP_ACCEPTED                        = 202;
  const HTTP_NON_AUTHORITATIVE_INFORMATION   = 203;
  const HTTP_NO_CONTENT                      = 204;
  const HTTP_RESET_CONTENT                   = 205;
  const HTTP_PARTIAL_CONTENT                 = 206;
  const HTTP_MULTI_STATUS                    = 207;
  const HTTP_MULTIPLE_CHOICES                = 300;
  const HTTP_MOVED_PERMANENTLY               = 301;
  const HTTP_FOUND                           = 302;
  const HTTP_SEE_OTHER                       = 303;
  const HTTP_NOT_MODIFIED                    = 304;
  const HTTP_USE_PROXY                       = 305;
  const HTTP_SWITCH_PROXY                    = 306;
  const HTTP_TEMPORARY_REDIRECT              = 307;
  const HTTP_BAD_REQUEST                     = 400;
  const HTTP_UNAUTHORIZED                    = 401;
  const HTTP_PAYMENT_REQUIRED                = 402;
  const HTTP_FORBIDDEN                       = 403;
  const HTTP_NOT_FOUND                       = 404;
  const HTTP_METHOD_NOT_ALLOWED              = 405;
  const HTTP_NOT_ACCEPTABLE                  = 406;
  const HTTP_PROXY_AUTHENTICATION_REQUIRED   = 407;
  const HTTP_REQUEST_TIMEOUT                 = 408;
  const HTTP_CONFLICT                        = 409;
  const HTTP_GONE                            = 410;
  const HTTP_LENGTH_REQUIRED                 = 411;
  const HTTP_PRECONDITION_FAILED             = 412;
  const HTTP_REQUEST_ENTITY_TOO_LARGE        = 413;
  const HTTP_REQUEST_URI_TOO_LONG            = 414;
  const HTTP_UNSUPPORTED_MEDIA_TYPE          = 415;
  const HTTP_REQUESTED_RANGE_NOT_SATISFIABLE = 416;
  const HTTP_EXPECTATION_FAILED              = 417;
  const HTTP_UNPROCESSABLE_ENTITY            = 422;
  const HTTP_LOCKED                          = 423;
  const HTTP_FAILED_DEPENDENCY               = 424;
  const HTTP_UNORDERED_COLLECTION            = 425;
  const HTTP_UPGRADE_REQUIRED                = 426;
  const HTTP_RETRY_WITH                      = 449;
  const HTTP_INTERNAL_SERVER_ERROR           = 500;
  const HTTP_NOT_IMPLEMENTED                 = 501;
  const HTTP_BAD_GATEWAY                     = 502;
  const HTTP_SERVICE_UNAVAILABLE             = 503;
  const HTTP_GATEWAY_TIMEOUT                 = 504;
  const HTTP_HTTP_VERSION_NOT_SUPPORTED      = 505;
  const HTTP_VARIANT_ALSO_VARIES             = 506;
  const HTTP_INSUFFICIENT_STORAGE            = 507;
  const HTTP_BANDWIDTH_LIMIT_EXCEEDED        = 509;
  const HTTP_NOT_EXTENDED                    = 510;
  
  
  /**
   * HTTP Status Codes
   * @var array
   */
  private static $STATUS_CODES = array(
    self::HTTP_CONTINUE                        => '100 Continue',
    self::HTTP_SWITCHING_PROTOCOLS             => '101 Switching Protocols',
    self::HTTP_PROCESSING                      => '102 Processing', # A WebDAV extension
    self::HTTP_OK                              => '200 OK',
    self::HTTP_CREATED                         => '201 Created',
    self::HTTP_ACCEPTED                        => '202 Accepted',
    self::HTTP_NON_AUTHORITATIVE_INFORMATION   => '203 Non-Authoritative Information', # HTTP/1.1 only
    self::HTTP_NO_CONTENT                      => '204 No Content',
    self::HTTP_RESET_CONTENT                   => '205 Reset Content',
    self::HTTP_PARTIAL_CONTENT                 => '206 Partial Content',
    self::HTTP_MULTI_STATUS                    => '207 Multi-Status', # A WebDAV extension
    self::HTTP_MULTIPLE_CHOICES                => '300 Multiple Choices',
    self::HTTP_MOVED_PERMANENTLY               => '301 Moved Permanently',
    self::HTTP_FOUND                           => '302 Found',
    self::HTTP_SEE_OTHER                       => '303 See Other', # HTTP/1.1 only
    self::HTTP_NOT_MODIFIED                    => '304 Not Modified',
    self::HTTP_USE_PROXY                       => '305 Use Proxy', # HTTP/1.1 only
    self::HTTP_SWITCH_PROXY                    => '306 Switch Proxy',
    self::HTTP_TEMPORARY_REDIRECT              => '307 Temporary Redirect', # HTTP/1.1 only
    self::HTTP_BAD_REQUEST                     => '400 Bad Request',
    self::HTTP_UNAUTHORIZED                    => '401 Unauthorized',
    self::HTTP_PAYMENT_REQUIRED                => '402 Payment Required',
    self::HTTP_FORBIDDEN                       => '403 Forbidden',
    self::HTTP_NOT_FOUND                       => '404 Not Found',
    self::HTTP_METHOD_NOT_ALLOWED              => '405 Method Not Allowed',
    self::HTTP_NOT_ACCEPTABLE                  => '406 Not Acceptable',
    self::HTTP_PROXY_AUTHENTICATION_REQUIRED   => '407 Proxy Authentication Required',
    self::HTTP_REQUEST_TIMEOUT                 => '408 Request Timeout',
    self::HTTP_CONFLICT                        => '409 Conflict',
    self::HTTP_GONE                            => '410 Gone',
    self::HTTP_LENGTH_REQUIRED                 => '411 Length Required',
    self::HTTP_PRECONDITION_FAILED             => '412 Precondition Failed',
    self::HTTP_REQUEST_ENTITY_TOO_LARGE        => '413 Request Entity Too Large',
    self::HTTP_REQUEST_URI_TOO_LONG            => '414 Request-URI Too Long',
    self::HTTP_UNSUPPORTED_MEDIA_TYPE          => '415 Unsupported Media Type',
    self::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE => '416 Requested Range Not Satisfiable',
    self::HTTP_EXPECTATION_FAILED              => '417 Expectation Failed',
    self::HTTP_UNPROCESSABLE_ENTITY            => '422 Unprocessable Entity', # A WebDAV/RFC2518 extension
    self::HTTP_LOCKED                          => '423 Locked', # A WebDAV/RFC2518 extension
    self::HTTP_FAILED_DEPENDENCY               => '424 Failed Dependency', # A WebDAV/RFC2518 extension
    self::HTTP_UNORDERED_COLLECTION            => '425 Unordered Collection',
    self::HTTP_UPGRADE_REQUIRED                => '426 Upgrade Required', # an RFC2817 extension
    self::HTTP_RETRY_WITH                      => '449 Retry With', # a Microsoft extension
    self::HTTP_INTERNAL_SERVER_ERROR           => '500 Internal Server Error',
    self::HTTP_NOT_IMPLEMENTED                 => '501 Not Implemented',
    self::HTTP_BAD_GATEWAY                     => '502 Bad Gateway',
    self::HTTP_SERVICE_UNAVAILABLE             => '503 Service Unavailable',
    self::HTTP_GATEWAY_TIMEOUT                 => '504 Gateway Timeout',
    self::HTTP_HTTP_VERSION_NOT_SUPPORTED      => '505 HTTP Version Not Supported',
    self::HTTP_VARIANT_ALSO_VARIES             => '506 Variant Also Negotiates', # an RFC2295 extension
    self::HTTP_INSUFFICIENT_STORAGE            => '507 Insufficient Storage (WebDAV)', # A WebDAV extension
    self::HTTP_BANDWIDTH_LIMIT_EXCEEDED        => '509 Bandwidth Limit Exceeded',
    self::HTTP_NOT_EXTENDED                    => '510 Not Extended', # an RFC2774 extension
  );
  /**
   * @param $name string some string
   * @return unknown_type
   */
  public static function status_code($code) {
    if (!isset(self::$STATUS_CODES[$code]))
      throw new Exception("Unknown status code $code");
    return self::$STATUS_CODES[$code];
  }


} // class REST


/**
 * Renders directory content in various formats.
 */
class RESTDirectory {


  /**
   * @var string
   */
  protected $html_form = "";


  /**
   * @var bool
   */
  protected $header_sent = false;

  
  /**
   * Abstract class has protected ctor;
   */
  protected function __construct($form) {
    $this->html_form = $form;
  }


  /**
   * @return object RESTDirectory
   */
  public static function factory() {
    $best_xhtml_type = REST::best_xhtml_type();
    $type = REST::best_content_type(
    array(
    $best_xhtml_type => 1.0,
        'text/plain' => 0.3,
        'text/tdv' => 0.5,
        'text/csv' => 0.8,
        'application/json' => 1.0,
    ), $best_xhtml_type
    );
    REST::header("{$type}; charset=UTF-8");
    switch ($type) {
      case 'application/xhtml+xml':
      case 'text/html'            : return new RESTDirectoryHTML();
      case 'text/tdv'             :
      case 'text/plain'           : return new RESTDirectoryPlain();
      case 'application/json'     : return new RESTDirectoryJSON();
      case 'text/csv'             : return new RESTDirectoryCSV();
    }
  }

  
  public static function setHTML($html_start, $html_end) {
    RESTDirectoryHTML::setHTML($html_start, $html_end);
  }
  
  
  /**
   * @param $name string
   */
  public function line($name, $size = '', $description = '') {
    throw new Exception( 'Not implemented' );
  }


  /**
   * Ends the output.
   */
  public function end() {
    throw new Exception( 'Not implemented' );
  }


} // class RESTDirectory


/**
 * Displays content in plain text format (tab delimited)
 */
class RESTDirectoryPlain extends RESTDirectory {


  /**
   * @param $name string
   * @return string
   */
  public function line($name, $size = '', $description = '') {
    echo "{$name}\t{$size}\n";
  }


  /**
   * Ends the output.
   * @return string
   */
  public function end() {
    echo '';
  }


} // class RESTDirectoryPlain


/**
 * Displays content in plain text format (tab delimited)
 */
class RESTDirectoryCSV extends RESTDirectory {

  private function start() {
    echo "Name,Size,Description\r\n";
    $this->header_sent = true;
  }

  /**
   * @param $name string
   */
  public function line($name, $size = '', $description = '') {
    if (!$this->header_sent) {
      $this->start();
    }
    $name = str_replace('"', '""', $name);
    $size = str_replace('"', '""', $size);
    $description = str_replace('"', '""', $description);
    echo "\"{$name}\",\"{$size}\",\"{$description}\"\r\n";
  }


  /**
   * Ends the output.
   * @return string
   */
  public function end() {
    if (!$this->header_sent) {
      $this->start();
    }
    echo '';
  }


} // class RESTDirectoryCSV


/**
 * Displays content in plain text format (tab delimited)
 */
class RESTDirectoryHTML extends RESTDirectory {


  private function start() {
    call_user_func(self::$html_start);
    echo <<<EOS
<h1>Contents</h1>
<table class="toc" id="directory_index"><tbody>
<tr><th class="name">Name</th><th class="size">Size</th><th class="description">Description</th></tr>
EOS;
    $this->header_sent = true;
  }

  /**
   * @param $name string
   * @return string
   */
  public function line($name, $size = '', $description = '') {
    if (!$this->header_sent) {
      $this->start();
    }
    $is_dir = substr($name, -1) === '/';
    echo '<tr class="' . ( $is_dir ? 'collection' : 'resource' ) .
      '"><td class="name"><a rel="child" href="' . REST::urlencode($name) .
      '">' . htmlentities($name) . "</a></td>
      <td class=\"size\">{$size}</td><td class=\"description\">{$description}</td></tr>\n";
  }


  /**
   * Ends the output.
   * @return string
   */
  public function end() {
    if (!$this->header_sent) {
      $this->start();
    }
    echo "</tbody></table>";
    call_user_func(self::$html_end);
  }


  private static $html_start = array('RESTDirectoryHTML', 'html_start');
  private static $html_end   = array('RESTDirectoryHTML', 'html_end'  );

  
  public static function setHTML($html_start, $html_end) {
    self::$html_start = $html_start;
    self::$html_end   = $html_end;
  }
  
  
  public static function html_start() {
    echo REST::xml_header();
    $indexURL = dirname($_SERVER['REQUEST_URI']);
    if ($indexURL != '/') $indexURL .= '/';
  ?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en-us">
<head>
  <link rel="index" rev="child" type="application/xhtml+xml" href="<?php echo $indexURL; ?>" />
  <link rel="stylesheet" type="text/css" href="/style.css" />
  <title>Directory index</title>
</head><body>
<p id="p_index"><a id="a_index" rel="index" rev="child" href="<?php echo $indexURL; ?>">Index</a></p><?php
  }
  
  
  /**
   * Outputs HTML end-tags
   */
  public static function html_end() {
    echo '</body></html>';
  }

  
} // class RESTDirectoryHTML


/**
 * Displays content in plain text format (tab delimited)
 * TODO: Should support streaming
 */
class RESTDirectoryJSON extends RESTDirectory {


  /**
   * Contains a structure...
   */
  private $dir = null;

  private function start() {
    $this->dir = array(
      'header' => array('filename', 'size', 'description'),
      'lines'  => array(),
    );
  }

  public function line($name, $size = '', $description = '') {
    if (empty($this->dir))
      $this->start();
    $this->dir['lines'][] = array($name, $size, $description);
  }

  public function end() {
    if (empty($this->dir))
      $this->start();
    echo json_encode($this->dir);
  }

} // class RESTDirectoryJSON

