<?php
/* Copie para config.php no servidor e preencha. NUNCA coloque config.php no git. */
return [
  'db' => ['host' => '127.0.0.1', 'name' => 'u000000000_leo', 'user' => 'u000000000_leo', 'pass' => 'TROCAR'],
  'secret' => 'TROCAR-por-64-caracteres-aleatorios',      // assina sessões, cotações e links de pedido
  'setup_code' => 'TROCAR',                               // pedido só uma vez, para criar a senha do painel
];
