<?php
// Tela 1: verifica o ambiente. Parte é feita aqui (pasta data, Python portátil) e o resto pelo worker.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
set_time_limit(95); // a checagem da chave faz uma geração mínima, que pode demorar

$itens = [];

// 1. Pasta data/ gravável + banco
try {
    $teste = DIR_DATA . '/.teste-escrita';
    $ok = @file_put_contents($teste, 'ok') !== false;
    @unlink($teste);
    if ($ok) {
        db();
    }
    $itens[] = ['id' => 'data', 'nome' => 'Pasta data/ com permissão de escrita', 'ok' => $ok,
        'detalhe' => $ok ? 'Banco SQLite pronto' : 'Sem permissão de escrita em ' . DIR_DATA, 'acao' => null];
} catch (Throwable $e) {
    error_log('checar/data: ' . $e->getMessage());
    $itens[] = ['id' => 'data', 'nome' => 'Pasta data/ com permissão de escrita', 'ok' => false,
        'detalhe' => 'Erro ao criar o banco (veja data/php-erros.log)', 'acao' => null];
}

// 2. Python portátil
$temPython = is_file(PYTHON);
$python = ['id' => 'python', 'nome' => 'Python (portátil, na pasta do app)', 'ok' => $temPython,
    'detalhe' => $temPython ? '' : 'Não instalado', 'acao' => $temPython ? null : 'python'];

// 3. Demais itens pelo worker
$dependentes = [
    'bibliotecas' => 'Bibliotecas do worker',
    'navegador' => 'Navegador para o Helpdesk',
    'chave_ia' => 'Chave de IA configurada e válida',
    'internet' => 'Acesso ao Helpdesk',
];
$doWorker = [];
if ($temPython) {
    [$cod, $saida, $erro] = executar([...PYTHON_ISOLADO, DIR_WORKER . '/worker.py', 'checar'], 85);
    $res = json_decode($saida, true);
    if ($cod === 0 && isset($res['itens'])) {
        $python['detalhe'] = 'Versão ' . $res['python'];
        foreach ($res['itens'] as $i) {
            $doWorker[$i['id']] = $i;
        }
    } else {
        error_log("checar/worker cod=$cod: " . mb_substr($erro ?: $saida, 0, 500));
        $python['ok'] = false;
        $python['detalhe'] = $cod === -2 ? 'O worker demorou demais para responder' : 'O worker não rodou (veja data/php-erros.log)';
        $python['acao'] = 'python';
    }
}
$itens[] = $python;
foreach ($dependentes as $id => $nome) {
    $itens[] = $doWorker[$id] ?? ['id' => $id, 'nome' => $nome, 'ok' => false,
        'detalhe' => 'Aguardando o Python', 'acao' => null, 'pendente' => true];
}

// Itens que dependem da internet: o internet vem antes da chave na tela
$ordem = ['data', 'python', 'bibliotecas', 'navegador', 'internet', 'chave_ia'];
usort($itens, fn($a, $b) => array_search($a['id'], $ordem) <=> array_search($b['id'], $ordem));

responder_json([
    'itens' => $itens,
    'tudo_ok' => !in_array(false, array_column($itens, 'ok'), true),
    'instalacao' => status_instalacao(),
    'instalando' => instalando(),
]);
