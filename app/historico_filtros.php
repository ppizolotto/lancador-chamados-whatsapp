<?php
// Filtros do histórico (tela e CSV): solicitante e período. Datas aceitas em DD/MM/AAAA ou AAAA-MM-DD; guardadas em ISO.
declare(strict_types=1);

function data_filtro(string $v): string {
    $v = trim($v);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return $v;
    }
    return '';
}

/** @return array{solicitante:string, de:string, ate:string} */
function filtros_historico(array $in): array {
    $f = ['solicitante' => trim((string) ($in['solicitante'] ?? '')),
          'de' => data_filtro((string) ($in['de'] ?? '')), 'ate' => data_filtro((string) ($in['ate'] ?? ''))];
    if ($f['de'] !== '' && $f['ate'] !== '' && $f['de'] > $f['ate']) {
        [$f['de'], $f['ate']] = [$f['ate'], $f['de']];
    }
    return $f;
}

/** Condição SQL (com parâmetros) sobre a tabela de tickets com o alias $t. */
function where_historico(array $f, string $t): array {
    $w = ['1=1'];
    $p = [];
    if ($f['solicitante'] !== '') { $w[] = "$t.solicitante = ?"; $p[] = $f['solicitante']; }
    if ($f['de'] !== '') { $w[] = "$t.data >= ?"; $p[] = $f['de']; }
    if ($f['ate'] !== '') { $w[] = "$t.data <= ?"; $p[] = $f['ate']; }
    return [implode(' AND ', $w), $p];
}
