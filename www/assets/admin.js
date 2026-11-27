// Administração — abas, aviso de plano gratuito, teste de chave e atualização.
(function () {
  'use strict';

  // ---- abas (pelo #endereço, para voltar na mesma aba depois de salvar)
  const abas = document.querySelectorAll('.aba');
  const links = document.querySelectorAll('.abas a');
  function mostrarAba() {
    const id = (location.hash || '#ia').slice(1);
    const alvo = document.getElementById(id) ? id : 'ia';
    abas.forEach(a => { a.hidden = a.id !== alvo; });
    links.forEach(l => l.classList.toggle('atual', l.getAttribute('href') === '#' + alvo));
  }
  window.addEventListener('hashchange', mostrarAba);
  mostrarAba();

  // ---- confirmação antes de ações que apagam algo
  document.querySelectorAll('form[data-confirmar]').forEach(f => {
    f.addEventListener('submit', e => { if (!confirm(f.dataset.confirmar)) e.preventDefault(); });
  });

  // ---- aviso ao escolher provedor gratuito
  function avisoGratuito() {
    const sel = document.querySelector('input[name="provedor"]:checked');
    document.getElementById('aviso-gratuito').hidden = !sel || sel.dataset.gratuito !== '1';
  }
  document.querySelectorAll('input[name="provedor"]').forEach(r => r.addEventListener('change', avisoGratuito));
  avisoGratuito();

  // ---- testar uma chave salva
  const ESTADOS = {
    ok: 'funcionando', limite: 'chave aceita, mas no limite de uso agora', sobrecarga: 'provedor sobrecarregado agora',
    sem_cota: 'o plano não inclui este modelo', chave: 'chave recusada', modelo: 'modelo não encontrado',
    config: 'falta configurar', rede: 'sem resposta', outro: 'erro'
  };
  document.querySelectorAll('[data-testar]').forEach(b => {
    b.addEventListener('click', async () => {
      const prov = b.dataset.testar;
      const saida = document.getElementById('t-' + prov);
      b.disabled = true;
      saida.className = 'resultado-teste';
      saida.textContent = 'testando…';
      try {
        const r = await fetch('api/testar_ia.php', { method: 'POST', body: new URLSearchParams({ provedor: prov }) });
        const d = await r.json();
        if (!r.ok) throw new Error(d.erro || 'erro');
        saida.classList.add(d.estado === 'ok' ? 'ok' : 'erro');
        saida.textContent = (d.estado === 'ok' ? '✓ ' : '✕ ') + (ESTADOS[d.estado] || d.estado) +
          (d.detalhe && d.estado !== 'ok' ? ' — ' + d.detalhe : '');
      } catch (e) {
        saida.classList.add('erro');
        saida.textContent = '✕ ' + e.message;
      } finally {
        b.disabled = false;
      }
    });
  });

  // ---- atualizar bibliotecas e navegador (em segundo plano)
  const btnAtu = document.getElementById('btn-atualizar');
  const msgAtu = document.getElementById('atu-msg');
  const caixa = document.getElementById('atu-barra-caixa');
  const barra = document.getElementById('atu-barra');
  let timer = null;
  let atualizando = false;

  async function acompanhar() {
    try {
      const r = await fetch('api/instalacao.php', { cache: 'no-store' });
      const d = await r.json();
      const st = d.instalacao;
      if (!st || st.item !== 'atualizar' || (!atualizando && st.status !== 'rodando')) return;
      caixa.hidden = false;
      barra.style.width = (st.progresso || 0) + '%';
      msgAtu.textContent = st.mensagem || '';
      if (st.status === 'rodando' && !timer) { atualizando = true; btnAtu.disabled = true; timer = setInterval(acompanhar, 1500); }
      if (st.status !== 'rodando') {
        clearInterval(timer); timer = null;
        btnAtu.disabled = false;
        if (st.status === 'ok' && atualizando) setTimeout(() => location.reload(), 1500);
      }
    } catch (e) { /* tenta de novo */ }
  }

  btnAtu.addEventListener('click', async () => {
    if (!confirm('Baixar e instalar as versões novas das bibliotecas e do Chromium dentro da pasta do app?')) return;
    btnAtu.disabled = true;
    msgAtu.textContent = 'Iniciando…';
    const r = await fetch('api/instalar.php', { method: 'POST', body: new URLSearchParams({ item: 'atualizar' }) });
    const d = await r.json();
    if (!r.ok) { msgAtu.textContent = d.erro || 'Não foi possível iniciar.'; btnAtu.disabled = false; return; }
    atualizando = true;
    timer = setInterval(acompanhar, 1500);
  });
  acompanhar(); // retoma se já houver uma atualização em andamento
})();
