<?php
/* Base comum: caminhos, configuração, banco, respostas JSON e execução de processos.
   Fica fora do www/ — não é acessível pelo navegador. */
declare(strict_types=1);

define('RAIZ', dirname(__DIR__));
define('DIR_DATA', RAIZ . '/data');
define('DIR_RUNTIME', RAIZ . '/runtime');
define('DIR_WORKER', RAIZ . '/worker');
define('PHP_CLI', RAIZ . '/php/php.exe');
define('PHP_INI', RAIZ . '/php/php.ini');
define('PYTHON', DIR_RUNTIME . '/python/python.exe');
// -s: ignora as bibliotecas do usuário do Windows (AppData\Roaming\Python). O app só usa o que está na própria pasta.
const PYTHON_ISOLADO = [PYTHON, '-s'];
define('DIR_NAVEGADORES', DIR_RUNTIME . '/navegadores');

if (!is_dir(DIR_DATA)) {
    @mkdir(DIR_DATA, 0775, true);
}
ini_set('error_log', DIR_DATA . '/php-erros.log');

/* ---------------------------------------------------------------- configuração */

const CONFIG_PADRAO = [
    'url_base' => 'https://helpdesk.exemplo.com.br',
    'simulacao' => true,
    'usar_chrome_instalado' => true,
    'python_versao' => '3.13.17',
    'nome_solicitante_conversa' => '',
    'busca_solicitante' => '',
    'nome_solicitante_helpdesk' => '',
    'campos' => [
        'sistema' => '101', 'tecnologia' => '5', 'servico' => '20', 'categoria' => '30',
        'urgencia' => '3', 'forma_contato' => '6', 'responsavel' => '1000', 'tipo_ticket' => 'publico',
    ],
    'status_encerramento' => '5',
    'texto_encerramento' => 'Solicitações do dia registradas a partir da conversa de WhatsApp e tratadas no atendimento. Ticket encerrado para registro.',
    'ia' => [
        'provedor' => 'gemini',
        'modelos' => ['gemini' => 'gemini-3.1-pro-preview', 'gemini_gratis' => 'gemini-3.8-flash', 'groq' => '', 'nvidia' => '', 'outro' => ''],
        'url_outro' => '',
        'troca_automatica' => true,
    ],
];

/** Provedores de IA. Endereços das APIs compatíveis com OpenAI: conferir na documentação de cada um. */
const PROVEDORES = [
    'gemini' => ['nome' => 'Google Gemini (pago — recomendado)', 'gratuito' => false, 'chave' => 'GEMINI_API_KEY', 'url' => ''],
    // Mesma API do Gemini, chave de projeto SEM faturamento. O Pro não está disponível no plano gratuito.
    'gemini_gratis' => ['nome' => 'Google Gemini (plano gratuito)', 'gratuito' => true, 'chave' => 'GEMINI_GRATIS_API_KEY', 'url' => ''],
    'groq'   => ['nome' => 'Groq (plano gratuito)', 'gratuito' => true, 'chave' => 'GROQ_API_KEY', 'url' => 'https://api.groq.com/openai/v1'],
    'nvidia' => ['nome' => 'NVIDIA (plano gratuito)', 'gratuito' => true, 'chave' => 'NVIDIA_API_KEY', 'url' => 'https://integrate.api.nvidia.com/v1'],
    'outro'  => ['nome' => 'Outro compatível com OpenAI', 'gratuito' => true, 'chave' => 'OUTRO_API_KEY', 'url' => ''],
];

function config(): array {
    $arq = DIR_DATA . '/config.json';
    if (!is_file($arq)) {
        salvar_config(CONFIG_PADRAO);
        return CONFIG_PADRAO;
    }
    $cfg = json_decode((string) file_get_contents($arq), true) ?: [];
    return array_replace_recursive(CONFIG_PADRAO, $cfg);
}

function salvar_config(array $cfg): void {
    file_put_contents(
        DIR_DATA . '/config.json',
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/* ---------------------------------------------------------------- chaves (.env) */

function ler_env(): array {
    $arq = DIR_DATA . '/.env';
    $vals = [];
    if (is_file($arq)) {
        foreach (file($arq, FILE_IGNORE_NEW_LINES) ?: [] as $linha) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $linha, $m)) {
                $vals[$m[1]] = trim($m[2]);
            }
        }
    }
    return $vals;
}

