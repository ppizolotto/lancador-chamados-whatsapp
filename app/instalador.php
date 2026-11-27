<?php
/* Instalador em segundo plano (roda pelo php.exe, nunca pelo navegador).
   Uso: php instalador.php python|bibliotecas|navegador|atualizar
   Tudo vai para LancadorChamados/runtime/ — nada é instalado no Windows.
   Só é chamado depois que o usuário clica em "Instalar" (aprovação). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const STATUS = DIR_DATA . '/instalacao.json';
const LOG_INST = DIR_DATA . '/instalacoes.log';

function status(string $item, string $estado, string $msg, int $progresso = 0): void {
    file_put_contents(STATUS, json_encode([
        'item' => $item, 'status' => $estado, 'mensagem' => $msg,
        'progresso' => $progresso, 'atualizado' => date('c'), 'pid' => getmypid(),
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function registrar(string $texto): void {
    file_put_contents(LOG_INST, '[' . date('Y-m-d H:i:s') . "] $texto\n", FILE_APPEND | LOCK_EX);
}

function falhar(string $item, string $msg): never {
    status($item, 'erro', $msg);
    registrar("ERRO $item: $msg");
    exit(1);
}

function baixar(string $url, string $destino, string $item, int $pIni, int $pFim): void {
    $fp = fopen($destino, 'wb');
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_USERAGENT => 'LancadorChamados-instalador',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($ch, $total, $baixado) use ($item, $pIni, $pFim) {
            static $ultimo = 0;
            if ($total > 0 && microtime(true) - $ultimo > 0.5) {
                $ultimo = microtime(true);
                $p = $pIni + (int) (($pFim - $pIni) * $baixado / $total);
                status($item, 'rodando', sprintf('Baixando… %.1f de %.1f MB', $baixado / 1048576, $total / 1048576), $p);
            }
            return 0;
        },
    ]);
    $ok = curl_exec($c);
    $cod = curl_getinfo($c, CURLINFO_HTTP_CODE);
    $erro = curl_error($c);
    fclose($fp);
    if (!$ok || $cod !== 200) {
        @unlink($destino);
        falhar($item, "Falha no download ($cod) $erro");
    }
}

function json_url(string $url): ?array {
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA, CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'LancadorChamados-instalador',
    ]);
    $r = curl_exec($c);
    return is_string($r) ? json_decode($r, true) : null;
}

/** Roda comando mostrando a última linha de saída como mensagem. */
function rodar(array $cmd, string $item, string $msg, int $progresso): void {
    status($item, 'rodando', $msg, $progresso);
    registrar("$item: " . implode(' ', array_map('basename', $cmd)));
    [$cod, $saida, $erro] = executar($cmd, 1200);
    file_put_contents(LOG_INST, $saida . $erro . "\n", FILE_APPEND | LOCK_EX);
    if ($cod !== 0) {
        $linhas = array_values(array_filter(array_map('trim', explode("\n", $erro ?: $saida))));
        falhar($item, 'Comando falhou: ' . ($linhas ? end($linhas) : "código $cod"));
    }
}

/* ------------------------------------------------------------------ itens */

