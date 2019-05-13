<?php

/**
 * Emit deprecation notice
 *
 * @param string $methodOrClass
 * @return void
 */
function trigger_deprecation($methodOrClass)
{
  trigger_error($methodOrClass . ' is deprecated', E_USER_DEPRECATED);
}
