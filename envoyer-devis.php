<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['ok' => $status === 200, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); respond(405, 'Méthode non autorisée.'); }
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') respond(403, 'Requête non autorisée.');
const MAX_JSON_BYTES = 32000;
const MAX_ATTACHMENTS = 3;
const MAX_ATTACHMENT_BYTES = 2 * 1024 * 1024;
const MAX_MULTIPART_BYTES = MAX_ATTACHMENTS * MAX_ATTACHMENT_BYTES + 65536;
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$uploads = null;
if ($contentType === 'application/json') {
    if ($length > MAX_JSON_BYTES) respond(413, 'Demande trop longue.');
    $raw = file_get_contents('php://input', false, null, 0, MAX_JSON_BYTES + 1);
    if ($raw === false || strlen($raw) > MAX_JSON_BYTES) respond(413, 'Demande trop longue.');
    $data = json_decode($raw, true);
} elseif ($contentType === 'multipart/form-data') {
    if ($length > MAX_MULTIPART_BYTES || ($length > 0 && !$_POST && !$_FILES)) {
        respond(413, 'La demande dépasse la taille acceptée. Réduisez la taille des pièces jointes.');
    }
    $data = $_POST;
    $uploads = $_FILES['pieces_jointes'] ?? null;
} else {
    respond(415, 'Format non accepté.');
}
if (!is_array($data)) respond(400, 'Demande invalide.');
function field(array $data, string $key, int $max, bool $required = true, bool $multiline = false): string {
    $v = $data[$key] ?? '';
    if (!is_string($v)) respond(422, 'Vérifiez les champs du formulaire.');
    $v = trim($v);
    // maxlength en HTML compte les unités UTF-16, y compris deux unités pour un emoji.
    $characters = preg_match_all('/./us', $v);
    $surrogatePairs = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $v);
    if ($characters === false || $surrogatePairs === false || $characters + $surrogatePairs > $max
        || ($required && $v === '') || (!$multiline && preg_match('/[\r\n]/', $v))
        || str_contains($v, "\0")) {
        respond(422, 'Vérifiez les champs du formulaire.');
    }
    return $v;
}
if (field($data, 'website', 300, false) !== '') respond(422, 'Demande non acceptée.');
$nom = field($data, 'nom', 120);
$email = field($data, 'email', 254);
$tel = field($data, 'tel', 40, false);
$ville = field($data, 'ville', 120);
$besoin = field($data, 'besoin', 120);
$msg = field($data, 'msg', 4000, true, true);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($besoin, ['Recherche de fuites','Installation sanitaire','Dépannage plomberie','Chauffage','Autre demande'], true)) respond(422, 'Vérifiez votre e-mail et votre prestation.');

function prepareAttachments(?array $uploads): array {
    if ($uploads === null) return [];
    $errors = $uploads['error'] ?? null;
    if (!is_array($errors) || !array_is_list($errors)) respond(422, 'Vérifiez les pièces jointes.');
    if ($errors === [UPLOAD_ERR_NO_FILE]) return [];
    if (count($errors) > MAX_ATTACHMENTS) respond(422, 'Ajoutez au maximum 3 pièces jointes.');
    if (!function_exists('finfo_open')) respond(503, 'Les pièces jointes sont momentanément indisponibles.');
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) respond(503, 'Les pièces jointes sont momentanément indisponibles.');

    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $attachments = [];
    foreach ($errors as $index => $error) {
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            respond(413, 'Chaque pièce jointe doit faire au maximum 2 Mo.');
        }
        $tmp = $uploads['tmp_name'][$index] ?? '';
        if ($error !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)) {
            respond(422, 'Une pièce jointe n’a pas pu être reçue. Sélectionnez à nouveau le fichier.');
        }
        $size = filesize($tmp);
        if ($size === false || $size === 0) respond(422, 'Les pièces jointes doivent contenir un fichier lisible.');
        if ($size > MAX_ATTACHMENT_BYTES) respond(413, 'Chaque pièce jointe doit faire au maximum 2 Mo.');
        $mime = finfo_file($fileInfo, $tmp);
        if (!$mime || !isset($extensions[$mime])) {
            respond(422, 'Formats acceptés pour les pièces jointes : JPG, PNG, WebP et PDF.');
        }
        $bytes = file_get_contents($tmp, false, null, 0, MAX_ATTACHMENT_BYTES + 1);
        if ($bytes === false || strlen($bytes) !== $size) respond(422, 'Impossible de lire une pièce jointe.');

        $rawName = (string)($uploads['name'][$index] ?? '');
        $base = pathinfo(str_replace('\\', '/', $rawName), PATHINFO_FILENAME);
        $base = trim(substr(preg_replace('/[^a-zA-Z0-9._ -]+/', '-', $base), 0, 96), ' .-_');
        $extensionsForJpeg = ['jpg', 'jpeg'];
        $ext = strtolower(pathinfo($rawName, PATHINFO_EXTENSION));
        if ($mime === 'image/jpeg' ? !in_array($ext, $extensionsForJpeg, true) : $ext !== $extensions[$mime]) {
            respond(422, 'Le type d’une pièce jointe ne correspond pas à son extension.');
        }
        $name = ($base !== '' ? $base : 'piece-jointe-' . ($index + 1)) . '.' . $extensions[$mime];
        $attachments[] = ['filename' => $name, 'fileblob' => base64_encode($bytes), 'mimetype' => $mime];
    }
    return $attachments;
}

