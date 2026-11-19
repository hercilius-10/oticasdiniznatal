<?php
// Recebe o formulário das LPs (Diniz e Diniz Prime) e envia por e-mail.
// Destinatários ficam em config-form.php, gerado no deploy a partir do secret FORM_TO (não vai ao repositório).
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sair(int $code, array $body = []): void { http_response_code($code); echo json_encode($body); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sair(405, ['ok' => false]);

$cfgFile = __DIR__ . '/config-form.php';
$cfg = is_file($cfgFile) ? include $cfgFile : null;
if (!is_array($cfg) || empty($cfg['to']) || empty($cfg['from'])) sair(500, ['ok' => false]);

// Anti-spam: campo isca preenchido = robô. Responde ok sem enviar.
if (trim((string)($_POST['website'] ?? '')) !== '') sair(200, ['ok' => true]);

// Mesma origem (o formulário vive no próprio domínio)
$host = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$orig = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($orig !== '' && stripos($orig, $host) === false) sair(403, ['ok' => false]);

function limpa(string $s, int $max): string {
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

$marcaChave = ($_POST['marca'] ?? '') === 'prime' ? 'prime' : 'diniz';
$marca  = $marcaChave === 'prime' ? 'Óticas Diniz Prime' : 'Óticas Diniz';
$nome   = limpa((string)($_POST['nome'] ?? ''), 80);
$loja   = limpa((string)($_POST['loja'] ?? ''), 80);
$inter  = limpa((string)($_POST['interesse'] ?? ''), 80);
$pagina = limpa((string)($_POST['pagina'] ?? ''), 200);
if ($nome === '') sair(422, ['ok' => false]);

// Limite simples por visitante: 8 envios por hora
$chave = sys_get_temp_dir() . '/dnz_form_' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('YmdH'));
$n = is_file($chave) ? (int)@file_get_contents($chave) : 0;
if ($n >= 8) sair(429, ['ok' => false]);
@file_put_contents($chave, (string)($n + 1));

$utm = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) {
    $v = limpa((string)($_POST[$k] ?? ''), 100);
    if ($v !== '') $utm[] = "$k: $v";
}

date_default_timezone_set('America/Fortaleza');
$quando = date('d/m/Y H:i');

$assunto = '=?UTF-8?B?' . base64_encode("Novo contato pelo site | $marca | $nome") . '?=';
$corpo = "Novo contato pelo site\r\n\r\n"
       . "Marca: $marca\r\n"
       . "Nome: $nome\r\n"
       . "Loja de preferência: $loja\r\n"
       . "Interesse: $inter\r\n"
       . "Página: $pagina\r\n"
       . "Origem: " . ($utm ? implode(' | ', $utm) : 'sem UTM') . "\r\n"
       . "Enviado em: $quando (horário de Natal)\r\n\r\n"
       . "O visitante foi direcionado ao WhatsApp da central, (84) 99691-9690, com estas informações na mensagem.\r\n";

$de = $cfg['from'];
$headers = [
    'From: =?UTF-8?B?' . base64_encode('Site ' . $marca) . "?= <$de>",
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: PHP',
];

$ok = @mail($cfg['to'], $assunto, $corpo, implode("\r\n", $headers), '-f' . $de);
sair($ok ? 200 : 500, ['ok' => $ok]);
