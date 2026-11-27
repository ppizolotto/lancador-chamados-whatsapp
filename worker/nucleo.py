"""
Lógica reaproveitada de um protótipo anterior (parser, limpeza LGPD, prompt, schema)
+ chamada aos provedores de IA com repetição e troca automática.

Corrigido em relação ao protótipo:
  - Gemini: response_mime_type + response_schema (o protótipo usava "response_format", que não existe)
  - Gemini: api_version v1beta (modelos "preview" não existem na v1)
"""
from __future__ import annotations

import html
import json
import re
import time
import urllib.error
import urllib.request
from datetime import date
from pathlib import Path
from typing import Callable, List

from pydantic import BaseModel, Field, ValidationError

# ---------------------------------------------------------------- 1. leitura
# Mesmas regras de app/conversa.php. Formatos testados com exportações reais:
#   Android "24/11/2026 08:21 - Nome: msg"; iPhone "[10/21/26, 11:16:30 AM] Nome: msg" (mês/dia detectado);
#   .md com "## 21 de outubro de 2026" e "[11:26] **Nome:** msg".
_D = r"(?P<data>\d{1,2}/\d{1,2}/\d{2,4})"
_H = r"(?P<hora>\d{1,2}:\d{2})(?::\d{2})?(?:\s?(?P<ap>[AaPp])\.?\s?[Mm]\.?)?"
RE_MSG_COMPLETA = re.compile(rf"^{_D},?\s+{_H}\s+-\s+(?P<msg>.*)$")
RE_MSG_COLCHETE = re.compile(rf"^\[{_D},?\s+{_H}\]\s*(?P<msg>.*)$")
RE_DATA_SOLTA = re.compile(rf"^{_D}\s*:?$")
RE_DATA_EXTENSO = re.compile(r"^(?P<dia>\d{1,2})\s+de\s+(?P<mes>[^\W\d_]+)\s+de\s+(?P<ano>\d{4})\s*:?$", re.I)
RE_HORA_TRACO = re.compile(rf"^{_H}\s+-\s+(?P<msg>.*)$")
RE_HORA_COLCHETE = re.compile(rf"^\[{_H}\]\s*(?P<msg>.*)$")
RE_SEPARADOR = re.compile(r"^\s*(?:-{3,}|\*{3,}|_{3,})\s*$")
MESES = {"janeiro": 1, "fevereiro": 2, "março": 3, "marco": 3, "abril": 4, "maio": 5, "junho": 6,
         "julho": 7, "agosto": 8, "setembro": 9, "outubro": 10, "novembro": 11, "dezembro": 12}
_INVISIVEIS = dict.fromkeys(map(ord, "‎‏‪‬﻿"), None) | {0x202F: " ", 0xA0: " "}


def normalizar_linha(linha: str) -> str:
    if RE_SEPARADOR.match(linha):
        return ""
    linha = linha.translate(_INVISIVEIS)
    linha = re.sub(r"\\([\\`*_{}\[\]()#+\-.!>|])", r"\1", linha)  # escapes do Markdown: \- \[ \*
    linha = linha.replace("**", "").replace("__", "")
    linha = re.sub(r"^\s*(?:(?:>|#{1,6}|[-*+]|\d+\.)\s+)*", "", linha)
    return linha.rstrip()


def ordem_datas(linhas: list[str]) -> str:
    """'dm' (dia/mês) ou 'md' (mês/dia, exportação em inglês), decidido pelas datas do arquivo."""
    md = False
    for l in linhas:
        m = RE_MSG_COMPLETA.match(l) or RE_MSG_COLCHETE.match(l) or RE_DATA_SOLTA.match(l)
        if m:
            a, b, _ = (int(x) for x in m.group("data").split("/"))
            if a > 12:
                return "dm"
            if b > 12:
                md = True
    return "md" if md else "dm"


def _norm_data(d: str, ordem: str = "dm") -> str | None:
    """DD/MM/AA(AA) (ou MM/DD) -> AAAA-MM-DD; None se a data não existir."""
    x, y, ano = d.split("/")
    dia, mes = (y, x) if ordem == "md" else (x, y)
    if len(ano) == 2:
        ano = "20" + ano
    try:
        return date(int(ano), int(mes), int(dia)).isoformat()
    except ValueError:
        return None


def _data_extenso(m: re.Match) -> str | None:
    mes = MESES.get(m.group("mes").lower())
    try:
        return date(int(m.group("ano")), mes, int(m.group("dia"))).isoformat() if mes else None
    except ValueError:
        return None


def _hora24(m: re.Match) -> str:
    h, mi = m.group("hora").split(":")
    h, ap = int(h), (m.group("ap") or "").upper()
    if ap == "P" and h < 12:
        h += 12
    elif ap == "A" and h == 12:
        h = 0
    return f"{h:02d}:{mi}"


