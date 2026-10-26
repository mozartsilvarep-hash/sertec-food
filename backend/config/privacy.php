<?php

return [
    /*
    | LGPD: depois deste prazo os pedidos finalizados perdem nome, telefone,
    | e-mail, endereço e observações (valores e itens permanecem).
    */
    'retention_months' => (int) env('PRIVACY_RETENTION_MONTHS', 24),
];
