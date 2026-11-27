"""
Automação do Helpdesk PELA INTERFACE, como um usuário faria (sem endpoints internos).
Isolada nesta classe para ser trocada por uma HelpdeskAPI quando a API oficial existir.

Seletores testados no protótipo (um protótipo anterior) em 24/11/2026.
"""
from __future__ import annotations

import html
import re
import unicodedata
from difflib import SequenceMatcher

SIMILARIDADE_MINIMA = 0.80


def normalizar(t: str) -> str:
    """Para comparar nomes: sem acento, maiúsculas, espaços simples ("Conceição" == "CONCEIÇÃO")."""
    t = unicodedata.normalize("NFD", t or "")
    t = "".join(c for c in t if unicodedata.category(c) != "Mn")
    return re.sub(r"\s+", " ", t).strip().upper()


def palavras(t: str) -> list[str]:
    """Só as palavras do nome: descarta o que a lista acrescenta, como "(org.)", e-mail ou códigos."""
    return [w for w in normalizar(t).split() if w.isalpha()]


def similaridade(nome: str, opcao: str) -> float:
    pa, pb = palavras(nome), palavras(opcao)
    if not pa or not pb:
        return 0.0
    if pa == pb:
        return 1.0
    if all(p in pb for p in pa):  # a opção tem todas as palavras do nome e mais alguma (ex.: JUNIOR, setor)
        return 0.90 + 0.05 * len(pa) / len(pb)
    return SequenceMatcher(None, " ".join(pa), " ".join(pb)).ratio()


def escolher_parecido(nome: str, opcoes: list[str]) -> tuple[int | None, str]:
    """Índice da opção mais parecida com o nome, ou (None, motivo) se nenhuma serve ou se há empate."""
    notas = sorted(((similaridade(nome, o), i) for i, o in enumerate(opcoes)), reverse=True)
    lista = "; ".join(opcoes[:5])
    melhor, idx = notas[0]
    if melhor < SIMILARIDADE_MINIMA:
        return None, f"nenhuma opção parecida com '{nome}' (apareceram: {lista})"
    if len(notas) > 1 and notas[1][0] >= SIMILARIDADE_MINIMA and melhor - notas[1][0] < 0.03:
        return None, f"mais de um cadastro parecido com '{nome}' (apareceram: {lista}); ajuste o nome em Administração"
    return idx, ""


# Mesma normalização do Python, no navegador.
_JS_NORM = r"""const N = s => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/\s+/g, ' ').trim().toUpperCase();"""

# Antes de digitar: marca o que JÁ tinha o termo na página (não é opção da lista).
JS_MARCAR_ANTIGOS = "(b) => {" + _JS_NORM + r"""
  document.querySelectorAll('body *').forEach(e => {
    if (!['SCRIPT', 'STYLE'].includes(e.tagName) && e.children.length === 0 && N(e.innerText).includes(b))
      e.setAttribute('data-lancador-antigo', '1');
  });
}"""

# Depois de digitar: opções visíveis que contêm o termo (o elemento mais interno de cada uma), marcadas por índice.
JS_OPCOES = "(b) => {" + _JS_NORM + r"""
  document.querySelectorAll('[data-lancador-opcao]').forEach(e => e.removeAttribute('data-lancador-opcao'));
  const visivel = e => { const r = e.getBoundingClientRect(), s = getComputedStyle(e);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const fora = ['SCRIPT', 'STYLE', 'INPUT', 'TEXTAREA', 'SELECT', 'OPTION', 'LABEL'];
  const cand = [...document.querySelectorAll('body *')].filter(e =>
    !fora.includes(e.tagName) && !e.hasAttribute('data-lancador-antigo') && N(e.innerText).includes(b) && visivel(e));
  const folhas = cand.filter(e => !cand.some(o => o !== e && e.contains(o)));
  // A lista costuma destacar o termo (<b>SILVA</b>): sobe até o item inteiro da opção.
  const ITEM = 'li,[role=option],[role=menuitem],a,button,tr,.dropdown-item,.list-group-item';
  const sozinho = (e, f) => !folhas.some(o => o !== f && e.contains(o));
  const itens = [];
  folhas.forEach(f => {
    let e = f.closest(ITEM);
    if (!e || !sozinho(e, f)) {
      e = f;
      for (let k = 0; k < 3 && e.parentElement && sozinho(e.parentElement, f)
           && (e.parentElement.innerText || '').length <= 150; k++) e = e.parentElement;
    }
    if (!itens.includes(e)) itens.push(e);
  });
  return itens.slice(0, 30).map((e, i) => {
    e.setAttribute('data-lancador-opcao', String(i));
    return (e.innerText || '').replace(/\s+/g, ' ').trim();
  });
}"""

