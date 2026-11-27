<?php
// Tela 2: recebe a conversa exportada, mostra o período e cria o job (nada é enviado a lugar nenhum aqui).
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require RAIZ . '/app/conversa.php';
exigir_post();

$arq = $_FILES['arquivo'] ?? null;
if (!$arq || $arq['error'] !== UPLOAD_ERR_OK) {
    responder_json(['erro' => 'Não recebi o arquivo. Tente de novo.'], 400);
}
$nome = basename((string) $arq['name']);
if (!preg_match('/\.(txt|md|zip)$/i', $nome)) {
    responder_json(['erro' => 'Use o arquivo exportado do WhatsApp: .txt, .md ou o .zip da exportação.'], 400);
}
if ($arq['size'] > 20 * 1024 * 1024) {
    responder_json(['erro' => 'Arquivo grande demais (máximo 20 MB).'], 400);
}
if (preg_match('/\.zip$/i', $nome)) {
    // Exportação "com mídia" ou do iPhone vem em .zip: usa só o texto da conversa, ignora fotos e áudios.
    $zip = new ZipArchive();
    if ($zip->open($arq['tmp_name']) !== true) {
        responder_json(['erro' => 'Não consegui abrir o .zip.'], 400);
    }
    $escolhido = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if (!preg_match('/\.(txt|md)$/i', $st['name']) || $st['size'] > 20 * 1024 * 1024) {
            continue;
        }
        if ($escolhido === null || preg_match('/chat|conversa/i', basename($st['name']))) {
            $escolhido = $st['name'];
        }
    }
    $texto = $escolhido !== null ? (string) $zip->getFromName($escolhido) : '';
    $zip->close();
    if ($texto === '') {
        responder_json(['erro' => 'O .zip não tem o arquivo de texto da conversa (.txt ou .md).'], 400);
    }
} else {
    $texto = (string) file_get_contents($arq['tmp_name']);
}
if (!mb_check_encoding($texto, 'UTF-8')) {
    $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
}

$leitura = analisar_conversa($texto);
$porDia = $leitura['dias'];
if (!$porDia) {
    // Mostra só o FORMATO das primeiras linhas (letras = a, números = 9), nunca o conteúdo.
    responder_json([
        'erro' => 'Não reconheci as mensagens deste arquivo. Formato das primeiras linhas (sem o conteúdo): '
            . implode('  |  ', formato_linhas($texto)) . '. Envie esse formato ao suporte do app para ajustarmos.',
    ], 422);
}

// Solicitante = quem escreve na conversa além de "Você" (o técnico que exportou).
// Se houver mais de um, usa o que mais escreveu, ou o escolhido pelo usuário na Tela 2.
$remetentes = $leitura['remetentes'];
if (!$remetentes) {
    responder_json(['erro' => 'Não encontrei quem escreveu nesta conversa além de você. Confira o arquivo.'], 422);
}
$pedido = trim((string) ($_POST['solicitante'] ?? ''));
$solicitante = ($pedido !== '' && isset($remetentes[$pedido])) ? $pedido : (string) array_key_first($remetentes);
$cadastro = solicitante_cadastro($solicitante);

$pdo = db();
$st = $pdo->prepare('SELECT data FROM tickets WHERE solicitante = ?');
$st->execute([$solicitante]);
$lancados = $st->fetchAll(PDO::FETCH_COLUMN);
$ultimo = ultimo_dia_lancado($solicitante);
$hoje = date('Y-m-d');

$dias = [];
$pendentes = [];
foreach ($porDia as $data => $qtd) {
    $jaLancado = in_array($data, $lancados, true);
    $antesDoMarcador = $ultimo && $data <= $ultimo;
    $situacao = $jaLancado ? 'lancado' : ($antesDoMarcador ? 'anterior' : ($data === $hoje ? 'hoje' : 'pendente'));
    $dias[] = ['data' => $data, 'data_br' => data_br($data), 'mensagens' => $qtd, 'situacao' => $situacao];
    if ($situacao === 'pendente' || $situacao === 'hoje') {
        $pendentes[] = $data;
    }
}

// Apaga conversas de jobs anteriores que não estão em análise (não guardar texto à toa).
$rodando = $pdo->query("SELECT id FROM jobs WHERE analise_status = 'rodando'")->fetchAll(PDO::FETCH_COLUMN);
@mkdir(DIR_DATA . '/conversas', 0775, true);
foreach (glob(DIR_DATA . '/conversas/job-*.txt') ?: [] as $velho) {
    if (!in_array((int) preg_replace('/\D/', '', basename($velho)), array_map('intval', $rodando), true)) {
        @unlink($velho);
    }
}

$pdo->beginTransaction();
$pdo->prepare('INSERT INTO jobs(arquivo, solicitante, hash, criado_em, primeiro_dia, ultimo_dia) VALUES(?, ?, ?, ?, ?, ?)')
    ->execute([$nome, $solicitante, hash('sha256', $texto), date('c'), array_key_first($porDia), array_key_last($porDia)]);
$job = (int) $pdo->lastInsertId();
$ins = $pdo->prepare('INSERT INTO dias(job_id, data, selecionado) VALUES(?, ?, ?)');
foreach ($pendentes as $d) {
    $ins->execute([$job, $d, $d === $hoje ? 0 : 1]);
}
$pdo->commit();

file_put_contents(DIR_DATA . "/conversas/job-$job.txt", $texto, LOCK_EX);
log_app('info', "Conversa carregada: " . count($porDia) . " dias, " . count($pendentes) . " a analisar", $job);

responder_json([
    'job' => $job,
    'arquivo' => $nome,
    'solicitante' => $solicitante,
    'remetentes' => array_keys($remetentes),
    'cadastro' => $cadastro ? ['busca' => $cadastro['busca'], 'nome_helpdesk' => $cadastro['nome_helpdesk']] : null,
    'primeiro' => data_br(array_key_first($porDia)),
    'ultimo' => data_br(array_key_last($porDia)),
    'total_dias' => count($porDia),
    'ultimo_lancado' => $ultimo ? data_br($ultimo) : null,
    'a_analisar' => count($pendentes),
    'dias' => $dias,
]);
