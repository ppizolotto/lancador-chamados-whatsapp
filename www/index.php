<?php
// Tela 1 — Verificação do ambiente.
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$simulacao = config()['simulacao'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lançador de Chamados</title>
<link rel="icon" href="assets/favicon.svg">
<link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body>
<?php topo(); ?>

<?php etapas(0, $job ?? 0); ?>

<main>
  <section class="cartao">
    <h2>Verificação do ambiente</h2>
    <p class="dica">Tudo precisa estar ✅ para prosseguir. O que faltar é instalado <strong>dentro da pasta do app</strong>,
      um item por vez, só quando você clicar em "Instalar".</p>
    <ul class="checagens" id="checagens">
      <li class="pendente"><span class="icone">…</span><span class="nome">Verificando…</span></li>
    </ul>
    <div class="instalacao" id="instalacao" hidden>
      <div class="instalacao-texto" id="instalacao-texto"></div>
      <div class="progresso"><span id="instalacao-barra" style="width:0"></span></div>
    </div>
    <div class="acoes">
      <button class="botao secundario" type="button" id="btn-verificar">Verificar de novo</button>
      <button class="botao" type="button" id="btn-prosseguir" disabled>Prosseguir</button>
    </div>
  </section>
</main>

<script src="<?= asset('tela1.js') ?>"></script>
</body>
</html>
