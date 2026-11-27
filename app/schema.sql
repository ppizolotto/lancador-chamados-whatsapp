-- Datas em ISO (AAAA-MM-DD). Nenhum texto da conversa é gravado (LGPD).
CREATE TABLE IF NOT EXISTS meta (
  chave TEXT PRIMARY KEY,
  valor TEXT
);

CREATE TABLE IF NOT EXISTS jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  arquivo TEXT,               -- só o nome do arquivo (o conteúdo é apagado após a análise)
  solicitante TEXT,           -- nome no WhatsApp de quem pede (detectado na conversa)
  hash TEXT,                  -- SHA-256 do arquivo
  criado_em TEXT NOT NULL,
  primeiro_dia TEXT,
  ultimo_dia TEXT,
  -- cada etapa tem status próprio: aguardando | rodando | ok | erro
  analise_status TEXT NOT NULL DEFAULT 'aguardando',
  analise_progresso INTEGER NOT NULL DEFAULT 0,
  analise_msg TEXT,
  login_status TEXT NOT NULL DEFAULT 'aguardando',
  login_msg TEXT,
  lancar_status TEXT NOT NULL DEFAULT 'aguardando',
  lancar_progresso INTEGER NOT NULL DEFAULT 0,
  lancar_msg TEXT,
  verificar_status TEXT NOT NULL DEFAULT 'aguardando',
  verificar_msg TEXT
);

CREATE TABLE IF NOT EXISTS dias (
  job_id INTEGER NOT NULL,
  data TEXT NOT NULL,
  tem_solicitacao INTEGER,
  titulo TEXT,
  itens_json TEXT,
  situacao TEXT,
  selecionado INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'aguardando',  -- aguardando | analisando | analisado | abrindo | aberto | resolvido | erro
  provedor TEXT,              -- qual IA analisou o dia
  erro TEXT,
  PRIMARY KEY (job_id, data)
);

-- Um ticket por solicitante por dia: (data, solicitante) é único entre jobs e evita duplicidade.
CREATE TABLE IF NOT EXISTS tickets (
  data TEXT NOT NULL,
  solicitante TEXT NOT NULL DEFAULT '',   -- nome como aparece no WhatsApp
  protocolo TEXT,
  job_id INTEGER,
  aberto_em TEXT,
  situacao_final TEXT,
  corrigido INTEGER NOT NULL DEFAULT 0,
  erro TEXT,
  PRIMARY KEY (data, solicitante)
);

-- Cadastro de solicitantes: nome no WhatsApp -> como achar no Helpdesk (Administração → Solicitantes).
CREATE TABLE IF NOT EXISTS solicitantes (
  nome_whatsapp TEXT PRIMARY KEY,
  busca TEXT NOT NULL,          -- termo digitado no campo Solicitante do Helpdesk
  nome_helpdesk TEXT NOT NULL,  -- nome exato a escolher na lista
  criado_em TEXT
);

CREATE TABLE IF NOT EXISTS logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_id INTEGER,
  quando TEXT NOT NULL,
  nivel TEXT NOT NULL,
  texto TEXT NOT NULL         -- sem dados pessoais
);
