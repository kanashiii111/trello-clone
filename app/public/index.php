<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
\App\Database::boot();

use App\Models\Note;
use App\Metrics;
use App\Log;

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestBody = '';
if ($requestMethod === 'POST' && isset($_POST['title'])) {
    $requestBody = $_POST['title'] . ' ' . ($_POST['content'] ?? '');
}
$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
Log::info('Incoming HTTP request', [
    'method' => $requestMethod,
    'uri' => $requestUri,
    'body' => $requestBody,
    'request_id' => $requestId,
]);

ob_start();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function setApplicationName(): void
{
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    if ($requestId !== '' && preg_match('/^[A-Za-z0-9-]{1,63}$/', $requestId) === 1) {
        $pdo = \App\Database::connection()->getPdo();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->query("SET application_name = '$requestId'");
    }
}

$validationError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['title'])) {
    $title = trim((string) ($_POST['title'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));

    if ($title === '' || $content === '') {
        $validationError = 'Заполните все поля';
    } else {
        try {
            setApplicationName();
            \App\Models\Note::create(['title' => $title, 'content' => $content]);
            \App\Metrics::noteCreated();
            header('Location: /?created=1');
            exit;
        } catch (\Throwable $e) {
            Log::error('note.create_failed', ['error' => $e->getMessage()]);
            $validationError = 'Не удалось сохранить заметку';
        }
    }
}

$rows = [];

try {
    setApplicationName();
    $rows = \App\Models\Note::orderByDesc('id')->get()->toArray();
} catch (\Throwable $e) {
}

$responseBody = ob_get_clean();
$responseStatus = http_response_code() ?? 200;
Log::info('HTTP response', [
    'request_id' => $requestId,
    'status' => $responseStatus,
    'body' => $responseBody,
]);

$showCreated = ($_GET['created'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title>Notes</title>
<style>
:root {
    --bg: #0e0f13;
    --bg-2: #0a0b0e;
    --card: #16171d;
    --card-2: #1c1d25;
    --border: #25262f;
    --border-hi: #353643;
    --text: #e4e4ec;
    --text-2: #9899a8;
    --text-3: #6c6d7e;
    --accent: #2dd4bf;
    --accent-dim: rgba(45, 212, 191, .14);
    --accent-border: rgba(45, 212, 191, .35);
    --ok: #34d399;
    --ok-dim: rgba(52, 211, 153, .12);
    --err: #fb7185;
    --err-dim: rgba(251, 113, 133, .1);
    --err-border: rgba(251, 113, 133, .3);
    --warn: #fbbf24;
    --warn-dim: rgba(251, 191, 36, .1);
    --warn-border: rgba(251, 191, 36, .3);
    --radius: 14px;
    --mono: "SF Mono", ui-monospace, Menlo, Consolas, monospace;
}
*, *::before, *::after { box-sizing: border-box; }

body {
    margin: 0;
    min-height: 100vh;
    font: 15px/1.65 system-ui, -apple-system, sans-serif;
    color: var(--text);
    background:
        radial-gradient(ellipse 600px 300px at 70% -10%, rgba(45,212,191,.06), transparent),
        radial-gradient(ellipse 500px 400px at 10% 100%, rgba(45,212,191,.03), transparent),
        var(--bg);
    -webkit-font-smoothing: antialiased;
}

.container {
    width: min(100% - 40px, 740px);
    margin: 0 auto;
    padding: 48px 0 80px;
}

header {
    margin-bottom: 36px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border);
}
.logo {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font: 700 22px/1.2 var(--mono);
    letter-spacing: -.02em;
    color: var(--text);
}
.logo-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--accent);
    box-shadow: 0 0 12px rgba(45,212,191,.5);
}
.subtitle {
    margin: 6px 0 0;
    font-size: 13px;
    color: var(--text-3);
    font-family: var(--mono);
}

.alert {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: var(--radius);
    margin-bottom: 20px;
    font-size: 14px;
}
.alert--ok {
    background: var(--ok-dim);
    border: 1px solid rgba(52,211,153,.25);
    color: var(--ok);
}
.alert--err {
    background: var(--err-dim);
    border: 1px solid var(--err-border);
    color: var(--err);
}
.alert--warn {
    background: var(--warn-dim);
    border: 1px solid var(--warn-border);
    color: var(--warn);
}

