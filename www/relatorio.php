<?php
// Tela 6 — verificação final (reabre cada ticket e corrige o que não ficou Resolvido) e relatório.
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$job = (int) ($_GET['job'] ?? 0);
if ($job <= 0) {
    require RAIZ . '/app/historico.php'; // sem job: histórico de todos os lançamentos
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatório — Lançador de Chamados</title>
<link rel="icon" href="assets/favicon.svg">
<link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body data-job="<?= $job ?>">
<?php topo(['Nova conversa' => 'conversa.php']); ?>
<?php etapas(5, $job ?? 0); ?>
<main>
  <section class="cartao etapa-cartao" id="c-verificar">
    <div class="etapa-cabeca"><span class="icone">…</span><h2>Verificação final</h2></div>
    <p class="etapa-msg" id="v-msg">Carregando…</p>
    <p class="dica" id="v-dica" hidden>O app reabre cada ticket na janela do navegador e lê a situação. Se algum não
      estiver "Resolvido", aplica o encerramento de novo (só por "Meus tickets"). Não feche a janela até terminar.</p>
    <div class="alerta erro" id="v-erro" hidden></div>
  </section>

  <section class="cartao">
    <h2>Relatório</h2>
    <p id="r-resumo" class="dica"></p>
    <table class="tabela-lanc">
      <thead><tr><th>Dia</th><th>Protocolo</th><th>Situação final</th><th>Corrigido?</th><th>Observação</th></tr></thead>
      <tbody id="r-dias"></tbody>
    </table>
    <p class="alerta ok" id="csv-ok" hidden></p>
    <div class="acoes">
      <button class="botao secundario" type="button" id="btn-reverificar" hidden>Conferir de novo</button>
      <button class="botao secundario" type="button" id="btn-csv">Exportar CSV</button>
      <a class="botao" href="relatorio.php">Voltar ao histórico</a>
    </div>
  </section>
</main>
<script src="<?= asset('tela6.js') ?>"></script>
</body>
</html>
