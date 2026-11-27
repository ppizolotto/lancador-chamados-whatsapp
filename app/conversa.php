<?php
/* Leitura da conversa exportada do WhatsApp — mesmas regras de ler_conversa() em worker/nucleo.py.
   Aqui só contamos mensagens por dia: o texto não é guardado nem exibido. */
declare(strict_types=1);

// Formatos aceitos (testados com exportações reais):
//   24/11/2026 08:21 - Nome: msg          24/11/26, 08:21 - Nome: msg        (Android, dia/mês)
//   [24/11/2026, 08:21:33] Nome: msg      [10/21/26, 11:16:30 AM] Nome: msg  (iPhone; mês/dia detectado sozinho)
//   linha só com a data: "24/11/2026", "## 24/11/2026" ou "## 21 de outubro de 2026" (.md)
//   e depois "08:21 - Nome: msg" ou "[11:26] **Nome:** msg"
const WA_D = '(\d{1,2}/\d{1,2}/\d{2,4})';
const WA_H = '\d{1,2}:\d{2}(?::\d{2})?(?:\s?[AaPp]\.?\s?[Mm]\.?)?';
const RE_MSG_COMPLETA = '#^' . WA_D . ',?\s+' . WA_H . '\s+-\s+(.*)$#u';
const RE_MSG_COLCHETE = '#^\[' . WA_D . ',?\s+' . WA_H . '\]\s*(.*)$#u';
const RE_DATA_SOLTA = '#^' . WA_D . '\s*:?$#u';
const RE_DATA_EXTENSO = '#^(\d{1,2})\s+de\s+(\p{L}+)\s+de\s+(\d{4})\s*:?$#iu';
const RE_MSG_HORA = '#^(?:' . WA_H . '\s+-\s+|\[' . WA_H . '\]\s*)(.*)$#u';
const RE_SEPARADOR = '#^\s*(?:-{3,}|\*{3,}|_{3,})\s*$#';
const MESES = ['janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6,
               'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12];

/** Tira marcas invisíveis e a formatação Markdown (títulos, listas, citação, negrito, itálico duplo, escapes). */
function normalizar_linha(string $l): string {
    if (preg_match(RE_SEPARADOR, $l)) {
        return '';
    }
    $l = str_replace(["\u{200E}", "\u{200F}", "\u{202A}", "\u{202C}", "\u{FEFF}"], '', $l);
    $l = str_replace(["\u{202F}", "\u{00A0}"], ' ', $l);
    $l = preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!>|])/u', '$1', $l);   // escapes do Markdown: \- \[ \*
    $l = str_replace(['**', '__'], '', $l);
    $l = preg_replace('/^\s*(?:(?:>|#{1,6}|[-*+]|\d+\.)\s+)*/u', '', $l);
    return rtrim($l);
}

/** "dm" (dia/mês, Brasil) ou "md" (mês/dia, exportação em inglês). Decide pelas datas do próprio arquivo. */
function ordem_datas(array $linhas): string {
    $md = false;
    foreach ($linhas as $l) {
        if (preg_match(RE_MSG_COMPLETA, $l, $m) || preg_match(RE_MSG_COLCHETE, $l, $m) || preg_match(RE_DATA_SOLTA, $l, $m)) {
            [$a, $b] = array_map('intval', explode('/', $m[1]));
            if ($a > 12) {
                return 'dm';
            }
            if ($b > 12) {
                $md = true;
            }
        }
    }
    return $md ? 'md' : 'dm';
}

/** "24/11/2026" (ou mês/dia) -> AAAA-MM-DD; null se a data não existir. */
function data_iso(string $d, string $ordem = 'dm'): ?string {
    [$x, $y, $ano] = explode('/', $d);
    [$dia, $mes] = $ordem === 'md' ? [$y, $x] : [$x, $y];
    if (strlen($ano) === 2) {
        $ano = '20' . $ano;
    }
    return checkdate((int) $mes, (int) $dia, (int) $ano) ? sprintf('%04d-%02d-%02d', $ano, $mes, $dia) : null;
}