.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 28px;
}
.card-label {
    font: 600 11px/1 var(--mono);
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--text-3);
    margin: 0 0 18px;
}
form { display: grid; gap: 14px; }
label {
    display: block;
    font: 500 12px/1 var(--mono);
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--text-2);
    margin-bottom: 6px;
}
input[type="text"], textarea {
    width: 100%;
    font: inherit;
    color: var(--text);
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px 14px;
    transition: border-color .2s, box-shadow .2s;
}
textarea { min-height: 120px; resize: vertical; }
input::placeholder, textarea::placeholder { color: var(--text-3); }
input:hover, textarea:hover { border-color: var(--border-hi); }
input:focus, textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-dim);
}
.btn {
    justify-self: start;
    font: 600 14px/1 system-ui, sans-serif;
    color: var(--bg);
    background: var(--accent);
    border: 0;
    border-radius: 10px;
    padding: 11px 22px;
    cursor: pointer;
    transition: opacity .2s, transform .15s;
}
.btn:hover { opacity: .88; }
.btn:active { transform: scale(.97); }

.notes-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 18px;
}
.notes-title {
    margin: 0;
    font: 600 18px/1.3 var(--mono);
    letter-spacing: -.01em;
}
.notes-count {
    font: 500 11px/1 var(--mono);
    color: var(--accent);
    background: var(--accent-dim);
    border: 1px solid var(--accent-border);
    border-radius: 999px;
    padding: 5px 10px;
}

.note {
    padding: 18px 0;
    border-top: 1px solid var(--border);
    animation: fadeUp .3s ease both;
}
.note:first-child { border-top: 0; padding-top: 0; }
.note:last-child { padding-bottom: 0; }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: none; }
}

.note-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    font: 500 11px/1 var(--mono);
    color: var(--text-3);
    margin-bottom: 8px;
}
.note-id {
    color: var(--accent);
    background: var(--accent-dim);
    border: 1px solid var(--accent-border);
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 10.5px;
}
.note-date { opacity: .7; }
.note-title {
    margin: 0 0 6px;
    font-size: 15px;
    font-weight: 650;
    letter-spacing: -.01em;
    overflow-wrap: anywhere;
}
.note-body {
    margin: 0;
    font-size: 14px;
    line-height: 1.6;
    color: var(--text-2);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.empty {
    text-align: center;
    padding: 48px 24px;
    border: 1px dashed var(--border-hi);
    border-radius: var(--radius);
    background: var(--bg-2);
}
.empty-icon { font-size: 28px; margin-bottom: 12px; opacity: .4; }
.empty-title { margin: 0; font: 600 14px/1.4 var(--mono); color: var(--text-2); }
.empty-sub { margin: 6px 0 0; font-size: 13px; color: var(--text-3); }
</style>
</head>
<body>
<div class="container">
    <header>
        <div class="logo">
            <span class="logo-dot"></span>
            notes
        </div>
        <p class="subtitle">треллыч</p>
    </header>

    <?php if ($showCreated): ?>
        <div class="alert alert--ok">✓ Заметка сохранена</div>
    <?php endif; ?>
    <?php if ($validationError !== null): ?>
        <div class="alert alert--warn">⚠ <?= h($validationError) ?></div>
    <?php endif; ?>

    <section class="card">
        <p class="card-label">новая запись</p>
        <form method="post" action="/">
            <div>
                <label for="title">Заголовок</label>
                <input type="text" id="title" name="title" required maxlength="255" placeholder="О чём думаем?">
            </div>
            <div>
                <label for="content">Текст</label>
                <textarea id="content" name="content" required placeholder="Распиши подробнее..."></textarea>
            </div>
            <button type="submit" class="btn">Сохранить</button>
        </form>
    </section>

    <div class="notes-header">
        <h2 class="notes-title">Заметки</h2>
        <span class="notes-count"><?= count($rows) ?></span>
    </div>

    <?php if (empty($rows)): ?>
        <div class="empty">
            <div class="empty-icon">◌</div>
            <p class="empty-title">Пока пусто</p>
            <p class="empty-sub">Напиши первую заметку выше</p>
        </div>
    <?php else: ?>
        <?php foreach ($rows as $row): ?>
            <article class="note">
                <div class="note-meta">
                    <span class="note-id">#<?= h((string) $row['id']) ?></span>
                    <span class="note-date"><?= h((string) $row['created_datetime']) ?></span>
                </div>
                <h3 class="note-title"><?= h((string) $row['title']) ?></h3>
                <p class="note-body"><?= h((string) $row['content']) ?></p>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
