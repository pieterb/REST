<?php
/*·************************************************************************
 * Copyright ©2007-2009 Pieter van Beek <http://pieterjavanbeek.hyves.nl/>
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at <http://www.apache.org/licenses/LICENSE-2.0>
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 **************************************************************************/

/**
 * This is the primary include file for the REST PHP library.
 * After inclusion of this file, clients might want to call the following
 * methods:
 * <code>REST::handle_method_spoofing();
 * REST::setHTML('html_start', 'html_end');</code>
 * @package REST
 */

/**
 * Namespace to REST-enable your scripts.
 * @package REST
 */
class REST {


  /**
   * Handles method spoofing.
   * 
   * Callers should use this method as one of the first methods in their
   * scripts. This method does the following:
   * - The <em>real</em> HTTP method must be POST.
   * - Modify "environment variables" <var>$_SERVER['QUERY_STRING']</var>,
   *   <var>$_SERVER['REQUEST_URI']</var>,
   *   <var>$_SERVER['REQUEST_METHOD']</var>,
   *   <var>$_SERVER['CONTENT_LENGTH']</var>,
   *   <var>$_SERVER['CONTENT_TYPE']</var> as necessary.
   * @return void
   */
  public static function handle_method_spoofing() {
    if ($_SERVER['REQUEST_METHOD'] == 'POST' and
        isset($_GET['http_method'])) {
      $http_method = strtoupper( $_GET['http_method'] );
      unset( $_GET['http_method'] );
      if ( $http_method === 'GET' &&
           strstr( @$_SERVER['CONTENT_TYPE'],
                   'application/x-www-form-urlencoded' ) !== false ) {
        $_GET = $_POST;
        $_POST = array();
      }
      elseif ( $http_method === 'PUT' &&
               strstr( @$_SERVER['CONTENT_TYPE'],
                       'application/x-www-form-urlencoded' ) !== false &&
               isset($_POST['entity'])) {
        self::$inputhandle = tmpfile();
        fwrite( self::$inputhandle, $_POST['entity'] );
        fseek(self::$inputhandle, 0);
        $_SERVER['CONTENT_LENGTH'] = strlen($_POST['entity']);
        unset($_POST['entity']);
        if (isset($_POST['http_content_type'])) {
          $_SERVER['CONTENT_TYPE'] = $_POST['http_content_type'];
          unset($_POST['http_content_type']);
        } else
          $_SERVER['CONTENT_TYPE'] = 'application/octet-stream';
      }
      elseif ( $http_method === 'PUT' &&
               strstr( @$_SERVER['CONTENT_TYPE'],
                       'multipart/form-data' ) !== false &&
               @$_FILES['entity']['error'] === UPLOAD_ERR_OK ) {
        self::$inputhandle = fopen($_FILES['entity']['tmp_name'], 'r');
        $_SERVER['CONTENT_LENGTH'] = $_FILES['entity']['size'];
        $_SERVER['CONTENT_TYPE']   = $_FILES['entity']['type'];
      }
      $_SERVER['QUERY_STRING'] = http_build_query($_GET);
      $_SERVER['REQUEST_URI'] =
        substr( $_SERVER['REQUEST_URI'], 0,
                strpos( $_SERVER['REQUEST_URI'], '?' ) );
      if ($_SERVER['QUERY_STRING'] != '')
        $_SERVER['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
      $_SERVER['REQUEST_METHOD'] = $http_method;
    }
  }
  
  
  /**
   * Handles header spoofing.
   * 
   * Callers should call {@link handle_method_spoofing()} <i>before</i>
   * calling this method (if they want method spoofing, of course).
   * @return void
   * @internal not implemented
   */
  public static function handle_header_spoofing() {
    throw new Exception('', self::HTTP_NOT_IMPLEMENTED);
  }
  
  
  /**
   * Require certain HTTP method(s).
   * 
   * This method takes a variable number of arguments.
   *
   * On failure, this method sends an HTTP/1.1 405 Method Not Allowed,
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
   * Wrapper around fopen('php://input', 'r').
   * 
   * This wrapper is necessary to facilitate chunked transfer encoding and
   * method spoofing (in case PUT requests).
   * @return resource filehandle
   */
  public static function inputhandle() {
    if (self::$inputhandle === null) {
      if ( isset( $_SERVER['CONTENT_LENGTH'] ) ) {
        self::$inputhandle = fopen('php://input', 'r');
      }
      elseif ( $_SERVER['HTTP_TRANSFER_ENCODING'] == 'chunked' ) {
        self::$inputhandle = tmpfile();
        $input = fopen('php://input', 'r');
        while ( !feof($input) )
          fwrite( self::$inputhandle, fgetc( $input ) );
        fclose( $input );
        fseek(self::$inputhandle, 0);
      }
      else {
        self::fatal(self::HTTP_LENGTH_REQUIRED);
      }
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
   * @param int $timestamp Unix timestamp of the last modification of the current
   *        resource.
   * @param boolean $return true if this 
   * @return boolean true if this resource has been modified, otherwise false.
   * If parameter $return is false (which is the default), this method doesn't
   * return if the resource isn't modified, but instead sends an 
   * HTTP/1.1 304 Not Modified.
   */
  public static function check_if_modified_since( $timestamp, $return = false ) {
    if (empty($_SERVER['HTTP_IF_MODIFIED_SINCE']))
      return true;
    $retval = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) < $timestamp;
    if (!$retval && !$return)
      self::fatal(self::HTTP_NOT_MODIFIED);
    return $retval;
  }
  
  
  /**
   * Compares two ETag values.
   * @param string $a
   * @param string $b
   * @return bool true if equal, otherwise false
   * @throws Exception if both tags are malformed.
   */
  public static function equalETags( $a, $b ) {
    if ( !preg_match( '@^\\s*(?:W/)?("(?:[^"\\\\]|\\\\.)*")\\s*@',
                      $a, $a_matches ) &&
         !preg_match( '@^\\s*(?:W/)?("(?:[^"\\\\]|\\\\.)*")\\s*@',
                      $b, $b_matches ) )
      throw new Exception("Comparing null ETags: $a $b");
    return ( isset( $a_matches[1] ) &&
             isset( $b_matches[1] ) &&
             $a_matches[1] === $b_matches[1] );
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
   * @return string Please note that if <var>$fallback</var> isn't set, this
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
    $status = null;
    if (isset($properties['status'])) {
      $status = $properties['status'];
      unset( $properties['status'] );
    }
    if (isset($properties['Location']))
      $properties['Location'] = self::rel2url($properties['Location']);
    foreach($properties as $key => $value)
      header("$key: $value");
    if ($status !== null)
      header(
        $_SERVER['SERVER_PROTOCOL'] . ' ' .
        self::status_code($status)
      );
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
           strpos( '/-_.~', $url[$i] ) !== false )
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
  public static function error($status, $message = '') {
    self::header(array(
      'status'       => $status,
      'Content-Type' => self::best_xhtml_type() . '; charset=UTF-8'
    ));
    if ($status >= 500)
      mail( $_SERVER['SERVER_ADMIN'], 'REST service error',
            "$message\n\n" . var_export(debug_backtrace(), true) . "\n\n" .
            var_export($_SERVER, true) );
    if (!preg_match('/^\\s*</s', $message))
      $message = '<pre id="message">' . htmlspecialchars( $message, ENT_COMPAT, 'UTF-8' ) . '</pre>';
    $status_code = "HTTP/1.1 " . self::status_code($status);
    echo self::html_start($status_code) . $message . self::html_end();
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
   * Redirects to a URL.
   * @param int $status
   * @param string $url a URL
   * @internal in fact, this method is more generic than the name suggests.
   * $url can also be an array of URL's. This feature is used by self::created().
   */
  public static function redirect($status, $url) {
    $xhtml = self::best_xhtml_type();
    $bct = self::best_content_type(
      array(
        $xhtml => 1.0,
        'text/plain' => 1.0
      ), $xhtml
    );
    $header = array(
      'status' => $status,
      'Content-Type' => $bct
    );
    if (is_string($url))
      $header['Location'] = self::rel2url($url);
    self::header($header);
    if ($bct == 'text/plain') {
      if (is_array($url)) foreach($url as $value) echo "$value\n";
      else echo "$url";
    } else {
      echo self::html_start('HTTP/1.1 ' . self::status_code($status));
      if (is_array($url)) {
        echo '<ul>';
        foreach($url as $value)
          echo "<li><a href=\"$value\">$value</a></li>\n";
        echo '</ul>';
      }
      else {
        echo "<a href=\"$url\">$url</a>";
      }
      echo self::html_end();
    }
    exit;
  }
  
  
  /**
   * Sends a proper HTTP/1.1 Created page.
   * @param string $url a URL or an array of URL's. 
   */
  public static function created($url) {
    return self::redirect(self::HTTP_CREATED, $url);
  }
  
  
  /**
   * The default xml header
   * @return string The xml header with proper version and encoding
   */
  public static function xml_header($encoding = 'UTF-8', $version = '1.0') {
    return "<?xml version=\"$version\" encoding=\"$encoding\"?>\n";
  }
  
  
  /**
   * @var callback
   */
  private static $html_start = null;
  /**
   * @var callback
   */
  private static $html_end   = null;
  /**
   * Injects your own HTML generation functions instead of the default ones.
   * 
   * Both parameters must be of PHP's pseudo type "callback". See PHP's
   * documentation for details.
   * 
   * @param $html_start callback Must point to a method with the same signature
   *        as self::html_start().
   * @param $html_end callback Must point to a method with the same signature
   *        os self::html_end().
   */
  public static function setHTML($html_start, $html_end) {
    self::$html_start = $html_start;
    self::$html_end   = $html_end;
  }
  
  
  public static $STYLESHEET = null;
  /**
   * @param $title string Title in UTF-8
   * @return string a piece of UTF-8 encoded XHTML, including XML and DOCTYPE
   * headers.
   */
  public static function html_start($title) {
    if (self::$html_start !== null)
      return call_user_func(self::$html_start, $title);
    $t_title = htmlspecialchars($title, ENT_COMPAT, 'UTF-8');
    $t_index = REST::urlencode( dirname( $_SERVER['REQUEST_URI'] ) );
    if ($t_index != '/') $t_index .= '/';
    $t_stylesheet = self::$STYLESHEET ? self::$STYLESHEET : "{$t_index}style.css";
    return REST::xml_header() . <<<EOS
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en-us">
<head>
  <title>{$t_title}</title>
  <link rel="stylesheet" type="text/css" href="{$t_stylesheet}" />
  <link rel="index" rev="child" type="application/xhtml+xml" href="{$t_index}"/>
</head><body>
<div id="div_header">
<div id="div_index"><a rel="index" rev="child" href="{$t_index}">index</a></div>
<h1 id="h1_title">{$t_title}</h1>
</div>
EOS;
  }


  /**
   * Outputs HTML end-tags.
   * @return string a piece of UTF-8 encoded XHTML.
   */
  public static function html_end() {
    if (self::$html_end !== null)
      return call_user_func(self::$html_end);
    return '</body></html>';
  }
  
  
  /**
   * Encodes plain UTF-8 into HTML text.
   * @param string $string
   * @return string HTML
   */
  public static function htmlspecialchars($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
  }

  
  /**
   * @param $uri string
   * @return boolean
   */
  public static function isValidURI($uri) {
    return preg_match('@^[a-z]+:(?:%[a-fA-F0-9]{2}|[-\\w.~:/?#\\[\\]\\@!$&\'()*+,;=]+)+$@', $uri);
  }
  
  
  /**
   * An xsl parser directive header.
   * @param $url string URL of the stylesheet.
   * @return string An xsl parser directive, pointing at <code>$url</code>
   */
  public static function xsl_header($url) {
    $url = htmlentities(self::rel2url($url));
    return "<?xml-stylesheet type=\"text/xsl\" href=\"$url\"?>\n";
  }
  
  
  /**
   * RFC4648 base64url encoder
   * @param $string string the string to encode
   * @return string
   */
  public static function base64url_encode($string) {
    return tr(base64_encode($string), '+/', '-_');
  }
  
  
  /**
   * RFC4648 base64url decoder
   * @param $string string the base64url encoded string to decode
   * @return string
   */
  public static function base64url_decode($string) {
    return base64_decode(tr($string, '-_', '+/'));
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

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'RESTDirectory.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'RESTDir.php';