JS_INSERIR_HTML = """([sel, conteudo]) => {
  const ed = document.querySelector(sel);
  ed.focus();
  document.execCommand('selectAll');
  document.execCommand('insertHTML', false, conteudo);
  return ed.innerText.length;
}"""

# id no formulário -> chave em config["campos"]
SELECTS = {"#sistema": "sistema", "#tecnologia": "tecnologia", "#servico": "servico", "#categoria": "categoria",
           "#urgencia": "urgencia", "#forma": "forma_contato", "#responsavel": "responsavel",
           "#tipoTicket": "tipo_ticket"}


class SessaoExpirada(Exception):
    """O Helpdesk mandou para /entrar: o usuário precisa fazer login de novo."""


class EnvioIncerto(Exception):
    """Clicou em "Abrir ticket" mas não confirmou o protocolo: o ticket PODE ter sido criado."""


class HelpdeskUI:
    def __init__(self, page, cfg: dict):
        self.pg = page
        self.cfg = cfg
        self.base = (cfg.get("url_base") or "https://helpdesk.exemplo.com.br").rstrip("/")

    # ------------------------------------------------------------ sessão
    def _ir(self, caminho: str) -> None:
        self.pg.goto(self.base + caminho, wait_until="domcontentloaded", timeout=45_000)
        if "/entrar" in self.pg.url:
            raise SessaoExpirada()

    def esperar_login(self, limite_ms: int, aviso=lambda: None) -> bool:
        """Leva a janela (visível) para /entrar e espera o usuário entrar. O app nunca digita senha."""
        self.pg.goto(self.base + "/entrar", timeout=45_000)
        aviso()
        passos = limite_ms // 1000
        for _ in range(passos):
            self.pg.wait_for_timeout(1000)
            url = self.pg.url
            if url.startswith(self.base) and "/entrar" not in url:
                self.pg.wait_for_load_state("networkidle", timeout=30_000)
                return True
        return False

    # ------------------------------------------------------------ novo ticket
    def escolher_solicitante(self, busca: str, nome: str) -> None:
        """Digita o termo no campo Solicitante, lê TODAS as opções que aparecem e clica na mais parecida
        com o nome cadastrado (sem diferenciar maiúsculas, acentos e espaços).
        Recusa (erro no dia, nada é enviado) se nenhuma for parecida o bastante ou se houver empate."""
        self.pg.evaluate(JS_MARCAR_ANTIGOS, normalizar(busca))  # o que já tinha o termo antes não é opção
        self.pg.fill("#solicitante", busca)
        opcoes: list[str] = []
        for _ in range(30):  # até ~15 s esperando a lista
            self.pg.wait_for_timeout(500)
            opcoes = self.pg.evaluate(JS_OPCOES, normalizar(busca))
            if opcoes:
                self.pg.wait_for_timeout(700)  # a lista pode ainda estar completando
                opcoes = self.pg.evaluate(JS_OPCOES, normalizar(busca))
                break
        if not opcoes:
            raise RuntimeError(f"nenhum solicitante apareceu na busca por '{busca}'")

        idx, motivo = escolher_parecido(nome, opcoes)
        if idx is None:
            raise RuntimeError(motivo)
        self.pg.locator(f'[data-lancador-opcao="{idx}"]').first.click()
        self.pg.wait_for_timeout(500)

        # Conferência: o nome escolhido precisa estar no formulário (no campo ou junto dele)
        # Depois do clique o Helpdesk pode trocar o campo pelo nome escolhido: não esperar o #solicitante existir.
        # Texto do formulário do ticket SEM as opções da lista (lista aberta não pode "confirmar" sozinha).
        js = """() => { const i = document.querySelector('#solicitante');
            const s = document.querySelector('#sistema');
            const f = (s && s.closest('form')) || document.querySelector('form') || document.body;
            const c = f.cloneNode(true); c.querySelectorAll('[data-lancador-opcao]').forEach(e => e.remove());
            return [i ? (i.value || '') : '', c.textContent || '']; }"""
        alvo_palavras = " ".join(palavras(opcoes[idx]))
        confirmado = False
        for _ in range(10):  # até ~5 s para o nome escolhido aparecer
            valor, texto_form = self.pg.evaluate(js)
            confirmado = (similaridade(nome, valor) >= SIMILARIDADE_MINIMA          # nome no campo
                          or alvo_palavras in " ".join(palavras(texto_form)))       # ou no lugar do campo
            if confirmado:
                break
            self.pg.wait_for_timeout(500)
        if not confirmado:
            raise RuntimeError(f"não confirmei a escolha do solicitante '{opcoes[idx]}'")

    def preencher_ticket(self, titulo: str, corpo_html: str, busca: str, nome_helpdesk: str) -> None:
        """Preenche o formulário de novo ticket SEM enviar, e confere o que ficou preenchido."""
        c = self.cfg["campos"]
        self._ir("/atendimento/novo")
        self.pg.wait_for_selector("#sistema", timeout=30_000)

        self.escolher_solicitante(busca, nome_helpdesk)

        for sel, chave in SELECTS.items():
            self.pg.select_option(sel, c[chave])
        self.pg.fill("#titulo", titulo)
        tamanho = self.pg.evaluate(JS_INSERIR_HTML, ["#descricao", corpo_html])

        # Conferência: nada de enviar um formulário pela metade
        faltando = [sel for sel, chave in SELECTS.items() if self.pg.input_value(sel) != str(c[chave])]
        if self.pg.input_value("#titulo") != titulo:
            faltando.append("#titulo")
        if not tamanho:
            faltando.append("#descricao")
        if faltando:
            raise RuntimeError("campos não preenchidos: " + ", ".join(faltando))

    def faixa_simulacao(self) -> None:
        """Só na tela do usuário (não altera nada no Helpdesk): avisa que o formulário NÃO será enviado."""
        self.pg.evaluate("""() => {
          const f = document.createElement('div');
          f.textContent = 'SIMULAÇÃO — este formulário NÃO será enviado. Confira os campos e clique em "Formulário conferido" no Lançador.';
          f.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483647;background:#b26a00;color:#fff;' +
            'font:600 15px sans-serif;padding:10px 16px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.3)';
          document.body.appendChild(f);
        }""")

    def enviar_ticket(self) -> str:
        """Clica em "Abrir ticket" e devolve o protocolo (a URL vira /atendimento/<protocolo>)."""
        self.pg.get_by_role("button", name="Abrir ticket").click()
        try:
            self.pg.wait_for_url(re.compile(r"/atendimento/\d+$"), timeout=30_000)
        except Exception:  # noqa: BLE001
            raise EnvioIncerto() from None
        return self.pg.url.rstrip("/").rsplit("/", 1)[-1]

    # ------------------------------------------------------------ encerramento
    def situacao(self, protocolo: str) -> str:
        self._ir(f"/atendimento/{protocolo}")
        self.pg.wait_for_selector("main", timeout=30_000)
        self.pg.wait_for_load_state("networkidle", timeout=30_000)
        m = re.search(r"Situação:\s*([^\n]+)", self.pg.inner_text("main"))
        return m.group(1).strip() if m else "desconhecida"

    def resolver(self, protocolo: str) -> str:
        """Resposta de encerramento SÓ por "Meus tickets" + status Resolvido. Devolve a situação lida depois."""
        self._ir(f"/atendimento/{protocolo}")
        self.pg.wait_for_selector("main [contenteditable=true]", timeout=30_000)
        self.pg.get_by_role("radio", name="Meus tickets").click()  # nunca WhatsApp/e-mail
        self.pg.locator('main select:has(option[value="manter"])').select_option(self.cfg["status_encerramento"])
        self.pg.evaluate(JS_INSERIR_HTML,
                         ["main [contenteditable=true]", f"<p>{html.escape(self.cfg['texto_encerramento'])}</p>"])
        self.pg.get_by_role("button", name="Adicionar ação").click()
        self.pg.wait_for_timeout(3000)
        return self.situacao(protocolo)
