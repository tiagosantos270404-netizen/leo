<?php
/* ===== HORÁRIOS DO LÉO: único lugar para mudar dias e horários =====
   Vale para a loja, o servidor (que recusa pedidos fora do horário), o painel e o texto do site.
   A hora usada é sempre a do servidor, no fuso abaixo (nunca a do celular do cliente).

   dias: 0 = domingo, 1 = segunda, 2 = terça, 3 = quarta, 4 = quinta, 5 = sexta, 6 = sábado
   horários no formato HH:MM (24 h). O horário de fechamento já é fechado: 19:30 não aceita mais pedido.
   As mensagens do site são montadas sozinhas a partir destes valores. */
return [
    'fuso' => 'America/Sao_Paulo',

    // restaurante (aceita pedidos)
    'dias'   => [1, 2, 3, 4, 5, 6],   // segunda a sábado; domingo fechado
    'abre'   => '07:00',
    'fecha'  => '19:30',

    // entrega (dentro do horário do restaurante)
    'entrega_dias'   => [1, 2, 3, 4, 5, 6],
    'entrega_inicio' => '09:00',
    'entrega_fim'    => '18:00',

    // almoço à la minuta (só dá para pedir neste horário, dentro do horário do restaurante)
    'almoco_dias'   => [1, 2, 3, 4, 5, 6],
    'almoco_inicio' => '11:30',
    'almoco_fim'    => '15:00',
];
