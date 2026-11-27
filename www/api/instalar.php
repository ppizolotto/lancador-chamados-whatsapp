<?php
// Inicia a instalação de UM item, em segundo plano. Só roda após o clique do usuário em "Instalar".
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
exigir_post();

$item = (string) ($_POST['item'] ?? '');
if (!in_array($item, ['python', 'bibliotecas', 'navegador', 'atualizar'], true)) {
    responder_json(['erro' => 'Item inválido.'], 400);
}
if (instalando()) {
    responder_json(['erro' => 'Já existe uma instalação em andamento. Aguarde terminar.'], 409);
}
if ($item === 'atualizar' && job_em_andamento()) {
    responder_json(['erro' => 'Há uma análise, login ou lançamento em andamento. Atualize depois.'], 409);
}
if ($item !== 'python' && !is_file(PYTHON)) {
    responder_json(['erro' => 'Instale o Python primeiro.'], 409);
}

file_put_contents(DIR_DATA . '/instalacao.json', json_encode([
    'item' => $item, 'status' => 'rodando', 'mensagem' => 'Iniciando…', 'progresso' => 0, 'atualizado' => date('c'),
], JSON_UNESCAPED_UNICODE), LOCK_EX);

try {
    executar_em_segundo_plano([PHP_CLI, '-c', PHP_INI, RAIZ . '/app/instalador.php', $item]);
} catch (Throwable $e) {
    error_log('instalar: ' . $e->getMessage());
    @unlink(DIR_DATA . '/instalacao.json');
    responder_json(['erro' => 'Não foi possível iniciar a instalação.'], 500);
}
responder_json(['ok' => true]);
