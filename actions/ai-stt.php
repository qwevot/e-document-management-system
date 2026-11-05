<?php
/**
 * AI Voice Speech-to-Text (STT) API Endpoint using Groq Whisper for EDMS
 * Handles speech recording upload and fast transcription in Indonesian/English.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai-config.php';

header('Content-Type: application/json; charset=utf-8');

function ai_stt_json_out(bool $status, array $data = [], string $message = ''): void {
    echo json_encode([
        'success' => $status,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Authentication check
if (current_user() === null) {
    ai_stt_json_out(false, [], 'Sesi Anda telah berakhir. Harap login kembali.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    ai_stt_json_out(false, [], 'Permintaan atau token keamanan (CSRF) tidak valid.');
}
session_write_close();

if (!defined('GROQ_API_KEY') || GROQ_API_KEY === 'YOUR_GROQ_API_KEY_HERE' || empty(GROQ_API_KEY)) {
    ai_stt_json_out(false, [], 'API Key belum dikonfigurasi di includes/ai-config.php.');
}

// 2. Validate uploaded audio file
if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['audio']['error'] ?? 'missing';
    ai_stt_json_out(false, [], "File audio tidak ditemukan atau gagal diunggah (Error code: {$errCode}).");
}

$uploadedFile = $_FILES['audio']['tmp_name'];
$originalName = $_FILES['audio']['name'] ?: 'speech.webm';
$fileSize     = $_FILES['audio']['size'];

if ($fileSize > 25 * 1024 * 1024) {
    ai_stt_json_out(false, [], 'Ukuran rekaman audio melebihi batas maksimal 25MB.');
}

// 3. Dispatch transcription request to Groq Whisper API
try {
    $endpoint = 'https://api.groq.com/openai/v1/audio/transcriptions';
    $model    = 'whisper-large-v3-turbo';

    // Determine MIME type
    $mimeType = mime_content_type($uploadedFile) ?: 'audio/webm';

    $postFields = [
        'file'            => new CURLFile($uploadedFile, $mimeType, $originalName),
        'model'           => $model,
        'response_format' => 'json',
        'temperature'     => '0.0',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_SSL_VERIFYPEER => (bool)(ini_get('curl.cainfo') || ini_get('openssl.cafile')),
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        log_activity('ai.stt_failed', 'ai', null, ['error' => $curlErr]);
        ai_stt_json_out(false, [], 'Koneksi ke server gagal: ' . $curlErr);
    }

    $res = json_decode((string)$response, true);

    if ($httpCode !== 200 || isset($res['error'])) {
        $msg = $res['error']['message'] ?? "Server mengembalikan kode HTTP {$httpCode}";
        log_activity('ai.stt_failed', 'ai', null, ['http_code' => $httpCode, 'error' => $msg]);
        ai_stt_json_out(false, [], 'Gagal mentranskripsi audio: ' . $msg);
    }

    $transcribedText = trim((string)($res['text'] ?? ''));

    if ($transcribedText === '') {
        ai_stt_json_out(false, [], 'Tidak ada suara atau percakapan yang terdeteksi dalam rekaman.');
    }

    log_activity('ai.stt', 'ai', null, [
        'text_preview' => mb_strimwidth($transcribedText, 0, 100, '...'),
        'model'        => $model
    ]);

    ai_stt_json_out(true, [
        'text'  => $transcribedText,
        'model' => $model,
    ]);
} catch (Throwable $e) {
    log_activity('ai.stt_failed', 'ai', null, ['error' => $e->getMessage()]);
    ai_stt_json_out(false, [], 'Terjadi kesalahan sistem STT: ' . $e->getMessage());
}
