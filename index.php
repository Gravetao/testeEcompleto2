<?php

// Configurações de conexão com o banco de dados
$host = 'localhost';
$db = 'Ecompleto';
$user = 'root';
$senha = '';
$dsn = "mysql:host=$host;dbname=$db;charset=UTF8";

try {
    $pdo = new PDO($dsn, $user, $senha);
} catch (PDOException $e) {
    exit('Conexão falhou: ' . $e->getMessage());
}

// Seleciona pedidos pendentes com pagamento via cartão de crédito
$sql = "
SELECT 
    pedidos.id,
    pedidos.valor,
    pedidos_pagamentos.id_formapagto, 
    pedidos_pagamentos.num_cartao, 
    pedidos_pagamentos.codigo_verificacao, 
    pedidos_pagamentos.vencimento,
    clientes.id as idCliente,   
    clientes.nome, 
    clientes.email, 
    clientes.cpf_cnpj, 
    clientes.data_nasc 
FROM pedidos 
JOIN pedidos_pagamentos ON pedidos.id = pedidos_pagamentos.id_pedido
JOIN clientes ON pedidos.id_cliente = clientes.id
WHERE pedidos.id_situacao = 1 
AND pedidos_pagamentos.id_form_pagto = 3;
";
$pedidos = $pdo->query($sql);

// Configurações iniciais da requisição para o gateway de pagamento
$ch = curl_init();
$headers = [
    'Content-Type: application/json',
    'Authorization: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdG9yZUlkIjoiNCIsInVzZXJJZCI6IjkwNDQiLCJpYXQiOjE3MDc5Mzg0ODUsImV4cCI6MTcwNzk0MjA4NX0.KYZGGBfSg3NyR29diuTYoDVs6Es20Zkf-qrhzwvdYvo' 
];
$url = "https://api11.ecompleto.com.br/exams/processTransaction";

// Processa cada pedido
foreach ($pedidos as $pedido) {
    // Prepara os dados do pedido para o corpo da requisição
    $data = [
        "external_order_id" => $pedido['id_formapagto'],
        "amount" => $pedido['valor'],
        "card_number" => $pedido['num_cartao'],
        "card_cvv" => $pedido['codigo_verificacao'],
        "card_expiration_date" => $pedido['vencimento'],
        "card_holder_name" => $pedido['nome'],
        "customer" => [
            "external_id" => $pedido['idCliente'],
            "name" => $pedido['nome'],
            "type" => "individual",
            "email" => $pedido['email'],
            "documents" => [
                [
                    "type" => "cpf",
                    "number" => $pedido['cpf_cnpj']
                ]
            ],
            "birthday" => $pedido['data_nasc']
        ]
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $responseDecoded = json_decode($response, true);

    // Atualiza o pedido no banco de dados com base na resposta
    $novoStatus = !$responseDecoded['Error'] ? 'Pagamento Aprovado' : 'Cancelado';
    $updateSql = "UPDATE pedidos SET situacao = :novoStatus, retorno_intermediador = :retorno WHERE id = :id";
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute([':novoStatus' => $novoStatus, ':retorno' => $response, ':id' => $pedido['id']]);
}

curl_close($ch);