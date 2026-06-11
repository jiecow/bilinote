<?php
/**
 * BiliNote API — 字幕提取 / Whisper 转录 / DeepSeek 笔记生成
 */
require_once __DIR__ . '/config.php';

error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(600);

header('Content-Type: application/json; charset=utf-8');
ob_clean();

$action = $_POST['action'] ?? '';

// ============================================
// 笔记风格 Prompt
// ============================================
function getStylePrompt($style) {
    $map = [
        '精简'     => '你是一个专业的内容摘要助手。请用最精简的语言总结以下视频内容，只保留核心要点。每个要点一句话，使用有序列表，总字数控制在200字以内。',
        '详细'     => '你是一个细致的笔记整理助手。请详细记录以下视频内容，包括关键细节、背景信息、论证过程和具体示例。使用清晰的标题层级(## 大标题, ### 小标题)，充分展开每个要点。',
        '教程'     => '你是一个教学笔记助手。请以教程/课程笔记形式整理以下内容。使用步骤式结构（第1步、第2步...），包含前置知识、操作步骤、注意事项和总结。适合读者跟随学习。',
        '学术'     => '你是一个学术研究助手。请以学术论文风格整理以下内容。包含：研究背景、研究问题、方法/论证、主要发现、结论与展望。使用正式严谨的学术语言。',
        '小红书'   => '你是一个社交媒体创作助手。请以小红书风格整理以下内容。要求：活泼有趣、大量emoji、段落短小精悍、口语化表达。适合社交分享，让人看了就想点赞收藏。',
        '生活向导' => '你是一个生活指南助手。请以实用生活指南形式整理以下内容。突出实用建议、操作技巧和可执行的行动方案。语言亲切自然，让读者看完就能用到日常生活中。',
        '任务导向' => '你是一个任务管理助手。请以任务清单和行动方案形式整理以下内容。明确列出行动项（含优先级P0-P3）、完成标准和预估时间。使用checkbox格式，适合项目执行跟踪。',
        '商业风格' => '你是一个商业分析助手。请以商业报告风格整理以下内容。侧重点：商业洞察、市场分析、战略建议、可落地的商业行动方案。语言精炼，数据驱动，结论明确。',
        '会议纪要' => '你是一个会议记录助手。请以标准会议纪要格式整理以下内容。包含：会议主题、参会要点概述、详细讨论内容（按议题组织）、决议事项、后续行动计划（含责任人和时间节点）。',
    ];
    return $map[$style] ?? $map['详细'];
}

// ============================================
// DeepSeek API（OpenAI 兼容）
// ============================================
function callDeepSeek($text, $style, $model) {
    $system = getStylePrompt($style);
    $payload = json_encode([
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "请根据以下视频转录内容生成笔记：\n\n" . $text],
        ],
        'temperature' => 0.7,
        'max_tokens'  => 4096,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(DEEPSEEK_BASE_URL . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . DEEPSEEK_API_KEY],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => 'DeepSeek 请求失败: ' . $err];
    $data = json_decode($resp, true);
    if (!$data) return ['error' => 'DeepSeek 返回解析失败'];
    if ($code !== 200 || !empty($data['error'])) {
        return ['error' => 'DeepSeek 错误: ' . ($data['error']['message'] ?? "HTTP {$code}")];
    }
    $content = $data['choices'][0]['message']['content'] ?? '';
    return $content ? ['notes' => $content] : ['error' => 'DeepSeek 返回为空'];
}

// ============================================
// Whisper API
// ============================================
function callWhisper($audioPath) {
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . WHISPER_API_KEY],
        CURLOPT_POSTFIELDS     => [
            'model'                    => 'whisper-1',
            'file'                     => new CURLFile($audioPath, 'audio/mp3', 'audio.mp3'),
            'response_format'          => 'verbose_json',
            'language'                 => 'zh',
            'timestamp_granularities'  => 'segment',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => 'Whisper 请求失败: ' . $err];
    $data = json_decode($resp, true);
    if (!$data) return ['error' => 'Whisper 返回解析失败'];
    if ($code !== 200 || !empty($data['error'])) {
        return ['error' => 'Whisper 错误: ' . ($data['error']['message'] ?? "HTTP {$code}")];
    }

    $text = $data['text'] ?? '';
    $ts = '';
    foreach ($data['segments'] ?? [] as $seg) {
        $ts .= '[' . gmdate('H:i:s', (int)$seg['start']) . '] ' . trim($seg['text']) . "\n";
    }
    return ['text' => $text, 'timestamped' => trim($ts)];
}