/** Grava uma chave no data/.env. O valor nunca é devolvido à tela nem registrado em log. */
function gravar_chave(string $nome, string $valor): void {
    $vals = ler_env();
    $vals[$nome] = preg_replace('/[\r\n\s]+/', '', $valor);
    $txt = "# Chaves de IA do Lançador de Chamados. NÃO compartilhe este arquivo.\n";
    foreach ($vals as $k => $v) {
        $txt .= "$k=$v\n";
    }
    file_put_contents(DIR_DATA . '/.env', $txt, LOCK_EX);
}

/** Apaga uma chave do data/.env. */
function remover_chave(string $nome): void {
    $vals = ler_env();
    unset($vals[$nome]);
    $txt = "# Chaves de IA do Lançador de Chamados. NÃO compartilhe este arquivo.
";
    foreach ($vals as $k => $v) {
        $txt .= "$k=$v
";
    }
    file_put_contents(DIR_DATA . '/.env', $txt, LOCK_EX);
}

/* ---------------------------------------------------------------- banco */

function db(): PDO {
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . DIR_DATA . '/app.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    $pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
    migrar_solicitantes($pdo);
    importar_processados($pdo);
    return $pdo;
}

function tem_coluna(PDO $pdo, string $tabela, string $coluna): bool {
    foreach ($pdo->query("PRAGMA table_info($tabela)")->fetchAll() as $c) {
        if ($c['name'] === $coluna) {
            return true;
        }
    }
    return false;
}

/** Versão com vários solicitantes (26/11/2026): tickets passam a ser únicos por (data, solicitante).
    O que já existia era todo do solicitante único da configuração antiga. Idempotente. */
