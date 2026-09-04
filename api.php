<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'POST vereist']);
  exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Ongeldige JSON']);
  exit;
}

const TESLA_AUTH = 'https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token';
const TESLA_EU = 'https://fleet-api.prd.eu.vn.cloud.tesla.com';
const TESLA_NA = 'https://fleet-api.prd.na.vn.cloud.tesla.com';
const TESLA_DOMAIN = 'fsd-europe-data.com';
const TESLA_REDIRECT = 'https://fsd-europe-data.com/auth/callback';

function tesla_form(array $fields): array {
  $ch = curl_init(TESLA_AUTH);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($fields),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
  ]);
  $text = curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($text === false) {
    return ['ok' => false, 'status' => 0, 'text' => $err ?: 'curl failed'];
  }
  return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'text' => (string) $text];
}

function tesla_json(string $url, string $token, string $method = 'GET', ?array $payload = null): array {
  $ch = curl_init($url);
  $headers = ['Authorization: Bearer ' . $token];
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => $headers,
  ];
  if ($method === 'POST') {
    $opts[CURLOPT_POST] = true;
    $opts[CURLOPT_POSTFIELDS] = json_encode($payload ?? new stdClass());
    $headers[] = 'Content-Type: application/json';
    $opts[CURLOPT_HTTPHEADER] = $headers;
  }
  curl_setopt_array($ch, $opts);
  $text = curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'text' => (string) $text];
}

function clean(string $v): string {
  return preg_replace('/\s+/', '', trim($v, " \t\n\r\0\x0B\"'"));
}

$action = (string) ($body['action'] ?? '');
$clientId = clean((string) ($body['clientId'] ?? ''));
$clientSecret = clean((string) ($body['clientSecret'] ?? ''));

if ($action === 'register') {
  if (strlen($clientId) < 8 || strlen($clientSecret) < 8) {
    echo json_encode(['ok' => false, 'message' => 'Client ID en Secret van Berkant plakken.']);
    exit;
  }
  $last = 'Geen antwoord van Tesla.';
  foreach ([TESLA_EU, TESLA_NA] as $audience) {
    $token = tesla_form([
      'grant_type' => 'client_credentials',
      'client_id' => $clientId,
      'client_secret' => $clientSecret,
      'audience' => $audience,
      'scope' => 'openid vehicle_device_data vehicle_cmds vehicle_charging_cmds',
    ]);
    if (!$token['ok']) {
      $last = tesla_human($token['status'], $token['text']);
      continue;
    }
    $parsed = json_decode($token['text'], true);
    $access = is_array($parsed) ? (string) ($parsed['access_token'] ?? '') : '';
    if ($access === '') {
      $last = 'Partner-token zonder access_token.';
      continue;
    }
    $reg = tesla_json($audience . '/api/1/partner_accounts', $access, 'POST', ['domain' => TESLA_DOMAIN]);
    $last = $reg['status'] . ': ' . substr($reg['text'], 0, 220);
    if ($reg['ok'] || str_contains($reg['text'], 'already registered')) {
      echo json_encode(['ok' => true, 'message' => 'Partner geregistreerd voor ' . TESLA_DOMAIN . '.']);
      exit;
    }
  }
  echo json_encode(['ok' => false, 'message' => $last]);
  exit;
}

if ($action === 'exchange') {
  $code = trim((string) ($body['code'] ?? ''));
  if ($code === '' || strlen($clientId) < 8 || strlen($clientSecret) < 8) {
    echo json_encode(['ok' => false, 'message' => 'Code, Client ID en Secret nodig.']);
    exit;
  }
  $last = 'Code-uitwisseling mislukt.';
  foreach ([TESLA_EU, TESLA_NA] as $audience) {
    $token = tesla_form([
      'grant_type' => 'authorization_code',
      'client_id' => $clientId,
      'client_secret' => $clientSecret,
      'code' => $code,
      'redirect_uri' => TESLA_REDIRECT,
      'audience' => $audience,
    ]);
    if (!$token['ok']) {
      $last = tesla_human($token['status'], $token['text']);
      continue;
    }
    $parsed = json_decode($token['text'], true);
    if (!is_array($parsed) || empty($parsed['access_token'])) {
      $last = substr($token['text'], 0, 180);
      continue;
    }
    echo json_encode([
      'ok' => true,
      'accessToken' => $parsed['access_token'],
      'refreshToken' => $parsed['refresh_token'] ?? null,
      'expiresIn' => $parsed['expires_in'] ?? 3600,
      'region' => $audience,
    ]);
    exit;
  }
  echo json_encode(['ok' => false, 'message' => $last]);
  exit;
}

if ($action === 'vehicles') {
  $access = trim((string) ($body['accessToken'] ?? ''));
  $region = trim((string) ($body['region'] ?? TESLA_EU));
  if ($access === '' || !str_starts_with($region, 'https://fleet-api.')) {
    echo json_encode(['ok' => false, 'vehicles' => [], 'message' => 'Sessie ongeldig.']);
    exit;
  }
  $list = tesla_json($region . '/api/1/vehicles', $access);
  if (!$list['ok']) {
    echo json_encode(['ok' => false, 'vehicles' => [], 'message' => 'Vehicles ' . $list['status']]);
    exit;
  }
  $json = json_decode($list['text'], true);
  $out = [];
  foreach (($json['response'] ?? []) as $v) {
    $id = (string) ($v['id'] ?? $v['vin'] ?? '');
    if ($id === '') continue;
    $detail = tesla_json($region . '/api/1/vehicles/' . rawurlencode($id) . '/vehicle_data', $access);
    $d = json_decode($detail['text'], true);
    $r = is_array($d) ? ($d['response'] ?? []) : [];
    $charge = $r['charge_state'] ?? [];
    $drive = $r['drive_state'] ?? [];
    $vehicle = $r['vehicle_state'] ?? [];
    $out[] = [
      'id' => $id,
      'name' => (string) ($v['display_name'] ?? $r['display_name'] ?? 'Tesla'),
      'vin' => (string) ($v['vin'] ?? $r['vin'] ?? ''),
      'state' => (string) ($v['state'] ?? 'unknown'),
      'battery' => (int) ($charge['battery_level'] ?? 0),
      'charging' => (string) ($charge['charging_state'] ?? ''),
      'rangeKm' => (int) round((float) ($charge['battery_range'] ?? 0) * 1.60934),
      'lat' => isset($drive['latitude']) ? (float) $drive['latitude'] : null,
      'lng' => isset($drive['longitude']) ? (float) $drive['longitude'] : null,
      'odometer' => isset($vehicle['odometer']) ? (int) round((float) $vehicle['odometer'] * 1.60934) : null,
    ];
  }
  echo json_encode(['ok' => true, 'vehicles' => $out]);
  exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'message' => 'Onbekende actie']);

function tesla_human(int $status, string $text): string {
  if (str_contains($text, 'unauthorized_client') || str_contains($text, 'invalid_client')) {
    return 'Tesla herkent deze Client ID + Secret niet. Berkant → Details: nieuw Clientgeheim kopiëren.';
  }
  if (str_contains($text, 'must match registered allowed origin')) {
    return 'Zet in Tesla bij herkomst: https://fsd-europe-data.com (zonder slash) en Update.';
  }
  return 'Token ' . $status . ': ' . substr($text, 0, 160);
}
