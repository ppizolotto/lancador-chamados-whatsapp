"""
Worker do Lançador de Chamados.

Contrato (saída sempre em JSON na saída padrão):
  worker.py checar
  worker.py analisar --job ID --arquivo CAMINHO      (próxima etapa)
  worker.py login --job ID                           (próxima etapa)
  worker.py lancar --job ID --de DD/MM/AAAA --ate DD/MM/AAAA   (próxima etapa)
  worker.py verificar --job ID                       (próxima etapa)

`checar` usa só a biblioteca padrão do Python, para funcionar antes de as
dependências estarem instaladas. Nunca imprime chaves nem trechos de conversa.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
DIR_DATA = RAIZ / "data"
# Navegador embutido sempre dentro da pasta do app (definido antes de qualquer import do Playwright).
os.environ.setdefault("PLAYWRIGHT_BROWSERS_PATH", str(RAIZ / "runtime" / "navegadores"))
DIR_NAVEGADORES = Path(os.environ["PLAYWRIGHT_BROWSERS_PATH"])
UA = "LancadorChamados-worker"

PROVEDORES = {
    "gemini": {"chave": "GEMINI_API_KEY", "url": "", "gratuito": False},
    "gemini_gratis": {"chave": "GEMINI_GRATIS_API_KEY", "url": "", "gratuito": True},
    "groq": {"chave": "GROQ_API_KEY", "url": "https://api.groq.com/openai/v1", "gratuito": True},
    "nvidia": {"chave": "NVIDIA_API_KEY", "url": "https://integrate.api.nvidia.com/v1", "gratuito": True},
    "outro": {"chave": "OUTRO_API_KEY", "url": "", "gratuito": True},
}

GEMINI_API_VERSAO = "v1beta"

NOMES_CURTOS = {"gemini": "Gemini pago", "gemini_gratis": "Gemini grátis", "groq": "Groq",
                "nvidia": "NVIDIA", "outro": "Outro provedor"}

BIBLIOTECAS = {  # módulo para importar -> nome do pacote
    "google.genai": "google-genai",
    "pydantic": "pydantic",
    "playwright": "playwright",
    "dotenv": "python-dotenv",
}


def ler_config() -> dict:
    try:
        return json.loads((DIR_DATA / "config.json").read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def ler_env() -> dict[str, str]:
    vals: dict[str, str] = {}
    try:
        for linha in (DIR_DATA / ".env").read_text(encoding="utf-8").splitlines():
            if "=" in linha and not linha.lstrip().startswith("#"):
                k, v = linha.split("=", 1)
                vals[k.strip()] = v.strip()
    except OSError:
        pass
    return vals


def item(id_: str, nome: str, ok: bool, detalhe: str, acao: str | None = None) -> dict:
    return {"id": id_, "nome": nome, "ok": ok, "detalhe": detalhe, "acao": None if ok else acao}


def http(url: str, *, metodo: str = "GET", headers: dict | None = None, corpo: dict | None = None,
         timeout: int = 15) -> tuple[int, str]:
    """Faz a requisição e devolve (código HTTP, corpo). O corpo serve só para classificar erros:
    nunca é impresso nem gravado (pode ecoar dados)."""
    dados = json.dumps(corpo).encode() if corpo is not None else None
    req = urllib.request.Request(url, data=dados, method=metodo, headers={"User-Agent": UA, **(headers or {})})
    if dados is not None:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, r.read(65536).decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read(65536).decode("utf-8", "replace")


# ------------------------------------------------------------------ checagens

def checar_bibliotecas() -> dict:
    faltando = [pkg for mod, pkg in BIBLIOTECAS.items() if importlib.util.find_spec(mod.split(".")[0]) is None
                or (mod == "google.genai" and _falta_submodulo(mod))]
    if faltando:
        return item("bibliotecas", "Bibliotecas do worker", False, "Faltando: " + ", ".join(faltando), "bibliotecas")
    return item("bibliotecas", "Bibliotecas do worker", True, ", ".join(BIBLIOTECAS.values()))


def _falta_submodulo(mod: str) -> bool:
    try:
        return importlib.util.find_spec(mod) is None
    except ModuleNotFoundError:
        return True


def caminho_chrome() -> Path | None:
    candidatos = [
        Path(os.environ.get("PROGRAMFILES", r"C:\Program Files")) / "Google/Chrome/Application/chrome.exe",
        Path(os.environ.get("PROGRAMFILES(X86)", r"C:\Program Files (x86)")) / "Google/Chrome/Application/chrome.exe",
        Path(os.environ.get("LOCALAPPDATA", "")) / "Google/Chrome/Application/chrome.exe",
    ]
    return next((c for c in candidatos if c.is_file()), None)


def checar_navegador(cfg: dict) -> dict:
    nome = "Navegador para o Helpdesk"
    if cfg.get("usar_chrome_instalado", True) and caminho_chrome():
        return item("navegador", nome, True, "Google Chrome instalado no computador")
    if DIR_NAVEGADORES.is_dir() and any(DIR_NAVEGADORES.glob("chromium-*")):
        return item("navegador", nome, True, "Chromium embutido na pasta do app")
    return item("navegador", nome, False, "Chrome não encontrado e Chromium embutido não instalado", "navegador")


def ordem_provedores(cfg: dict, env: dict[str, str]) -> list[str]:
    """Provedor escolhido primeiro; se a troca automática estiver ligada, depois os outros
    que tenham chave E modelo salvos (pagos antes dos gratuitos). O worker da análise usa a mesma ordem."""
    ia = cfg.get("ia", {})
    preferido = ia.get("provedor", "gemini")
    ordem = [preferido] if preferido in PROVEDORES else []
    if ia.get("troca_automatica", True):
        modelos = ia.get("modelos") or {}
        extras = [p for p, info in PROVEDORES.items()
                  if p != preferido and env.get(info["chave"]) and modelos.get(p)]
        ordem += sorted(extras, key=lambda p: PROVEDORES[p]["gratuito"])
    return ordem


def testar_provedor(prov: str, cfg: dict, env: dict[str, str]) -> tuple[str, str]:
    """Teste mínimo (texto neutro, sem dados da conversa). Devolve (estado, detalhe).
    estado: ok | limite | sobrecarga | sem_cota | chave | modelo | config | rede | outro"""
    ia = cfg.get("ia", {})
    info = PROVEDORES[prov]
    chave = env.get(info["chave"], "")
    modelo = (ia.get("modelos") or {}).get(prov, "")
    if not chave:
        return "config", "sem chave cadastrada"
    if not modelo:
        return "config", "modelo não informado"
    try:
        if prov.startswith("gemini"):
            # v1beta: modelos "preview" não existem na v1 (o worker usa a mesma versão).
            cod, corpo = http(
                f"https://generativelanguage.googleapis.com/{GEMINI_API_VERSAO}/models/"
                f"{urllib.request.quote(modelo)}:generateContent",
                metodo="POST", headers={"x-goog-api-key": chave}, timeout=25,
                corpo={"contents": [{"parts": [{"text": "Responda apenas: ok"}]}]})
        else:
            base = (ia.get("url_outro") if prov == "outro" else info["url"]) or ""
            if not base.startswith("https://"):
                return "config", "endereço da API não informado"
            cod, corpo = http(base.rstrip("/") + "/chat/completions", metodo="POST", timeout=25,
                              headers={"Authorization": f"Bearer {chave}"},
                              corpo={"model": modelo, "messages": [{"role": "user", "content": "ok"}], "max_tokens": 1})
    except (urllib.error.URLError, TimeoutError, OSError):
        return "rede", "sem resposta (internet ou tempo esgotado)"

    if cod == 200:
        return "ok", modelo
    if cod == 429:
        if "limit: 0" in corpo and "free_tier" in corpo:
            return "sem_cota", f"plano gratuito não inclui '{modelo}'"
        return "limite", "limite de uso atingido agora"
    if cod in (401, 403) or (prov.startswith("gemini") and cod == 400):
        return "chave", f"chave recusada (HTTP {cod})"
    if cod == 404:
        return "modelo", f"modelo '{modelo}' não encontrado ou não liberado (HTTP 404)"
    if cod in (500, 502, 503, 504):
        return "sobrecarga", f"sobrecarregado agora (HTTP {cod})"
    return "outro", f"resposta inesperada (HTTP {cod})"


def checar_chave_ia(cfg: dict) -> dict:
    nome = "Chave de IA configurada e válida"
    env = ler_env()
    ordem = ordem_provedores(cfg, env)
    if not ordem:
        return item("chave_ia", nome, False, "Provedor de IA desconhecido", "config")

    import time
    limite_tempo = time.monotonic() + 70  # a tela espera no máximo ~85 s
    falhas: list[str] = []
    temporario: str | None = None
    for prov in ordem:
        if time.monotonic() > limite_tempo:
            break
        estado, detalhe = testar_provedor(prov, cfg, env)
        rotulo = NOMES_CURTOS.get(prov, prov)
        if estado == "ok":
            aviso = " — plano gratuito: textos podem ser usados para treino" if PROVEDORES[prov]["gratuito"] else ""
            troca = f" (troca automática; {'; '.join(falhas)})" if falhas else ""
            return item("chave_ia", nome, True, f"{rotulo} / {detalhe}{aviso}{troca}")
        if estado == "limite" and temporario is None:
            temporario = rotulo
        falhas.append(f"{rotulo}: {detalhe}")

    if temporario:  # chave válida, só no limite momentâneo: não bloqueia
        return item("chave_ia", nome, True, f"{temporario} — chave aceita, mas no limite de uso agora ({'; '.join(falhas)})")
    modelos = cfg.get("ia", {}).get("modelos") or {}
    sem_modelo = [NOMES_CURTOS.get(p, p) for p, info in PROVEDORES.items()
                  if p not in ordem and env.get(info["chave"]) and not modelos.get(p)]
    if sem_modelo:
        dica = f" Para a troca automática funcionar, informe o modelo de: {', '.join(sem_modelo)} (Administração)."
    elif len(ordem) == 1:
        dica = " Cadastre outra chave em Administração para troca automática."
    else:
        dica = ""
    return item("chave_ia", nome, False, "; ".join(falhas) + "." + dica, "config")


def checar_internet(cfg: dict) -> dict:
    base = (cfg.get("url_base") or "https://helpdesk.exemplo.com.br").rstrip("/")
    nome = "Acesso ao Helpdesk"
    try:
        cod, _ = http(base + "/entrar", timeout=10)
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        return item("internet", nome, False, f"Sem acesso a {base} ({type(e).__name__})")
    if cod < 500:
        return item("internet", nome, True, f"{base} respondeu (HTTP {cod})")
    return item("internet", nome, False, f"{base} com erro (HTTP {cod})")


def cmd_checar(_args) -> dict:
    cfg = ler_config()
    return {
        "python": sys.version.split()[0],
        "itens": [checar_bibliotecas(), checar_navegador(cfg), checar_chave_ia(cfg), checar_internet(cfg)],
    }


def cmd_testar(args) -> dict:
    """Testa UM provedor (Administração). Texto neutro, sem dados da conversa."""
    if args.provedor not in PROVEDORES:
        return {"erro": "provedor desconhecido"}
    estado, detalhe = testar_provedor(args.provedor, ler_config(), ler_env())
    return {"estado": estado, "detalhe": detalhe}


def nao_implementado(_args) -> dict:
    return {"erro": "Etapa ainda não implementada."}


# ------------------------------------------------------------------ banco

def conectar():
    import sqlite3
    con = sqlite3.connect(DIR_DATA / "app.db", timeout=15, isolation_level=None)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA busy_timeout=15000")
    return con


def atualizar_job(con, job: int, **campos) -> None:
    sets = ", ".join(f"{k} = ?" for k in campos)
    con.execute(f"UPDATE jobs SET {sets} WHERE id = ?", (*campos.values(), job))


def log(con, job: int | None, nivel: str, texto: str) -> None:
    """Só mensagens operacionais: nunca texto da conversa, chave ou dado pessoal."""
    from datetime import datetime
    con.execute("INSERT INTO logs(job_id, quando, nivel, texto) VALUES(?, ?, ?, ?)",
                (job, datetime.now().isoformat(timespec="seconds"), nivel, texto[:500]))


def provedores_configurados(cfg: dict) -> list[dict]:
    env = ler_env()
    ia = cfg.get("ia", {})
    lista = []
    for p in ordem_provedores(cfg, env):
        info = PROVEDORES[p]
        chave, modelo = env.get(info["chave"], ""), (ia.get("modelos") or {}).get(p, "")
        url = ia.get("url_outro", "") if p == "outro" else info["url"]
        if chave and modelo and (p.startswith("gemini") or url.startswith("https://")):
            lista.append({"id": p, "rotulo": NOMES_CURTOS.get(p, p), "chave": chave, "modelo": modelo, "url": url,
                          "tipo": "gemini" if p.startswith("gemini") else "openai"})
    return lista


# ------------------------------------------------------------------ analisar

def cmd_analisar(args) -> dict:
    import nucleo

    con = conectar()
    job, arquivo = args.job, Path(args.arquivo or "")
    cfg = ler_config()
    r = con.execute("SELECT solicitante FROM jobs WHERE id=?", (job,)).fetchone()
    solicitante = (r and r[0]) or cfg.get("nome_solicitante_conversa", "")
    try:
        provedores = provedores_configurados(cfg)
        if not provedores:
            raise RuntimeError("Nenhuma chave de IA com modelo configurado.")
        if not arquivo.is_file():
            raise RuntimeError("Arquivo da conversa não encontrado. Carregue de novo.")

        conversa = nucleo.ler_conversa(arquivo)
        dias = [r["data"] for r in con.execute(
            "SELECT data FROM dias WHERE job_id = ? AND status IN ('aguardando','erro') ORDER BY data", (job,))]
        total = len(dias)
        log(con, job, "info", f"Análise iniciada: {total} dias; provedores: {', '.join(p['rotulo'] for p in provedores)}")
        erros = 0
        for n, data in enumerate(dias, 1):
            def aviso(msg: str, n=n, data=data):
                atualizar_job(con, job, analise_msg=f"Analisando {nucleo.data_br(data)}… {n} de {total} — {msg}")

            atualizar_job(con, job, analise_msg=f"Analisando {nucleo.data_br(data)}… {n} de {total}",
                          analise_progresso=int((n - 1) * 100 / max(total, 1)))
            con.execute("UPDATE dias SET status='analisando', erro=NULL WHERE job_id=? AND data=?", (job, data))
            msgs = conversa.get(data, [])
            if not msgs:
                con.execute("UPDATE dias SET status='analisado', tem_solicitacao=0, selecionado=0, titulo=NULL "
                            "WHERE job_id=? AND data=?", (job, data))
                continue
            try:
                t, prov = nucleo.interpretar_dia(provedores, data, msgs, solicitante, aviso)
            except nucleo.FalhaProvedor as e:
                erros += 1
                con.execute("UPDATE dias SET status='erro', erro=?, selecionado=0 WHERE job_id=? AND data=?",
                            (f"IA indisponível: {e}", job, data))
                log(con, job, "erro", f"Dia {data}: IA indisponível ({e})")
                continue
            # Mantém o provedor que funcionou na frente para os próximos dias (evita repetir esperas).
            provedores.sort(key=lambda p: p["id"] != prov)
            tem = bool(t.tem_solicitacao and t.itens)
            con.execute(
                "UPDATE dias SET status='analisado', tem_solicitacao=?, titulo=?, itens_json=?, situacao=?, "
                "provedor=?, selecionado=CASE WHEN ? THEN selecionado ELSE 0 END WHERE job_id=? AND data=?",
                (int(tem), t.titulo, json.dumps(t.itens, ensure_ascii=False), t.situacao, prov, int(tem), job, data))

        if total and erros == total:
            atualizar_job(con, job, analise_status="erro", analise_progresso=100,
                          analise_msg="A IA não respondeu para nenhum dia. Tente de novo mais tarde.")
        else:
            msg = f"{total - erros} de {total} dias analisados" + (f" ({erros} com erro)" if erros else "")
            atualizar_job(con, job, analise_status="ok", analise_progresso=100, analise_msg=msg)
        log(con, job, "info", f"Análise concluída: {total - erros}/{total}")
        return {"ok": True}
    except Exception as e:  # noqa: BLE001
        atualizar_job(con, job, analise_status="erro", analise_msg=str(e)[:300])
        log(con, job, "erro", f"Análise falhou: {type(e).__name__}")
        raise
    finally:
        # LGPD: o texto da conversa não fica guardado depois da análise.
        try:
            if arquivo.is_file() and arquivo.parent == DIR_DATA / "conversas":
                arquivo.unlink()
        except OSError:
            pass


# ------------------------------------------------------------------ login

PERFIL_NAVEGADOR = DIR_DATA / "perfil_navegador"
LOGIN_LIMITE_S = 15 * 60


def abrir_contexto(p, cfg: dict, headless: bool):
    canal = "chrome" if cfg.get("usar_chrome_instalado", True) and caminho_chrome() else None
    return p.chromium.launch_persistent_context(str(PERFIL_NAVEGADOR), headless=headless, channel=canal,
                                                viewport=None if not headless else {"width": 1280, "height": 900})


def cmd_login(args) -> dict:
    """Abre o Helpdesk numa janela VISÍVEL; o usuário faz o login (o app nunca digita senha).
    Detecta o fim do login quando a URL deixa de ser /entrar. A sessão fica no perfil do app."""
    import time
    from playwright.sync_api import Error as PwError, sync_playwright

    con = conectar()
    job = args.job
    cfg = ler_config()
    base = (cfg.get("url_base") or "https://helpdesk.exemplo.com.br").rstrip("/")
    PERFIL_NAVEGADOR.mkdir(parents=True, exist_ok=True)
    try:
        with sync_playwright() as p:
            # 1) Sessão salva ainda vale? (sem abrir janela)
            atualizar_job(con, job, login_msg="Conferindo se a sessão salva ainda vale…")
            ctx = abrir_contexto(p, cfg, headless=True)
            pg = ctx.pages[0] if ctx.pages else ctx.new_page()
            pg.goto(base + "/atendimento/novo", wait_until="networkidle", timeout=45_000)
            logado = "/entrar" not in pg.url
            ctx.close()
            if logado:
                atualizar_job(con, job, login_status="ok", login_msg="Sessão salva válida — já conectado ao Helpdesk.")
                return {"ok": True}

            # 2) Janela visível para o usuário entrar
            atualizar_job(con, job, login_msg="Faça o login na janela do navegador que abriu.")
            ctx = abrir_contexto(p, cfg, headless=False)
            pg = ctx.pages[0] if ctx.pages else ctx.new_page()
            pg.goto(base + "/entrar", timeout=45_000)
            fim = time.monotonic() + LOGIN_LIMITE_S
            while time.monotonic() < fim:
                try:
                    url = pg.url
                    pg.wait_for_timeout(1000)
                except PwError:
                    atualizar_job(con, job, login_status="erro", login_msg="A janela de login foi fechada.")
                    return {"ok": False}
                if url.startswith(base) and "/entrar" not in url:
                    pg.wait_for_load_state("networkidle", timeout=30_000)
                    ctx.close()  # grava a sessão no perfil
                    atualizar_job(con, job, login_status="ok", login_msg="Login concluído.")
                    log(con, job, "info", "Login no Helpdesk concluído")
                    return {"ok": True}
            ctx.close()
            atualizar_job(con, job, login_status="erro", login_msg="Tempo esgotado (15 min) esperando o login.")
            return {"ok": False}
    except Exception as e:  # noqa: BLE001
        atualizar_job(con, job, login_status="erro", login_msg=f"Erro ao abrir o navegador: {type(e).__name__}")
        log(con, job, "erro", f"Login falhou: {type(e).__name__}: {str(e)[:200]}")
        raise


# ------------------------------------------------------------------ lançar

CONFERENCIA_LIMITE_S = 15 * 60  # simulação: quanto tempo o formulário fica aberto esperando o "conferido"


def esperar_conferencia(con, pg, job: int, data: str) -> str:
    """Simulação: deixa o formulário aberto até o usuário clicar em "Formulário conferido" na Tela 5.
    Devolve 'ok', 'fechada' (janela fechada) ou 'tempo'."""
    import time
    from playwright.sync_api import Error as PwError
    chave = f"conferido_job_{job}"
    fim = time.monotonic() + CONFERENCIA_LIMITE_S
    while time.monotonic() < fim:
        r = con.execute("SELECT valor FROM meta WHERE chave=?", (chave,)).fetchone()
        if r and r[0] == data:
            return "ok"
        try:
            pg.wait_for_timeout(1000)
        except PwError:
            return "fechada"
    return "tempo"


def gravar_marcador(con, job: int, dia: str) -> None:
    """data/marcador.json, um registro por solicitante: só metadados (nunca o texto da conversa)."""
    from datetime import datetime
    j = con.execute("SELECT arquivo, solicitante, hash, primeiro_dia, ultimo_dia FROM jobs WHERE id=?", (job,)).fetchone()
    arq = DIR_DATA / "marcador.json"
    todos = {}
    if arq.is_file():
        try:
            todos = json.loads(arq.read_text(encoding="utf-8"))
        except ValueError:
            todos = {}
    todos.setdefault("solicitantes", {})
    anterior = todos["solicitantes"].get(j["solicitante"], {})
    ultimo = max(filter(None, [anterior.get("ultimo_dia_lancado"), dia]))
    todos["solicitantes"][j["solicitante"]] = {
        "arquivo": j["arquivo"], "sha256": j["hash"],
        "primeiro_dia_conversa": j["primeiro_dia"], "ultimo_dia_conversa": j["ultimo_dia"],
        "ultimo_dia_lancado": ultimo, "gravado_em": datetime.now().isoformat(timespec="seconds"), "job": job,
    }
    tmp = arq.with_suffix(".tmp")
    tmp.write_text(json.dumps(todos, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(arq)


def cmd_lancar(args) -> dict:
    """Abre (ou, em simulação, só preenche) um ticket por dia confirmado na Tela 4.
    Só roda depois do clique em "Lançar/Simular N tickets" (api/lancar_confirmar.php)."""
    from datetime import datetime
    from playwright.sync_api import sync_playwright

    import nucleo
    from helpdesk import EnvioIncerto, HelpdeskUI, SessaoExpirada

    con = conectar()
    job = args.job
    cfg = ler_config()
    simulacao = bool(cfg.get("simulacao", True))
    r = con.execute("SELECT solicitante FROM jobs WHERE id=?", (job,)).fetchone()
    solicitante = (r and r[0]) or ""
    cad = con.execute("SELECT busca, nome_helpdesk FROM solicitantes WHERE nome_whatsapp=?", (solicitante,)).fetchone()
    if not cad:
        atualizar_job(con, job, lancar_status="erro", lancar_msg=f"Solicitante '{solicitante}' sem cadastro (Administração).")
        return {"ok": False}
    dias = [dict(r) for r in con.execute(
        "SELECT data, titulo, itens_json, situacao FROM dias WHERE job_id=? AND selecionado=1 "
        "AND tem_solicitacao=1 ORDER BY data", (job,))]
    total = len(dias)
    modo = "simulação" if simulacao else "real"
    log(con, job, "info", f"Lançamento iniciado ({modo}): {total} dias")
    erros = feitos = 0
    con.execute("DELETE FROM meta WHERE chave=?", (f"conferido_job_{job}",))
    try:
        with sync_playwright() as p:
            ctx = abrir_contexto(p, cfg, headless=False)  # visível: o usuário acompanha
            pg = ctx.pages[0] if ctx.pages else ctx.new_page()
            hd = HelpdeskUI(pg, cfg)
            for n, d in enumerate(dias, 1):
                data = d["data"]
                rot = f"{nucleo.data_br(data)} ({n} de {total})"
                atualizar_job(con, job, lancar_progresso=int((n - 1) * 100 / max(total, 1)),
                              lancar_msg=f"{'Simulando' if simulacao else 'Abrindo'} {rot}…")
                if not simulacao and con.execute("SELECT 1 FROM tickets WHERE data=? AND solicitante=?",
                                                 (data, solicitante)).fetchone():
                    con.execute("UPDATE dias SET status='erro', erro=? WHERE job_id=? AND data=?",
                                ("já lançado antes — ignorado", job, data))
                    continue
                con.execute("UPDATE dias SET status='abrindo', erro=NULL WHERE job_id=? AND data=?", (job, data))
                t = nucleo.Ticket(tem_solicitacao=True, titulo=d["titulo"] or "",
                                 itens=json.loads(d["itens_json"] or "[]"), situacao=d["situacao"] or "")
                corpo = nucleo.montar_html(data, t, solicitante)

                preenchido = False
                for tentativa_login in range(2):  # sessão expirada: pausa, pede login, tenta o dia de novo
                    try:
                        hd.preencher_ticket(t.titulo, corpo, cad["busca"], cad["nome_helpdesk"])
                        preenchido = True
                        break
                    except SessaoExpirada:
                        if tentativa_login:
                            raise
                        atualizar_job(con, job, lancar_msg="Sessão do Helpdesk expirou: faça o login na janela do navegador.")
                        log(con, job, "aviso", "Sessão expirada durante o lançamento; aguardando login")
                        if not hd.esperar_login(15 * 60 * 1000):
                            raise RuntimeError("Tempo esgotado esperando o login (15 min).")
                        atualizar_job(con, job, lancar_msg=f"Login refeito. Continuando {rot}…")
                    except Exception as e:  # noqa: BLE001 — erro num dia não para os outros
                        erros += 1
                        con.execute("UPDATE dias SET status='erro', erro=? WHERE job_id=? AND data=?",
                                    (f"Formulário: {str(e)[:200]}", job, data))
                        log(con, job, "erro", f"Dia {data}: falha ao preencher ({type(e).__name__})")
                        break
                if not preenchido:
                    continue

                if simulacao:
                    hd.faixa_simulacao()
                    con.execute("UPDATE dias SET status='conferindo' WHERE job_id=? AND data=?", (job, data))
                    atualizar_job(con, job, lancar_msg=f"Confira o formulário de {rot} na janela do navegador e "
                                                       "clique em \"Formulário conferido\" aqui.")
                    res = esperar_conferencia(con, pg, job, data)
                    con.execute("UPDATE dias SET status='simulado' WHERE job_id=? AND data=?", (job, data))
                    feitos += 1
                    if res == "fechada":
                        log(con, job, "info", "Janela fechada pelo usuário durante a simulação")
                        break
                    continue

                # ----- envio real
                try:
                    protocolo = hd.enviar_ticket()
                except EnvioIncerto:
                    # Pode ter sido criado: registra o dia para NUNCA duplicar e pede conferência manual.
                    erros += 1
                    con.execute("INSERT OR IGNORE INTO tickets(data, solicitante, protocolo, job_id, aberto_em, situacao_final, erro) "
                                "VALUES(?, ?, NULL, ?, ?, 'incerto', ?)",
                                (data, solicitante, job, datetime.now().isoformat(timespec="seconds"),
                                 "Sem confirmação do protocolo: confira no Helpdesk"))
                    con.execute("UPDATE dias SET status='erro', erro=? WHERE job_id=? AND data=?",
                                ("Sem confirmação do protocolo: o ticket pode ter sido aberto. Confira no Helpdesk.", job, data))
                    log(con, job, "erro", f"Dia {data}: envio sem confirmação de protocolo")
                    continue
                con.execute("INSERT INTO tickets(data, solicitante, protocolo, job_id, aberto_em, situacao_final) "
                            "VALUES(?, ?, ?, ?, ?, 'aberto')",
                            (data, solicitante, protocolo, job, datetime.now().isoformat(timespec="seconds")))
                con.execute("UPDATE dias SET status='aberto', erro=NULL WHERE job_id=? AND data=?", (job, data))
                gravar_marcador(con, job, data)
                log(con, job, "info", f"Dia {data}: ticket {protocolo} aberto")
                try:
                    sit = hd.resolver(protocolo)
                except Exception as e:  # noqa: BLE001 — a verificação final tenta de novo
                    sit = f"erro ao resolver: {type(e).__name__}"
                con.execute("UPDATE tickets SET situacao_final=? WHERE data=? AND solicitante=?", (sit, data, solicitante))
                ok = "resolvid" in sit.lower()
                con.execute("UPDATE dias SET status=?, erro=? WHERE job_id=? AND data=?",
                            ("resolvido" if ok else "aberto", None if ok else f"Situação: {sit}", job, data))
                feitos += 1
            try:
                ctx.close()
            except Exception:  # noqa: BLE001 — janela já fechada pelo usuário
                pass

        if simulacao:
            msg = f"Simulação concluída: {feitos} de {total} formulários preenchidos" + (f", {erros} com erro" if erros else "") \
                  + ". Nada foi enviado ao Helpdesk."
        else:
            msg = f"{feitos} de {total} tickets abertos" + (f", {erros} com erro" if erros else "")
        atualizar_job(con, job, lancar_status="ok" if feitos else "erro", lancar_progresso=100, lancar_msg=msg)
        log(con, job, "info", msg)
        return {"ok": True}
    except Exception as e:  # noqa: BLE001
        atualizar_job(con, job, lancar_status="erro", lancar_msg=f"Lançamento interrompido: {type(e).__name__}: {str(e)[:200]}")
        log(con, job, "erro", f"Lançamento falhou: {type(e).__name__}")
        raise


def encerrado(situacao: str) -> bool:
    """Resolvido (ou já Fechado, que vem depois): não precisa de encerramento de novo."""
    s = situacao.lower()
    return "resolvid" in s or "fechad" in s


def cmd_verificar(args) -> dict:
    """Tela 6: reabre cada ticket do job e lê a situação. Se não estiver Resolvido, aplica o encerramento de novo
    (só por "Meus tickets", até 2 tentativas) e marca como corrigido. Nada novo é aberto aqui."""
    from playwright.sync_api import sync_playwright

    from helpdesk import HelpdeskUI, SessaoExpirada

    con = conectar()
    job = args.job
    cfg = ler_config()
    tks = [dict(r) for r in con.execute(
        "SELECT data, solicitante, protocolo FROM tickets WHERE job_id=? AND protocolo IS NOT NULL AND protocolo != '' "
        "ORDER BY data", (job,))]
    total = len(tks)
    if not total:
        atualizar_job(con, job, verificar_status="ok", verificar_msg="Nenhum ticket deste lançamento para conferir.")
        return {"ok": True}
    log(con, job, "info", f"Verificação iniciada: {total} tickets")
    corrigidos = problemas = 0
    try:
        with sync_playwright() as p:
            ctx = abrir_contexto(p, cfg, headless=False)  # visível: se a sessão expirou, o usuário entra de novo
            pg = ctx.pages[0] if ctx.pages else ctx.new_page()
            hd = HelpdeskUI(pg, cfg)

            def com_login(fn, *a):
                for tentativa in range(2):
                    try:
                        return fn(*a)
                    except SessaoExpirada:
                        if tentativa:
                            raise
                        atualizar_job(con, job, verificar_msg="Sessão do Helpdesk expirou: faça o login na janela do navegador.")
                        if not hd.esperar_login(15 * 60 * 1000):
                            raise RuntimeError("Tempo esgotado esperando o login (15 min).") from None

            for n, t in enumerate(tks, 1):
                prot = t["protocolo"]
                atualizar_job(con, job, verificar_msg=f"Conferindo ticket {prot} ({n} de {total})…")
                corrigido = 0
                erro = None
                try:
                    sit = com_login(hd.situacao, prot)
                    tentativas = 0
                    if sit == "desconhecida":  # não leu: não mexe no ticket (não manda resposta às cegas)
                        raise RuntimeError("situação não encontrada na página")
                    while not encerrado(sit) and tentativas < 2:
                        tentativas += 1
                        atualizar_job(con, job, verificar_msg=f"Ticket {prot} estava \"{sit}\": resolvendo ({tentativas}ª tentativa)…")
                        log(con, job, "aviso", f"Ticket {prot}: situação '{sit[:40]}', aplicando encerramento")
                        try:
                            sit = com_login(hd.resolver, prot)
                        except SessaoExpirada:
                            raise
                        except Exception as e:  # noqa: BLE001
                            sit = f"erro ao resolver: {type(e).__name__}"
                    if encerrado(sit):
                        corrigido = 1 if tentativas else 0
                    else:
                        erro = f"Continua \"{sit}\" após {tentativas} tentativas: confira no Helpdesk."
                except SessaoExpirada:
                    raise
                except Exception as e:  # noqa: BLE001 — um ticket com problema não para os outros
                    sit = "não conferido"
                    erro = f"Não consegui conferir o ticket ({str(e)[:80] or type(e).__name__}): confira no Helpdesk."
                corrigidos += corrigido
                problemas += 1 if erro else 0
                con.execute("UPDATE tickets SET situacao_final=?, corrigido=MAX(corrigido, ?), erro=? "
                            "WHERE data=? AND solicitante=?", (sit[:80], corrigido, erro, t["data"], t["solicitante"]))
                if not erro:
                    con.execute("UPDATE dias SET status='resolvido', erro=NULL WHERE job_id=? AND data=?", (job, t["data"]))
                log(con, job, "erro" if erro else "info",
                    f"Ticket {prot}: {sit[:40]}" + (" (corrigido)" if corrigido else ""))
            try:
                ctx.close()
            except Exception:  # noqa: BLE001
                pass
        msg = f"{total} tickets conferidos: {total - problemas} resolvidos" +               (f" ({corrigidos} corrigidos agora)" if corrigidos else "") +               (f", {problemas} com problema" if problemas else "") + "."
        atualizar_job(con, job, verificar_status="erro" if problemas else "ok", verificar_msg=msg)
        log(con, job, "info", msg)
        return {"ok": True}
    except Exception as e:  # noqa: BLE001
        atualizar_job(con, job, verificar_status="erro",
                      verificar_msg=f"Verificação interrompida: {type(e).__name__}: {str(e)[:200]}")
        log(con, job, "erro", f"Verificação falhou: {type(e).__name__}")
        raise


def main() -> None:
    ap = argparse.ArgumentParser(description="Worker do Lançador de Chamados")
    sub = ap.add_subparsers(dest="comando", required=True)
    sub.add_parser("checar").set_defaults(fn=cmd_checar)
    t = sub.add_parser("testar")
    t.add_argument("--provedor", required=True)
    t.set_defaults(fn=cmd_testar)
    acoes = {"analisar": cmd_analisar, "login": cmd_login, "lancar": cmd_lancar, "verificar": cmd_verificar}
    for nome in ("analisar", "login", "lancar", "verificar"):
        p = sub.add_parser(nome)
        p.add_argument("--job", type=int)
        p.add_argument("--arquivo")
        p.add_argument("--de")
        p.add_argument("--ate")
        p.set_defaults(fn=acoes.get(nome, nao_implementado))
    args = ap.parse_args()
    if args.comando not in ("checar", "testar"):
        # Em segundo plano não há terminal: erros vão para data/worker-erros.log (sem dados pessoais).
        sys.stderr = open(DIR_DATA / "worker-erros.log", "a", encoding="utf-8")
    try:
        saida = args.fn(args)
    except Exception as e:  # noqa: BLE001 — devolver erro legível em JSON
        saida = {"erro": f"{type(e).__name__}: {e}"}
        if args.comando not in ("checar", "testar"):
            import traceback
            from datetime import datetime
            sys.stderr.write(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] {args.comando}: {traceback.format_exc()}\n")
    sys.stdout.write(json.dumps(saida, ensure_ascii=True))


if __name__ == "__main__":
    main()
