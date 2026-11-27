<?php
// Tela 5 — volta à confirmação (Tela 4) do mesmo job, sem analisar de novo.
// Uso típico: depois da simulação, desligar a simulação e lançar de verdade.
// Dias que já viraram ticket continuam bloqueados (tabela tickets), então não há risco de duplicar.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
exigir_post();

$jobId = (int) ($_POST['job'] ?? 0);
$pdo = db();
$st = $pdo->prepare('SELECT lancar_status FROM jobs WHERE id = ?');
$st->execute([$jobId]);
$status = $st->fetchColumn();
if ($status === false) {
    responder_json(['erro' => 'Job não encontrado.'], 404);
}
if ($status === 'rodando') {
    responder_json(['erro' => 'O lançamento ainda está em andamento.'], 409);
}
$pdo->beginTransaction();
$pdo->prepare("UPDATE dias SET status = 'analisado', erro = NULL
               WHERE job_id = ? AND tem_solicitacao IS NOT NULL AND status IN ('simulado', 'abrindo', 'conferindo', 'erro')")
    ->execute([$jobId]);
$pdo->prepare("UPDATE jobs SET lancar_status = 'aguardando', lancar_progresso = 0, lancar_msg = NULL WHERE id = ?")
    ->execute([$jobId]);
$pdo->commit();
log_app('info', 'Voltou à confirmação', $jobId);
responder_json(['ok' => true]);
