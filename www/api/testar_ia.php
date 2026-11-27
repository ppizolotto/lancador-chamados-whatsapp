<?php
// Administração: testa UM provedor de IA com um texto neutro (nenhum dado da conversa é enviado).
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
exigir_post();
set_time_limit(50);

$prov = (string) ($_POST['provedor'] ?? '');
if (!isset(PROVEDORES[$prov])) {
    responder_json(['erro' => 'Provedor inválido.'], 400);
}
if (!is_file(PYTHON)) {
    responder_json(['erro' => 'Instale o Python primeiro (Tela 1).'], 409);
}
[, $saida] = executar([...PYTHON_ISOLADO, DIR_WORKER . '/worker.py', 'testar', '--provedor', $prov], 40);
$d = json_decode(trim($saida), true);
if (!is_array($d) || isset($d['erro'])) {
    responder_json(['erro' => 'Não foi possível testar agora.'], 500);
}
responder_json($d);
