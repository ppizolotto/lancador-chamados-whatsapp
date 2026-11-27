<?php
// Tela 3 — Análise da conversa + login no Helpdesk, em paralelo.
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$job = (int) ($_GET['job'] ?? 0);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Análise — Lançador de Chamados</title>
<link rel="icon" href="assets/favicon.svg">
<link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body data-job="<?= $job ?>">
<?php topo(['← Outra conversa' => 'conversa.php']); ?>

<?php etapas(2, $job ?? 0); ?>

<main>
  <div class="lado-a-lado">
    <section class="cartao etapa-cartao" id="c-analise">
      <div class="etapa-cabeca"><span class="icone">…</span><h2>Análise da conversa</h2></div>
      <p class="etapa-msg" id="a-msg">Aguardando…</p>
      <div class="progresso"><span id="a-barra" style="width:0"></span></div>
      <ul class="dias-status" id="a-dias"></ul>
    </section>

    <section class="cartao etapa-cartao" id="c-login">
      <div class="etapa-cabeca"><span class="icone">…</span><h2>Login no Helpdesk</h2></div>
      <p class="etapa-msg" id="l-msg">Aguardando…</p>
      <p class="dica">O app <strong>não digita senha</strong>: você entra na janela do navegador que abrir.
        A sessão fica salva para as próximas vezes.</p>
      <div class="acoes">
        <button class="botao secundario" type="button" id="btn-reabrir" hidden>Abrir de novo</button>
      </div>
    </section>
  </div>

  <section class="cartao" id="pronto" hidden>
    <h2>Tudo pronto</h2>
    <p class="dica">Análise e login concluídos. Próximo passo: confirmar quais dias lançar.</p>
    <div class="acoes"><button class="botao" type="button" id="btn-confirmar">Revisar e confirmar</button></div>
  </section>
</main>

<!-- Tela 4 — pop-up de confirmação. Nada é enviado ao Helpdesk antes de "Lançar N tickets". -->
<div class="modal-fundo" id="modal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="m-titulo">
    <div class="modal-cabeca">
      <h2 id="m-titulo">Confirmar os dias a lançar</h2>
      <p class="dica" id="m-resumo"></p>
    </div>

    <div class="modal-intervalo">
      <label class="opcao-inline"><input type="radio" name="modo" value="intervalo" checked> De
        <select id="m-de" aria-label="Primeiro dia"></select> até <select id="m-ate" aria-label="Último dia"></select></label>
      <label class="opcao-inline"><input type="radio" name="modo" value="tudo"> Lançar tudo</label>
    </div>
    <div class="alerta aviso" id="m-aviso-hoje" hidden></div>
    <div class="alerta aviso" id="m-aviso-primeiro" hidden></div>

    <ul class="previa" id="m-previa"></ul>

    <div class="modal-rodape">
      <p id="m-contagem"></p>
      <div class="alerta erro" id="m-erro" hidden></div>
      <div class="acoes">
        <button class="botao secundario" type="button" id="m-cancelar">Cancelar</button>
        <button class="botao" type="button" id="m-lancar" disabled>Lançar</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= asset('tela3.js') ?>"></script>
</body>
</html>
