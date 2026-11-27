<?php
// Tela 6 — grava o relatório do job em CSV na pasta Relatorios/ do app e abre o Explorer com o arquivo selecionado.
// Só metadados (dia, solicitante, protocolo, situação): nenhum texto da conversa.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require RAIZ . '/app/conversa.php';
exigir_post();

$job = (int) ($_POST['job'] ?? 0);
$pdo = db();
$j = $pdo->prepare('SELECT solicitante FROM jobs WHERE id = ?');
$j->execute([$job]);
$sol = $j->fetchColumn();
if ($sol === false) {
    responder_json(['erro' => 'Job não encontrado.'], 404);
}
$st = $pdo->prepare("SELECT d.data, d.status, d.erro, t.protocolo, t.situacao_final, t.corrigido, t.erro AS ticket_erro
                     FROM dias d LEFT JOIN tickets t ON t.data = d.data AND t.job_id = d.job_id AND t.solicitante = ?
                     WHERE d.job_id = ? AND d.selecionado = 1 AND d.tem_solicitacao = 1 ORDER BY d.data");
$st->execute([(string) $sol, $job]);
$base = rtrim((string) config()['url_base'], '/');

$dir = RAIZ . '/Relatorios';
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    responder_json(['erro' => 'Não consegui criar a pasta Relatorios.'], 500);
}
$arq = $dir . '/relatorio-' . date('Y-m-d-His') . "-job$job.csv";
$f = fopen($arq, 'wb');
fwrite($f, "\xEF\xBB\xBF"); // BOM: o Excel abre os acentos certo
$linha = fn(array $c) => fputcsv($f, $c, ';', '"', '');
$linha(['Dia', 'Solicitante', 'Protocolo', 'Situação final', 'Corrigido na verificação', 'Observação', 'Link']);
foreach ($st as $r) {
    $obs = $r['ticket_erro'] ?: ($r['protocolo'] ? '' : ($r['erro'] ?: ($r['status'] === 'simulado' ? 'simulação (não enviado)' : '')));
    $linha([
        data_br($r['data']), (string) $sol, (string) $r['protocolo'],
        (string) ($r['situacao_final'] ?? ($r['status'] === 'simulado' ? '—' : 'não lançado')),
        $r['protocolo'] ? ((int) $r['corrigido'] ? 'sim' : 'não') : '',
        (string) $obs,
        $r['protocolo'] ? $base . '/atendimento/' . $r['protocolo'] : '',
    ]);
}
fclose($f);
log_app('info', 'Relatório CSV exportado: ' . basename($arq), $job);

try {
    executar_em_segundo_plano(['explorer.exe', '/select,' . str_replace('/', '\\', $arq)]);
} catch (Throwable $e) {
    error_log('relatorio_csv explorer: ' . $e->getMessage());
}
responder_json(['ok' => true, 'arquivo' => 'Relatorios\\' . basename($arq)]);
