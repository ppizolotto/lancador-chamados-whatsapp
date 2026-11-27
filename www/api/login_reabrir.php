<?php
// Tela 3: "Abrir de novo" — reabre a janela de login se o usuário fechou ou se o tempo acabou.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
exigir_post();

$job = (int) ($_POST['job'] ?? 0);
$st = db()->prepare('SELECT login_status FROM jobs WHERE id = ?');
$st->execute([$job]);
$status = $st->fetchColumn();
if ($status === false) {
    responder_json(['erro' => 'Job não encontrado.'], 404);
}
if ($status === 'rodando') {
    responder_json(['erro' => 'A janela de login já está aberta.'], 409);
}
db()->prepare("UPDATE jobs SET login_status='rodando', login_msg='Abrindo o navegador…' WHERE id=?")->execute([$job]);
executar_em_segundo_plano([...PYTHON_ISOLADO, DIR_WORKER . '/worker.py', 'login', '--job', (string) $job]);
responder_json(['ok' => true]);
