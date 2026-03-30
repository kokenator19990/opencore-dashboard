<?php
/**
 * upload.php — Backend de archivos adjuntos para flujos.html
 * Copiar a: opencore.cl/dashboard/upload.php
 * Crear carpeta: opencore.cl/dashboard/uploads/ (permisos 755)
 *
 * Endpoints:
 *   GET  → devuelve JSON con todos los adjuntos por nodo
 *   POST action=upload   → sube archivo (multipart/form-data: nodeId, file)
 *   POST action=add_link → agrega link (nodeId, url, name)
 *   POST action=delete   → elimina adjunto (nodeId, id)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

define('DATA_FILE',  __DIR__ . '/flujos-files.json');
define('STATE_FILE', __DIR__ . '/flujos-state.json');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'https://opencore.cl/dashboard/uploads/');
define('MAX_SIZE',   10 * 1024 * 1024); // 10 MB
define('ALLOWED',    ['jpg','jpeg','png','gif','webp','pdf','xlsx','xls','docx','doc','pptx','ppt','txt','csv']);

// Crear carpeta de uploads si no existe
if (!is_dir(UPLOAD_DIR)) { mkdir(UPLOAD_DIR, 0755, true); }

// ── Cargar datos ──────────────────────────────────
function loadData() {
    if (!file_exists(DATA_FILE)) return [];
    $d = json_decode(file_get_contents(DATA_FILE), true);
    return is_array($d) ? $d : [];
}

function saveData($data) {
    file_put_contents(DATA_FILE, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// ── GET — devolver todos los adjuntos ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'load_state') {
        $wpId = $_GET['workspace'] ?? 'default';
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $wpId) ?: 'default';
        $file = __DIR__ . "/flujos-state-{$safeId}.json";
        
        if ($safeId === 'default' && !file_exists($file)) {
            $file = STATE_FILE; // Retrocompatibilidad
        }
        
        if (!file_exists($file)) { echo json_encode(['empty' => true]); exit; }
        echo file_get_contents($file);
        exit;
    }
    if ($action === 'load_index') {
        $file = __DIR__ . "/flujos-workspaces.json";
        if (!file_exists($file)) { echo json_encode(['empty' => true]); exit; }
        echo file_get_contents($file);
        exit;
    }
    // Default: return attachments
    echo json_encode(loadData(), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── UPLOAD ────────────────────────────────────
    if ($action === 'upload') {
        $nodeId = trim($_POST['nodeId'] ?? '');
        if (!$nodeId)          err('Falta nodeId');
        if (!isset($_FILES['file'])) err('Falta archivo');

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) err('Error en upload: ' . $file['error']);
        if ($file['size'] > MAX_SIZE)         err('Archivo demasiado grande (máx 10 MB)');

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED))         err('Tipo de archivo no permitido: ' . $ext);

        // Nombre seguro: id único + nombre limpio
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $filename  = uniqid('f') . '_' . $safeName;
        $dest      = UPLOAD_DIR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) err('No se pudo guardar el archivo', 500);

        $mime = mime_content_type($dest) ?: $file['type'];

        $entry = [
            'id'       => uniqid('a'),
            'type'     => 'file',
            'name'     => $file['name'],
            'filename' => $filename,
            'mime'     => $mime,
            'size'     => $file['size'],
            'url'      => UPLOAD_URL . $filename,
            'date'     => date('d/m/Y'),
        ];

        $data = loadData();
        $data[$nodeId][] = $entry;
        saveData($data);

        echo json_encode(['ok' => true, 'entry' => $entry]);
        exit;
    }

    // ── ADD LINK ──────────────────────────────────
    if ($action === 'add_link') {
        $nodeId = trim($_POST['nodeId'] ?? '');
        $url    = trim($_POST['url']    ?? '');
        $name   = trim($_POST['name']   ?? '') ?: $url;

        if (!$nodeId) err('Falta nodeId');
        if (!$url)    err('Falta url');

        $entry = [
            'id'   => uniqid('l'),
            'type' => 'link',
            'name' => $name,
            'url'  => $url,
            'date' => date('d/m/Y'),
        ];

        $data = loadData();
        $data[$nodeId][] = $entry;
        saveData($data);

        echo json_encode(['ok' => true, 'entry' => $entry]);
        exit;
    }

    // ── DELETE ────────────────────────────────────
    if ($action === 'delete') {
        $nodeId = trim($_POST['nodeId'] ?? '');
        $attId  = trim($_POST['id']     ?? '');

        if (!$nodeId || !$attId) err('Falta nodeId o id');

        $data = loadData();

        if (isset($data[$nodeId])) {
            foreach ($data[$nodeId] as $k => $entry) {
                if ($entry['id'] === $attId) {
                    // Eliminar archivo físico si existe
                    if (!empty($entry['filename'])) {
                        $path = UPLOAD_DIR . $entry['filename'];
                        if (file_exists($path)) unlink($path);
                    }
                    array_splice($data[$nodeId], $k, 1);
                    break;
                }
            }
            if (empty($data[$nodeId])) unset($data[$nodeId]);
        }

        saveData($data);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── SAVE FULL STATE ───────────────────────────
    if ($action === 'save_state') {
        $state = $_POST['state'] ?? '';
        if (!$state) err('Falta el estado (state)');
        
        $wpId = $_POST['workspace'] ?? 'default';
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $wpId) ?: 'default';
        $file = __DIR__ . "/flujos-state-{$safeId}.json";

        if (file_put_contents($file, $state)) {
            echo json_encode(['ok' => true, 'ts' => date('Y-m-d H:i:s')]);
        } else {
            err('No se pudo guardar el archivo de estado', 500);
        }
        exit;
    }

    // ── SAVE INDEX ────────────────────────────────
    if ($action === 'save_index') {
        $index = $_POST['index'] ?? '';
        if (!$index) err('Falta el índice');
        $file = __DIR__ . "/flujos-workspaces.json";
        if (file_put_contents($file, $index)) {
            echo json_encode(['ok' => true, 'ts' => date('Y-m-d H:i:s')]);
        } else {
            err('No se pudo guardar el índice de perfiles', 500);
        }
        exit;
    }

    err('Acción desconocida: ' . $action);
}

err('Método no soportado', 405);
