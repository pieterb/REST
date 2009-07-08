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
 * File documentation.
 * @package REST
 */

/**
 * Renders directory content in various formats.
 * @deprecated This class is deprecated in favor of class {@link RESTDir}.
 * @package REST
 */
class RESTDirectory {

  
  /**
   * @var string plain text
   */
  protected $title;

  
  /**
   * @var string html
   */
  protected $html_form;


  /**
   * @var bool
   */
  protected $header_sent = false;

  
  /**
   * Abstract class has protected ctor;
   */
  protected function __construct($title, $form) {
    $this->title     = $title;
    $this->html_form = $form;
  }


  /**
   * @param $title string plain text
   * @return object RESTDirectory
   */
  public static function factory( $title = null, $html_form = '' ) {
    if ($title === null)
      $title = 'Index for ' . htmlspecialchars(urldecode($_SERVER['REQUEST_URI']), ENT_COMPAT, 'UTF-8');
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
      case 'text/html'            : return new RESTDirectoryHTML($title, $html_form);
      case 'text/tdv'             :
      case 'text/plain'           : return new RESTDirectoryPlain($title, $html_form);
      case 'application/json'     : return new RESTDirectoryJSON($title, $html_form);
      case 'text/csv'             : return new RESTDirectoryCSV($title, $html_form);
    }
  }
  
  
  /**
   * @param $name string URL-encoded name
   * @param $size string
   * @param $description string HTML
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
 * @deprecated This class is deprecated in favor of class {@link RESTDirPlain}.
 * @package REST
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
 * @deprecated This class is deprecated in favor of class {@link RESTDirCSV}.
 * @package REST
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
    $escsize = str_replace('"', '""', $size);
    if ($escsize != $size) $escsize = "\"$escsize\"";
    $description = str_replace('"', '""', $description);
    echo "{$name},{$escsize},\"{$description}\"\r\n";
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
 * @deprecated This class is deprecated in favor of class {@link RESTDirHTML}.
 * @package REST
 */
class RESTDirectoryHTML extends RESTDirectory {


  private function start() {
    echo REST::html_start( $this->title ) . $this->html_form . <<<EOS
<h2>Contents</h2>
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
    // ⌧ (the erase sign)
    if (!$this->header_sent) {
      $this->start();
    }
    $expname = explode('?', $name, 2);
    $escname = htmlspecialchars(urldecode($expname[0]), ENT_COMPAT, 'UTF-8');
    $escsize = htmlspecialchars($size, ENT_COMPAT, 'UTF-8');
    $is_dir = substr($name[0], -1) === '/';
    echo '<tr class="' . ( $is_dir ? 'collection' : 'resource' ) . '">';
    echo <<<EOS
<td class="name"><a rel="child" href="{$name}">{$escname}</a></td>
<td class="size">{$escsize}</td>
<td class="description">{$description}</td>
</tr>
EOS;
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
    echo REST::html_end();
  }


} // class RESTDirectoryHTML


/**
 * Displays content in plain text format (tab delimited)
 * @package REST
 * @deprecated This class is deprecated in favor of class {@link RESTDirJSON}.
 * @todo Should support streaming
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
