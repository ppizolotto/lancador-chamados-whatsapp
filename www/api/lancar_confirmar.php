<?php
// Tela 4 — grava a escolha do usuário (dias a lançar) e INICIA o lançamento (ou a simulação).
// É o único caminho que dispara o worker "lancar": só roda depois do clique em "Lançar/Simular N tickets".
// Tudo é conferido de novo aqui: a tela pode estar desatualizada.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require RAIZ . '/app/conversa.php';
exigir_post();

$jobId = (int) ($_POST['job'] ?? 0);
$datas = json_decode((string) ($_POST['datas'] ?? '[]'), true);
if (!is_array($datas)) {
    responder_json(['erro' => 'Seleção inválida.'], 400);
}
$datas = array_values(array_unique(array_filter($datas, fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d))));
if (!$datas) {
    responder_json(['erro' => 'Nenhum dia selecionado.'], 400);
}

$pdo = db();
$st = $pdo->prepare('SELECT * FROM jobs WHERE id = ?');
$st->execute([$jobId]);
$job = $st->fetch();
if (!$job) {
    responder_json(['erro' => 'Job não encontrado.'], 404);
}
if ($job['analise_status'] !== 'ok' || $job['login_status'] !== 'ok') {
    responder_json(['erro' => 'A análise e o login precisam estar concluídos.'], 409);
}
if (job_em_andamento()) {
    responder_json(['erro' => 'Há outra etapa em andamento. Aguarde terminar.'], 409);
}
if (!in_array($job['lancar_status'], ['aguardando', 'confirmado'], true)) {
    responder_json(['erro' => 'Este lançamento já foi iniciado.'], 409);
}

$cfg = config();
if (!$cfg['simulacao'] && primeiro_lancamento_real() && count($datas) > 1) {
    responder_json(['erro' => 'Primeiro lançamento real: escolha só 1 dia e confira o ticket no Helpdesk antes de lançar os outros.'], 409);
}

$dia = $pdo->prepare("SELECT status, tem_solicitacao FROM dias WHERE job_id = ? AND data = ?");
$sol = (string) $job['solicitante'];
if (!solicitante_cadastro($sol)) {
    responder_json(['erro' => 'Solicitante sem cadastro (Administração → Solicitantes).'], 409);
}
$lancado = $pdo->prepare('SELECT 1 FROM tickets WHERE data = ? AND solicitante = ?');
foreach ($datas as $d) {
    $dia->execute([$jobId, $d]);
    $r = $dia->fetch();
    if (!$r || $r['status'] !== 'analisado' || !(int) $r['tem_solicitacao']) {
        responder_json(['erro' => 'O dia ' . data_br($d) . ' não pode ser lançado (sem solicitação ou sem análise).'], 409);
    }
    $lancado->execute([$d, $sol]);
    if ($lancado->fetchColumn()) {
        responder_json(['erro' => 'O dia ' . data_br($d) . ' já foi lançado antes.'], 409);
    }
}

$pdo->beginTransaction();
$pdo->prepare('UPDATE dias SET selecionado = 0 WHERE job_id = ?')->execute([$jobId]);
$marca = $pdo->prepare('UPDATE dias SET selecionado = 1 WHERE job_id = ? AND data = ?');
foreach ($datas as $d) {
    $marca->execute([$jobId, $d]);
}
sort($datas);
$n = count($datas);
$msg = "$n " . ($n === 1 ? 'dia confirmado' : 'dias confirmados') . ': ' . data_br($datas[0])
     . ($n > 1 ? ' a ' . data_br(end($datas)) : '') . ($cfg['simulacao'] ? ' (simulação)' : '');
$pdo->prepare("UPDATE jobs SET lancar_status = 'rodando', lancar_progresso = 0, lancar_msg = ? WHERE id = ?")
    ->execute(['Iniciando… ' . $msg, $jobId]);
$pdo->commit();
log_app('info', 'Confirmação: ' . $msg, $jobId);

try {
    executar_em_segundo_plano([...PYTHON_ISOLADO, DIR_WORKER . '/worker.py', 'lancar', '--job', (string) $jobId]);
} catch (Throwable $e) {
    error_log('lancar_confirmar: ' . $e->getMessage());
    $pdo->prepare("UPDATE jobs SET lancar_status = 'erro', lancar_msg = 'Não foi possível iniciar o lançamento.' WHERE id = ?")
        ->execute([$jobId]);
    responder_json(['erro' => 'Não foi possível iniciar o lançamento.'], 500);
}

responder_json(['ok' => true, 'quantidade' => $n]);
