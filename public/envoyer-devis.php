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
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') respond(415, 'Format non accepté.');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 24000) respond(413, 'Demande trop longue.');
$raw = file_get_contents('php://input', false, null, 0, 24001);
if ($raw === false || strlen($raw) > 24000) respond(413, 'Demande trop longue.');
$data = json_decode($raw, true);
if (!is_array($data)) respond(400, 'Demande invalide.');
function field(array $data, string $key, int $max, bool $required = true, bool $multiline = false): string {
    $v = $data[$key] ?? '';
    if (!is_string($v)) respond(422, 'Vérifiez les champs du formulaire.');
    $v = trim($v);
    if (($required && $v === '') || strlen($v) > $max || (!$multiline && preg_match('/[\r\n\x00]/', $v)) || str_contains($v, "\0")) respond(422, 'Vérifiez les champs du formulaire.');
    return $v;
}
if (field($data, 'website', 300, false) !== '') respond(422, 'Demande non acceptée.');
$nom = field($data, 'nom', 240);
$email = field($data, 'email', 254);
$tel = field($data, 'tel', 80, false);
$ville = field($data, 'ville', 240);
$besoin = field($data, 'besoin', 120);
$msg = field($data, 'msg', 10000, true, true);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($besoin, ['Recherche de fuites','Installation sanitaire','Dépannage plomberie','Chauffage','Autre demande'], true)) respond(422, 'Vérifiez votre e-mail et votre prestation.');
$private = dirname(__DIR__) . '/private';
$config = is_file($private . '/smtp2go.php') ? require $private . '/smtp2go.php' : [];
$key = getenv('SMTP2GO_API_KEY') ?: ($config['api_key'] ?? '');
$sender = getenv('SMTP2GO_SENDER') ?: ($config['sender'] ?? 'contact@frer-o.com');
if (!$key || !filter_var($sender, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $key)) respond(503, 'L’envoi est momentanément indisponible. Contactez-nous par e-mail.');
// Quota partagé par IP et quota global, sans journaliser les messages ou adresses.
$dir = $private . '/rate';
if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) respond(503, 'Service momentanément indisponible.');
function limit(string $dir, string $bucket, int $max, int $window): void {
    $f = @fopen($dir . '/' . $bucket . '.json', 'c+');
    if (!$f || !flock($f, LOCK_EX)) respond(503, 'Service momentanément indisponible.');
    $old = json_decode(stream_get_contents($f) ?: '[]', true);
    $now = time();
    $times = array_values(array_filter(is_array($old) ? $old : [], fn($t) => is_int($t) && $t > $now - $window));
    if (count($times) >= $max) { flock($f, LOCK_UN); fclose($f); header('Retry-After: ' . $window); respond(429, 'Trop de demandes. Réessayez plus tard ou contactez-nous par e-mail.'); }
    $times[] = $now; rewind($f); ftruncate($f, 0); fwrite($f, json_encode($times)); fflush($f); flock($f, LOCK_UN); fclose($f);
}
limit($dir, hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $key), 4, 900);
limit($dir, 'global', 30, 3600);
$payload = ['sender' => "Les Frér'O <$sender>", 'to' => ['contact@frer-o.com'], 'subject' => 'Demande de devis - ' . $besoin,
 'text_body' => "Nouvelle demande depuis le site Les Frér’O\n\nNom : $nom\nE-mail : $email\nTéléphone : $tel\nCommune : $ville\nPrestation : $besoin\n\nDescription :\n$msg",
 'custom_headers' => [['header' => 'Reply-To', 'value' => $email]], 'fastaccept' => false];
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
    $status = (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
}
$r = is_string($result) ? json_decode($result, true) : null;
if ($status !== 200 || !is_array($r) || (int)($r['data']['succeeded'] ?? 0) !== 1 || (int)($r['data']['failed'] ?? 0) !== 0) {
    error_log('Les Frero SMTP2GO: échec API, HTTP ' . $status);
    respond(502, 'L’envoi n’a pas pu être confirmé. Réessayez ou contactez-nous par e-mail.');
}
respond(200, 'Merci ! Votre demande a été acceptée pour envoi. Nous reviendrons vers vous dès que possible.');
