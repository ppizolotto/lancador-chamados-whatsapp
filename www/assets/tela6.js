// Tela 6 — dispara a verificação final (uma vez) e mostra o relatório.
(function () {
  'use strict';
  const job = document.body.dataset.job;
  const $ = id => document.getElementById(id);
  const ICONES = { ok: '✓', erro: '✕', rodando: '…', aguardando: '…' };
  let timer = null;
  let iniciou = false;

  const dataBr = iso => { const [a, m, d] = iso.split('-'); return d + '/' + m + '/' + a; };
  const td = t => { const c = document.createElement('td'); c.textContent = t; return c; };

  function erro(msg) { $('v-erro').textContent = msg; $('v-erro').hidden = !msg; }

  async function iniciar(forcar) {
    erro('');
    const r = await fetch('api/verificar_iniciar.php', { method: 'POST', body: new URLSearchParams({ job, forcar: forcar ? '1' : '' }) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) { erro(d.erro || 'Não foi possível iniciar a verificação.'); return; }
    if (!timer) timer = setInterval(atualizar, 1500);
    atualizar();
  }

  function desenhar(d) {
    const j = d.job;
    const st = j.verificar_status;
    const cartao = $('c-verificar');
    cartao.classList.remove('ok', 'erro', 'rodando', 'aguardando');
    cartao.classList.add(st);
    cartao.querySelector('.icone').textContent = ICONES[st] || '…';
    $('v-msg').textContent = j.verificar_msg || (st === 'aguardando' ? 'Iniciando…' : '');
    $('v-dica').hidden = st !== 'rodando';
    $('btn-reverificar').hidden = !((st === 'ok' || st === 'erro') && d.dias.some(x => x.protocolo));

    // Lançamento ainda não terminou: volta para a Tela 5.
    if (!['ok', 'erro'].includes(j.lancar_status)) { location.href = 'lancamento.php?job=' + encodeURIComponent(job); return; }
    if (st === 'aguardando' && !iniciou) { iniciou = true; iniciar(false); }

    const linhas = d.dias.filter(x => Number(x.selecionado) === 1 && Number(x.tem_solicitacao) === 1);
    const corpo = $('r-dias');
    corpo.replaceChildren();
    let resolvidos = 0, corrigidos = 0, problemas = 0, simulados = 0;
    linhas.forEach(x => {
      const tr = document.createElement('tr');
      const tp = document.createElement('td');
      if (x.protocolo) {
        const a = document.createElement('a');
        a.href = d.url_base + '/atendimento/' + encodeURIComponent(x.protocolo);
        a.target = '_blank'; a.rel = 'noopener'; a.textContent = x.protocolo;
        tp.append(a);
      } else { tp.textContent = '—'; }
      const sit = x.situacao_final || (x.status === 'simulado' ? 'simulação' : 'não lançado');
      const ok = /resolvid|fechad/i.test(sit);
      const obs = x.ticket_erro || (x.protocolo ? '' : (x.erro || (x.status === 'simulado' ? 'não enviado (modo simulação)' : '')));
      const sim = !x.protocolo && x.status === 'simulado';
      if (ok) resolvidos++; else if (sim) simulados++; else problemas++;
      if (Number(x.corrigido)) corrigidos++;
      tr.className = ok ? 'resolvido' : (sim ? 'simulado' : 'erro');
      tr.append(td(dataBr(x.data)), tp, td(sit), td(x.protocolo ? (Number(x.corrigido) ? 'sim' : 'não') : ''), td(obs));
      corpo.append(tr);
    });
    $('r-resumo').textContent = 'Solicitante: ' + (d.solicitante || '—') + ' · ' + linhas.length + ' dia(s): ' +
      resolvidos + ' resolvido(s)' + (corrigidos ? ', ' + corrigidos + ' corrigido(s) na verificação' : '') +
      (simulados ? ', ' + simulados + ' só simulado(s), nada enviado' : '') +
      (problemas ? ', ' + problemas + ' com problema' : '') + '.';

    if ((st === 'ok' || st === 'erro') && timer) { clearInterval(timer); timer = null; }
  }

  async function atualizar() {
    try {
      const r = await fetch('api/job_status.php?job=' + encodeURIComponent(job), { cache: 'no-store' });
      if (r.ok) desenhar(await r.json());
    } catch (e) { /* tenta de novo */ }
  }

  $('btn-reverificar').addEventListener('click', () => {
    if (confirm('Conferir todos os tickets deste lançamento de novo no Helpdesk?')) iniciar(true);
  });

  $('btn-csv').addEventListener('click', async () => {
    $('csv-ok').hidden = true;
    const r = await fetch('api/relatorio_csv.php', { method: 'POST', body: new URLSearchParams({ job }) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) { erro(d.erro || 'Não foi possível exportar.'); return; }
    $('csv-ok').textContent = 'Relatório salvo em ' + d.arquivo + ' (dentro da pasta do app). A pasta foi aberta no Explorer.';
    $('csv-ok').hidden = false;
  });

  timer = setInterval(atualizar, 1500);
  atualizar();
})();
