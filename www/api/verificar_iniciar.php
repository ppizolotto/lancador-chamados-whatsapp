<?php
// Tela 6 — inicia a verificação final (worker `verificar`): reabre cada ticket do job e corrige o que não ficou Resolvido.
// Não abre ticket novo. Roda sozinha ao entrar na Tela 6 (uma vez) ou pelo botão "Conferir de novo".
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
exigir_post();

$job = (int) ($_POST['job'] ?? 0);
$forcar = ($_POST['forcar'] ?? '') === '1';
$pdo = db();
$j = $pdo->prepare('SELECT lancar_status, verificar_status FROM jobs WHERE id = ?');
$j->execute([$job]);
$j = $j->fetch();
if (!$j) {
    responder_json(['erro' => 'Job não encontrado.'], 404);
}
if (!in_array($j['lancar_status'], ['ok', 'erro'], true)) {
    responder_json(['erro' => 'O lançamento deste job ainda não terminou.'], 409);
}
if ($j['verificar_status'] === 'rodando') {
    responder_json(['ok' => true, 'ja_rodando' => true]);
}
if ($j['verificar_status'] !== 'aguardando' && !$forcar) {
    responder_json(['ok' => true, 'ja_feita' => true]);
}
$n = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE job_id = ? AND protocolo IS NOT NULL AND protocolo != ''");
$n->execute([$job]);
if (!(int) $n->fetchColumn()) {
    $pdo->prepare("UPDATE jobs SET verificar_status='ok', verificar_msg=? WHERE id=?")
        ->execute(['Nenhum ticket aberto neste lançamento: nada a conferir.', $job]);
    responder_json(['ok' => true, 'nada' => true]);
}
if (job_em_andamento()) {
    responder_json(['erro' => 'Há outra etapa usando o navegador do app (análise, login ou lançamento). Espere terminar.'], 409);
}
$pdo->prepare("UPDATE jobs SET verificar_status='rodando', verificar_msg='Abrindo o navegador…' WHERE id=?")->execute([$job]);
try {
    executar_em_segundo_plano([...PYTHON_ISOLADO, DIR_WORKER . '/worker.py', 'verificar', '--job', (string) $job]);
} catch (Throwable $e) {
    error_log('verificar_iniciar: ' . $e->getMessage());
    $pdo->prepare("UPDATE jobs SET verificar_status='erro', verificar_msg='Não foi possível iniciar o worker.' WHERE id=?")->execute([$job]);
    responder_json(['erro' => 'Não foi possível iniciar o worker.'], 500);
}
log_app('info', 'Verificação final iniciada', $job);
responder_json(['ok' => true]);
