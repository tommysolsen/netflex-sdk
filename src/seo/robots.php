<?php
// Sent the correct header so browsers display properly.
header('Content-Type: text/plain');
?>
#robots.txt generated by netflex.
#The content of this file can be modified in the settings panel.
User-agent: *
<?php if (getenv('ENV') !== master) {
  echo "Disallow: /";
} else {
  echo "Allow: /";
} ?>
