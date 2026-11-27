// Tela 2 — envia o arquivo, identifica o solicitante, mostra o período e inicia análise + login.
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const entrada = $('arquivo');
  const zona = $('zona');
  const textoZona = $('arquivo-texto');
  const caixaErro = $('erro');
  const resumo = $('resumo');
  const btn = $('btn-prosseguir');
  let job = null;
  let arquivoAtual = null;
  let ultimaResposta = null;

  const ROTULOS = {
    lancado: 'já lançado', anterior: 'antes do último lançamento', hoje: 'hoje (fica desmarcado)', pendente: 'a analisar'
  };

  function erro(msg) {
    caixaErro.textContent = msg;
    caixaErro.hidden = !msg;
  }

  // Envia o arquivo; "solicitante" só quando o usuário escolhe outra pessoa na lista.
  async function enviar(arquivo, solicitante) {
    erro('');
    resumo.hidden = true;
    $('solicitante').hidden = true;
    btn.disabled = true;
    arquivoAtual = arquivo;
    textoZona.textContent = 'Lendo ' + arquivo.name + '…';
    const corpo = new FormData();
    corpo.append('arquivo', arquivo);
    if (solicitante) corpo.append('solicitante', solicitante);
    try {
      const r = await fetch('api/conversa_carregar.php', { method: 'POST', body: corpo });
      const d = await r.json();
      if (!r.ok) { erro(d.erro || 'Erro ao ler o arquivo.'); textoZona.textContent = 'Escolher outro arquivo'; return; }
      job = d.job;
      ultimaResposta = d;
      textoZona.textContent = '📄 ' + d.arquivo + ' — clique para trocar';
      mostrarSolicitante(d);
      mostrar(d);
    } catch (e) {
      erro('Erro ao enviar o arquivo.');
    }
  }

  function mostrarSolicitante(d) {
    $('solicitante').hidden = false;
    $('s-nome').textContent = d.solicitante;
    const cad = d.cadastro;
    $('s-status').textContent = cad ? '→ no Helpdesk: ' + cad.nome_helpdesk : '— ainda não cadastrado';
    $('s-status').className = cad ? 'sol-ok' : 'sol-falta';

    const escolha = $('s-escolha');
    escolha.hidden = d.remetentes.length < 2;
    if (d.remetentes.length > 1) {
      const lista = $('s-lista');
      lista.replaceChildren(...d.remetentes.map(n => {
        const o = document.createElement('option');
        o.value = n; o.textContent = n; o.selected = n === d.solicitante;
        return o;
      }));
    }

    $('s-form').hidden = !!cad;
    if (!cad) {
      $('s-busca').value = d.solicitante.split(/\s+/).filter(p => p.length > 2).slice(-1)[0] || '';
      $('s-helpdesk').value = '';
      $('s-erro').hidden = true;
    }
  }

  function mostrar(d) {
    $('r-periodo').textContent = d.primeiro + ' a ' + d.ultimo;
    $('r-dias').textContent = d.total_dias;
    $('r-lancados').textContent = d.dias.filter(x => x.situacao === 'lancado').length;
    $('r-analisar').textContent = d.a_analisar;
    const lista = $('r-lista');
    lista.replaceChildren();
    d.dias.forEach(x => {
      const li = document.createElement('li');
      li.className = x.situacao;
      li.title = x.mensagens + ' mensagens';
      li.textContent = x.data_br.slice(0, 5);
      const s = document.createElement('small');
      s.textContent = ROTULOS[x.situacao];
      li.append(s);
      lista.append(li);
    });
    const aviso = $('r-aviso');
    if (d.a_analisar === 0) {
      aviso.textContent = 'Não há dias novos de ' + d.solicitante + ' nesta conversa' +
        (d.ultimo_lancado ? ' (último lançado: ' + d.ultimo_lancado + ').' : '.');
    } else if (!d.cadastro) {
      aviso.textContent = 'Cadastre o solicitante acima para prosseguir.';
    } else {
      aviso.textContent = 'Ao prosseguir, a IA analisa os ' + d.a_analisar +
        ' dias (sem CPF, CNS e senhas) e, ao mesmo tempo, abre o navegador para você entrar no Helpdesk. ' +
        'Nada é enviado ao Helpdesk antes da sua confirmação.' +
        (d.ultimo_lancado ? ' Último dia já lançado para ' + d.solicitante + ': ' + d.ultimo_lancado + '.' : '');
    }
    resumo.hidden = false;
    btn.disabled = d.a_analisar === 0 || !d.cadastro;
  }

  $('s-lista').addEventListener('change', () => {
    if (arquivoAtual) enviar(arquivoAtual, $('s-lista').value);
  });

  $('s-salvar').addEventListener('click', async () => {
    const d = ultimaResposta;
    $('s-erro').hidden = true;
    $('s-salvar').disabled = true;
    try {
      const r = await fetch('api/solicitante_salvar.php', {
        method: 'POST',
        body: new URLSearchParams({ nome_whatsapp: d.solicitante, busca: $('s-busca').value, nome_helpdesk: $('s-helpdesk').value })
      });
      const res = await r.json();
      if (!r.ok) throw new Error(res.erro || 'Não foi possível salvar.');
      d.cadastro = res.cadastro;
      mostrarSolicitante(d);
      mostrar(d);
    } catch (e) {
      $('s-erro').textContent = e.message;
      $('s-erro').hidden = false;
    } finally {
      $('s-salvar').disabled = false;
    }
  });

  entrada.addEventListener('change', () => { if (entrada.files[0]) enviar(entrada.files[0]); });
  ['dragover', 'dragenter'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.add('sobre'); }));
  ['dragleave', 'drop'].forEach(ev => zona.addEventListener(ev, () => zona.classList.remove('sobre')));
  zona.addEventListener('drop', e => { e.preventDefault(); if (e.dataTransfer.files[0]) enviar(e.dataTransfer.files[0]); });

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    const r = await fetch('api/job_iniciar.php', { method: 'POST', body: new URLSearchParams({ job }) });
    const d = await r.json();
    if (!r.ok) { erro(d.erro); btn.disabled = false; return; }
    location.href = 'analise.php?job=' + encodeURIComponent(job);
  });
})();
