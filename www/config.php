<?php
/**
 * BiliNote - AI 视频笔记生成
 * API Key 从环境变量读取（Docker 中通过 docker-compose.yml 注入）
 * 本地开发：复制 .env.example 为 .env 并填入 Key
 */

// 尝试加载 .env 文件（本地开发用）
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($k, $_ENV)) $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
    }
}

// DeepSeek API
define('DEEPSEEK_API_KEY', getenv('DEEPSEEK_API_KEY') ?: '');
define('DEEPSEEK_BASE_URL', getenv('DEEPSEEK_BASE_URL') ?: 'https://api.deepseek.com');
define('DEEPSEEK_MODEL_DEFAULT', getenv('DEEPSEEK_MODEL') ?: 'deepseek-v4-flash');

// OpenAI Whisper API
define('WHISPER_API_KEY', getenv('WHISPER_API_KEY') ?: '');

// 上传限制
define('MAX_UPLOAD_SIZE', 500 * 1024 * 1024); // 500MB
define('UPLOAD_DIR', __DIR__ . '/../uploads/videos/');
