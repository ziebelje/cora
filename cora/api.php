<?php

namespace cora;

/**
 * This is the base class of both crud and dictionary, which are base classes
 * for almost everything else. It provides access to a few useful things.
 *
 * @author Jon Ziebell
 */
abstract class api {

  /**
   * The current resource.
   */
  protected $resource;

  /**
   * The database object.
   */
  protected $database;

  /**
   * Construct and set the variables. Strip the namespace "cora\" from the
   * resource name.
   */
  final function __construct() {
    $this->resource = str_replace('cora\\', '', get_class($this));
    $this->database = database::get_instance();
  }

}

?>