<?php
// Histórico — grava em Relatorios/ o CSV dos dias lançados com os filtros da tela e abre o Explorer no arquivo.
// Só metadados (dia, solicitante, protocolo, situação): nenhum texto da conversa.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require RAIZ . '/app/conversa.php';
require RAIZ . '/app/historico_filtros.php';
exigir_post();

$f = filtros_historico($_POST);
[$where, $params] = where_historico($f, 't');
$st = db()->prepare("SELECT t.data, t.solicitante, t.protocolo, t.situacao_final, t.corrigido, t.erro
                     FROM tickets t WHERE $where ORDER BY t.data, t.solicitante");
$st->execute($params);
$base = rtrim((string) config()['url_base'], '/');

$dir = RAIZ . '/Relatorios';
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    responder_json(['erro' => 'Não consegui criar a pasta Relatorios.'], 500);
}
// Nome do arquivo sem acentos (tabela fixa: o iconv do Windows troca "ã" por "~a")
$semAcento = strtr((string) preg_replace('/\p{Mn}/u', '', $f['solicitante']), [
    'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
    'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ù' => 'u', 'ç' => 'c', 'ñ' => 'n',
    'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'Ê' => 'E', 'È' => 'E', 'Í' => 'I', 'Ì' => 'I',
    'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ù' => 'U', 'Ç' => 'C', 'Ñ' => 'N',
]);
$sufixo = $f['solicitante'] !== ''
    ? '-' . (trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $semAcento), '-') ?: 'solicitante') : '';
$arq = $dir . '/historico-' . date('Y-m-d-His') . substr($sufixo, 0, 40) . '.csv';
$fh = fopen($arq, 'wb');
fwrite($fh, "\xEF\xBB\xBF"); // BOM: o Excel abre os acentos certo
$linha = fn(array $c) => fputcsv($fh, $c, ';', '"', '');
$linha(['Dia', 'Solicitante', 'Protocolo', 'Situação', 'Corrigido na verificação', 'Observação', 'Link']);
$n = 0;
foreach ($st as $r) {
    $n++;
    $imp = $r['situacao_final'] === 'importado';
    $linha([
        data_br($r['data']), $r['solicitante'], (string) $r['protocolo'],
        $imp ? 'importado (lançado antes do app)' : (string) $r['situacao_final'],
        $r['protocolo'] ? ((int) $r['corrigido'] ? 'sim' : 'não') : '',
        (string) $r['erro'],
        $r['protocolo'] ? $base . '/atendimento/' . $r['protocolo'] : '',
    ]);
}
fclose($fh);
log_app('info', "Histórico CSV exportado: $n dias");

try {
    executar_em_segundo_plano(['explorer.exe', '/select,' . str_replace('/', '\\', $arq)]);
} catch (Throwable $e) {
    error_log('historico_csv explorer: ' . $e->getMessage());
}
responder_json(['ok' => true, 'arquivo' => 'Relatorios\\' . basename($arq), 'dias' => $n]);