// ============================================
// 查找可执行文件
// ============================================
function findBin($names) {
    foreach ((array)$names as $n) {
        $w = trim(@shell_exec((DIRECTORY_SEPARATOR === '\\' ? "where {$n} 2>nul" : "which {$n} 2>/dev/null") ?? ''));
        if ($w) return strtok($w, "\n");
        if (@file_exists($n) && @is_executable($n)) return $n;
    }
    return null;
}

// ============================================
// yt-dlp 提取在线字幕（多次尝试降级策略）
// ============================================
function fetchOnlineSubs($url) {
    $bin = findBin(['yt-dlp', '/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp']);
    if (!$bin) return ['found' => false, 'message' => 'yt-dlp 未安装'];

    $tmp = sys_get_temp_dir() . '/bilinote_' . uniqid();
    @mkdir($tmp, 0755, true);

    $srtFiles = [];

    // 策略1: 人工字幕 + 自动字幕，不限语言
    $cmds = [
        // 同时尝试人工+自动字幕，最常用语言
        sprintf('%s --no-playlist --skip-download --write-subs --write-auto-subs --sub-langs "zh.*,en.*,ja.*,ko.*" --convert-subs srt -o %s %s 2>&1',
            escapeshellcmd($bin), escapeshellarg($tmp . '/v'), escapeshellarg($url)),
        // 回退：不限语言，所有可用字幕
        sprintf('%s --no-playlist --skip-download --write-auto-subs --convert-subs srt -o %s %s 2>&1',
            escapeshellcmd($bin), escapeshellarg($tmp . '/v2'), escapeshellarg($url)),
    ];

    foreach ($cmds as $cmd) {
        exec($cmd, $o, $rc);
        $srts = glob($tmp . '/*.srt') ?: [];
        if ($srts) {
            $srtFiles = $srts;
            break;
        }
    }

    // 检查 VTT 格式
    if (!$srtFiles) {
        $vtts = glob($tmp . '/*.vtt') ?: [];
        foreach ($vtts as $vtt) {
            $cvt = $tmp . '/c_' . uniqid() . '.srt';
            exec(sprintf('ffmpeg -y -i %s %s 2>/dev/null', escapeshellarg($vtt), escapeshellarg($cvt)), $o3, $rc3);
            if ($rc3 === 0 && file_exists($cvt)) $srtFiles[] = $cvt;
        }
    }

    if (!$srtFiles) {
        array_map('unlink', glob($tmp . '/*') ?: []);
        @rmdir($tmp);
        return ['found' => false, 'message' => '该视频无可抓取字幕，请尝试上传文件使用 Whisper 转录'];
    }

    // 优先中文，其次英文
    $pick = null; $source = 'auto_subtitle';
    foreach ($srtFiles as $f) {
        $bn = basename($f);
        if (preg_match('/zh|cn|chinese|中文/i', $bn)) {
            $pick = $f;
            $source = stripos($bn, 'auto') !== false ? 'auto_subtitle' : 'manual_subtitle';
            break;
        }
    }
    if (!$pick) {
        foreach ($srtFiles as $f) {
            if (preg_match('/en|english/i', basename($f))) { $pick = $f; break; }
        }
    }
    if (!$pick) $pick = $srtFiles[0];

    $raw = file_get_contents($pick);
    array_map('unlink', glob($tmp . '/*') ?: []);
    @rmdir($tmp);

    $text = []; $ts = []; $ct = '';
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^\d+$/', $line)) continue;
        if (preg_match('/^(\d{2}:\d{2}:\d{2}[,\.]\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}[,\.]\d{3})$/', $line, $m)) { $ct = $m[1]; continue; }
        $cl = strip_tags($line);
        if ($ct) $ts[] = "[{$ct}] {$cl}";
        $text[] = $cl;
    }

    return [
        'found'       => true,
        'text'        => implode("\n", $text),
        'timestamped' => implode("\n", $ts),
        'source'      => $source,
    ];
}

