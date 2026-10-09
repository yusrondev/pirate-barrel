<?php
/**
 * Pirate Barrel - Lightweight Multiplayer Room API
 * Storage: JSON files in ./rooms (file-locked). Clients poll via action=get.
 * Server is authoritative for turn order, trap check, and host-only actions.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

const ROOM_DIR = __DIR__ . '/rooms';
const PLAYER_TIMEOUT = 20;   // seconds without polling => player removed
const ROOM_TTL = 7200;       // seconds before abandoned room files are cleaned up
const MAX_PLAYERS = 6;

if (!is_dir(ROOM_DIR)) { @mkdir(ROOM_DIR, 0777, true); }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$input = array_merge($_GET, $input);
$action = $input['action'] ?? '';
$uid = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($input['uid'] ?? '')), 0, 40);
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($input['code'] ?? '')));

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function fail($msg, $status = 400) { respond(['ok' => false, 'error' => $msg], $status); }

function roomPath($code) { return ROOM_DIR . '/' . $code . '.json'; }

/** Strip secret fields before sending to clients. */
function publicRoom($room) {
    unset($room['trapSlotId']);
    return ['ok' => true, 'room' => $room];
}

function cleanName($name) {
    $name = trim(strip_tags((string)$name));
    if ($name === '') $name = 'Kapten Jack';
    return mb_substr($name, 0, 20);
}

function newPlayer($uid, $name) {
    return ['id' => $uid, 'name' => cleanName($name), 'score' => 0, 'swords' => 0, 'wins' => 0, 'losses' => 0, 'lastSeen' => time()];
}

function startRound(&$room) {
    $room['round'] = ($room['round'] ?? 0) + 1;
    $room['insertedSlots'] = [];
    $room['isGameOver'] = false;
    $room['loserIndex'] = null;
    $room['loserName'] = null;
    $room['currentTurnIndex'] = 0;
    $room['rotationAngle'] = 0;
    $room['trapSlotId'] = random_int(0, max(0, $room['slotCount'] - 1));
}

/** Remove players that stopped polling; keep turn index & host consistent. */
function pruneInactive(&$room) {
    $now = time();
    $changed = false;
    for ($i = count($room['players']) - 1; $i >= 0; $i--) {
        if ($now - ($room['players'][$i]['lastSeen'] ?? $now) > PLAYER_TIMEOUT) {
            array_splice($room['players'], $i, 1);
            if ($i < $room['currentTurnIndex']) $room['currentTurnIndex']--;
            $changed = true;
        }
    }
    $n = count($room['players']);
    if ($n > 0) {
        if ($room['currentTurnIndex'] >= $n) $room['currentTurnIndex'] = 0;
        $hostStillHere = false;
        foreach ($room['players'] as $p) if ($p['id'] === $room['hostId']) $hostStillHere = true;
        if (!$hostStillHere) { $room['hostId'] = $room['players'][0]['id']; $changed = true; }
    }
    return $changed;
}

function playerIndex($room, $uid) {
    foreach ($room['players'] as $i => $p) if ($p['id'] === $uid) return $i;
    return -1;
}

/**
 * Open room file with exclusive lock, run mutator, persist if it returns true.
 */
function withRoom($code, callable $fn) {
    $path = roomPath($code);
    if (!file_exists($path)) fail('Room tidak ditemukan', 404);
    $fp = fopen($path, 'c+');
    if (!$fp) fail('Gagal membuka room', 500);
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $room = json_decode($raw, true);
    if (!$room) { flock($fp, LOCK_UN); fclose($fp); fail('Room rusak', 500); }

    $dirty = $fn($room);
    if ($dirty) {
        $room['version'] = ($room['version'] ?? 0) + 1;
        $room['updatedAt'] = time();
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($room));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $room;
}

// Occasional cleanup of abandoned rooms
if (random_int(1, 50) === 1) {
    foreach (glob(ROOM_DIR . '/*.json') as $f) {
        if (time() - filemtime($f) > ROOM_TTL) @unlink($f);
    }
}

if ($uid === '' && $action !== '') fail('uid wajib diisi');