function data_extenso_iso(array $m): ?string {
    $mes = MESES[mb_strtolower($m[2])] ?? null;
    return $mes && checkdate($mes, (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $mes, $m[1]) : null;
}

/** Quem exportou a conversa aparece com estes nomes (é o técnico). */
const NOMES_PROPRIOS = ['você', 'voce', 'you'];

/** Remetente de uma mensagem ("Nome: texto"), ou null para mensagens do sistema. */
function remetente(string $resto): ?string {
    if (preg_match('/^([^:\n]{1,60}?):(?:\s|$)/u', $resto, $m)) {
        $n = trim($m[1]);
        if ($n !== '' && !str_starts_with($n, '-')) {
            return $n;
        }
    }
    return null;
}

/** Lê a conversa: ['dias' => [AAAA-MM-DD => qtd de mensagens], 'remetentes' => [nome => qtd]] (sem "Você"). */
function analisar_conversa(string $texto): array {
    $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);          // BOM
    $linhas = array_map('normalizar_linha', preg_split('/\r\n|\r|\n/', $texto));
    $ordem = ordem_datas($linhas);
    $dias = [];
    $rem = [];
    $atual = null;
    foreach ($linhas as $linha) {
        $resto = null;
        if (preg_match(RE_MSG_COMPLETA, $linha, $m) || preg_match(RE_MSG_COLCHETE, $linha, $m)) {
            $atual = data_iso($m[1], $ordem);
            if ($atual) {
                $dias[$atual] = ($dias[$atual] ?? 0) + 1;
                $resto = $m[2];
            }
        } elseif (preg_match(RE_DATA_SOLTA, $linha, $m) || preg_match(RE_DATA_EXTENSO, $linha, $m)) {
            $atual = count($m) === 2 ? data_iso($m[1], $ordem) : data_extenso_iso($m);
            if ($atual) {
                $dias[$atual] ??= 0;
            }
        } elseif ($atual && preg_match(RE_MSG_HORA, $linha, $m)) {
            $dias[$atual]++;
            $resto = $m[1];
        }
        if ($resto !== null && ($n = remetente($resto)) !== null && !in_array(mb_strtolower($n), NOMES_PROPRIOS, true)) {
            $rem[$n] = ($rem[$n] ?? 0) + 1;
        }
    }
    ksort($dias);
    arsort($rem);
    return ['dias' => array_filter($dias), 'remetentes' => $rem]; // dias só com a data e nenhuma mensagem não contam
}

/** Retorna [AAAA-MM-DD => quantidade de mensagens], em ordem de data. */
function contar_mensagens_por_dia(string $texto): array {
    return analisar_conversa($texto)['dias'];
}

/** Para diagnóstico sem expor conteúdo: letras viram "a", dígitos "9". Ex.: "99/99/9999 99:99 - aaaa: aaa". */
function formato_linhas(string $texto, int $n = 3): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $texto) as $l) {
        $l = trim($l);
        if ($l === '') {
            continue;
        }
        $f = preg_replace(['/\p{L}+/u', '/\d/'], ['a', '9'], mb_substr($l, 0, 40));
        $out[] = $f;
        if (count($out) >= $n) {
            break;
        }
    }
    return $out;
}

function data_br(string $iso): string {
    [$a, $m, $d] = explode('-', $iso);
    return "$d/$m/$a";
}

/** Último dia já lançado PARA ESTE SOLICITANTE: do marcador; se não houver, o maior dia da tabela tickets. */
function ultimo_dia_lancado(string $solicitante): ?string {
    $arq = DIR_DATA . '/marcador.json';
    if (is_file($arq)) {
        $m = json_decode((string) file_get_contents($arq), true);
        if (!empty($m['solicitantes'][$solicitante]['ultimo_dia_lancado'])) {
            return $m['solicitantes'][$solicitante]['ultimo_dia_lancado'];
        }
    }
    $st = db()->prepare('SELECT MAX(data) FROM tickets WHERE solicitante = ?');
    $st->execute([$solicitante]);
    return $st->fetchColumn() ?: null;
}

/** Ainda não houve nenhum ticket aberto de verdade pelo app (os importados não contam).
    Regra do projeto: o primeiro lançamento real é de UM dia só, conferido no Helpdesk. */
function primeiro_lancamento_real(): bool {
    return !db()->query("SELECT 1 FROM tickets WHERE protocolo IS NOT NULL AND protocolo <> '' LIMIT 1")->fetchColumn();
}


/** Mesmas regras de limpar() em worker/nucleo.py: CPF, CNS, senha e login de operador.
    Usada no texto que o usuário edita na Tela 4 (o ticket nunca leva esses dados). */
function limpar_lgpd(string $t): string {
    $regras = [
        '/\b\d{3}\.?\d{3}\.?\d{3}[-.]?\d{2}\b/u' => '[CPF removido]',
        '/\b\d{15}\b/u' => '[CNS removido]',
        '/\b\d{3}\s\d{4}\s\d{4}\s\d{4}\b/u' => '[CNS removido]',
        '/\bsenha\s*[:=]\s*\S+|\bsenha\s+(?=\S*[\d@])\S+/iu' => 'senha [removida]',
        '/\boperador\s*[:=]?\s*\d{6,}/iu' => 'operador [removido]',
    ];
    return (string) preg_replace(array_keys($regras), array_values($regras), $t);
}