function migrar_solicitantes(PDO $pdo): void {
    $cfg = config();
    $antigo = (string) ($cfg['nome_solicitante_conversa'] ?? '');
    if (!tem_coluna($pdo, 'jobs', 'solicitante')) {
        $pdo->exec('ALTER TABLE jobs ADD COLUMN solicitante TEXT');
        $pdo->prepare('UPDATE jobs SET solicitante = ?')->execute([$antigo]);
    }
    if (!tem_coluna($pdo, 'tickets', 'solicitante')) {
        $pdo->beginTransaction();
        $pdo->exec("CREATE TABLE tickets_novo (
            data TEXT NOT NULL, solicitante TEXT NOT NULL DEFAULT '', protocolo TEXT, job_id INTEGER, aberto_em TEXT,
            situacao_final TEXT, corrigido INTEGER NOT NULL DEFAULT 0, erro TEXT, PRIMARY KEY (data, solicitante))");
        $pdo->prepare('INSERT INTO tickets_novo(data, solicitante, protocolo, job_id, aberto_em, situacao_final, corrigido, erro)
                       SELECT data, ?, protocolo, job_id, aberto_em, situacao_final, corrigido, erro FROM tickets')
            ->execute([$antigo]);
        $pdo->exec('DROP TABLE tickets');
        $pdo->exec('ALTER TABLE tickets_novo RENAME TO tickets');
        $pdo->commit();
    }
    if (!$pdo->query("SELECT 1 FROM meta WHERE chave='solicitantes_semeados'")->fetchColumn()) {
        if ($antigo !== '' && !empty($cfg['nome_solicitante_helpdesk'])) {
            $pdo->prepare('INSERT OR IGNORE INTO solicitantes(nome_whatsapp, busca, nome_helpdesk, criado_em) VALUES(?, ?, ?, ?)')
                ->execute([$antigo, (string) ($cfg['busca_solicitante'] ?? $antigo), (string) $cfg['nome_solicitante_helpdesk'], date('c')]);
        }
        $pdo->prepare("INSERT INTO meta(chave, valor) VALUES('solicitantes_semeados', ?)")->execute([date('c')]);
    }
    // marcador.json antigo (um solicitante só) -> por solicitante
    $arq = DIR_DATA . '/marcador.json';
    if (is_file($arq)) {
        $m = json_decode((string) file_get_contents($arq), true);
        if (is_array($m) && isset($m['ultimo_dia_lancado']) && !isset($m['solicitantes'])) {
            file_put_contents($arq, json_encode(['solicitantes' => [$antigo => $m]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
    }
}

/** Valida e grava um solicitante. Devolve o cadastro salvo ou a mensagem de erro (string). */
function salvar_solicitante(string $nome, string $busca, string $nomeHelpdesk): array|string {
    $um = fn($t) => trim((string) preg_replace('/\s+/u', ' ', $t));
    [$nome, $busca, $nomeHelpdesk] = [$um($nome), $um($busca), $um($nomeHelpdesk)];
    if ($nome === '' || $busca === '' || $nomeHelpdesk === '') {
        return 'Preencha o nome no WhatsApp, o termo de busca e o nome no Helpdesk.';
    }
    if (mb_strlen($nome) > 60 || mb_strlen($busca) > 60 || mb_strlen($nomeHelpdesk) > 120) {
        return 'Texto longo demais.';
    }
    if (mb_strlen($busca) < 3) {
        return 'O termo de busca precisa ter ao menos 3 letras.';
    }
    db()->prepare('INSERT INTO solicitantes(nome_whatsapp, busca, nome_helpdesk, criado_em) VALUES(?, ?, ?, ?)
                   ON CONFLICT(nome_whatsapp) DO UPDATE SET busca = excluded.busca, nome_helpdesk = excluded.nome_helpdesk')
        ->execute([$nome, $busca, $nomeHelpdesk, date('c')]);
    log_app('info', 'Solicitante cadastrado/atualizado');
    return ['nome_whatsapp' => $nome, 'busca' => $busca, 'nome_helpdesk' => $nomeHelpdesk];
}

/** Cadastro do solicitante (nome no WhatsApp) ou null se ainda não cadastrado. */
function solicitante_cadastro(string $nome): ?array {
    $st = db()->prepare('SELECT * FROM solicitantes WHERE nome_whatsapp = ?');
    $st->execute([$nome]);
    return $st->fetch() ?: null;
}

/** Na primeira execução, marca como já lançados os dias do protótipo (sem protocolo conhecido). */
function importar_processados(PDO $pdo): void {
    $feito = $pdo->query("SELECT valor FROM meta WHERE chave='processados_importados'")->fetchColumn();
    if ($feito) {
        return;
    }
    $dias = json_decode((string) @file_get_contents(__DIR__ . '/seed/processados.json'), true) ?: [];
    $ins = $pdo->prepare(
        "INSERT OR IGNORE INTO tickets(data, solicitante, protocolo, job_id, aberto_em, situacao_final, corrigido, erro)
         VALUES(?, ?, NULL, NULL, NULL, 'importado', 0, NULL)"
    );
    $pdo->beginTransaction();
    foreach ($dias as $d) {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $d, $m)) {
            $ins->execute(["$m[3]-$m[2]-$m[1]", (string) (config()["nome_solicitante_conversa"] ?? "")]);
        }
    }
    $pdo->prepare("INSERT INTO meta(chave, valor) VALUES('processados_importados', ?)")
        ->execute([date('c') . ' (' . count($dias) . ' dias)']);
    $pdo->commit();
}

/* ---------------------------------------------------------------- respostas */

function responder_json(array $dados, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function exigir_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        responder_json(['erro' => 'Método não permitido.'], 405);
    }
    // Só aceita POST vindo das próprias telas do app (bloqueia outra página tentando chamar o servidor local).
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origem !== '' && $origem !== "http://$host") {
        responder_json(['erro' => 'Origem não permitida.'], 403);
    }
}

function status_instalacao(): ?array {
    $arq = DIR_DATA . '/instalacao.json';
    return is_file($arq) ? json_decode((string) file_get_contents($arq), true) : null;
}

/** Instalação em andamento de verdade (status "rodando" atualizado nos últimos 20 min). */
function instalando(): bool {
    $st = status_instalacao();
    return $st && $st['status'] === 'rodando' && time() - strtotime($st['atualizado']) < 1200;
}

/* ---------------------------------------------------------------- processos */

function ambiente_filhos(): array {
    $env = getenv();
    $env['PLAYWRIGHT_BROWSERS_PATH'] = DIR_NAVEGADORES;
    $env['PYTHONIOENCODING'] = 'utf-8';
    $env['PYTHONUTF8'] = '1';
    $env['PIP_DISABLE_PIP_VERSION_CHECK'] = '1';
    return $env;
}

/** Roda um comando e espera terminar (até $limite segundos). Retorna [codigo, saida, erro]. */
function executar(array $cmd, int $limite = 25): array {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, RAIZ, ambiente_filhos(), ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return [-1, '', 'Não foi possível iniciar o processo.'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $saida = $erro = '';
    $fim = microtime(true) + $limite;
    while (true) {
        $saida .= (string) stream_get_contents($pipes[1]);
        $erro .= (string) stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $saida .= (string) stream_get_contents($pipes[1]);
            $erro .= (string) stream_get_contents($pipes[2]);
            proc_close($proc);
            return [$st['exitcode'], $saida, $erro];
        }
        if (microtime(true) > $fim) {
            proc_terminate($proc);
            proc_close($proc);
            return [-2, $saida, 'Tempo esgotado.'];
        }
        usleep(100_000);
    }
}

/** Inicia um comando em segundo plano, sem janela, e retorna na hora.
    Variáveis de ambiente: o instalador as repassa via executar(); o worker as define sozinho. */
function executar_em_segundo_plano(array $cmd): void {
    $linha = implode(' ', array_map(fn($p) => '"' . str_replace('"', '', $p) . '"', $cmd));
    $shell = new COM('WScript.Shell');
    $shell->CurrentDirectory = RAIZ;
    $shell->Run($linha, 0, false);
}

/** Alguma etapa (análise, login, lançamento, verificação) rodando agora? */
function job_em_andamento(): bool {
    return (bool) db()->query(
        "SELECT 1 FROM jobs WHERE 'rodando' IN (analise_status, login_status, lancar_status, verificar_status) LIMIT 1"
    )->fetchColumn();
}

/** Endereço de um arquivo de www/assets com a data de modificação (?v=...): o navegador do app
    não usa uma versão antiga em cache depois de uma atualização. */
function asset(string $nome): string {
    $mt = @filemtime(RAIZ . '/www/assets/' . $nome) ?: 0;
    return 'assets/' . $nome . '?v=' . $mt;
}

/** Barra das etapas (1 a 6). "6. Relatório" é sempre um link: o relatório do job, se ele já foi lançado,
    ou o histórico de todos os lançamentos. */
function etapas(int $atual, int $job = 0): void {
    $nomes = ['Ambiente', 'Conversa', 'Análise + login', 'Confirmação', 'Lançamento', 'Relatório'];
    $href = 'relatorio.php';
    if ($job > 0) {
        $st = db()->prepare('SELECT lancar_status FROM jobs WHERE id = ?');
        $st->execute([$job]);
        if (in_array($st->fetchColumn(), ['ok', 'erro'], true)) {
            $href = 'relatorio.php?job=' . $job;
        }
    }
    echo '<ol class="etapas">';
    foreach ($nomes as $i => $nome) {
        $classe = $i === $atual ? 'atual' : ($i < $atual ? 'feita' : '');
        $txt = ($i + 1) . '. ' . htmlspecialchars($nome);
        if ($i === 5 && $i !== $atual) {
            $txt = '<a href="' . htmlspecialchars($href) . '" title="Relatório e histórico de lançamentos">' . $txt . '</a>';
        }
        echo '<li class="' . $classe . '">' . $txt . '</li>';
    }
    echo '</ol>';
}

/** Cabeçalho comum a todas as telas, com o botão de Administração. $links: [rótulo => href]. */
function topo(array $links = [], string $titulo = 'Lançador de Chamados', string $sub = 'WhatsApp → Helpdesk', bool $admin = true): void {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    echo '<header class="topo"><img src="assets/logo.svg" alt=""><span class="divisor"></span>';
    echo '<div><h1>' . $h($titulo) . '</h1><div class="sub">' . $h($sub) . '</div></div>';
    if (config()['simulacao']) {
        echo '<span class="selo">Modo simulação</span>';
    }
    echo '<nav>';
    foreach ($links as $rotulo => $href) {
        echo '<a href="' . $h($href) . '">' . $h($rotulo) . '</a>';
    }
    if ($admin) {
        echo '<a class="botao-admin" href="admin.php" title="Chaves, Helpdesk, atualizações e manutenção">⚙ Administração</a>';
    }
    echo '</nav></header>';
}

function log_app(string $nivel, string $texto, ?int $job = null): void {
    db()->prepare('INSERT INTO logs(job_id, quando, nivel, texto) VALUES(?, ?, ?, ?)')
        ->execute([$job, date('c'), $nivel, $texto]);
}