def ler_conversa(caminho: Path) -> dict[str, list[str]]:
    texto = caminho.read_text(encoding="utf-8", errors="replace").lstrip("﻿")
    linhas = [normalizar_linha(l) for l in texto.splitlines()]
    ordem = ordem_datas(linhas)
    dias: dict[str, list[str]] = {}
    atual = None
    for linha in linhas:
        if m := (RE_MSG_COMPLETA.match(linha) or RE_MSG_COLCHETE.match(linha)):
            atual = _norm_data(m.group("data"), ordem)
            if atual:
                dias.setdefault(atual, []).append(f"{_hora24(m)} - {m.group('msg')}")
        elif m := RE_DATA_SOLTA.match(linha):
            atual = _norm_data(m.group("data"), ordem)
            if atual:
                dias.setdefault(atual, [])
        elif m := RE_DATA_EXTENSO.match(linha):
            atual = _data_extenso(m)
            if atual:
                dias.setdefault(atual, [])
        elif atual and (m := (RE_HORA_TRACO.match(linha) or RE_HORA_COLCHETE.match(linha))):
            dias[atual].append(f"{_hora24(m)} - {m.group('msg')}")
        elif atual and linha.strip() and dias.get(atual):
            dias[atual][-1] += "\n" + linha  # continuação de mensagem longa ou citação
    return {d: msgs for d, msgs in dias.items() if msgs}


def data_br(iso: str) -> str:
    a, m, d = iso.split("-")
    return f"{d}/{m}/{a}"


# ------------------------------------------------------------- 2. limpeza LGPD
REGRAS_LIMPEZA = [
    (re.compile(r"\b\d{3}\.?\d{3}\.?\d{3}[-.]?\d{2}\b"), "[CPF removido]"),
    (re.compile(r"\b\d{15}\b"), "[CNS removido]"),
    (re.compile(r"\b\d{3}\s\d{4}\s\d{4}\s\d{4}\b"), "[CNS removido]"),  # CNS com espaços
    # só remove quando parece uma senha de fato (com ":" ou com número/@)
    (re.compile(r"(?i)\bsenha\s*[:=]\s*\S+|\bsenha\s+(?=\S*[\d@])\S+"), "senha [removida]"),
    (re.compile(r"(?i)\boperador\s*[:=]?\s*\d{6,}"), "operador [removido]"),
]


def limpar(texto: str) -> str:
    for regex, troca in REGRAS_LIMPEZA:
        texto = regex.sub(troca, texto)
    return texto


# ------------------------------------------------------------------ 3. IA
class Ticket(BaseModel):
    tem_solicitacao: bool = Field(
        description="false se no dia não houve nenhum pedido do solicitante "
        "(só cumprimentos, mídia, ligações, recados ou respostas automáticas)."
    )
    titulo: str = Field(description="Assunto do ticket, até 120 caracteres.")
    itens: List[str] = Field(description="Uma frase objetiva por solicitação do dia.")
    situacao: str = Field(
        description="O que a conversa mostra como concluído ou pendente. "
        "Não invente: se não houver confirmação, diga 'sem confirmação de conclusão'."
    )


PROMPT = """Você organiza solicitações de suporte para registro em helpdesk.
Abaixo estão as mensagens de WhatsApp do dia {data} entre {solicitante} (quem pede)
e o técnico do fornecedor. Extraia SOMENTE os pedidos feitos ao técnico.

Regras:
- Quem não é {solicitante} (inclusive "Você", que é quem exportou a conversa) é o técnico.
- Use apenas o que está escrito. Não invente nomes, códigos, números nem status.
- "<Mídia oculta>" significa um print que você não vê: escreva "detalhes enviados por imagem".
- Não inclua CPF, CNS, senhas nem logins.
- Ignore respostas automáticas de ausência e mensagens apagadas.
- O título começa com "Solicitações WhatsApp {data} - " seguido de um resumo curto.

Mensagens:
{mensagens}
"""

# Provedores compatíveis com OpenAI não recebem o schema pelo SDK: vai no prompt.
INSTRUCAO_JSON = """
Responda SOMENTE com um objeto JSON válido, sem texto antes ou depois, neste formato:
{"tem_solicitacao": true, "titulo": "...", "itens": ["..."], "situacao": "..."}

Significado dos campos:
- tem_solicitacao: false se no dia não houve nenhum pedido (só cumprimentos, mídia, ligações, recados ou respostas automáticas).
- titulo: assunto do ticket, até 120 caracteres.
- itens: uma frase objetiva por solicitação, reescrita em terceira pessoa, sem cumprimentos (ex.: "Recepcionista da UBS X sem acesso ao sistema").
- situacao: para cada pedido, o que a conversa mostra como concluído ou pendente; sem confirmação, escreva "sem confirmação de conclusão".
"""


class FalhaProvedor(Exception):
    """tipo: temporaria (tentar de novo / próximo) ou definitiva (pular provedor)."""

    def __init__(self, tipo: str, msg: str):
        super().__init__(msg)
        self.tipo = tipo


