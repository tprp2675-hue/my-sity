<?php
header('Content-Type: application/json; charset=utf-8');

// ================================
// TELEGRAM: токен и chat_id берём из соседнего файла
// ================================
require __DIR__ . '/tgsecrets.php'; // объявляет $botToken и $chatId

// ================================
// ПРОСТОЙ ТРОТТЛИНГ ПО IP (защита от долбёжки запросами)
// ================================
function tooManyRequests($limit = 5, $windowSeconds = 3600) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = sys_get_temp_dir() . '/lead_throttle';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $file = $dir . '/' . md5($ip) . '.json';

    $now = time();
    $hits = [];

    $fp = @fopen($file, 'c+');
    if (!$fp) return false; // если не смогли открыть файл - не блокируем заявку
    flock($fp, LOCK_EX);
    $content = stream_get_contents($fp);
    if ($content) {
        $hits = json_decode($content, true) ?: [];
    }
    $hits = array_values(array_filter($hits, function ($t) use ($now, $windowSeconds) {
        return ($now - $t) < $windowSeconds;
    }));

    $blocked = count($hits) >= $limit;
    if (!$blocked) {
        $hits[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($hits));
    }
    flock($fp, LOCK_UN);
    fclose($fp);

    return $blocked;
}

if (tooManyRequests()) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Слишком много запросов, попробуйте позже'
    ]);
    exit;
}

// ================================
// ПОЛУЧАЕМ ДАННЫЕ ИЗ ФОРМЫ
// ================================
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Некорректные данные'
    ]);
    exit;
}

$name         = trim($data['name'] ?? '');
$phone        = trim($data['phone'] ?? '');
$service      = trim($data['service'] ?? '');
$message      = trim($data['message'] ?? '');
$page         = trim($data['page'] ?? '');
$website      = trim($data['website'] ?? '');       // honeypot-поле
$formLoadedAt = $data['formLoadedAt'] ?? '';         // время открытия формы (мс)

// ================================
// АНТИСПАМ: HONEYPOT
// ================================
if ($website !== '') {
    echo json_encode(['success' => true]);
    exit;
}

// ================================
// АНТИСПАМ: ПРОВЕРКА ПО ВРЕМЕНИ ЗАПОЛНЕНИЯ
// ================================
$loadedAtMs = is_numeric($formLoadedAt) ? (float) $formLoadedAt : 0;
$nowMs = round(microtime(true) * 1000);
if ($loadedAtMs <= 0 || ($nowMs - $loadedAtMs) < 2000) {
    echo json_encode(['success' => true]);
    exit;
}

// ================================
// ПРОВЕРКА ОБЯЗАТЕЛЬНЫХ ПОЛЕЙ
// ================================
if ($name === '' || $phone === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Не заполнены обязательные поля'
    ]);
    exit;
}

$digitsOnly = preg_replace('/\D+/', '', $phone);
if (strlen($digitsOnly) < 10) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Проверьте номер телефона'
    ]);
    exit;
}

// ================================
// ЗАЩИТА ОТ HTML В TELEGRAM
// ================================
$name    = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$phone   = htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$service = htmlspecialchars($service, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page    = htmlspecialchars($page, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ================================
// СОБИРАЕМ СООБЩЕНИЕ
// ================================
$text  = "📩 <b>Новая заявка с сайта «Астория К-3»</b>\n\n";
$text .= "👤 <b>Имя:</b> {$name}\n";
$text .= "📞 <b>Телефон:</b> {$phone}\n";
if ($service !== '') {
    $text .= "🔧 <b>Услуга:</b> {$service}\n";
}
if ($message !== '') {
    $text .= "\n💬 <b>Комментарий:</b>\n{$message}\n";
}
if ($page !== '') {
    $text .= "\n🌐 <b>Страница:</b> {$page}";
}

// ================================
// ОТПРАВЛЯЕМ В TELEGRAM
// ================================
$telegramUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
$postData = [
    'chat_id' => $chatId,
    'text' => $text,
    'parse_mode' => 'HTML'
];

$ch = curl_init($telegramUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 15
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ================================
// ОБРАБОТКА ОШИБКИ
// ================================
if ($curlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка соединения с Telegram'
    ]);
    exit;
}

$telegramResponse = json_decode($response, true);
if ($httpCode >= 200 && $httpCode < 300 && !empty($telegramResponse['ok'])) {
    echo json_encode([
        'success' => true
    ]);
    exit;
}

http_response_code(500);
echo json_encode([
    'success' => false,
    'message' => 'Telegram не принял заявку'
]);
exit;