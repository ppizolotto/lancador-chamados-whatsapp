<?php
// Administração: chaves e modelos de IA, valores do Helpdesk, lançamento e manutenção.
// Chaves nunca são mostradas: os campos vêm vazios e só substituem se forem preenchidos.
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require RAIZ . '/app/conversa.php';

$cfg = config();
$erro = '';
$MSGS = [
    'ia' => 'Configurações de IA salvas.', 'chave_removida' => 'Chave removida.',
    'helpdesk' => 'Valores do Helpdesk salvos.', 'helpdesk_padrao' => 'Valores do Helpdesk restaurados para o padrão.',
    'lancamento' => 'Opções de lançamento salvas.',
    'solicitante' => 'Solicitante salvo.',
    'solicitante_removido' => 'Solicitante removido do cadastro (os tickets já lançados continuam registrados).', 'sessao' => 'Sessão do Helpdesk apagada. No próximo uso, faça o login de novo.',
];
$CAMPOS = [
    'sistema' => ['Sistema', '#sistema', '101 = nome do sistema (exemplo)'],
    'tecnologia' => ['Tecnologia', '#tecnologia', '5 = Sistema Web'],
    'servico' => ['Serviço', '#servico', '20 = Suporte Técnico (exemplo)'],
    'categoria' => ['Categoria', '#categoria', '30 = Suporte (exemplo)'],
    'urgencia' => ['Urgência', '#urgencia', '3 = Média'],
    'forma_contato' => ['Forma de contato', '#forma', '6 = Outro'],
    'responsavel' => ['Responsável', '#responsavel', '1000 = técnico responsável (exemplo)'],
    'tipo_ticket' => ['Tipo do ticket', '#tipoTicket', 'publico = Público'],
];

function voltar(string $msg, string $aba): never {
    header('Location: admin.php?msg=' . urlencode($msg) . '#' . $aba, true, 303);
    exit;
}