def _extrair_json(texto: str) -> str:
    texto = texto.strip()
    texto = re.sub(r"^```(?:json)?\s*|\s*```$", "", texto)
    ini, fim = texto.find("{"), texto.rfind("}")
    return texto[ini:fim + 1] if ini >= 0 and fim > ini else texto


def _chamar_gemini(chave: str, modelo: str, prompt: str) -> Ticket:
    from google import genai
    from google.genai import errors

    cli = genai.Client(api_key=chave, http_options={"api_version": "v1beta"})
    try:
        r = cli.models.generate_content(
            model=modelo, contents=prompt,
            config={"response_mime_type": "application/json", "response_schema": Ticket},
        )
    except errors.APIError as e:
        cod = getattr(e, "code", 0) or 0
        tipo = "temporaria" if cod in (429, 500, 502, 503, 504) and "limit: 0" not in str(e) else "definitiva"
        raise FalhaProvedor(tipo, f"HTTP {cod}") from None
    if isinstance(r.parsed, Ticket):
        return r.parsed
    try:
        return Ticket.model_validate_json(_extrair_json(r.text or ""))
    except ValidationError:
        raise FalhaProvedor("temporaria", "resposta fora do formato") from None


def _chamar_openai_compat(base: str, chave: str, modelo: str, prompt: str) -> Ticket:
    corpo = {
        "model": modelo,
        "messages": [{"role": "user", "content": prompt + INSTRUCAO_JSON}],
        "temperature": 0.1,
        "response_format": {"type": "json_object"},
    }
    for tentativa in range(2):  # 2ª tentativa sem response_format (nem todo modelo aceita)
        req = urllib.request.Request(
            base.rstrip("/") + "/chat/completions", data=json.dumps(corpo).encode(), method="POST",
            headers={"Authorization": f"Bearer {chave}", "Content-Type": "application/json",
                     "User-Agent": "LancadorChamados-worker"})
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                dados = json.load(r)
            break
        except urllib.error.HTTPError as e:
            if e.code == 400 and tentativa == 0:
                corpo.pop("response_format", None)
                continue
            tipo = "temporaria" if e.code in (429, 500, 502, 503, 504) else "definitiva"
            raise FalhaProvedor(tipo, f"HTTP {e.code}") from None
        except (urllib.error.URLError, TimeoutError, OSError):
            raise FalhaProvedor("temporaria", "sem resposta") from None
    try:
        conteudo = dados["choices"][0]["message"]["content"] or ""
        return Ticket.model_validate_json(_extrair_json(conteudo))
    except (KeyError, IndexError, ValidationError):
        raise FalhaProvedor("temporaria", "resposta fora do formato") from None


def interpretar_dia(provedores: list[dict], data_iso: str, mensagens: list[str], solicitante: str,
                    aviso: Callable[[str], None] = lambda _m: None) -> tuple[Ticket, str]:
    """Tenta cada provedor na ordem; falha temporária: repete com espera (2x) e depois passa ao próximo.
    `provedores`: [{"id", "rotulo", "tipo": gemini|openai, "chave", "modelo", "url"}]. Retorna (ticket, id)."""
    texto = limpar("\n".join(mensagens))  # LGPD: antes de qualquer envio
    data = data_br(data_iso)
    prompt = PROMPT.format(data=data, solicitante=solicitante, mensagens=texto)
    falhas = []
    for p in provedores:
        for tentativa in range(3):
            try:
                if p["tipo"] == "gemini":
                    t = _chamar_gemini(p["chave"], p["modelo"], prompt)
                else:
                    t = _chamar_openai_compat(p["url"], p["chave"], p["modelo"], prompt)
                # limpa de novo a saída: a IA não pode devolver dado pessoal
                t.titulo = limpar(t.titulo)[:120]
                if not t.titulo.startswith("Solicitações WhatsApp"):
                    t.titulo = f"Solicitações WhatsApp {data} - {t.titulo}"[:120]
                t.itens = [limpar(i) for i in t.itens if i.strip()]
                t.situacao = limpar(t.situacao)
                return t, p["id"]
            except FalhaProvedor as e:
                if e.tipo == "definitiva" or tentativa == 2:
                    falhas.append(f"{p['rotulo']}: {e}")
                    break
                espera = 10 * (tentativa + 1)
                aviso(f"{p['rotulo']} indisponível ({e}); nova tentativa em {espera} s")
                time.sleep(espera)
    raise FalhaProvedor("definitiva", "; ".join(falhas) or "nenhum provedor configurado")


def montar_html(data_iso: str, t: Ticket, solicitante: str) -> str:
    itens = "".join(f"<li>{html.escape(i)}</li>" for i in t.itens)
    return (
        f"<p>Solicitações recebidas via WhatsApp em {data_br(data_iso)} "
        f"({html.escape(solicitante)}):</p>"
        f"<ul>{itens}</ul>"
        f"<p>Situação pela conversa: {html.escape(t.situacao)}</p>"
    )
