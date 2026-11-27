// Tela 5 — acompanha o lançamento (ou a simulação) dia a dia.
(function () {
  'use strict';
  const job = document.body.dataset.job;
  const $ = id => document.getElementById(id);
  const ICONES = { ok: '✓', erro: '✕', rodando: '…', aguardando: '…', confirmado: '…' };
  const STATUS = {
    analisado: 'aguardando', abrindo: 'preenchendo…', conferindo: 'aguardando sua conferência', simulado: 'preenchido (simulação)',
    aberto: 'aberto', resolvido: 'resolvido ✓', erro: 'erro'
  };
  let timer = null;
  let emConferencia = null;
  let clicado = null;
  let vaiParaRelatorio = false;

  const dataBr = iso => { const [a, m, d] = iso.split('-'); return d + '/' + m + '/' + a; };

  function desenhar(d) {
    const j = d.job;
    const cartao = $('c-lancar');
    cartao.classList.remove('ok', 'erro', 'rodando', 'aguardando', 'confirmado');
    cartao.classList.add(j.lancar_status);
    cartao.querySelector('.icone').textContent = ICONES[j.lancar_status] || '…';
    $('l-titulo').textContent = d.simulacao ? 'Lançamento — modo simulação' : 'Lançamento';
    $('l-msg').textContent = j.lancar_msg || '';
    $('l-barra').style.width = (j.lancar_progresso || 0) + '%';

    const corpo = $('l-dias');
    corpo.replaceChildren();
    d.dias.filter(x => Number(x.selecionado) === 1 && Number(x.tem_solicitacao) === 1).forEach(x => {
      const tr = document.createElement('tr');
      tr.className = x.status;
      const td = t => { const c = document.createElement('td'); c.textContent = t; return c; };
      tr.append(td(dataBr(x.data)), td(x.titulo || ''));
      const st = td(STATUS[x.status] || x.status);
      if (x.erro) { st.textContent += ' — ' + x.erro; }
      tr.append(st);
      const tp = document.createElement('td');
      if (x.protocolo) {
        const a = document.createElement('a');
        a.href = d.url_base + '/atendimento/' + encodeURIComponent(x.protocolo);
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = x.protocolo;
        tp.append(a);
      } else {
        tp.textContent = '—';
      }
      tr.append(tp);
      corpo.append(tr);
    });

    const conf = d.dias.find(x => x.status === 'conferindo');
    $('conferir').hidden = !conf;
    if (conf) {
      emConferencia = conf.data;
      const resta = d.dias.filter(x => Number(x.selecionado) === 1 && x.status === 'analisado').length;
      $('conferir-texto').textContent = 'Formulário de ' + dataBr(conf.data) + ' preenchido. ' +
        (resta ? 'Ao confirmar, o app passa para o próximo dia (' + resta + ' restante' + (resta > 1 ? 's' : '') + ').'
               : 'É o último dia: ao confirmar, a simulação termina e a janela fecha.');
      $('btn-conferido').disabled = clicado === conf.data;
    }

    const terminou = j.lancar_status === 'ok' || j.lancar_status === 'erro';
    const parado = j.lancar_status === 'aguardando' || j.lancar_status === 'confirmado';
    $('l-dica').hidden = !(j.lancar_status === 'rodando');
    $('fim').hidden = !(terminou || parado);
    if (parado) {
      $('fim-titulo').textContent = 'Lançamento não iniciado';
      $('fim-texto').textContent = 'Volte à confirmação para escolher os dias.';
    } else if (terminou && d.simulacao) {
      $('fim-titulo').textContent = 'Simulação concluída';
      $('fim-texto').textContent = 'Nada foi enviado ao Helpdesk. Se o formulário foi preenchido corretamente, ' +
        'desligue o modo simulação em ⚙ Administração → Lançamento e clique em "Voltar à confirmação" para lançar ' +
        'de verdade — o primeiro lançamento real é de 1 dia só, para conferir no Helpdesk.';
    } else if (terminou) {
      $('fim-titulo').textContent = 'Lançamento concluído';
      $('fim-texto').textContent = 'Confira os tickets no Helpdesk pelos links de protocolo.';
    }
    // Lançamento real terminado com algum ticket aberto: segue para a Tela 6 (verificação final + relatório).
    const comTicket = d.dias.some(x => x.protocolo);
    $('btn-relatorio').hidden = !(terminou && comTicket);
    if (terminou && comTicket && !vaiParaRelatorio) {
      vaiParaRelatorio = true;
      $('fim-texto').textContent += ' Abrindo a verificação final…';
      setTimeout(() => { location.href = 'relatorio.php?job=' + encodeURIComponent(job); }, 3000);
    }
    if ((terminou || parado) && timer) { clearInterval(timer); timer = null; }
  }

  async function atualizar() {
    try {
      const r = await fetch('api/job_status.php?job=' + encodeURIComponent(job), { cache: 'no-store' });
      if (r.ok) desenhar(await r.json());
    } catch (e) { /* tenta de novo */ }
  }

  $('btn-voltar').addEventListener('click', async () => {
    $('fim-erro').hidden = true;
    const r = await fetch('api/lancar_reabrir.php', { method: 'POST', body: new URLSearchParams({ job }) });
    const d = await r.json();
    if (!r.ok) { $('fim-erro').textContent = d.erro || 'Erro.'; $('fim-erro').hidden = false; return; }
    location.href = 'analise.php?job=' + encodeURIComponent(job);
  });

  $('btn-conferido').addEventListener('click', async () => {
    if (!emConferencia) return;
    clicado = emConferencia;
    $('btn-conferido').disabled = true;
    await fetch('api/lancar_conferido.php', { method: 'POST', body: new URLSearchParams({ job, data: emConferencia }) });
    atualizar();
  });

  timer = setInterval(atualizar, 1500);
  atualizar();
})();