function apagar_pasta(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origem !== '' && $origem !== 'http://' . ($_SERVER['HTTP_HOST'] ?? '')) {
        http_response_code(403);
        exit('Origem não permitida.');
    }
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'ia') {
        $prov = (string) ($_POST['provedor'] ?? '');
        $url = trim((string) ($_POST['url_outro'] ?? ''));
        if (!isset(PROVEDORES[$prov])) {
            $erro = 'Provedor inválido.';
        } elseif ($url !== '' && !str_starts_with($url, 'https://')) {
            $erro = 'O endereço da API precisa começar com https://';
        } else {
            $cfg['ia']['provedor'] = $prov;
            foreach (array_keys(PROVEDORES) as $p) {
                $cfg['ia']['modelos'][$p] = trim((string) ($_POST['modelo'][$p] ?? ''));
                $chave = trim((string) ($_POST['chave'][$p] ?? ''));
                if ($chave !== '') {
                    gravar_chave(PROVEDORES[$p]['chave'], $chave);
                }
            }
            $cfg['ia']['url_outro'] = $url;
            $cfg['ia']['troca_automatica'] = isset($_POST['troca_automatica']);
            salvar_config($cfg);
            voltar('ia', 'ia');
        }
    } elseif ($acao === 'remover_chave') {
        $prov = (string) ($_POST['provedor'] ?? '');
        if (isset(PROVEDORES[$prov])) {
            remover_chave(PROVEDORES[$prov]['chave']);
        }
        voltar('chave_removida', 'ia');
    } elseif ($acao === 'helpdesk') {
        $url = rtrim(trim((string) ($_POST['url_base'] ?? '')), '/');
        $novos = [];
        foreach (array_keys($CAMPOS) as $c) {
            $v = trim((string) ($_POST['campos'][$c] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{1,30}$/', $v)) {
                $erro = $erro ?: 'Valor inválido em "' . $CAMPOS[$c][0] . '": use só letras, números, - ou _.';
            }
            $novos[$c] = $v;
        }
        $textos = [];
        foreach (['texto_encerramento' => 1000] as $k => $max) {
            $textos[$k] = trim((string) ($_POST[$k] ?? ''));
            if ($textos[$k] === '' || mb_strlen($textos[$k]) > $max) {
                $erro = $erro ?: 'Preencha todos os campos de texto (limite de tamanho).';
            }
        }
        $status = trim((string) ($_POST['status_encerramento'] ?? ''));
        if (!str_starts_with($url, 'https://')) {
            $erro = $erro ?: 'O endereço do Helpdesk precisa começar com https://';
        } elseif (!ctype_digit($status)) {
            $erro = $erro ?: 'Status de encerramento deve ser um número (5 = Resolvido).';
        }
        if (!$erro) {
            $cfg['url_base'] = $url;
            $cfg['campos'] = $novos;
            $cfg['status_encerramento'] = $status;
            $cfg = array_replace($cfg, $textos);
            salvar_config($cfg);
            voltar('helpdesk', 'helpdesk');
        }
    } elseif ($acao === 'helpdesk_padrao') {
        foreach (['url_base', 'campos', 'status_encerramento', 'texto_encerramento'] as $k) {
            $cfg[$k] = CONFIG_PADRAO[$k];
        }
        salvar_config($cfg);
        voltar('helpdesk_padrao', 'helpdesk');
    } elseif ($acao === 'solicitante_salvar') {
        $original = trim((string) ($_POST['original'] ?? ''));
        $r = salvar_solicitante((string) ($_POST['nome_whatsapp'] ?? ''), (string) ($_POST['busca'] ?? ''),
                                (string) ($_POST['nome_helpdesk'] ?? ''));
        if (is_string($r)) {
            $erro = $r;
        } else {
            if ($original !== '' && $original !== $r['nome_whatsapp']) {
                db()->prepare('DELETE FROM solicitantes WHERE nome_whatsapp = ?')->execute([$original]);
            }
            voltar('solicitante', 'solicitantes');
        }
    } elseif ($acao === 'solicitante_remover') {
        db()->prepare('DELETE FROM solicitantes WHERE nome_whatsapp = ?')->execute([(string) ($_POST['nome_whatsapp'] ?? '')]);
        log_app('info', 'Solicitante removido do cadastro');
        voltar('solicitante_removido', 'solicitantes');
    } elseif ($acao === 'lancamento') {
        $cfg['simulacao'] = isset($_POST['simulacao']);
        $cfg['usar_chrome_instalado'] = isset($_POST['usar_chrome_instalado']);
        salvar_config($cfg);
        voltar('lancamento', 'lancamento');
    } elseif ($acao === 'sair_helpdesk') {
        if (job_em_andamento()) {
            $erro = 'Há uma análise, login ou lançamento em andamento. Espere terminar.';
        } else {
            apagar_pasta(DIR_DATA . '/perfil_navegador');
            log_app('info', 'Sessão do Helpdesk apagada pela Administração');
            voltar('sessao', 'manutencao');
        }
    }
}

/* ---------- dados para a tela */
$env = ler_env();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$msg = $MSGS[$_GET['msg'] ?? ''] ?? '';

