<?php
// Tela 4 — o usuário corrige o que a IA extraiu de um dia (título, itens, situação) antes de lançar.
// Só dias ainda não lançados; o texto passa pela mesma limpeza LGPD da análise.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require RAIZ . '/app/conversa.php';
exigir_post();

$jobId = (int) ($_POST['job'] ?? 0);
$data = (string) ($_POST['data'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    responder_json(['erro' => 'Data inválida.'], 400);
}
$pdo = db();
$st = $pdo->prepare('SELECT j.lancar_status, j.solicitante, d.status, d.tem_solicitacao FROM jobs j JOIN dias d ON d.job_id = j.id
                     WHERE j.id = ? AND d.data = ?');
$st->execute([$jobId, $data]);
$r = $st->fetch();
if (!$r) {
    responder_json(['erro' => 'Dia não encontrado.'], 404);
}
if (!in_array($r['lancar_status'], ['aguardando', 'confirmado'], true)) {
    responder_json(['erro' => 'O lançamento deste job já começou; não dá para editar agora.'], 409);
}
if ($r['status'] !== 'analisado' || !(int) $r['tem_solicitacao']) {
    responder_json(['erro' => 'Só dá para editar dias analisados com solicitação.'], 409);
}
$ja = $pdo->prepare('SELECT 1 FROM tickets WHERE data = ? AND solicitante = ?');
$ja->execute([$data, (string) $r['solicitante']]);
if ($ja->fetchColumn()) {
    responder_json(['erro' => 'Este dia já foi lançado.'], 409);
}

$um = fn($s) => trim((string) preg_replace('/\s+/u', ' ', (string) $s));
$titulo = $um($_POST['titulo'] ?? '');
$situacao = $um($_POST['situacao'] ?? '');
$itens = array_values(array_filter(array_map($um, preg_split('/\r\n|\r|\n/', (string) ($_POST['itens'] ?? ''))),
    fn($i) => $i !== ''));
$itens = array_map(fn($i) => preg_replace('/^[-•*]\s*/u', '', $i), $itens); // tira marcadores colados

if ($titulo === '' || !$itens || $situacao === '') {
    responder_json(['erro' => 'Preencha o título, ao menos um item e a situação.'], 400);
}
if (count($itens) > 40 || max(array_map('mb_strlen', $itens)) > 500 || mb_strlen($situacao) > 800) {
    responder_json(['erro' => 'Texto longo demais (até 40 itens de 500 caracteres; situação até 800).'], 400);
}

// Mesmo padrão de assunto da análise
$prefixo = 'Solicitações WhatsApp ' . data_br($data) . ' - ';
if (!str_starts_with($titulo, 'Solicitações WhatsApp')) {
    $titulo = $prefixo . $titulo;
}
$antes = implode("\n", [$titulo, ...$itens, $situacao]);
$titulo = limpar_lgpd($titulo);
$itens = array_map('limpar_lgpd', $itens);
$situacao = limpar_lgpd($situacao);
$removido = implode("\n", [$titulo, ...$itens, $situacao]) !== $antes;
$titulo = mb_substr($titulo, 0, 120);

$pdo->prepare("UPDATE dias SET titulo = ?, itens_json = ?, situacao = ?,
                 provedor = CASE WHEN provedor LIKE '%editado' THEN provedor ELSE COALESCE(provedor, 'IA') || ' + editado' END
               WHERE job_id = ? AND data = ?")
    ->execute([$titulo, json_encode($itens, JSON_UNESCAPED_UNICODE), $situacao, $jobId, $data]);
log_app('info', "Dia $data editado pelo usuário (" . count($itens) . ' itens)', $jobId);

responder_json(['ok' => true, 'titulo' => $titulo, 'itens' => $itens, 'situacao' => $situacao,
                'aviso' => $removido ? 'Dados pessoais (CPF, CNS, senha ou login) foram removidos do texto.' : null]);