function instalar_python(): void {
    $item = 'python';
    $versao = config()['python_versao'];
    if (!preg_match('/^3\.\d+\.\d+$/', $versao)) {
        falhar($item, 'Versão do Python inválida na configuração.');
    }
    $nomeZip = "python-$versao-embed-amd64.zip";
    $url = "https://www.python.org/ftp/python/$versao/$nomeZip";

    // SHA-256 oficial pela API do python.org
    status($item, 'rodando', 'Consultando python.org…', 2);
    $rel = json_url('https://www.python.org/api/v2/downloads/release/?name=' . rawurlencode("Python $versao"));
    $id = isset($rel[0]['resource_uri']) ? basename(rtrim($rel[0]['resource_uri'], '/')) : null;
    $sha = null;
    foreach (($id ? json_url("https://www.python.org/api/v2/downloads/release_file/?release=$id") : []) ?? [] as $f) {
        if (($f['url'] ?? '') === $url) {
            $sha = $f['sha256_sum'] ?? null;
        }
    }
    if (!$sha) {
        falhar($item, "Não encontrei o SHA-256 oficial de $nomeZip no python.org.");
    }

    @mkdir(DIR_RUNTIME, 0775, true);
    $zip = DIR_RUNTIME . "/$nomeZip";
    baixar($url, $zip, $item, 5, 50);

    status($item, 'rodando', 'Conferindo integridade do arquivo…', 52);
    if (!hash_equals(strtolower($sha), hash_file('sha256', $zip))) {
        @unlink($zip);
        falhar($item, 'O arquivo baixado não confere com o SHA-256 oficial. Nada foi instalado.');
    }
    registrar("python: $nomeZip SHA-256 conferido");

    status($item, 'rodando', 'Extraindo…', 55);
    $dest = DIR_RUNTIME . '/python';
    @mkdir($dest, 0775, true);
    $z = new ZipArchive();
    if ($z->open($zip) !== true || !$z->extractTo($dest)) {
        falhar($item, 'Não consegui extrair o Python.');
    }
    $z->close();
    unlink($zip);

    // Habilita site-packages (pip) e coloca a pasta worker/ no caminho de importação.
    $pth = glob("$dest/python3*._pth")[0] ?? null;
    if (!$pth) {
        falhar($item, 'Arquivo ._pth não encontrado no Python portátil.');
    }
    $txt = (string) file_get_contents($pth);
    $txt = preg_replace('/^#\s*import site/m', 'import site', $txt);
    if (!str_contains($txt, '..\\..\\worker')) {
        $txt = rtrim($txt) . "\r\n..\\..\\worker\r\n";
    }
    file_put_contents($pth, $txt);

    $getpip = DIR_RUNTIME . '/get-pip.py';
    baixar('https://bootstrap.pypa.io/get-pip.py', $getpip, $item, 60, 70);
    rodar([...PYTHON_ISOLADO, $getpip, '--no-warn-script-location'], $item, 'Instalando o pip…', 75);
    unlink($getpip);

    registrar("python: Python $versao portátil instalado em runtime/python");
    status($item, 'ok', "Python $versao instalado na pasta do app.", 100);
}

function instalar_bibliotecas(): void {
    $item = 'bibliotecas';
    if (!is_file(PYTHON)) {
        falhar($item, 'Instale o Python primeiro.');
    }
    rodar([...PYTHON_ISOLADO, '-m', 'pip', 'install', '--no-warn-script-location', '-r', DIR_WORKER . '/requirements.txt'],
        $item, 'Instalando bibliotecas (pode levar alguns minutos)…', 20);
    registrar('bibliotecas: instaladas');
    status($item, 'ok', 'Bibliotecas instaladas.', 100);
}

function instalar_navegador(): void {
    $item = 'navegador';
    if (!is_file(PYTHON)) {
        falhar($item, 'Instale o Python primeiro.');
    }
    @mkdir(DIR_NAVEGADORES, 0775, true);
    rodar([...PYTHON_ISOLADO, '-m', 'playwright', 'install', 'chromium'], $item, 'Baixando o navegador Chromium (~150 MB)…', 20);
    registrar('navegador: Chromium do Playwright instalado em runtime/navegadores');
    status($item, 'ok', 'Navegador instalado na pasta do app.', 100);
}

/** Administração: atualiza as bibliotecas do worker e o Chromium embutido para as versões atuais. */
function atualizar_tudo(): void {
    $item = 'atualizar';
    if (!is_file(PYTHON)) {
        falhar($item, 'Instale o Python primeiro (Tela 1).');
    }
    rodar([...PYTHON_ISOLADO, '-m', 'pip', 'install', '--upgrade', '--no-warn-script-location', '-r', DIR_WORKER . '/requirements.txt'],
        $item, 'Atualizando bibliotecas…', 15);
    registrar('atualizar: bibliotecas atualizadas');
    @mkdir(DIR_NAVEGADORES, 0775, true);
    rodar([...PYTHON_ISOLADO, '-m', 'playwright', 'install', 'chromium'], $item, 'Atualizando o Chromium embutido…', 60);
    registrar('atualizar: Chromium do Playwright atualizado');
    status($item, 'ok', 'Bibliotecas e navegador atualizados.', 100);
}

/* ------------------------------------------------------------------ main */

$item = $argv[1] ?? '';
$acoes = ['python' => 'instalar_python', 'bibliotecas' => 'instalar_bibliotecas', 'navegador' => 'instalar_navegador', 'atualizar' => 'atualizar_tudo'];
if (!isset($acoes[$item])) {
    fwrite(STDERR, "Item desconhecido.\n");
    exit(2);
}
set_time_limit(0);
registrar("INÍCIO $item (aprovado pelo usuário — clique no app)");
status($item, 'rodando', 'Iniciando…', 1);
try {
    $acoes[$item]();
} catch (Throwable $e) {
    falhar($item, $e->getMessage());
}
