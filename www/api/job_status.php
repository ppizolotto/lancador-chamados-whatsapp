<?php
// Estado do job para as telas 3–6 (consultado a cada 1,5 s).
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require RAIZ . '/app/conversa.php';

$job = (int) ($_GET['job'] ?? 0);
$pdo = db();
$j = $pdo->prepare('SELECT * FROM jobs WHERE id = ?');
$j->execute([$job]);
$j = $j->fetch();
if (!$j) {
    responder_json(['erro' => 'Job não encontrado.'], 404);
}
unset($j['hash']);
$sol = (string) $j['solicitante'];
$d = $pdo->prepare('SELECT d.data, d.tem_solicitacao, d.titulo, d.itens_json, d.situacao, d.selecionado, d.status,
                           d.provedor, d.erro, t.protocolo, t.situacao_final, t.corrigido, t.erro AS ticket_erro
                    FROM dias d LEFT JOIN tickets t ON t.data = d.data AND t.job_id = d.job_id AND t.solicitante = ?
                    WHERE d.job_id = ? ORDER BY d.data');
$d->execute([$sol, $job]);
$dias = array_map(function ($r) {
    $r['itens'] = json_decode((string) $r['itens_json'], true) ?: [];
    unset($r['itens_json']);
    return $r;
}, $d->fetchAll());

// Dias deste job que já viraram ticket deste solicitante (neste job, em outro ou antes): nunca lançar de novo.
$lancados = $pdo->prepare('SELECT t.data FROM tickets t JOIN dias d ON d.data = t.data
                           WHERE d.job_id = ? AND t.solicitante = ?');
$lancados->execute([$job, $sol]);
$cad = solicitante_cadastro($sol);

responder_json([
    'job' => $j,
    'dias' => $dias,
    'hoje' => date('Y-m-d'),
    'ultimo_lancado' => ultimo_dia_lancado($sol),
    'solicitante' => $sol,
    'solicitante_helpdesk' => $cad['nome_helpdesk'] ?? null,
    'ja_lancados' => $lancados->fetchAll(PDO::FETCH_COLUMN),
    'simulacao' => (bool) config()['simulacao'],
    'primeiro_real' => primeiro_lancamento_real(),
    'url_base' => rtrim((string) config()['url_base'], '/'),
]);
