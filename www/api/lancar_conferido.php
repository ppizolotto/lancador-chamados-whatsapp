<?php
// Tela 5 (simulação) — o usuário conferiu o formulário do dia: o worker passa ao próximo.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
exigir_post();

$jobId = (int) ($_POST['job'] ?? 0);
$data = (string) ($_POST['data'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    responder_json(['erro' => 'Data inválida.'], 400);
}
$pdo = db();
$st = $pdo->prepare("SELECT 1 FROM dias WHERE job_id = ? AND data = ? AND status = 'conferindo'");
$st->execute([$jobId, $data]);
if (!$st->fetchColumn()) {
    responder_json(['erro' => 'Este dia não está aguardando conferência.'], 409);
}
$pdo->prepare('INSERT OR REPLACE INTO meta(chave, valor) VALUES(?, ?)')->execute(["conferido_job_$jobId", $data]);
responder_json(['ok' => true]);