$libs = [];
foreach (glob(DIR_RUNTIME . '/python/Lib/site-packages/*.dist-info') ?: [] as $d) {
    if (preg_match('/^(google_genai|pydantic|playwright|python_dotenv)-([\d.]+)\.dist-info$/', basename($d), $m)) {
        $libs[$m[1]] = $m[2];
    }
}
$chromium = array_map('basename', glob(DIR_NAVEGADORES . '/chromium-*', GLOB_ONLYDIR) ?: []);
$temSessao = is_dir(DIR_DATA . '/perfil_navegador');
$marcador = is_file(DIR_DATA . '/marcador.json') ? json_decode((string) file_get_contents(DIR_DATA . '/marcador.json'), true) : null;
$totTickets = (int) db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
$solicitantes = db()->query(
    "SELECT s.nome_whatsapp, s.busca, s.nome_helpdesk,
            (SELECT COUNT(*) FROM tickets t WHERE t.solicitante = s.nome_whatsapp) AS tickets
     FROM solicitantes s ORDER BY s.nome_whatsapp")->fetchAll();
$porSolicitante = db()->query(
    "SELECT solicitante, COUNT(*) AS n, MAX(data) AS ultimo FROM tickets GROUP BY solicitante ORDER BY solicitante")->fetchAll();

function ultimas_linhas(string $arq, int $n = 40): string {
    if (!is_file($arq)) {
        return '';
    }
    $l = file($arq, FILE_IGNORE_NEW_LINES) ?: [];
    return implode("\n", array_slice($l, -$n));
}
$logApp = db()->query('SELECT quando, nivel, texto FROM logs ORDER BY id DESC LIMIT 40')->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Administração — Lançador de Chamados</title>
<link rel="icon" href="assets/favicon.svg">
<link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body>
<?php topo(['← Voltar ao início' => 'index.php'], 'Administração', 'Lançador de Chamados', false); ?>

<main>
<?php if ($msg): ?><div class="alerta ok"><?= $h($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alerta erro"><?= $h($erro) ?></div><?php endif; ?>

<nav class="abas">
  <a href="#ia">Chaves e IA</a>
  <a href="#helpdesk">Helpdesk</a>
  <a href="#lancamento">Lançamento</a>
  <a href="#solicitantes">Solicitantes</a>
  <a href="#manutencao">Manutenção</a>
</nav>

<!-- ============================================================ IA -->
<section class="aba" id="ia">
<form method="post" autocomplete="off">
  <input type="hidden" name="acao" value="ia">
  <div class="cartao">
    <h2>Provedores de IA</h2>
    <p class="dica">Antes de qualquer envio, CPF, CNS, senhas e logins são removidos do texto.
      As chaves ficam só em <code>data/.env</code> e nunca aparecem na tela.</p>

    <table class="tabela-prov">
      <thead><tr><th>Usar</th><th>Provedor</th><th>Chave</th><th>Nova chave</th><th>Modelo</th><th></th></tr></thead>
      <tbody>
      <?php foreach (PROVEDORES as $id => $p):
          $temChave = !empty($env[$p['chave']]); ?>
        <tr>
          <td><input type="radio" name="provedor" value="<?= $h($id) ?>" data-gratuito="<?= $p['gratuito'] ? '1' : '0' ?>"
                     <?= $id === $cfg['ia']['provedor'] ? 'checked' : '' ?> aria-label="Usar <?= $h($p['nome']) ?>"></td>
          <td><strong><?= $h($p['nome']) ?></strong></td>
          <td><?= $temChave ? '<span class="tag ok">cadastrada</span>' : '<span class="tag">sem chave</span>' ?></td>
          <td><input type="password" name="chave[<?= $h($id) ?>]" placeholder="<?= $temChave ? 'manter a atual' : 'colar a chave' ?>"></td>
          <td><input type="text" name="modelo[<?= $h($id) ?>]" value="<?= $h($cfg['ia']['modelos'][$id] ?? '') ?>" placeholder="nome do modelo"></td>
          <td class="acoes-linha">
            <?php if ($temChave): ?>
              <button class="botao secundario pequeno" type="button" data-testar="<?= $h($id) ?>">Testar</button>
              <button class="botao perigo pequeno" type="submit" form="rm-<?= $h($id) ?>">Remover</button>
            <?php endif; ?>
            <span class="resultado-teste" id="t-<?= $h($id) ?>"></span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="dica">"Testar" usa a chave <em>salva</em> (salve antes se acabou de trocar) e envia só a palavra "ok".
      Modelos atuais do Gemini: ai.google.dev/gemini-api/docs/models. No plano gratuito do Gemini use um modelo "flash".</p>

    <div class="alerta aviso" id="aviso-gratuito" hidden>
      <strong>Atenção:</strong> planos gratuitos podem usar os textos enviados para treinar os modelos deles.
      Mesmo com a limpeza automática, o texto descreve atendimentos de saúde. O recomendado é o Gemini com chave paga.
    </div>

    <div class="campo">
      <label for="url_outro">Endereço da API do "Outro compatível com OpenAI"</label>
      <input type="text" id="url_outro" name="url_outro" value="<?= $h($cfg['ia']['url_outro']) ?>" placeholder="https://…/v1">
    </div>

    <label class="opcao">
      <input type="checkbox" name="troca_automatica" <?= !empty($cfg['ia']['troca_automatica']) ? 'checked' : '' ?>>
      <span><strong>Troca automática</strong> — se o provedor escolhido falhar, usa outra chave salva que tenha modelo.
        Pagos antes dos gratuitos.</span>
    </label>
  </div>
  <div class="acoes"><button class="botao" type="submit">Salvar</button></div>
</form>
<?php foreach (PROVEDORES as $id => $p): ?>
<form method="post" id="rm-<?= $h($id) ?>" data-confirmar="Remover a chave de <?= $h($p['nome']) ?>?">
  <input type="hidden" name="acao" value="remover_chave"><input type="hidden" name="provedor" value="<?= $h($id) ?>">
</form>
<?php endforeach; ?>
</section>

<!-- ============================================================ HELPDESK -->
<section class="aba" id="helpdesk">
<form method="post" autocomplete="off">
  <input type="hidden" name="acao" value="helpdesk">
  <div class="cartao">
    <h2>Formulário do Helpdesk</h2>
    <p class="dica">Valores usados para preencher o ticket. Só altere se o Helpdesk mudar (ex.: novo responsável).
      Os números são o <code>value</code> de cada opção no formulário de novo ticket.</p>
    <div class="campo">
      <label for="url_base">Endereço do Helpdesk</label>
      <input type="text" id="url_base" name="url_base" value="<?= $h($cfg['url_base']) ?>">
    </div>
    <div class="grade-campos">
    <?php foreach ($CAMPOS as $c => [$rot, $sel, $pad]): ?>
      <div class="campo">
        <label for="c-<?= $c ?>"><?= $h($rot) ?> <small><?= $h($sel) ?></small></label>
        <input type="text" id="c-<?= $c ?>" name="campos[<?= $c ?>]" value="<?= $h($cfg['campos'][$c] ?? '') ?>">
        <small>padrão: <?= $h($pad) ?></small>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <div class="cartao">
    <h2>Encerramento</h2>
    <p class="dica">Os solicitantes ficam na aba <a href="#solicitantes">Solicitantes</a>.</p>
    <div class="grade-campos">
      <div class="campo"><label for="st">Status de encerramento</label>
        <input type="text" id="st" name="status_encerramento" value="<?= $h($cfg['status_encerramento']) ?>">
        <small>5 = Resolvido</small></div>
    </div>
    <div class="campo"><label for="txt">Texto da resposta de encerramento (enviada só por "Meus tickets")</label>
      <textarea id="txt" name="texto_encerramento" rows="3"><?= $h($cfg['texto_encerramento']) ?></textarea></div>
  </div>
  <div class="acoes">
    <button class="botao secundario" type="submit" form="hd-padrao">Restaurar padrões</button>
    <button class="botao" type="submit">Salvar</button>
  </div>
</form>
<form method="post" id="hd-padrao" data-confirmar="Voltar todos os valores do Helpdesk para o padrão?">
  <input type="hidden" name="acao" value="helpdesk_padrao">
</form>
</section>

<!-- ============================================================ SOLICITANTES -->
<section class="aba" id="solicitantes">
  <div class="cartao">
    <h2>Solicitantes</h2>
    <p class="dica">Cada conversa exportada é de uma pessoa. O app identifica quem é pelo nome no WhatsApp e usa este
      cadastro para escolher o solicitante no Helpdesk. Pessoa nova também pode ser cadastrada na Tela 2.</p>
    <table class="tabela-prov">
      <thead><tr><th>Nome no WhatsApp</th><th>Termo de busca</th><th>Nome no Helpdesk</th><th>Tickets</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($solicitantes as $i => $s): ?>
        <tr>
          <td><input type="text" form="sol-<?= $i ?>" name="nome_whatsapp" value="<?= $h($s['nome_whatsapp']) ?>" maxlength="60"></td>
          <td><input type="text" form="sol-<?= $i ?>" name="busca" value="<?= $h($s['busca']) ?>" maxlength="60"></td>
          <td><input type="text" form="sol-<?= $i ?>" name="nome_helpdesk" value="<?= $h($s['nome_helpdesk']) ?>" maxlength="120"></td>
          <td><?= (int) $s['tickets'] ?></td>
          <td class="acoes-linha">
            <button class="botao secundario pequeno" type="submit" form="sol-<?= $i ?>">Salvar</button>
            <button class="botao perigo pequeno" type="submit" form="solrm-<?= $i ?>">Remover</button>
          </td>
        </tr>
      <?php endforeach; ?>
        <tr>
          <td><input type="text" form="sol-novo" name="nome_whatsapp" placeholder="como aparece no WhatsApp" maxlength="60"></td>
          <td><input type="text" form="sol-novo" name="busca" placeholder="ex.: sobrenome" maxlength="60"></td>
          <td><input type="text" form="sol-novo" name="nome_helpdesk" placeholder="NOME COMPLETO NO HELPDESK" maxlength="120"></td>
          <td></td>
          <td class="acoes-linha"><button class="botao pequeno" type="submit" form="sol-novo">Adicionar</button></td>
        </tr>
      </tbody>
    </table>
    <p class="dica">Se o nome no WhatsApp mudar (a pessoa trocou o nome no celular), atualize aqui. Mudar o nome não
      mexe nos tickets já lançados: eles continuam no nome antigo.</p>
  </div>
<?php foreach ($solicitantes as $i => $s): ?>
  <form method="post" id="sol-<?= $i ?>"><input type="hidden" name="acao" value="solicitante_salvar">
    <input type="hidden" name="original" value="<?= $h($s['nome_whatsapp']) ?>"></form>
  <form method="post" id="solrm-<?= $i ?>" data-confirmar="Remover <?= $h($s['nome_whatsapp']) ?> do cadastro?">
    <input type="hidden" name="acao" value="solicitante_remover">
    <input type="hidden" name="nome_whatsapp" value="<?= $h($s['nome_whatsapp']) ?>"></form>
<?php endforeach; ?>
  <form method="post" id="sol-novo"><input type="hidden" name="acao" value="solicitante_salvar"></form>
</section>

<!-- ============================================================ LANÇAMENTO -->
<section class="aba" id="lancamento">
<form method="post">
  <input type="hidden" name="acao" value="lancamento">
  <div class="cartao">
    <h2>Lançamento</h2>
    <label class="opcao">
      <input type="checkbox" name="simulacao" <?= $cfg['simulacao'] ? 'checked' : '' ?>>
      <span><strong>Modo simulação</strong> — preenche tudo, mas não clica em "Abrir ticket". Deixe ligado até o primeiro teste real.</span>
    </label>
    <label class="opcao">
      <input type="checkbox" name="usar_chrome_instalado" <?= $cfg['usar_chrome_instalado'] ? 'checked' : '' ?>>
      <span><strong>Usar o Google Chrome do computador</strong> — se desmarcado ou ausente, usa o Chromium embutido.</span>
    </label>
  </div>
  <div class="acoes"><button class="botao" type="submit">Salvar</button></div>
</form>
</section>

<!-- ============================================================ MANUTENÇÃO -->
<section class="aba" id="manutencao">
  <div class="cartao">
    <h2>Componentes instalados</h2>
    <table class="tabela-info">
      <tr><th>Python portátil</th><td><?= is_file(PYTHON) ? $h($cfg['python_versao']) : 'não instalado' ?></td></tr>
      <tr><th>google-genai</th><td><?= $h($libs['google_genai'] ?? '—') ?></td></tr>
      <tr><th>pydantic</th><td><?= $h($libs['pydantic'] ?? '—') ?></td></tr>
      <tr><th>playwright</th><td><?= $h($libs['playwright'] ?? '—') ?></td></tr>
      <tr><th>python-dotenv</th><td><?= $h($libs['python_dotenv'] ?? '—') ?></td></tr>
      <tr><th>Chromium embutido</th><td><?= $chromium ? $h(implode(', ', $chromium)) : 'não instalado (usa o Chrome do computador)' ?></td></tr>
    </table>
    <p class="dica">Atualizar baixa as versões mais novas das bibliotecas e do Chromium para dentro da pasta do app
      (nada é instalado no Windows) e registra em <code>data/instalacoes.log</code>.</p>
    <div class="progresso" id="atu-barra-caixa" hidden><span id="atu-barra" style="width:0"></span></div>
    <p class="etapa-msg" id="atu-msg"></p>
    <div class="acoes"><button class="botao secundario" type="button" id="btn-atualizar">Atualizar bibliotecas e navegador</button></div>
  </div>

  <div class="cartao">
    <h2>Sessão do Helpdesk</h2>
    <p><?= $temSessao ? 'Há uma sessão salva no perfil do navegador do app.' : 'Nenhuma sessão salva.' ?></p>
    <p class="dica">Apague para entrar com outro usuário ou se o login ficar travado. O app não guarda a senha.</p>
    <form method="post" data-confirmar="Apagar a sessão salva do Helpdesk?">
      <input type="hidden" name="acao" value="sair_helpdesk">
      <div class="acoes"><button class="botao perigo" type="submit" <?= $temSessao ? '' : 'disabled' ?>>Sair do Helpdesk (apagar sessão)</button></div>
    </form>
  </div>

  <div class="cartao">
    <h2>Lançamentos</h2>
    <table class="tabela-info">
      <tr><th>Dias registrados como lançados</th><td><?= $totTickets ?></td></tr>
      <?php foreach ($porSolicitante as $ps):
          $mk = $marcador['solicitantes'][$ps['solicitante']] ?? null; ?>
      <tr><th><?= $h($ps['solicitante'] ?: '(sem nome)') ?></th>
        <td><?= (int) $ps['n'] ?> dias · último: <?= $h(data_br((string) ($mk['ultimo_dia_lancado'] ?? $ps['ultimo']))) ?>
          <?= $mk ? ' · marcador de ' . $h($mk['gravado_em'] ?? '') : '' ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="cartao">
    <h2>Registros</h2>
    <p class="dica">Os registros não contêm texto das conversas nem chaves.</p>
    <details><summary>Atividade do app (últimos 40)</summary>
      <pre class="log"><?php foreach ($logApp as $l) { echo $h($l['quando'] . ' [' . $l['nivel'] . '] ' . $l['texto']) . "\n"; } ?></pre></details>
    <details><summary>Instalações (data/instalacoes.log)</summary>
      <pre class="log"><?= $h(ultimas_linhas(DIR_DATA . '/instalacoes.log')) ?></pre></details>
    <details><summary>Erros do worker (data/worker-erros.log)</summary>
      <pre class="log"><?= $h(ultimas_linhas(DIR_DATA . '/worker-erros.log')) ?: 'Nenhum erro.' ?></pre></details>
  </div>
</section>
</main>

<script src="<?= asset('admin.js') ?>"></script>
</body>
</html>