// ============================================
// ffmpeg 提取文件内嵌字幕
// ============================================
function extractFileSubs($path) {
    exec(sprintf("ffprobe -v quiet -print_format json -show_streams %s 2>/dev/null", escapeshellarg($path)), $o, $rc);
    $info = json_decode(implode("\n", $o), true);
    $subIdx = null;
    foreach ($info['streams'] ?? [] as $s) {
        if (($s['codec_type'] ?? '') === 'subtitle') { $subIdx = $s['index']; break; }
    }
    if ($subIdx === null) return ['found' => false];

    $srtPath = $path . '.srt';
    exec(sprintf("ffmpeg -y -i %s -map 0:s:%d -c:s srt %s 2>/dev/null",
        escapeshellarg($path), $subIdx, escapeshellarg($srtPath)), $o2, $rc2);
    if ($rc2 !== 0 || !file_exists($srtPath)) return ['found' => false];

    $raw = file_get_contents($srtPath);
    @unlink($srtPath);

    $text = []; $ts = []; $ct = '';
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^\d+$/', $line)) continue;
        if (preg_match('/^(\d{2}:\d{2}:\d{2}[,\.]\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}[,\.]\d{3})$/', $line, $m)) { $ct = $m[1]; continue; }
        if ($ct) $ts[] = "[{$ct}] {$line}";
        $text[] = $line;
    }
    return ['found' => true, 'text' => implode("\n", $text), 'timestamped' => implode("\n", $ts), 'source' => 'embedded_subtitle'];
}

// ============================================
// ffmpeg 提取音频
// ============================================
function extractAudio($path) {
    $mp3 = $path . '_audio.mp3';
    exec(sprintf("ffmpeg -y -i %s -vn -ar 16000 -ac 1 -b:a 64k -f mp3 %s 2>/dev/null",
        escapeshellarg($path), escapeshellarg($mp3)), $o, $rc);
    return ($rc === 0 && file_exists($mp3)) ? $mp3 : null;
}

// ============================================
// 处理文件上传
// ============================================
function handleUpload($file) {
    // 详细的上传错误
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => '文件超过 php.ini 限制',
        UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
        UPLOAD_ERR_PARTIAL    => '文件只上传了一部分',
        UPLOAD_ERR_NO_FILE    => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
        UPLOAD_ERR_CANT_WRITE => '无法写入磁盘',
    ];
    $errCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($errCode !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        $msg = $uploadErrors[$errCode] ?? '上传错误码: ' . $errCode;
        return ['error' => '文件上传失败: ' . $msg];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4','avi','mkv','mov','webm','flv','wmv','m4v'])) {
        return ['error' => '不支持格式: ' . $ext];
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) return ['error' => '文件超过500MB'];

    // 确保上传目录存在（递归创建）
    if (!is_dir(UPLOAD_DIR)) {
        if (!@mkdir(UPLOAD_DIR, 0777, true)) {
            return ['error' => '无法创建上传目录: ' . UPLOAD_DIR . '，请检查权限'];
        }
    }
    if (!is_writable(UPLOAD_DIR)) {
        return ['error' => '上传目录不可写: ' . UPLOAD_DIR . '，请执行 chmod 777'];
    }

    $dest = UPLOAD_DIR . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        $tmpExists = file_exists($file['tmp_name']);
        return ['error' => '保存失败: ' . ($tmpExists ? '目标目录权限不足' : '临时文件丢失') . ' (' . UPLOAD_DIR . ')'];
    }

    // 1. 提取字幕
    $subs = extractFileSubs($dest);
    if ($subs['found']) {
        return ['success' => true, 'source' => $subs['source'], 'transcript' => $subs['text'], 'timestamped' => $subs['timestamped'], 'video_path' => 'uploads/videos/' . basename($dest)];
    }
    // 2. 提取音频 → Whisper
    $audio = extractAudio($dest);
    if (!$audio) return ['error' => '音频提取失败'];
    $wr = callWhisper($audio);
    @unlink($audio);
    if (isset($wr['error'])) return $wr;
    return ['success' => true, 'source' => 'whisper', 'transcript' => $wr['text'], 'timestamped' => $wr['timestamped'], 'video_path' => 'uploads/videos/' . basename($dest)];
}

