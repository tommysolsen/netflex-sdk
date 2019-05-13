<?php

spl_autoload_register('modelsAutoloader');

function modelsAutoloader($className)
{
  $classPath = explode('\\', $className);
  if (count($classPath) && strtolower($classPath[0]) === 'models') {
    array_shift($classPath);
    $classPath = 'models' . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $classPath) . '.php';

    if (file_exists($classPath)) {
      include($classPath);
    }
  }
}
