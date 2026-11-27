<?php
// Tela 5 — Lançamento (ou simulação) acompanhado dia a dia.
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$job = (int) ($_GET['job'] ?? 0);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lançamento — Lançador de Chamados</title>
<link rel="icon" href="assets/favicon.svg">
<link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body data-job="<?= $job ?>">
<?php topo(['Nova conversa' => 'conversa.php']); ?>
<?php etapas(4, $job ?? 0); ?>
<main>
  <section class="cartao etapa-cartao" id="c-lancar">
    <div class="etapa-cabeca"><span class="icone">…</span><h2 id="l-titulo">Lançamento</h2></div>
    <p class="etapa-msg" id="l-msg">Carregando…</p>
    <div class="progresso"><span id="l-barra" style="width:0"></span></div>
    <p class="dica" id="l-dica">A janela do navegador mostra o preenchimento. Não feche a janela até terminar.</p>
    <table class="tabela-lanc">
      <thead><tr><th>Dia</th><th>Assunto</th><th>Status</th><th>Protocolo</th></tr></thead>
      <tbody id="l-dias"></tbody>
    </table>
  </section>

  <section class="cartao destaque-conferir" id="conferir" hidden>
    <h2>Confira o formulário</h2>
    <p id="conferir-texto"></p>
    <p class="dica">Confira na janela do navegador: solicitante, sistema, serviço, categoria, urgência, forma, responsável,
      tipo, assunto e mensagem. Nada é enviado — o app não clica em "Abrir ticket".</p>
    <div class="acoes"><button class="botao" type="button" id="btn-conferido">Formulário conferido</button></div>
  </section>

  <section class="cartao" id="fim" hidden>
    <h2 id="fim-titulo">Concluído</h2>
    <p id="fim-texto"></p>
    <div class="alerta erro" id="fim-erro" hidden></div>
    <div class="acoes">
      <a class="botao secundario" href="conversa.php">Nova conversa</a>
      <a class="botao" id="btn-relatorio" href="relatorio.php?job=<?= $job ?>" hidden>Ver relatório e verificação final</a>
      <button class="botao secundario" type="button" id="btn-voltar">Voltar à confirmação</button>
    </div>
  </section>
</main>
<script src="<?= asset('tela5.js') ?>"></script>
</body>
</html>
