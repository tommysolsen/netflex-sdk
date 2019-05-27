<?php

class MockSite {
  /** @var array */
  public $variables = [];

  /** @var array */
  public $labels = [];

  /** @var array */
  public $content = [];

  /** @var array */
  public $templates = [];

  public function mockVariable ($alias, $value) {
    $this->variables[$alias] = $value;
  }

  public function mockLabel ($label, $value, $lang) {
    $this->labels[base64_encode($label)] = [
      $lang => $value
    ];
  }
}