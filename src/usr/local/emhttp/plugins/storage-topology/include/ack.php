<?PHP
/* Acknowledge or unacknowledge one problem (POST only). The webGUI's local_prepend.php checks csrf_token on every
 * POST before this file runs and stops the request when it is missing or wrong; csrf_terminate() existing means that
 * check ran. Only acks.json on the flash drive is written (see include/common.php).
 */
require_once __DIR__ . '/common.php';
header('Content-Type: application/json');
$fail = function (int $code, string $msg) { http_response_code($code); exit(json_encode(['ok' => false, 'error' => $msg])); };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !function_exists('csrf_terminate')) $fail(403, 'POST through the webGUI only');
$space = (string)($_POST['space'] ?? '');
$id = (string)($_POST['id'] ?? '');
$fp = (string)($_POST['fp'] ?? '');
$act = (string)($_POST['act'] ?? '');
if (!in_array($space, ST_ACK_SPACES, true) || !preg_match('/^[0-9a-f]{16}$/', $id) || !in_array($act, ['ack', 'unack'], true)
    || ($act === 'ack' && !preg_match('/^[0-9a-f]{12}$/', $fp))) $fail(400, 'bad request');
$ok = st_acks_write($space, $id, $act === 'ack' ? ['fp' => $fp, 'text' => (string)($_POST['text'] ?? '')] : null);
if (!$ok) $fail(500, 'could not write ' . ST_ACK_FILE);
echo json_encode(['ok' => true]);
