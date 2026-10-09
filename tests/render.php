<?php
/* Renders one plugin page the way Unraid includes it: php tests/render.php <docroot> <Page.page> > page.html
 * $docroot/plugins/storage-topology must hold the plugin. Any PHP notice or warning goes to stderr.
 * Set ST_TOPO_CACHE to render the storage page from a fixture folder, ST_ACK_FILE for the acknowledgement store.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($no, $str, $file, $line) {
  if (!(error_reporting() & $no)) return true;            // @-suppressed
  fwrite(STDERR, "PHP[$no] $str at $file:$line\n");
  return true;
});
$docroot = $argv[1];
$display = ['unit' => 'C'];
$var = ['csrf_token' => 'TESTTOKEN'];
$src = file_get_contents("$docroot/plugins/storage-topology/{$argv[2]}");
eval('?>' . substr($src, strpos($src, "---\n") + 4));