$private = dirname(__DIR__) . '/private';
$config = is_file($private . '/smtp2go.php') ? require $private . '/smtp2go.php' : [];
if (!is_array($config)) respond(503, 'L’envoi est momentanément indisponible. Contactez-nous par e-mail.');
$key = getenv('SMTP2GO_API_KEY') ?: ($config['api_key'] ?? '');
$sender = getenv('SMTP2GO_SENDER') ?: ($config['sender'] ?? 'contact@frer-o.com');
if (!is_string($key) || $key === '' || $key === 'REMPLACER_PAR_VOTRE_CLE_API'
    || preg_match('/[\r\n]/', $key) || !is_string($sender) || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
    respond(503, 'L’envoi est momentanément indisponible. Contactez-nous par e-mail.');
}
$attachments = prepareAttachments($uploads);
// Quota partagé par IP et quota global, sans journaliser les messages ou adresses.
$dir = $private . '/rate';
if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) respond(503, 'Service momentanément indisponible.');
function limit(string $dir, string $bucket, int $max, int $window): void {
    $f = @fopen($dir . '/' . $bucket . '.json', 'c+');
    if (!$f || !flock($f, LOCK_EX)) respond(503, 'Service momentanément indisponible.');
    $old = json_decode(stream_get_contents($f) ?: '[]', true);
    $now = time();
    $times = array_values(array_filter(is_array($old) ? $old : [], fn($t) => is_int($t) && $t > $now - $window));
    if (count($times) >= $max) {
        flock($f, LOCK_UN);
        fclose($f);
        header('Retry-After: ' . max(1, min($times) + $window - $now));
        respond(429, 'Trop de demandes. Réessayez plus tard ou contactez-nous par e-mail.');
    }
    $times[] = $now;
    $encoded = json_encode($times, JSON_THROW_ON_ERROR);
    $saved = rewind($f)
        && @fwrite($f, $encoded) === strlen($encoded)
        && @ftruncate($f, strlen($encoded))
        && @fflush($f);
    flock($f, LOCK_UN);
    fclose($f);
    if (!$saved) respond(503, 'Service momentanément indisponible.');
}
limit($dir, hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $key), 4, 900);
limit($dir, 'global', 30, 3600);
$payload = ['sender' => "Les Frér'O <$sender>", 'to' => ['contact@frer-o.com'], 'subject' => 'Demande de devis - ' . $besoin,
 'text_body' => "Nouvelle demande depuis le site Les Frér’O\n\nNom : $nom\nE-mail : $email\nTéléphone : $tel\nCommune : $ville\nPrestation : $besoin\n\nDescription :\n$msg",
 'custom_headers' => [['header' => 'Reply-To', 'value' => $email]], 'fastaccept' => false];
if ($attachments) $payload['attachments'] = $attachments;
$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$url = 'https://api.smtp2go.com/v3/email/send';
$headers = ['Content-Type: application/json', 'Accept: application/json', 'X-Smtp2go-Api-Key: ' . $key];
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
    $result = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
} else {
    $ctx = stream_context_create(['http' => ['method'=>'POST','header'=>implode("\r\n", $headers), 'content'=>$body,'timeout'=>15,'ignore_errors'=>true,'follow_location'=>0], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $result = @file_get_contents($url, false, $ctx);
    $status = preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $http_response_header[0] ?? '', $matches)
        ? (int)$matches[1]
        : 0;
}
$r = is_string($result) ? json_decode($result, true) : null;
if ($status !== 200 || !is_array($r) || (int)($r['data']['succeeded'] ?? 0) !== 1 || (int)($r['data']['failed'] ?? 0) !== 0) {
    error_log('Les Frero SMTP2GO: échec API, HTTP ' . $status);
    respond(502, 'L’envoi n’a pas pu être confirmé. Réessayez ou contactez-nous par e-mail.');
}
respond(200, 'Merci ! Votre demande a été acceptée pour envoi. Nous reviendrons vers vous dès que possible.');
