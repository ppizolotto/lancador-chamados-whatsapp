<?php
// Progresso da instalação em andamento (a Tela 1 consulta a cada 1,5 s).
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

responder_json(['instalacao' => status_instalacao(), 'instalando' => instalando()]);
