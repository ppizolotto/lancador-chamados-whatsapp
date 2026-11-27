<?php
// Tela 2 — Arquivo da conversa.
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require RAIZ . '/app/conversa.php';

?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Conversa — Lançador de Chamados</title>
<link rel="icon" href="assets/favicon.svg">
<link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body>
<?php topo(['← Ambiente' => 'index.php']); ?>

<?php etapas(1, $job ?? 0); ?>

<main>
  <section class="cartao">
    <h2>Arquivo da conversa</h2>
    <p class="dica">No WhatsApp: abra a conversa → ⋮ → Mais → <strong>Exportar conversa → Sem mídia</strong>.
      Depois escolha aqui o arquivo <code>.txt</code>, <code>.md</code> ou o <code>.zip</code> da exportação.
      O app identifica o solicitante pela conversa.</p>

    <label class="arquivo" id="zona">
      <input type="file" id="arquivo" accept=".txt,.md,.zip,text/plain,text/markdown,application/zip">
      <span class="arquivo-texto" id="arquivo-texto">Clique para escolher o arquivo (ou arraste aqui)</span>
    </label>
    <div class="alerta erro" id="erro" hidden></div>
  </section>

  <section class="cartao" id="solicitante" hidden>
    <h2>Solicitante</h2>
    <p class="sol-linha"><strong id="s-nome"></strong> <span id="s-status"></span></p>
    <div class="campo" id="s-escolha" hidden>
      <label for="s-lista">Esta conversa tem mais de uma pessoa além de você. Quem é o solicitante?</label>
      <select id="s-lista"></select>
      <small>Só os pedidos dessa pessoa serão lançados.</small>
    </div>
    <div id="s-form" hidden>
      <p class="dica">Primeira vez com esta pessoa: informe como ela está cadastrada no Helpdesk
        (campo <em>Solicitante</em> do formulário de novo ticket).</p>
      <div class="grade-campos">
        <div class="campo"><label for="s-busca">Termo de busca</label>
          <input type="text" id="s-busca" maxlength="60" placeholder="ex.: sobrenome">
          <small>O que o app digita no campo Solicitante.</small></div>
        <div class="campo"><label for="s-helpdesk">Nome no Helpdesk</label>
          <input type="text" id="s-helpdesk" maxlength="120" placeholder="ex.: MARIA DA SILVA">
          <small>Como aparece na lista do Helpdesk (maiúsculas e acentos não importam).</small></div>
      </div>
      <div class="alerta erro" id="s-erro" hidden></div>
      <div class="acoes"><button class="botao secundario" type="button" id="s-salvar">Salvar solicitante</button></div>
    </div>
  </section>

  <section class="cartao" id="resumo" hidden>
    <h2>Conversa encontrada</h2>
    <div class="numeros">
      <div><span id="r-periodo">—</span><small>período</small></div>
      <div><span id="r-dias">—</span><small>dias com mensagens</small></div>
      <div><span id="r-lancados">—</span><small>já lançados antes</small></div>
      <div class="destaque"><span id="r-analisar">—</span><small>a analisar</small></div>
    </div>
    <ul class="dias-lista" id="r-lista"></ul>
    <p class="dica" id="r-aviso"></p>
    <div class="acoes">
      <button class="botao" type="button" id="btn-prosseguir" disabled>Prosseguir</button>
    </div>
  </section>
</main>

<script src="<?= asset('tela2.js') ?>"></script>
</body>
</html>
