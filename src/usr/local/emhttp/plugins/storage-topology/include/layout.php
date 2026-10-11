<?PHP
/* Set or clear one shelf's bay layout on the storage page (POST only). As include/ack.php: the webGUI's
 * local_prepend.php checks csrf_token on every POST before this file runs; csrf_terminate() existing means that check
 * ran. Only layouts.json on the flash drive is written (see include/common.php).
 */
require_once __DIR__ . '/topology.php';
require_once __DIR__ . '/common.php';
header('Content-Type: application/json');
$fail = function (int $code, string $msg) { http_response_code($code); exit(json_encode(['ok' => false, 'error' => $msg])); };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !function_exists('csrf_terminate')) $fail(403, 'POST through the webGUI only');
$shelf = (string)($_POST['shelf'] ?? '');
$layout = (string)($_POST['layout'] ?? '');
if (!st_layout_valid($shelf, 'grid') || ($layout !== 'auto' && $layout !== 'grid' && !isset(TOPO_LAYOUTS[$layout]))) $fail(400, 'bad request');
if (!st_layout_write($shelf, $layout === 'auto' ? null : $layout)) $fail(500, 'could not write ' . ST_LAYOUT_FILE);
echo json_encode(['ok' => true]);
