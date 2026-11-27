<?php
// Histórico de lançamentos (aberto por "6. Relatório" fora de um job). Só metadados: nada da conversa.
// Filtros (GET): solicitante, de, ate (AAAA-MM-DD). Valem para o resumo, os lançamentos e os dias.
declare(strict_types=1);
require_once RAIZ . '/app/conversa.php';
require_once RAIZ . '/app/historico_filtros.php';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$pdo = db();
$base = rtrim((string) config()['url_base'], '/');
$f = filtros_historico($_GET);
[$where, $params] = where_historico($f, 't');

/* ---------- resumo por solicitante (respeita o período, não o solicitante: os cartões servem para escolher) */
[$wPer, $pPer] = where_historico(['solicitante' => '', 'de' => $f['de'], 'ate' => $f['ate']], 't');
$st = $pdo->prepare("SELECT t.solicitante, COUNT(*) AS dias,
        SUM(CASE WHEN t.protocolo IS NOT NULL AND t.protocolo != '' THEN 1 ELSE 0 END) AS tickets,
        SUM(CASE WHEN lower(t.situacao_final) LIKE '%resolvid%' OR lower(t.situacao_final) LIKE '%fechad%' THEN 1 ELSE 0 END) AS ok,
        SUM(CASE WHEN t.situacao_final = 'importado' THEN 1 ELSE 0 END) AS importados,
        SUM(t.corrigido) AS corrigidos, MAX(t.data) AS ultimo
     FROM tickets t WHERE $wPer GROUP BY t.solicitante ORDER BY t.solicitante");
$st->execute($pPer);
$resumo = $st->fetchAll();
$cad = $pdo->query('SELECT nome_whatsapp, nome_helpdesk FROM solicitantes')->fetchAll(PDO::FETCH_KEY_PAIR);
$todosSol = $pdo->query('SELECT DISTINCT solicitante FROM tickets ORDER BY solicitante')->fetchAll(PDO::FETCH_COLUMN);

/* ---------- lançamentos reais (jobs com protocolo) */
$st = $pdo->prepare(
    "SELECT j.id, j.criado_em, j.solicitante, j.verificar_status, COUNT(*) AS n,
            SUM(CASE WHEN lower(t.situacao_final) LIKE '%resolvid%' OR lower(t.situacao_final) LIKE '%fechad%' THEN 1 ELSE 0 END) AS ok,
            MIN(t.data) AS de, MAX(t.data) AS ate
     FROM jobs j JOIN tickets t ON t.job_id = j.id AND t.protocolo IS NOT NULL AND t.protocolo != ''
     WHERE $where GROUP BY j.id ORDER BY j.id DESC LIMIT 100");
$st->execute($params);
$jobs = $st->fetchAll();

/* ---------- dias */
$st = $pdo->prepare("SELECT t.data, t.solicitante, t.protocolo, t.job_id, t.situacao_final, t.corrigido, t.erro
                     FROM tickets t WHERE $where ORDER BY t.data DESC, t.solicitante LIMIT 1000");
$st->execute($params);
$tickets = $st->fetchAll();

$VERIF = ['aguardando' => 'não conferido', 'rodando' => 'conferindo…', 'ok' => 'conferido ✓', 'erro' => 'conferido, com problema'];
$link = fn(array $mudar) => 'relatorio.php?' . http_build_query(array_filter(array_merge($f, $mudar), fn($v) => $v !== ''));
$filtrado = $f['solicitante'] !== '' || $f['de'] !== '' || $f['ate'] !== '';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatório — Lançador de Chamados</title>
<link rel="icon" href="assets/favicon.svg">
<link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body>
<?php topo(['Nova conversa' => 'conversa.php']); ?>
<?php etapas(5); ?>
<main>
  <section class="cartao">
    <h2>Histórico de lançamentos</h2>
    <form method="get" class="filtros-hist">
      <div class="campo"><label for="f-sol">Solicitante</label>
        <select id="f-sol" name="solicitante">
          <option value="">todos</option>
          <?php foreach ($todosSol as $s): ?>
            <option value="<?= $h($s) ?>" <?= $s === $f['solicitante'] ? 'selected' : '' ?>><?= $h($s) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="campo"><label for="f-de">De</label>
        <input type="text" id="f-de" name="de" placeholder="DD/MM/AAAA" maxlength="10" value="<?= $h($f['de'] ? data_br($f['de']) : '') ?>"></div>
      <div class="campo"><label for="f-ate">Até</label>
        <input type="text" id="f-ate" name="ate" placeholder="DD/MM/AAAA" maxlength="10" value="<?= $h($f['ate'] ? data_br($f['ate']) : '') ?>"></div>
      <div class="acoes-filtro">
        <button class="botao" type="submit">Filtrar</button>
        <?php if ($filtrado): ?><a class="botao secundario" href="relatorio.php">Limpar</a><?php endif; ?>
        <button class="botao secundario" type="button" id="btn-csv-hist">Exportar CSV</button>
      </div>
    </form>
    <p class="alerta ok" id="csv-ok" hidden></p>
    <p class="alerta erro" id="csv-erro" hidden></p>

    <div class="cartoes-sol">
    <?php foreach ($resumo as $r):
        $ativo = $r['solicitante'] === $f['solicitante']; ?>
      <a class="cartao-sol<?= $ativo ? ' ativo' : '' ?>" href="<?= $h($link(['solicitante' => $ativo ? '' : $r['solicitante']])) ?>"
         title="<?= $ativo ? 'Mostrar todos' : 'Mostrar só este solicitante' ?>">
        <strong><?= $h($r['solicitante'] ?: '(sem nome)') ?></strong>
        <?php if (!empty($cad[$r['solicitante']])): ?><small><?= $h($cad[$r['solicitante']]) ?></small><?php endif; ?>
        <span><b><?= (int) $r['dias'] ?></b> dia(s) lançado(s)</span>
        <span><?= (int) $r['tickets'] ?> pelo app · <?= (int) $r['ok'] ?> resolvido(s)<?= (int) $r['importados'] ? ' · ' . (int) $r['importados'] . ' importado(s)' : '' ?></span>
        <?php if ((int) $r['corrigidos']): ?><span><?= (int) $r['corrigidos'] ?> corrigido(s) na verificação</span><?php endif; ?>
        <span>último dia: <?= $h(data_br((string) $r['ultimo'])) ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (!$resumo): ?><p class="dica">Nada lançado <?= $filtrado ? 'com esses filtros' : 'ainda' ?>.</p><?php endif; ?>
    </div>
  </section>

  <section class="cartao">
    <h2>Lançamentos<?= $f['solicitante'] !== '' ? ' — ' . $h($f['solicitante']) : '' ?></h2>
    <?php if (!$jobs): ?>
      <p class="dica">Nenhum lançamento pelo app <?= $filtrado ? 'com esses filtros' : 'ainda' ?>.</p>
    <?php else: ?>
    <table class="tabela-lanc">
      <thead><tr><th>Quando</th><th>Solicitante</th><th>Dias</th><th>Tickets</th><th>Verificação</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $j): ?>
        <tr class="<?= (int) $j['ok'] === (int) $j['n'] ? 'resolvido' : 'erro' ?>">
          <td><?= $h(date('d/m/Y H:i', strtotime((string) $j['criado_em']) ?: 0)) ?></td>
          <td><?= $h($j['solicitante']) ?></td>
          <td><?= $h(data_br($j['de'])) ?><?= $j['de'] !== $j['ate'] ? ' a ' . $h(data_br($j['ate'])) : '' ?></td>
          <td><?= (int) $j['ok'] ?> de <?= (int) $j['n'] ?> resolvido(s)</td>
          <td><?= $h($VERIF[$j['verificar_status']] ?? $j['verificar_status']) ?></td>
          <td><a href="relatorio.php?job=<?= (int) $j['id'] ?>">Abrir relatório</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="cartao">
    <h2>Dias lançados<?= $f['solicitante'] !== '' ? ' — ' . $h($f['solicitante']) : '' ?></h2>
    <table class="tabela-lanc">
      <thead><tr><th>Dia</th><?php if ($f['solicitante'] === ''): ?><th>Solicitante</th><?php endif; ?><th>Protocolo</th><th>Situação</th><th>Corrigido?</th><th>Observação</th></tr></thead>
      <tbody>
      <?php foreach ($tickets as $t):
          $imp = $t['situacao_final'] === 'importado'; ?>
        <tr>
          <td><?= $h(data_br($t['data'])) ?></td>
          <?php if ($f['solicitante'] === ''): ?><td><?= $h($t['solicitante']) ?></td><?php endif; ?>
          <td><?php if ($t['protocolo']): ?><a href="<?= $h($base . '/atendimento/' . rawurlencode((string) $t['protocolo'])) ?>" target="_blank" rel="noopener"><?= $h($t['protocolo']) ?></a><?php else: ?>—<?php endif; ?></td>
          <td><?= $h($imp ? 'importado (lançado antes do app)' : ($t['situacao_final'] ?? '')) ?></td>
          <td><?= $t['protocolo'] ? ((int) $t['corrigido'] ? 'sim' : 'não') : '' ?></td>
          <td><?= $h($t['erro'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="dica"><?= count($tickets) ?> dia(s)<?= $filtrado ? ' com os filtros escolhidos' : '' ?>.</p>
  </section>
</main>
<script>
document.getElementById('btn-csv-hist').addEventListener('click', async () => {
  const ok = document.getElementById('csv-ok'), er = document.getElementById('csv-erro');
  ok.hidden = er.hidden = true;
  const r = await fetch('api/historico_csv.php', { method: 'POST',
    body: new URLSearchParams(<?= json_encode(['solicitante' => $f['solicitante'], 'de' => $f['de'], 'ate' => $f['ate']]) ?>) });
  const d = await r.json().catch(() => ({}));
  if (!r.ok) { er.textContent = d.erro || 'Não foi possível exportar.'; er.hidden = false; return; }
  ok.textContent = 'Relatório salvo em ' + d.arquivo + ' (dentro da pasta do app). A pasta foi aberta no Explorer.';
  ok.hidden = false;
});
</script>
</body>
</html>
