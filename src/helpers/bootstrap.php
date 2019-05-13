<?php

$_GET['_path'] = ltrim($_SERVER['REQUEST_URI'], '/');
NF::init(getenv('SERVER_SITENAME'));

// Set standard variables
$current_date = date('Y-m-d H:i:s');
$edit_tools = null;
$url_asset = null;
$tested_url = null;

// Load globals
NF::$site->loadGlobals();

// Start page generation
require NF::nfPath('helpers/functions.php');
require NF::nfPath('helpers/controller_page.php');

if ($page_id) {
  NF::$site->loadPage($page_id, $revision);
  $site = NF::$site;
  require NF::nfPath('helpers/build_template.php');
  die();
}

require NF::nfPath('helpers/build_error.php');
die();
