// Tela 3 — acompanha análise e login. Quando os dois terminam, abre o pop-up de confirmação (Tela 4).
(function () {
  'use strict';
  const job = document.body.dataset.job;
  const $ = id => document.getElementById(id);
  const ICONES = { ok: '✓', erro: '✕', rodando: '…', aguardando: '…' };
  const DIA_ROTULO = { aguardando: 'aguardando', analisando: 'analisando…', analisado: 'analisado', erro: 'erro' };
  let timer = null;
  let ultimo = null;        // última resposta de job_status
  let abriuSozinho = false; // o pop-up abre automaticamente uma vez só

  const dataCurta = iso => { const [, m, d] = iso.split('-'); return d + '/' + m; };
  const dataBr = iso => { const [a, m, d] = iso.split('-'); return d + '/' + m + '/' + a; };

  function estado(cartao, status) {
    cartao.classList.remove('ok', 'erro', 'rodando', 'aguardando');
    cartao.classList.add(status);
    cartao.querySelector('.icone').textContent = ICONES[status] || '…';
  }

  function desenhar(d) {
    ultimo = d;
    const j = d.job;
    estado($('c-analise'), j.analise_status);
    $('a-msg').textContent = j.analise_msg || '';
    $('a-barra').style.width = (j.analise_progresso || 0) + '%';

    const lista = $('a-dias');
    lista.replaceChildren();
    d.dias.forEach(x => {
      const li = document.createElement('li');
      const sem = x.status === 'analisado' && !Number(x.tem_solicitacao);
      li.className = sem ? 'sem' : x.status;
      li.textContent = dataCurta(x.data) + ' — ' + (sem ? 'sem solicitação' : (DIA_ROTULO[x.status] || x.status));
      if (x.erro) li.title = x.erro;
      lista.append(li);
    });

    estado($('c-login'), j.login_status);
    $('l-msg').textContent = j.login_msg || '';
    $('btn-reabrir').hidden = j.login_status !== 'erro';

    const tudo = j.analise_status === 'ok' && j.login_status === 'ok';
    $('pronto').hidden = !tudo;
    const fim = ['ok', 'erro'].includes(j.analise_status) && ['ok', 'erro'].includes(j.login_status);
    if (fim && timer) { clearInterval(timer); timer = null; }
    if (j.lancar_status !== 'aguardando' && j.lancar_status !== 'confirmado') {
      location.href = 'lancamento.php?job=' + encodeURIComponent(job); // já confirmado e em andamento
      return;
    }
    if (tudo && !abriuSozinho) { abriuSozinho = true; abrirConfirmacao(); }
  }

  async function atualizar() {
    try {
      const r = await fetch('api/job_status.php?job=' + encodeURIComponent(job), { cache: 'no-store' });
      if (r.ok) desenhar(await r.json());
    } catch (e) { /* tenta de novo */ }
  }

  // ================================================================ Tela 4 (pop-up)
  const modal = $('modal'), deEl = $('m-de'), ateEl = $('m-ate'), previa = $('m-previa');
  const btnLancar = $('m-lancar');
  let dias = [];          // dias do job com marcação
  let podeLancar = [];    // datas que podem virar ticket

  function elegivel(x) {
    return x.status === 'analisado' && Number(x.tem_solicitacao) === 1 && !ultimo.ja_lancados.includes(x.data);
  }

  function abrirConfirmacao() {
    const d = ultimo;
    dias = d.dias;
    podeLancar = dias.filter(elegivel).map(x => x.data);
    const semHoje = podeLancar.filter(x => x !== d.hoje);

    $('m-resumo').textContent =
      'Solicitante: ' + d.solicitante + (d.solicitante_helpdesk ? ' (no Helpdesk: ' + d.solicitante_helpdesk + ')' : '') +
      ' · Conversa: ' + dataBr(d.job.primeiro_dia) + ' a ' + dataBr(d.job.ultimo_dia) +
      ' · Último dia já lançado: ' + (d.ultimo_lancado ? dataBr(d.ultimo_lancado) : 'nenhum');

    const todas = dias.map(x => x.data);
    // Listas com os dias da própria conversa, em DD/MM/AAAA (o campo de data nativo segue o idioma do
    // navegador interno, que pode estar em inglês: 11/26/2026).
    [deEl, ateEl].forEach(el => {
      el.replaceChildren(...todas.map(iso => {
        const o = document.createElement('option');
        o.value = iso;
        o.textContent = dataBr(iso) + (iso === d.hoje ? ' (hoje)' : '');
        return o;
      }));
    });
    const base = semHoje.length ? semHoje : (podeLancar.length ? podeLancar : todas);
    deEl.value = base[0] || '';
    ateEl.value = base[base.length - 1] || '';
    document.querySelector('input[name="modo"][value="intervalo"]').checked = true;

    const aviso1 = $('m-aviso-primeiro');
    aviso1.hidden = d.simulacao || !d.primeiro_real;
    aviso1.textContent = 'Primeiro lançamento real: escolha só 1 dia e confira o ticket no Helpdesk antes de lançar os outros.';
    $('m-erro').hidden = true;

    montarPrevia();
    aplicarIntervalo();
    modal.hidden = false;
    deEl.focus();
  }

  function montarPrevia() {
    previa.replaceChildren();
    dias.forEach(x => {
      const li = document.createElement('li');
      li.dataset.data = x.data;
      const pode = podeLancar.includes(x.data);
      const jaFoi = ultimo.ja_lancados.includes(x.data);
      li.className = pode ? '' : (x.status === 'erro' ? 'erro' : 'apagado');

      const cab = document.createElement('label');
      cab.className = 'previa-cabeca';
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.disabled = !pode;
      cb.addEventListener('change', contar);
      const dt = document.createElement('strong');
      dt.textContent = dataBr(x.data) + (x.data === ultimo.hoje ? ' (hoje)' : '');
      const tit = document.createElement('span');
      tit.className = 'previa-titulo';
      if (jaFoi) tit.textContent = 'já lançado antes';
      else if (x.status === 'erro') tit.textContent = 'não analisado: ' + (x.erro || 'erro') + ' — carregue a conversa de novo para tentar outra vez';
      else if (!Number(x.tem_solicitacao)) tit.textContent = 'sem solicitação';
      else tit.textContent = x.titulo || '';
      cab.append(cb, dt, tit);
      li.append(cab);

      if (pode) {
        const corpo = document.createElement('div');
        corpo.className = 'previa-corpo';
        li.append(corpo);
        mostrarDia(corpo, x, li);
      }
      previa.append(li);
    });
  }

  // ---- prévia de um dia (somente leitura) com o botão Editar
  function mostrarDia(corpo, x, li) {
    corpo.replaceChildren();
    li.querySelector('.previa-titulo').textContent = x.titulo || '';
    const ul = document.createElement('ul');
    (x.itens || []).forEach(i => { const it = document.createElement('li'); it.textContent = i; ul.append(it); });
    const sit = document.createElement('p');
    sit.className = 'previa-situacao';
    sit.textContent = 'Situação pela conversa: ' + (x.situacao || '—') + (x.provedor ? ' · analisado por ' + x.provedor : '');
    const ed = document.createElement('button');
    ed.type = 'button';
    ed.className = 'botao secundario pequeno previa-editar';
    ed.textContent = 'Editar';
    ed.addEventListener('click', () => editarDia(corpo, x, li));
    corpo.append(ul, sit, ed);
  }

  // ---- edição: título, itens (um por linha) e situação. Salva no servidor (com limpeza LGPD).
  function editarDia(corpo, x, li) {
    corpo.replaceChildren();
    const campo = (rotulo, el) => {
      const d = document.createElement('div'); d.className = 'campo';
      const l = document.createElement('label'); l.textContent = rotulo;
      d.append(l, el); return d;
    };
    const tit = document.createElement('input');
    tit.type = 'text'; tit.maxLength = 120; tit.value = x.titulo || '';
    const itens = document.createElement('textarea');
    itens.rows = Math.max(3, (x.itens || []).length + 1);
    itens.value = (x.itens || []).join('\n');
    const sit = document.createElement('textarea');
    sit.rows = 2; sit.value = x.situacao || '';
    const msg = document.createElement('div');
    msg.className = 'alerta erro'; msg.hidden = true;
    const salvar = document.createElement('button');
    salvar.type = 'button'; salvar.className = 'botao pequeno'; salvar.textContent = 'Salvar';
    const cancelar = document.createElement('button');
    cancelar.type = 'button'; cancelar.className = 'botao secundario pequeno'; cancelar.textContent = 'Cancelar';
    const acoes = document.createElement('div');
    acoes.className = 'acoes'; acoes.append(cancelar, salvar);

    corpo.append(
      campo('Assunto (até 120 caracteres)', tit),
      campo('Itens — um por linha', itens),
      campo('Situação pela conversa', sit),
      msg, acoes);
    btnLancar.disabled = true; // não lança com um dia em edição
    tit.focus();

    cancelar.addEventListener('click', () => { mostrarDia(corpo, x, li); contar(); });
    salvar.addEventListener('click', async () => {
      salvar.disabled = true; msg.hidden = true;
      try {
        const r = await fetch('api/dia_editar.php', {
          method: 'POST',
          body: new URLSearchParams({ job, data: x.data, titulo: tit.value, itens: itens.value, situacao: sit.value })
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.erro || 'Não foi possível salvar.');
        x.titulo = d.titulo; x.itens = d.itens; x.situacao = d.situacao;
        x.provedor = /editado$/.test(x.provedor || '') ? x.provedor : (x.provedor || 'IA') + ' + editado';
        mostrarDia(corpo, x, li);
        if (d.aviso) {
          const av = document.createElement('div');
          av.className = 'alerta aviso'; av.textContent = d.aviso;
          corpo.prepend(av);
        }
        contar();
      } catch (e) {
        msg.textContent = e.message; msg.hidden = false; salvar.disabled = false;
      }
    });
  }

  function modo() { return document.querySelector('input[name="modo"]:checked').value; }

  // Ao mudar o intervalo, marca todos os dias que podem ser lançados dentro dele (o usuário pode desmarcar um a um).
  function aplicarIntervalo() {
    const tudo = modo() === 'tudo';
    deEl.disabled = ateEl.disabled = tudo;
    let de = deEl.value, ate = ateEl.value;
    if (de && ate && de > ate) [de, ate] = [ate, de];
    previa.querySelectorAll(':scope > li').forEach(li => {
      const cb = li.querySelector('input');
      const dentro = tudo || (de && ate && li.dataset.data >= de && li.dataset.data <= ate);
      const pode = podeLancar.includes(li.dataset.data);
      cb.checked = pode && dentro;
      cb.disabled = !pode || !dentro;
      li.classList.toggle('fora', !dentro);
    });
    contar();
  }

  function selecionadas() {
    return [...previa.querySelectorAll(':scope > li')]
      .filter(li => li.querySelector('input').checked).map(li => li.dataset.data);
  }

  function contar() {
    const sel = selecionadas();
    const n = sel.length;
    const hoje = ultimo.hoje;
    const avisoHoje = $('m-aviso-hoje');
    avisoHoje.hidden = !sel.includes(hoje);
    avisoHoje.textContent = 'Você incluiu hoje (' + dataBr(hoje) + '). Mensagens que chegarem depois não serão lançadas: ' +
      'o marcador vai passar deste dia.';

    const bloqueio = !ultimo.simulacao && ultimo.primeiro_real && n > 1;
    let txt = n === 0 ? 'Nenhum dia selecionado.'
      : (n === 1 ? 'Será aberto 1 ticket e marcado como Resolvido.' : 'Serão abertos ' + n + ' tickets e marcados como Resolvidos.');
    if (ultimo.simulacao && n) txt += ' Modo simulação: o app preenche o formulário mas não clica em "Abrir ticket".';
    if (bloqueio) txt += ' Escolha só 1 dia neste primeiro lançamento real.';
    $('m-contagem').textContent = txt;
    btnLancar.textContent = ultimo.simulacao ? 'Simular ' + n + (n === 1 ? ' ticket' : ' tickets')
                                             : 'Lançar ' + n + (n === 1 ? ' ticket' : ' tickets');
    const editando = !!previa.querySelector('.previa-corpo .campo');
    btnLancar.disabled = n === 0 || bloqueio || editando;
    if (editando) btnLancar.title = 'Salve ou cancele a edição antes de lançar';
    else btnLancar.removeAttribute('title');
  }

  function fecharConfirmacao() { modal.hidden = true; $('btn-confirmar').focus(); }

  [deEl, ateEl].forEach(el => el.addEventListener('change', aplicarIntervalo));
  document.querySelectorAll('input[name="modo"]').forEach(r => r.addEventListener('change', aplicarIntervalo));
  $('m-cancelar').addEventListener('click', fecharConfirmacao);
  modal.addEventListener('keydown', e => { if (e.key === 'Escape') fecharConfirmacao(); });

  btnLancar.addEventListener('click', async () => {
    const datas = selecionadas();
    btnLancar.disabled = true;
    $('m-erro').hidden = true;
    try {
      const r = await fetch('api/lancar_confirmar.php', {
        method: 'POST', body: new URLSearchParams({ job, datas: JSON.stringify(datas) })
      });
      const d = await r.json();
      if (!r.ok) throw new Error(d.erro || 'Não foi possível confirmar.');
      location.href = 'lancamento.php?job=' + encodeURIComponent(job);
    } catch (e) {
      $('m-erro').textContent = e.message;
      $('m-erro').hidden = false;
      contar();
    }
  });

  // ================================================================ início
  $('btn-reabrir').addEventListener('click', async () => {
    await fetch('api/login_reabrir.php', { method: 'POST', body: new URLSearchParams({ job }) });
    if (!timer) timer = setInterval(atualizar, 1500);
    atualizar();
  });
  $('btn-confirmar').addEventListener('click', () => { if (ultimo) abrirConfirmacao(); });

  timer = setInterval(atualizar, 1500);
  atualizar();
})();
