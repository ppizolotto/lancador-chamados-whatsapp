// Tela 1 — verificação do ambiente e instalação item a item.
(function () {
  'use strict';
  const lista = document.getElementById('checagens');
  const btnVerificar = document.getElementById('btn-verificar');
  const btnProsseguir = document.getElementById('btn-prosseguir');
  const caixaInst = document.getElementById('instalacao');
  const textoInst = document.getElementById('instalacao-texto');
  const barraInst = document.getElementById('instalacao-barra');

  const NOMES_ACAO = { python: 'Instalar', bibliotecas: 'Instalar', navegador: 'Instalar', config: 'Configurar' };
  let acompanhando = null;

  function el(tag, cls, texto) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (texto !== undefined) e.textContent = texto;
    return e;
  }

  function desenhar(dados) {
    lista.replaceChildren();
    dados.itens.forEach(item => {
      const estado = item.ok ? 'ok' : (item.pendente ? 'pendente' : 'erro');
      const li = el('li', estado);
      li.append(
        el('span', 'icone', item.ok ? '✓' : (item.pendente ? '…' : '✕')),
        el('span', 'nome', item.nome),
        el('span', 'detalhe', item.detalhe || '')
      );
      if (item.acao) {
        const b = el('button', 'botao pequeno', NOMES_ACAO[item.acao] || 'Corrigir');
        b.type = 'button';
        b.disabled = dados.instalando;
        b.addEventListener('click', () => agir(item.acao));
        li.append(b);
      }
      lista.append(li);
    });
    btnProsseguir.disabled = !dados.tudo_ok || dados.instalando;
  }

  async function verificar() {
    btnVerificar.disabled = true;
    lista.querySelectorAll('.detalhe').forEach(d => { d.textContent = 'verificando…'; });
    try {
      const r = await fetch('api/checar.php', { cache: 'no-store' });
      const dados = await r.json();
      desenhar(dados);
      if (dados.instalando) acompanhar();
      else mostrarInstalacao(dados.instalacao);
    } catch (e) {
      lista.replaceChildren(el('li', 'erro', 'Erro ao verificar o ambiente. Tente de novo.'));
    } finally {
      btnVerificar.disabled = false;
    }
  }

  function mostrarInstalacao(inst) {
    if (!inst || inst.status === 'ok') { caixaInst.hidden = true; return; }
    caixaInst.hidden = false;
    caixaInst.className = 'instalacao ' + inst.status;
    textoInst.textContent = (inst.status === 'erro' ? 'Falha ao instalar ' : 'Instalando ') + inst.item + ': ' + inst.mensagem;
    barraInst.style.width = (inst.progresso || 0) + '%';
  }

  async function agir(acao) {
    if (acao === 'config') { location.href = 'admin.php#ia'; return; }
    // O clique em "Instalar" é a aprovação do usuário para este item.
    const corpo = new URLSearchParams({ item: acao });
    const r = await fetch('api/instalar.php', { method: 'POST', body: corpo });
    const dados = await r.json();
    if (!r.ok) { mostrarInstalacao({ item: acao, status: 'erro', mensagem: dados.erro, progresso: 0 }); return; }
    lista.querySelectorAll('button').forEach(b => { b.disabled = true; });
    btnProsseguir.disabled = true;
    acompanhar();
  }

  function acompanhar() {
    if (acompanhando) return;
    acompanhando = setInterval(async () => {
      try {
        const r = await fetch('api/instalacao.php', { cache: 'no-store' });
        const dados = await r.json();
        mostrarInstalacao(dados.instalacao);
        if (!dados.instalando) {
          clearInterval(acompanhando);
          acompanhando = null;
          verificar();
        }
      } catch (e) { /* tenta de novo no próximo ciclo */ }
    }, 1500);
  }

  btnVerificar.addEventListener('click', verificar);
  btnProsseguir.addEventListener('click', () => { location.href = 'conversa.php'; });
  verificar();
})();
