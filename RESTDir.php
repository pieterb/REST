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
 *
 * $Id$
 **************************************************************************/

/**
 * This file contains a newer and better directory display implementation.
 * @package REST
 */

/**
 * Renders directory content in various formats.
 * @package REST
 */
class RESTDir {

  
  /**
   * @var string plain text
   */
  protected $title;

  
  /**
   * @var string html
   */
  protected $html_form = null;


  /**
   * @var array array of strings
   */
  protected $headers = null;


  /**
   * @var bool
   */
  protected $header_sent = false;


  /**
   * Abstract class has protected ctor;
   */
  protected function __construct($title) {
    $this->title     = $title;
  }
  
  
  /**
   * @param string $form HTML of the form
   * @return RESTDir $this
   */
  public function setForm($form) {
    $this->html_form = $form;
    return $this;
  }


  /**
   * @param array $headers array of strings of the headers, in appearing order.
   * @return RESTDir $this
   */
  public function setHeaders() {
    $this->headers = func_get_args();
    return $this;
  }


  /**
   * @param $title string plain text
   * @return RESTDir
   */
  public static function factory( $title = null ) {
    if ($title === null) {
      preg_match('@^(.*)/@', $_SERVER['REQUEST_URI'], $matches );
      $title = 'Index for ' . htmlspecialchars( $matches[1] . '/', ENT_COMPAT, 'UTF-8');
    }
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
      case 'text/html'            : return new RESTDirHTML($title);
      case 'text/tdv'             :
      case 'text/plain'           : return new RESTDirPlain($title);
      case 'application/json'     : return new RESTDirJSON($title);
      case 'text/csv'             : return new RESTDirCSV($title);
    }
  }
  
  
  /**
   * @param $name string URL-encoded name
   * @param $size string
   * @param $description string HTML
   */
  public function line($name, $info = array()) {
    throw new Exception( 'Not implemented' );
  }


  /**
   * Ends the output.
   */
  public function end() {
    throw new Exception( 'Not implemented' );
  }


} // class RESTDir


/**
 * Displays content in plain text format (tab delimited)
 * @package REST
 */
class RESTDirPlain extends RESTDir {


  private function start() {
    if ($this->headers === null) $this->headers = array();
    echo 'Name';
    foreach ($this->headers as $header)
      echo "\t" . str_replace("\t", '\\t', $header);
    echo "\r\n";
    $this->header_sent = true;
  }

  
  /**
   * @param $name string
   * @return string
   */
  public function line($name, $info = array()) {
    unset($info['HTML']);
    if (!$this->header_sent) {
      if ($this->headers === null) {
        $this->headers = array_keys($info);
      }
      $this->start();
    }
    echo str_replace( array( "\t", "\r", "\n" ),
                      array( '\\t', '\\r', '\\n' ),
                      $name );
    foreach ($this->headers as $value)
      echo "\t" . (
        isset($info[$value])
          ? str_replace( array( "\t", "\r", "\n" ),
                         array( '\\t', '\\r', '\\n' ),
                         $info[$value] )
          : ''
      );
    echo "\r\n";
  }


  /**
   * Ends the output.
   * @return string
   */
  public function end() {
    if (!$this->header_sent) $this->start();
  }


} // class RESTDirPlain


/**
 * Displays content in plain text format (tab delimited)
 * @package REST
 */
class RESTDirCSV extends RESTDir {


  private function start() {
    if ($this->headers === null) $this->headers = array();
    echo '"Name"';
    foreach ($this->headers as $header)
      echo ',"' . str_replace('"', '""', $header) . '"';
    echo "\r\n";
    $this->header_sent = true;
  }

  
  /**
   * @param $name string
   * @return string
   */
  public function line($name, $info = array()) {
    unset($info['HTML']);
    if (!$this->header_sent) {
      if ($this->headers === null) {
        $this->headers = array_keys($info);
      }
      $this->start();
    }
    echo '"' . str_replace( '"', '""', $name );
    foreach ($this->headers as $value)
      echo '","' . (
        isset($info[$value])
          ? str_replace( '"', '""', $info[$value] )
          : ''
      );
    echo "\"\r\n";
  }


  /**
   * Ends the output.
   * @return string
   */
  public function end() {
    if (!$this->header_sent) $this->start();
  }


} // class RESTDirCSV


/**
 * Displays content in plain text format (tab delimited)
 * @package REST
 */
class RESTDirHTML extends RESTDir {


  private function start() {
    if ($this->headers === null) $this->headers = array();
    echo REST::html_start( $this->title ) . $this->html_form . <<<EOS
<h2>Contents</h2>
<table class="toc" id="directory_index"><tbody>
<tr><th class="delete"></th><th class="name">Name</th>
EOS;
    foreach ($this->headers as $header)
      echo '<th class="' . preg_replace('/[^\\w\\d]+/', '', $header) .
        '">' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</th>';
    echo "</tr>\n";
    $this->header_sent = true;
  }

  /**
   * @param $name string
   * @return string
   */
  public function line($name, $info = array()) {
    if (!$this->header_sent) {
      if ($this->headers === null)
        $this->headers = array_keys($info);
      $this->start();
    }
    // ⌧ (the erase sign)
    $expname = explode('?', $name, 2);
    $escname = htmlspecialchars(urldecode($expname[0]), ENT_COMPAT, 'UTF-8');
    #$escsize = htmlspecialchars($size, ENT_COMPAT, 'UTF-8');
    $is_dir = substr($name[0], -1) === '/';
    echo '<tr class="' . ( $is_dir ? 'collection' : 'resource' ) . '">' .
      '<td class="delete"><form action="' .
      htmlspecialchars($name, ENT_QUOTES, 'UTF-8') .
      (strstr($name, '?') === false ? '?' : '&') .
      'http_method=DELETE" method="post"><input type="submit" value="X" title="Delete ' .
      htmlspecialchars(urldecode($expname[0]), ENT_QUOTES, 'UTF-8') .
      '"/></form></td><td class="name"><a rel="child" href="' .
      htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">' .
      htmlspecialchars(urldecode($expname[0]), ENT_COMPAT, 'UTF-8') .
      '</a></td>';
    foreach ($this->headers as $header) {
      echo '<td class="' . strtolower(
        preg_replace('/[^\\w\\d]+/', '', $header)
      ) . '">';
      if (isset($info[$header]))
        echo ($header === 'HTML') ?
          $info[$header] :
          htmlspecialchars($info[$header], ENT_COMPAT, 'UTF-8');
      echo "</td>\n";
    }
    echo "</tr>\n";
  }


  /**
   * Ends the output.
   * @return string
   */
  public function end() {
    if (!$this->header_sent) $this->start();
    echo "</tbody></table>";
    echo REST::html_end();
  }


} // class RESTDirHTML


/**
 * Displays content in plain text format (tab delimited)
 * @package REST
 * @todo Should support streaming
 */
class RESTDirJSON extends RESTDir {


  /**
   * Contains a structure...
   */
  private $dir = null;

  private function start() {
#    $this->dir = array(
#      'header' => array('filename', 'size', 'description'),
#      'lines'  => array(),
#    );
  }

  public function line($name, $info = array()) {
    $info['Name'] = $name;
    if ($this->dir === null) $this->dir = array();
    $this->dir[] = $info;
  }

  public function end() {
    echo json_encode($this->dir);
  }

} // class RESTDirJSON
