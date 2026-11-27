<?php
// Tela 2 → 3: inicia, AO MESMO TEMPO, a análise (IA) e o login no Helpdesk. Nada é enviado ao Helpdesk aqui.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
exigir_post();

$job = (int) ($_POST['job'] ?? 0);
$pdo = db();
$j = $pdo->prepare('SELECT * FROM jobs WHERE id = ?');
$j->execute([$job]);
$j = $j->fetch();
if (!$j) {
    responder_json(['erro' => 'Job não encontrado.'], 404);
}
if (!solicitante_cadastro((string) $j['solicitante'])) {
    responder_json(['erro' => 'Cadastre o solicitante antes de prosseguir.'], 409);
}
$arquivo = DIR_DATA . "/conversas/job-$job.txt";

try {
    if ($j['analise_status'] === 'aguardando') {
        if (!is_file($arquivo)) {
            responder_json(['erro' => 'O arquivo da conversa não está mais disponível. Carregue de novo.'], 409);
        }
        $pdo->prepare("UPDATE jobs SET analise_status='rodando', analise_msg='Iniciando…' WHERE id=?")->execute([$job]);
        executar_em_segundo_plano([...PYTHON_ISOLADO, DIR_WORKER . '/worker.py', 'analisar', '--job', (string) $job, '--arquivo', $arquivo]);
    }
    if ($j['login_status'] !== 'ok' && $j['login_status'] !== 'rodando') {
        $pdo->prepare("UPDATE jobs SET login_status='rodando', login_msg='Abrindo o navegador…' WHERE id=?")->execute([$job]);
        executar_em_segundo_plano([...PYTHON_ISOLADO, DIR_WORKER . '/worker.py', 'login', '--job', (string) $job]);
    }
} catch (Throwable $e) {
    error_log('job_iniciar: ' . $e->getMessage());
    responder_json(['erro' => 'Não foi possível iniciar o worker.'], 500);
}
responder_json(['ok' => true]);