// ============================================
// yt-dlp 下载在线音频（字幕失败时的 Whisper 降级方案）
// ============================================
function fetchOnlineAudio($url) {
    $bin = findBin(['yt-dlp', '/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp']);
    if (!$bin) return null;

    $tmp = sys_get_temp_dir() . '/bilinote_audio_' . uniqid();
    @mkdir($tmp, 0755, true);

    exec(sprintf('%s -x --audio-format mp3 --no-playlist --audio-quality 0 -o %s %s 2>&1',
        escapeshellcmd($bin),
        escapeshellarg($tmp . '/audio.%(ext)s'),
        escapeshellarg($url)), $out, $rc);

    $files = glob($tmp . '/*.mp3');
    if (!$files) {
        // 也检查其他音频格式
        foreach (['m4a', 'opus', 'aac', 'wav'] as $ext) {
            $files = glob($tmp . '/*.' . $ext);
            if ($files) {
                // 转 mp3
                $mp3 = $tmp . '/audio.mp3';
                exec(sprintf('ffmpeg -y -i %s -ar 16000 -ac 1 -b:a 64k %s 2>/dev/null',
                    escapeshellarg($files[0]), escapeshellarg($mp3)), $o2, $rc2);
                if ($rc2 === 0 && file_exists($mp3)) {
                    @unlink($files[0]);
                    $files = [$mp3];
                }
                break;
            }
        }
    }
    return $files ? $files[0] : null;
}

// ============================================
// 路由
// ============================================
try {
    if ($action === 'transcribe') {
        $hasFile = !empty($_FILES['video_file']['tmp_name']);
        $hasUrl  = !empty($_POST['video_url']);

        if (!$hasFile && !$hasUrl) {
            echo json_encode(['error' => '请提供视频URL或上传文件'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($hasFile) {
            $result = handleUpload($_FILES['video_file']);
        } else {
            $url = trim($_POST['video_url']);
            if (!preg_match('#^https?://#i', $url)) {
                echo json_encode(['error' => 'URL 格式无效'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 策略1: 抓取字幕
            $subs = fetchOnlineSubs($url);
            if ($subs['found']) {
                $result = ['success' => true, 'source' => $subs['source'], 'transcript' => $subs['text'], 'timestamped' => $subs['timestamped'], 'video_url' => $url];
            } else {
                // 策略2: 下载音频 → Whisper 转录
                $audio = fetchOnlineAudio($url);
                if ($audio) {
                    $wr = callWhisper($audio);
                    @unlink($audio);
                    // 清理 yt-dlp 临时目录
                    $tmpDir = dirname($audio);
                    array_map('unlink', glob($tmpDir . '/*') ?: []);
                    @rmdir($tmpDir);

                    if (isset($wr['error'])) {
                        $result = ['success' => true, 'source' => 'url_only', 'transcript' => '', 'timestamped' => '', 'video_url' => $url, 'notice' => '字幕抓取失败，Whisper 转录也失败: ' . $wr['error']];
                    } else {
                        $result = ['success' => true, 'source' => 'whisper_online', 'transcript' => $wr['text'], 'timestamped' => $wr['timestamped'], 'video_url' => $url];
                    }
                } else {
                    $result = ['success' => true, 'source' => 'url_only', 'transcript' => '', 'timestamped' => '', 'video_url' => $url, 'notice' => $subs['message'] ?? '字幕和音频均获取失败'];
                }
            }
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);

    } elseif ($action === 'generate') {
        $text  = trim($_POST['transcript'] ?? '');
        $style = trim($_POST['style'] ?? '详细');
        $model = trim($_POST['model'] ?? DEEPSEEK_MODEL_DEFAULT);

        if (empty($text)) {
            echo json_encode(['error' => '转录文本为空'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (mb_strlen($text) > 15000) $text = mb_substr($text, 0, 15000) . "\n...(已截断)";
        if (!in_array($model, ['deepseek-v4-flash', 'deepseek-v4-pro'])) $model = DEEPSEEK_MODEL_DEFAULT;

        echo json_encode(callDeepSeek($text, $style, $model), JSON_UNESCAPED_UNICODE);

    } else {
        echo json_encode(['error' => '无效 action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