switch ($action) {
    case 'create': {
        $slotCount = max(1, min(64, (int)($input['slotCount'] ?? 18)));
        do {
            $code = '';
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            for ($i = 0; $i < 5; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        } while (file_exists(roomPath($code)));

        $allowedDesigns = ['lid', 'energy', 'anchor', 'kraken', 'crown', 'compass', 'atom'];
        $rawDesign = $input['topDesign'] ?? '';
        $normalized = in_array($rawDesign, ['crown', 'compass', 'atom'], true) ? 'energy' : $rawDesign;
        $topDesign = in_array($normalized, ['lid', 'energy', 'anchor', 'kraken'], true) ? $normalized : 'lid';

        $room = [
            'code' => $code,
            'hostId' => $uid,
            'status' => 'lobby',
            'topDesign' => $topDesign,
            'slotCount' => $slotCount,
            'players' => [newPlayer($uid, $input['name'] ?? '')],
            'version' => 1,
            'round' => 0,
            'updatedAt' => time(),
        ];
        startRound($room);
        $room['round'] = 0;
        file_put_contents(roomPath($code), json_encode($room), LOCK_EX);
        respond(publicRoom($room));
    }

    case 'setTopDesign': {
        $rawDesign = $input['topDesign'] ?? 'lid';
        $normalized = in_array($rawDesign, ['crown', 'compass', 'atom'], true) ? 'energy' : $rawDesign;
        $design = in_array($normalized, ['lid', 'energy', 'anchor', 'kraken'], true) ? $normalized : 'lid';

        $room = withRoom($code, function (&$room) use ($uid, $design) {
            if ($room['hostId'] !== $uid) fail('Hanya HOST yang bisa mengubah dekorasi drum', 403);
            $room['topDesign'] = $design;
            return true;
        });
        respond(publicRoom($room));
    }

    case 'join': {
        $room = withRoom($code, function (&$room) use ($uid, $input) {
            pruneInactive($room);
            $idx = playerIndex($room, $uid);
            if ($idx >= 0) {
                $room['players'][$idx]['name'] = cleanName($input['name'] ?? $room['players'][$idx]['name']);
                $room['players'][$idx]['lastSeen'] = time();
                return true;
            }
            if (count($room['players']) >= MAX_PLAYERS) fail('Room penuh (maks ' . MAX_PLAYERS . ' pemain)', 409);
            $room['players'][] = newPlayer($uid, $input['name'] ?? '');
            return true;
        });
        respond(publicRoom($room));
    }

    case 'get': {
        $sinceVersion = isset($input['v']) ? (int)$input['v'] : -1;
        $room = withRoom($code, function (&$room) use ($uid) {
            $idx = playerIndex($room, $uid);
            $dirty = false;
            if ($idx >= 0) {
                // Heartbeat: only persist every few seconds to reduce writes
                if (time() - ($room['players'][$idx]['lastSeen'] ?? 0) >= 3) {
                    $room['players'][$idx]['lastSeen'] = time();
                    $dirty = true;
                }
            }
            if (pruneInactive($room)) $dirty = true;
            return $dirty;
        });
        if (playerIndex($room, $uid) < 0) fail('Kamu sudah tidak berada di room ini', 410);
        if ($sinceVersion === (int)$room['version']) respond(['ok' => true, 'unchanged' => true, 'version' => $room['version']]);
        respond(publicRoom($room));
    }

    case 'start':
    case 'restart': {
        $room = withRoom($code, function (&$room) use ($uid, $action) {
            if ($room['hostId'] !== $uid) fail('Hanya HOST yang bisa memulai game', 403);
            if ($action === 'start' && count($room['players']) < 2) fail('Minimal 2 pemain untuk memulai', 409);
            $room['status'] = 'playing';
            startRound($room);
            return true;
        });
        respond(publicRoom($room));
    }

    case 'rotate': {
        $angle = (float)($input['angle'] ?? 0);
        $room = withRoom($code, function (&$room) use ($uid, $angle) {
            if ($room['status'] !== 'playing' || $room['isGameOver']) return false;
            $active = $room['players'][$room['currentTurnIndex']] ?? null;
            if (!$active || $active['id'] !== $uid) fail('Bukan giliran kamu', 403);
            $room['rotationAngle'] = $angle;
            return true;
        });
        respond(['ok' => true, 'version' => $room['version']]);
    }

    case 'insert': {
        $slotId = (int)($input['slotId'] ?? -1);
        $room = withRoom($code, function (&$room) use ($uid, $slotId) {
            if ($room['status'] !== 'playing') fail('Game belum dimulai', 409);
            if ($room['isGameOver']) fail('Ronde sudah selesai', 409);
            $turn = $room['currentTurnIndex'];
            $active = $room['players'][$turn] ?? null;
            if (!$active || $active['id'] !== $uid) fail('Bukan giliran kamu', 403);
            if ($slotId < 0 || $slotId >= $room['slotCount']) fail('Slot tidak valid');
            foreach ($room['insertedSlots'] as $s) if ((int)$s['slotId'] === $slotId) fail('Slot sudah terisi', 409);

            $room['insertedSlots'][] = ['slotId' => $slotId, 'playerIndex' => $turn, 'playerId' => $uid];
            $isTrap = ($slotId === (int)$room['trapSlotId']) || (count($room['insertedSlots']) >= $room['slotCount']);

            if ($isTrap) {
                $room['players'][$turn]['losses'] += 1;
                $room['isGameOver'] = true;
                $room['loserIndex'] = $turn;
                $room['loserName'] = $room['players'][$turn]['name'];
            } else {
                $room['players'][$turn]['swords'] += 1;
                $room['players'][$turn]['score'] += 1;
                $room['currentTurnIndex'] = ($turn + 1) % count($room['players']);
            }
            return true;
        });
        respond(publicRoom($room));
    }

    case 'resetScores': {
        $room = withRoom($code, function (&$room) use ($uid) {
            if ($room['hostId'] !== $uid) fail('Hanya HOST yang bisa reset skor', 403);
            foreach ($room['players'] as &$p) { $p['score'] = 0; $p['swords'] = 0; $p['wins'] = 0; $p['losses'] = 0; }
            return true;
        });
        respond(publicRoom($room));
    }

    case 'leave': {
        $room = withRoom($code, function (&$room) use ($uid) {
            $idx = playerIndex($room, $uid);
            if ($idx < 0) return false;
            array_splice($room['players'], $idx, 1);
            if ($idx < $room['currentTurnIndex']) $room['currentTurnIndex']--;
            pruneInactive($room);
            return true;
        });
        respond(['ok' => true]);
    }

    default:
        fail('Action tidak dikenal');
}
