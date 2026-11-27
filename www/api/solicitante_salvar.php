<?php
// Cadastra/atualiza um solicitante: nome no WhatsApp -> termo de busca e nome exato no Helpdesk.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
exigir_post();

$r = salvar_solicitante(
    (string) ($_POST['nome_whatsapp'] ?? ''),
    (string) ($_POST['busca'] ?? ''),
    (string) ($_POST['nome_helpdesk'] ?? '')
);
if (is_string($r)) {
    responder_json(['erro' => $r], 400);
}
responder_json(['ok' => true, 'cadastro' => $r]);
