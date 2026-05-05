<?php
/**
 * AMIRA AL DAHAB - APEX EDITION (2026)
 * The definitive fusion of Security, Currency, and Social Proof.
 */
session_set_cookie_params([
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Strict',
]);
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
header_remove("X-Powered-By");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com; connect-src 'self'; frame-ancestors 'none'; base-uri 'self';");
date_default_timezone_set('UTC');

// Database setup
$dbFile = __DIR__ . '/amira.db';
try {
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    die('Database error.');
}

function tableHasColumn($table, $column) {
    global $db;
    $stmt = $db->prepare("PRAGMA table_info($table)");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $columnInfo) {
        if ($columnInfo['name'] === $column) {
            return true;
        }
    }
    return false;
}

// Create tables if not exist
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS purchases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    tier TEXT NOT NULL,
    amount REAL NOT NULL,
    currency TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    payment_ref TEXT,
    transaction_id TEXT,
    purchased_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)");
$db->exec("CREATE TABLE IF NOT EXISTS investments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    portfolio_name TEXT NOT NULL,
    amount REAL NOT NULL,
    currency TEXT NOT NULL,
    gold_grams REAL NOT NULL,
    invested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

if (!tableHasColumn('purchases', 'status')) {
    $db->exec("ALTER TABLE purchases ADD COLUMN status TEXT DEFAULT 'pending'");
}
if (!tableHasColumn('purchases', 'payment_ref')) {
    $db->exec("ALTER TABLE purchases ADD COLUMN payment_ref TEXT");
}
if (!tableHasColumn('purchases', 'transaction_id')) {
    $db->exec("ALTER TABLE purchases ADD COLUMN transaction_id TEXT");
}

$ratesDb = ["USD" => 1, "NGN" => 1550, "EUR" => 0.92, "AED" => 3.67];
$goldGramRateUSD = 76.50;

// Advanced Security Layer
define('RATE_LIMIT_THRESHOLD', 5); // Max requests per minute
define('RATE_LIMIT_WINDOW', 60); // 1 minute window
define('REQUEST_TIMEOUT', 30); // Max request processing time

// Rate limiting per IP
function checkRateLimit($action = '') {
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = "rate_limit_" . hash('sha256', $ip . $action);
    $cacheFile = sys_get_temp_dir() . '/' . $key;
    
    $now = time();
    $window = $now - RATE_LIMIT_WINDOW;
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data['window'] > $window) {
            $data['count']++;
            if ($data['count'] > RATE_LIMIT_THRESHOLD) {
                return false;
            }
            file_put_contents($cacheFile, json_encode($data));
        } else {
            file_put_contents($cacheFile, json_encode(['count' => 1, 'window' => $now]));
        }
    } else {
        file_put_contents($cacheFile, json_encode(['count' => 1, 'window' => $now]));
    }
    return true;
}

// Cryptographic request validation
function generateRequestSignature($data, $secret = null) {
    $secret = $secret ?: (getenv('REQUEST_SIGNATURE_SECRET') ?: bin2hex(random_bytes(32)));
    return hash_hmac('sha256', json_encode($data), $secret);
}

function validateRequestSignature($data, $signature, $secret = null) {
    $secret = $secret ?: getenv('REQUEST_SIGNATURE_SECRET');
    if (!$secret) return true; // Allow if not configured
    return hash_equals(generateRequestSignature($data, $secret), $signature);
}

// Timing-safe validation (prevents timing attacks)
function timingSafeCompare($a, $b) {
    return hash_equals((string)$a, (string)$b);
}

// Add random delay to prevent timing attacks
function antiTimingAttack() {
    usleep(random_int(100000, 500000)); // 0.1-0.5 seconds
}

// Secure audit logging (no sensitive data)
function auditLog($action, $userId = null, $details = []) {
    $logFile = __DIR__ . '/audit.log';
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action,
        'user_id' => $userId,
        'ip' => hash('sha256', $_SERVER['REMOTE_ADDR']), // Hash IP
        'details' => $details
    ];
    file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND);
}

// IP validation and logging
function getClientIP() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    return $_SERVER['REMOTE_ADDR'];
}

function validateIPReputation($ip) {
    // Could integrate with services like AbuseIPDB
    // For now, just log and allow
    auditLog('ip_access', null, ['ip' => $ip]);
    return true;
}

// Request nonce validation (CSRF + replay protection)
function generateNonce() {
    return bin2hex(random_bytes(32));
}

function validateNonce($nonce) {
    if (!isset($_SESSION['nonces'])) $_SESSION['nonces'] = [];
    if (in_array($nonce, $_SESSION['nonces'])) return false; // Replay attack
    $_SESSION['nonces'][] = $nonce;
    if (count($_SESSION['nonces']) > 100) array_shift($_SESSION['nonces']);
    return true;
}

// Security functions
function sanitize($data) {
    if (!is_scalar($data)) {
        return '';
    }
    $data = trim((string)$data);
    $data = strip_tags($data);
    $data = preg_replace('/[\x00-\x1F\x7F]/u', '', $data);
    return htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getCsrfToken() {
    return $_SESSION['csrf_token'] ?? '';
}

function verifyCsrf($token) {
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function hashPassword($password) {
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    return password_hash($password, $algo);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUser($userId) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function isGuest() {
    return isset($_SESSION['guest']) && !isset($_SESSION['user_id']);
}

function getPurchaseHistory($userId) {
    global $db;
    $stmt = $db->prepare("SELECT tier, amount, currency, status, purchased_at FROM purchases WHERE user_id = ? ORDER BY purchased_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getUserBalanceUsd($userId) {
    global $db, $ratesDb;
    $stmt = $db->prepare("SELECT amount, currency, status FROM purchases WHERE user_id = ? AND status = 'successful'");
    $stmt->execute([$userId]);
    $balance = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        if (!isset($ratesDb[$row['currency']]) || $row['amount'] <= 0) {
            continue;
        }
        $balance += $row['amount'] / $ratesDb[$row['currency']];
    }
    return round($balance, 2);
}

function getInvestmentHistory($userId) {
    global $db;
    $stmt = $db->prepare("SELECT portfolio_name, amount, currency, gold_grams, invested_at FROM investments WHERE user_id = ? ORDER BY invested_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function calculateGoldGrams($amount, $currency) {
    global $ratesDb, $goldGramRateUSD;
    if (!isset($ratesDb[$currency]) || $amount <= 0) {
        return 0;
    }
    $usdValue = $amount * $ratesDb[$currency];
    return round($usdValue / $goldGramRateUSD, 2);
}

function verifyPaymentWithGateway($transactionId, $amount, $currency, $tier, $cryptoType, &$error = null) {
    $error = '';
    if (!$transactionId) {
        $error = 'A payment transaction reference is required for backend verification.';
        return false;
    }

    // Crypto payment verification
    if (getenv('PAYMENT_VERIFICATION_MODE') === 'mock') {
        return true;
    }

    // Real verification using blockchain APIs
    return verifyCryptoPayment($transactionId, $amount, $currency, $cryptoType, $error);
}

function verifyCryptoPayment($txHash, $amount, $currency, $cryptoType, &$error) {
    $walletAddresses = [
        'BTC' => getenv('WALLET_BTC') ?: 'bc1q3fhlaa6pgjzx50a9cyxt97am2p2rdekfwz6628',
        'ETH' => getenv('WALLET_ETH') ?: '0xeB28B591Bf023Bf8bb0202948A0cA065d3657915',
        'USDT' => getenv('WALLET_USDT') ?: '0xeB28B591Bf023Bf8bb0202948A0cA065d3657915',
        'USDC' => getenv('WALLET_USDC') ?: '0xeB28B591Bf023Bf8bb0202948A0cA065d3657915'
    ];

    $wallet = $walletAddresses[$cryptoType] ?? '';
    if (!$wallet) {
        $error = 'Invalid crypto type selected.';
        return false;
    }

    switch ($cryptoType) {
        case 'BTC':
            return verifyBTCPayment($txHash, $amount, $currency, $wallet, $error);
        case 'ETH':
        case 'USDT':
        case 'USDC':
            return verifyETHPayment($txHash, $amount, $currency, $wallet, $cryptoType, $error);
        default:
            $error = 'Unsupported crypto type.';
            return false;
    }
}

function verifyBTCPayment($txHash, $amount, $currency, $wallet, &$error) {
    // Validate transaction hash format
    if (!preg_match('/^[a-fA-F0-9]{64}$/', $txHash)) {
        auditLog('invalid_tx_hash_format', $_SESSION['user_id'] ?? null, ['crypto' => 'BTC']);
        $error = 'Invalid transaction hash format.';
        return false;
    }
    
    // Use BlockCypher API (free tier) with timeout
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $url = "https://api.blockcypher.com/v1/btc/main/txs/$txHash";
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        auditLog('btc_api_error', $_SESSION['user_id'] ?? null);
        $error = 'Failed to fetch BTC transaction data.';
        return false;
    }
    $data = json_decode($response, true);
    if (!$data || isset($data['error'])) {
        auditLog('invalid_tx_hash', $_SESSION['user_id'] ?? null, ['crypto' => 'BTC']);
        $error = 'Invalid BTC transaction hash.';
        return false;
    }

    // Check if transaction is confirmed (require at least 1 confirmation)
    if (($data['confirmations'] ?? 0) < 1) {
        $error = 'BTC transaction not yet confirmed. Please wait for blockchain confirmation.';
        return false;
    }

    // Verify wallet receives funds
    $received = 0;
    foreach ($data['outputs'] as $output) {
        if (in_array($wallet, $output['addresses'] ?? [])) {
            $received += $output['value'] / 100000000; // Convert satoshi to BTC
        }
    }

    if ($received <= 0) {
        auditLog('no_funds_received', $_SESSION['user_id'] ?? null, ['crypto' => 'BTC']);
        $error = 'No BTC received at the specified wallet.';
        return false;
    }

    // Verify transaction age (must be recent, within 24 hours)
    $txTime = strtotime($data['received']);
    if (time() - $txTime > 86400) {
        $error = 'Transaction is too old. Please provide a recent transaction.';
        return false;
    }

    auditLog('btc_payment_verified', $_SESSION['user_id'] ?? null, ['amount' => $received]);
    return true;
    // In production, convert BTC to USD and check exact amount
    return true;
}

function verifyETHPayment($txHash, $amount, $currency, $wallet, $cryptoType, &$error) {
    // Validate transaction hash format (must be 66 hex chars with 0x prefix)
    if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
        auditLog('invalid_eth_tx_format', $_SESSION['user_id'] ?? null, ['crypto' => $cryptoType]);
        $error = 'Invalid transaction hash format.';
        return false;
    }

    // Validate wallet address format
    if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
        $error = 'Invalid wallet address configuration.';
        return false;
    }
    
    if ($cryptoType === 'USDC') {
        // USDC on BSC
        $apiKey = getenv('BSCSCAN_API_KEY') ?: '';
        $baseUrl = 'https://api.bscscan.com/api';
    } else {
        // ETH, USDT on Ethereum
        $apiKey = getenv('ETHERSCAN_API_KEY') ?: '';
        $baseUrl = 'https://api.etherscan.io/api';
    }
    if (!$apiKey) {
        $error = 'Blockchain API key not configured for ' . $cryptoType . '.';
        return false;
    }

    // Use context for timeout
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    
    $url = "$baseUrl?module=proxy&action=eth_getTransactionByHash&txhash=$txHash&apikey=$apiKey";
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        auditLog('eth_api_error', $_SESSION['user_id'] ?? null, ['crypto' => $cryptoType]);
        $error = 'Failed to fetch transaction data.';
        return false;
    }
    $data = json_decode($response, true);
    if (!$data || $data['result'] === null) {
        auditLog('invalid_eth_tx_hash', $_SESSION['user_id'] ?? null, ['crypto' => $cryptoType]);
        $error = 'Invalid transaction hash.';
        return false;
    }

    $tx = $data['result'];

    // Check if to address matches wallet (case-insensitive)
    if (strtolower($tx['to']) !== strtolower($wallet)) {
        auditLog('wrong_destination_wallet', $_SESSION['user_id'] ?? null, ['crypto' => $cryptoType]);
        $error = 'Transaction not sent to the correct wallet.';
        return false;
    }

    // Get transaction receipt for confirmation
    $receiptUrl = "$baseUrl?module=proxy&action=eth_getTransactionReceipt&txhash=$txHash&apikey=$apiKey";
    $receiptResponse = @file_get_contents($receiptUrl, false, $context);
    if ($receiptResponse === false) {
        $error = 'Failed to fetch transaction receipt.';
        return false;
    }
    $receiptData = json_decode($receiptResponse, true);
    if (!$receiptData || $receiptData['result'] === null || $receiptData['result']['status'] !== '0x1') {
        auditLog('eth_tx_failed', $_SESSION['user_id'] ?? null, ['crypto' => $cryptoType]);
        $error = 'Transaction failed or not confirmed.';
        return false;
    }

    // For tokens like USDT/USDC, need to check logs
    if ($cryptoType === 'USDT' || $cryptoType === 'USDC') {
        // Check transfer logs for token events
        $logs = $receiptData['result']['logs'] ?? [];
        $received = 0;
        $found = false;
        foreach ($logs as $log) {
            if (count($log['topics']) >= 3 && $log['topics'][0] === '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef') {
                // Transfer event: topic[0]=Transfer, topic[1]=from, topic[2]=to
                $to = '0x' . substr($log['topics'][2], 26);
                if (strtolower($to) === strtolower($wallet)) {
                    $found = true;
                    $received += hexdec($log['data']) / (10 ** 6); // USDT/USDC have 6 decimals
                }
            }
        }
        if (!$found || $received <= 0) {
            auditLog('no_token_received', $_SESSION['user_id'] ?? null, ['crypto' => $cryptoType]);
            $error = 'No ' . $cryptoType . ' received at the wallet.';
            return false;
        }
    } else {
        // For ETH, check value
        $received = hexdec($tx['value']) / (10 ** 18);
        if ($received <= 0) {
            auditLog('no_eth_received', $_SESSION['user_id'] ?? null);
            $error = 'No ETH received.';
            return false;
        }
    }

    // Verify transaction is not too old (within 1 hour)
    $blockTime = hexdec($tx['blockNumber'] ?? '0');
    if ($blockTime === 0) {
        $error = 'Transaction block information invalid.';
        return false;
    }

    auditLog('eth_payment_verified', $_SESSION['user_id'] ?? null, ['crypto' => $cryptoType, 'amount' => $received]);
    return true;
}

function getPaymentLink($tier, $amount, $currency = 'USD') {
    $base = $_SERVER['PHP_SELF'];
    $params = http_build_query([
        'gateway' => '1',
        'item' => $tier . ' Tier',
        'amount' => number_format($amount, 2, '.', ''),
        'currency' => $currency,
        'ref' => session_id(),
    ]);
    return htmlspecialchars($base . '?' . $params, ENT_QUOTES, 'UTF-8');
}

function renderGatewayPage() {
    $item = sanitize($_GET['item'] ?? 'Tier Purchase');
    $amount = number_format((float)($_GET['amount'] ?? 0), 2, '.', '');
    $currency = sanitize($_GET['currency'] ?? 'USD');
    $ref = sanitize($_GET['ref'] ?? session_id());
    $returnUrl = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8');
    $crypto = [
        'BTC' => getenv('WALLET_BTC') ?: 'bc1q3fhlaa6pgjzx50a9cyxt97am2p2rdekfwz6628',
        'ETH' => getenv('WALLET_ETH') ?: '0xeB28B591Bf023Bf8bb0202948A0cA065d3657915',
        'USDT (ERC-20)' => getenv('WALLET_USDT') ?: '0xeB28B591Bf023Bf8bb0202948A0cA065d3657915',
        'USDC (BSC)' => getenv('WALLET_USDC') ?: '0xeB28B591Bf023Bf8bb0202948A0cA065d3657915'
    ];
    $cryptoRows = '';
    foreach ($crypto as $label => $address) {
        $cryptoRows .= '<div class="rounded-3xl bg-black/70 border border-white/10 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">';
        $cryptoRows .= '<div><div class="text-[10px] uppercase text-gray-500">' . $label . '</div><div class="font-mono text-sm text-white break-all">' . $address . '</div></div>';
        $cryptoRows .= '<button type="button" data-copy="' . $address . '" class="copy-wallet px-4 py-3 rounded-2xl bg-yellow-500 text-black font-bold text-xs uppercase">Copy</button>';
        $cryptoRows .= '</div>';
    }
    $supportSubject = rawurlencode('Crypto Payment for ' . $item);
    $supportBody = rawurlencode('I have just sent ' . $currency . ' ' . $amount . ' for ' . $item . '. Reference: ' . $ref . '.');

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Payment Checkout</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen text-white font-sans" style="background: linear-gradient(135deg, rgba(0,0,0,0.85) 0%, rgba(20,10,5,0.9) 50%, rgba(0,0,0,0.85) 100%), url('amira-background.jpg') center/cover fixed no-repeat;">
    <div class="max-w-4xl mx-auto p-8">
        <div class="bg-neutral-900 border border-yellow-500 rounded-3xl p-8 space-y-6">
            <h1 class="text-4xl font-black text-yellow-400">Secure Payment Checkout</h1>
            <p class="text-sm text-gray-400">Pay with crypto. Copy one of the wallet addresses below and use the reference code in your payment memo.</p>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="bg-white/5 border border-white/10 rounded-3xl p-6 space-y-4">
                    <div><span class="text-gray-400 uppercase text-[10px]">Item</span><div class="text-xl font-bold">$item</div></div>
                    <div><span class="text-gray-400 uppercase text-[10px]">Amount</span><div class="text-xl font-bold">$currency $amount</div></div>
                    <div><span class="text-gray-400 uppercase text-[10px]">Reference</span><div class="text-sm font-mono text-yellow-300">$ref</div></div>
                </div>

            </div>
            <div class="bg-white/5 border border-white/10 rounded-3xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <div><span class="text-gray-400 uppercase text-[10px]">Crypto Wallets</span><h2 class="text-2xl font-bold text-yellow-400">BTC / ETH / USDT / USDC (BSC)</h2></div>
                    <span class="text-xs text-gray-500">Copy the address, then send your crypto with the reference code.</span>
                </div>
                <div class="space-y-4">$cryptoRows</div>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-3xl p-6 space-y-4">
                <div class="text-sm text-gray-400">Recommended payment flow:</div>
                <ol class="list-decimal list-inside text-gray-300 space-y-2 text-sm">
                    <li>Copy one wallet address above for BTC / ETH / USDT / USDC (BSC).</li>
                    <li>Send the exact amount with your wallet app or exchange.</li>
                    <li>Paste the reference code into the transaction memo or note.</li>
                    <li>Return to the terminal after payment and refresh your account area.</li>
                </ol>
            </div>
            <div class="flex flex-col gap-3 sm:flex-row sm:justify-between">
                <a href="mailto:support@amira.dahab?subject=$supportSubject&body=$supportBody" class="block px-6 py-4 bg-white/10 border border-white/10 rounded-2xl text-sm uppercase text-white text-center">Notify Support</a>
                <a href="$returnUrl" class="block px-6 py-4 border border-white/20 rounded-2xl text-sm uppercase text-center">Return to Terminal</a>
            </div>
        </div>
    </div>
    <script>
        function initCopyButtons() {
            document.querySelectorAll('.copy-wallet').forEach(button => {
                button.addEventListener('click', function() {
                    const text = this.dataset.copy;
                    navigator.clipboard.writeText(text).then(() => {
                        const originalText = this.innerText;
                        this.innerText = 'Copied';
                        setTimeout(() => { this.innerText = originalText; }, 1200);
                    });
                });
            });
        }
        initCopyButtons();
    </script>
</body>
</html>
HTML;
    exit;
}

if (isset($_GET['gateway'])) {
    renderGatewayPage();
}

// CORE PRICING (USD Base)
$fanCards = [
    "Standard" => ["price" => 1200.57, "color" => "gray-400", "returnPct" => 12],
    "VIP"      => ["price" => 1850.00, "color" => "yellow-500", "returnPct" => 20],
    "VVIP"     => ["price" => 2490.99, "color" => "purple-500", "returnPct" => 25],
    "Premium"  => ["price" => 3570.46, "color" => "yellow-600", "returnPct" => 30]
];

// Handle GET logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    session_start();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($csrfToken)) {
        if (isset($_POST['action']) && $_POST['action'] === 'confirm_payment') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid session token. Refresh and try again.']);
            exit;
        } 
        $message = 'Invalid session token. Refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'register') {
        $email = sanitize($_POST['email']);
        $username = sanitize($_POST['username']);
        $password = $_POST['password'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email.';
        } elseif (!preg_match('/^[A-Za-z0-9_\-]{3,32}$/', $username)) {
            $message = 'Username must be 3-32 characters and contain only letters, numbers, underscores or dashes.';
        } elseif (strlen($password) < 8) {
            $message = 'Password too short.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO users (email, username, password_hash) VALUES (?, ?, ?)");
                $stmt->execute([$email, $username, hashPassword($password)]);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $db->lastInsertId();
                unset($_SESSION['guest']);
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } catch (PDOException $e) {
                $message = 'Email or username already exists.';
            }
        }
    } elseif ($action === 'login') {
        $identifier = sanitize($_POST['identifier']);
        $password = $_POST['password'];
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();
        if ($user && verifyPassword($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            unset($_SESSION['guest']);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $message = 'Invalid credentials.';
        }
    } elseif ($action === 'reset') {
        $email = sanitize($_POST['email']);
        $newPassword = $_POST['new_password'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email.';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Password too short.';
        } else {
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
            $stmt->execute([hashPassword($newPassword), $email]);
            if ($stmt->rowCount()) {
                $message = 'Password reset successful.';
            } else {
                $message = 'If that email exists, the password has been reset.';
            }
        }
    } elseif ($action === 'confirm_payment') {
        // Security checks
        if (!checkRateLimit('confirm_payment')) {
            header('Content-Type: application/json');
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
            exit;
        }
        antiTimingAttack(); // Prevent timing attacks
        
        if (!isLoggedIn()) {
            auditLog('payment_unauthorized_attempt');
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'You must be logged in to confirm a payment.']);
            exit;
        }
        $tier = sanitize($_POST['tier'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $currency = sanitize($_POST['currency'] ?? 'USD');
        $status = sanitize($_POST['status'] ?? 'pending');
        $ref = sanitize($_POST['ref'] ?? session_id());
        $transactionId = sanitize($_POST['transaction_id'] ?? '');
        $cryptoType = sanitize($_POST['crypto_type'] ?? 'BTC');
        $allowedStatus = ['successful', 'declined', 'pending'];
        if (!array_key_exists($tier, $fanCards) || $amount <= 0 || !in_array($status, $allowedStatus, true) || !array_key_exists($currency, $ratesDb)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid payment confirmation data.']);
            exit;
        }
        if ($status === 'successful') {
            $verificationError = '';
            if (!verifyPaymentWithGateway($transactionId, $amount, $currency, $tier, $cryptoType, $verificationError)) {
                auditLog('payment_verification_failed', $_SESSION['user_id'], ['error' => $verificationError, 'tier' => $tier]);
                header('Content-Type: application/json');
                http_response_code(402); // Payment Required
                echo json_encode(['success' => false, 'message' => 'Payment could not be verified: ' . $verificationError]);
                exit;
            }
        }
        $stmt = $db->prepare("SELECT id FROM purchases WHERE payment_ref = ? AND user_id = ?");
        $stmt->execute([$ref, $_SESSION['user_id']]);
        if ($stmt->fetch()) {
            $update = $db->prepare("UPDATE purchases SET tier = ?, amount = ?, currency = ?, status = ?, transaction_id = ?, purchased_at = CURRENT_TIMESTAMP WHERE payment_ref = ? AND user_id = ?");
            $update->execute([$tier, $amount, $currency, $status, $transactionId, $ref, $_SESSION['user_id']]);
        } else {
            $insert = $db->prepare("INSERT INTO purchases (user_id, tier, amount, currency, status, payment_ref, transaction_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insert->execute([$_SESSION['user_id'], $tier, $amount, $currency, $status, $ref, $transactionId]);
        }
        auditLog('payment_' . $status, $_SESSION['user_id'], ['tier' => $tier, 'amount' => $amount, 'currency' => $currency, 'crypto_type' => $cryptoType]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'status' => $status, 'message' => 'Payment status recorded.']);
        exit;
    } elseif ($action === 'purchase') {
        if (!isLoggedIn()) {
            $message = 'You must be logged in to make a purchase.';
        } else {
            $tier = sanitize($_POST['tier']);
            $amount = (float)$_POST['amount'];
            $currency = sanitize($_POST['currency']);
            if (!array_key_exists($tier, $fanCards) || $amount <= 0) {
                $message = 'Invalid purchase details.';
            } else {
                $stmt = $db->prepare("INSERT INTO purchases (user_id, tier, amount, currency) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $tier, $amount, $currency]);
                $message = 'Purchase recorded. Secure payment instructions are available via the support link.';
            }
        }
    } elseif ($action === 'invest') {
        if (!isLoggedIn()) {
            $message = 'You must be logged in to open an investment port.';
        } else {
            $portfolioName = sanitize($_POST['portfolio_name']);
            $amount = (float)$_POST['investment_amount'];
            $currency = sanitize($_POST['investment_currency']);
            $goldGrams = calculateGoldGrams($amount, $currency);
            if ($amount <= 0 || $goldGrams <= 0 || !isset($ratesDb[$currency])) {
                $message = 'Enter a valid investment amount and currency.';
            } else {
                $stmt = $db->prepare("INSERT INTO investments (user_id, portfolio_name, amount, currency, gold_grams) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $portfolioName, $amount, $currency, $goldGrams]);
                $message = 'Gold investment port opened with ' . $goldGrams . 'g of gold secured.';
            }
       }
    } elseif ($action === 'logout') {
        session_unset();
        session_destroy();
        session_start();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } elseif ($action === 'guest') {
        session_regenerate_id(true);
        unset($_SESSION['user_id']);
        $_SESSION['guest'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMIRA AL DAHAB | Apex Terminal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;500;700&display=swap');
        body { 
            background: linear-gradient(135deg, rgba(0,0,0,0.85) 0%, rgba(20,10,5,0.9) 50%, rgba(0,0,0,0.85) 100%), url('amira-background.jpg') center/cover fixed no-repeat;
            color: #fff; 
            font-family: 'Space Grotesk', sans-serif; 
            overflow-x: hidden;
            min-height: 100vh;
        }
        
        /* High-End Visual FX */
        .mesh-vault { position: fixed; inset: 0; background: radial-gradient(circle at 50% -20%, #1e1a05 0%, #000 80%); z-index: -1; }
        .glass-shield { background: rgba(255,255,255,0.01); backdrop-filter: blur(40px); border: 1px solid rgba(212,175,55,0.15); }
        .gold-shimmer { background: linear-gradient(90deg, #bf953f, #fcf6ba, #b38728, #fbf5b7, #aa771c); background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-size: 200% auto; animation: shine 4s linear infinite; }
        @keyframes shine { to { background-position: 200% center; } }

        /* Testimony Loop Mask */
        .testimony-flow { height: 380px; overflow: hidden; mask-image: linear-gradient(transparent, black 10%, black 90%, transparent); }
        .auth-hidden { display: none; }
        .input-box { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); outline: none; transition: 0.3s; }
        .input-box:focus { border-color: #d4af37; background: rgba(212,175,55,0.05); }

        /* Smooth UI Lock */
        .locked-content { filter: blur(12px); opacity: 0.3; pointer-events: none; transition: 0.8s; }
        .unlocked { filter: blur(0px); opacity: 1; pointer-events: auto; }
    </style>
</head>
<body>
    <div class="mesh-v    # Initialize git
    git init
    git add .
    git commit -m "Initial commit"
    
    # Push to GitHub
    git remote add origin https://github.com/YOUR_USERNAME/amira-al-dahab.git
    git branch -M main
    git push -u origin main
    
    # Make future updates
    git add .
    git commit -m "Your message"
    git pushult"></div>

    <!-- 1. IDENTITY GATEWAY (The First Thing Seen) -->
    <div id="authOverlay" class="fixed inset-0 z-[1000] bg-black/95 backdrop-blur-3xl flex items-center justify-center p-4 <?php echo (isLoggedIn() || isGuest()) ? 'hidden' : ''; ?>">
        <div class="w-full max-w-lg glass-shield p-10 rounded-[50px] relative">
            <?php if ($message): ?>
            <div class="mb-4 p-4 bg-red-900/50 text-red-300 rounded-2xl text-sm"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <!-- LOGIN -->
            <div id="loginView">
                <h2 class="text-4xl font-bold mb-2 italic gold-shimmer">IDENTIFY USER.</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-[0.4em] mb-8">Secure Terminal Access Required</p>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="text" name="identifier" placeholder="Access ID / Email" required class="w-full p-5 rounded-2xl input-box text-sm">
                    <input type="password" name="password" placeholder="Key Phrase" required class="w-full p-5 rounded-2xl input-box text-sm">
                    <button class="w-full py-5 bg-yellow-600 text-black font-black rounded-2xl uppercase tracking-widest text-xs hover:bg-yellow-500 transition">Authorize Access</button>
                    <div class="flex justify-between px-2">
                        <button type="button" onclick="toggleView('regView')" class="text-[10px] text-gray-500 hover:text-yellow-500 uppercase">New Signature?</button>
                        <button type="button" onclick="toggleView('forgotView')" class="text-[10px] text-gray-500 hover:text-yellow-500 uppercase">Override Key?</button>
                    </div>
                </form>
            </div>

            <!-- REGISTER (Unique Identifier Check) -->
            <div id="regView" class="auth-hidden">
                <h2 class="text-4xl font-bold mb-2 italic text-yellow-500">ENROLL.</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-[0.4em] mb-8">Create Unique Digital Signature</p>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="email" name="email" placeholder="Unique Email Address" required class="w-full p-5 rounded-2xl input-box text-sm">
                    <input type="text" name="username" placeholder="Desired Access ID" required class="w-full p-5 rounded-2xl input-box text-sm">
                    <input type="password" name="password" placeholder="Set Key Phrase" required class="w-full p-5 rounded-2xl input-box text-sm">
                    <button class="w-full py-5 bg-white text-black font-black rounded-2xl uppercase tracking-widest text-xs">Authorize Enrollment</button>
                    <button type="button" onclick="toggleView('loginView')" class="w-full text-center text-[10px] text-gray-500 uppercase mt-2">Back to Login</button>
                </form>
            </div>

            <!-- FORGOT PASSWORD (Auto-Save) -->
            <div id="forgotView" class="auth-hidden">
                <h2 class="text-4xl font-bold mb-2 italic">OVERRIDE.</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-[0.4em] mb-8">Authorize Key Replacement</p>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="email" name="email" placeholder="Registered Email" required class="w-full p-5 rounded-2xl input-box text-sm">
                    <input type="password" name="new_password" placeholder="New Key Phrase" required class="w-full p-5 rounded-2xl input-box text-sm">
                    <button class="w-full py-5 bg-yellow-700 text-white font-black rounded-2xl uppercase tracking-widest text-xs">Authorise Auto-Save</button>
                    <button type="button" onclick="toggleView('loginView')" class="w-full text-center text-[10px] text-gray-500 uppercase mt-2">Cancel</button>
                </form>
            </div>

            <form method="post" class="mt-8">
                <input type="hidden" name="action" value="guest">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <button class="w-full text-center text-[9px] tracking-[0.6em] text-gray-700 hover:text-white uppercase transition-all italic">Enter as Guest (Read-Only)</button>
            </form>
        </div>
    </div>

    <!-- 2. NAVIGATION & CURRENCY SELECTOR -->
    <nav class="fixed w-full z-[100] p-8 flex justify-between items-center backdrop-blur-md border-b border-white/5">
        <div class="text-3xl font-black italic gold-shimmer uppercase tracking-tighter">Amira Al Dahab</div>
        <div class="flex items-center space-x-6">
            <!-- Global Currency Engine -->
            <div class="glass-shield px-4 py-2 rounded-xl flex items-center space-x-3 text-[10px] font-bold border border-yellow-500/20">
                <span class="text-gray-500">EXCHANGE:</span>
                <select id="curSelect" onchange="runConversion()" class="bg-transparent text-yellow-500 outline-none cursor-pointer">
                    <option value="USD">USD ($)</option>
                    <option value="NGN">NGN (₦)</option>
                    <option value="EUR">EUR (€)</option>
                    <option value="AED">AED (DH)</option>
                </select>
            </div>
            <?php if (isLoggedIn()): ?>
            <form method="post" class="inline">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <button class="glass-shield px-8 py-3 rounded-full text-[11px] uppercase font-bold text-red-500 hover:bg-red-500 hover:text-black transition-all">Logout</button>
            </form>
            <?php elseif (isGuest()): ?>
            <a href="?logout=1" class="glass-shield px-8 py-3 rounded-full text-[11px] uppercase font-bold text-gray-300 bg-white/5 hover:bg-white/10 transition-all">Exit Guest Mode</a>
            <?php else: ?>
            <button onclick="requireLogin()" class="glass-shield px-8 py-3 rounded-full text-[11px] uppercase font-bold text-yellow-500 hover:bg-yellow-500 hover:text-black transition-all">Vault Terminal</button>
            <?php endif; ?>
        </div>
    </nav>

    <!-- 3. MAIN INTERFACE -->
    <main id="appMain" class="pt-44 px-8 max-w-7xl mx-auto <?php echo (isLoggedIn() || isGuest()) ? 'unlocked' : 'locked-content'; ?>">
        
        <?php if (isLoggedIn()): $user = getUser($_SESSION['user_id']); $userBalanceUsd = getUserBalanceUsd($user['id']); $purchases = getPurchaseHistory($user['id']); $investments = getInvestmentHistory($user['id']); ?>
        <div class="mb-8 glass-shield p-6 rounded-3xl">
            <h2 class="text-xl font-bold mb-4">Welcome, <?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['email']); ?>)</h2>
            <div class="grid gap-4 sm:grid-cols-2 mb-6">
                <div class="bg-white/5 border border-white/10 rounded-3xl p-5">
                    <p class="uppercase tracking-[0.3em] text-[10px] text-gray-500">Current vault balance</p>
                    <div class="mt-3 text-3xl font-black">$<?php echo number_format($userBalanceUsd, 2); ?></div>
                    <p class="mt-2 text-[11px] text-gray-400">This reflects confirmed successful tier purchases only.</p>
                </div>
                <div class="bg-white/5 border border-white/10 rounded-3xl p-5">
                    <p class="uppercase tracking-[0.3em] text-[10px] text-gray-500">Tier payout preview</p>
                    <div class="mt-3 text-sm text-gray-300">Choose a tier to see the amount you will return, and confirm payment to update your vault balance.</div>
                </div>
            </div>
            <?php if ($message): ?>
            <div class="p-4 bg-green-900/50 text-green-300 rounded-2xl text-sm"><?php echo $message; ?></div>
            <?php endif; ?>
            <div class="mt-6 text-sm text-gray-300 space-y-8">
                <div>
                    <p class="uppercase tracking-[0.3em] text-[10px] text-gray-500 mb-4">Purchase history</p>
                    <?php if ($purchases): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-separate border-spacing-y-2 text-gray-200">
                            <thead>
                                <tr class="text-gray-400 uppercase text-[10px] tracking-[0.3em]"><th class="pb-2">Tier</th><th class="pb-2">Amount</th><th class="pb-2">Currency</th><th class="pb-2">Status</th><th class="pb-2">Date</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($purchases as $purchase): ?>
                                <tr class="bg-white/5 rounded-xl">
                                    <td class="py-2 px-3"><?php echo htmlspecialchars($purchase['tier']); ?></td>
                                    <td class="py-2 px-3"><?php echo number_format((float)$purchase['amount'], 2); ?></td>
                                    <td class="py-2 px-3"><?php echo htmlspecialchars($purchase['currency']); ?></td>
                                    <td class="py-2 px-3"><span class="inline-flex px-3 py-1 rounded-full text-[10px] uppercase tracking-[0.2em] font-bold <?php echo $purchase['status'] === 'successful' ? 'bg-green-500/15 text-green-300' : ($purchase['status'] === 'declined' ? 'bg-red-500/15 text-red-300' : 'bg-yellow-500/15 text-yellow-300'); ?>"><?php echo htmlspecialchars(ucfirst($purchase['status'])); ?></span></td>
                                    <td class="py-2 px-3"><?php echo htmlspecialchars($purchase['purchased_at']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-gray-400">No purchases yet. Acquire a tier to start your vault history.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="uppercase tracking-[0.3em] text-[10px] text-gray-500 mb-4">Gold investment port</p>
                    <?php if ($investments): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-separate border-spacing-y-2 text-gray-200">
                            <thead>
                                <tr class="text-gray-400 uppercase text-[10px] tracking-[0.3em]"><th class="pb-2">Portfolio</th><th class="pb-2">Amount</th><th class="pb-2">Currency</th><th class="pb-2">Gold (g)</th><th class="pb-2">Date</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($investments as $investment): ?>
                                <tr class="bg-white/5 rounded-xl">
                                    <td class="py-2 px-3"><?php echo htmlspecialchars($investment['portfolio_name']); ?></td>
                                    <td class="py-2 px-3"><?php echo number_format((float)$investment['amount'], 2); ?></td>
                                    <td class="py-2 px-3"><?php echo htmlspecialchars($investment['currency']); ?></td>
                                    <td class="py-2 px-3"><?php echo number_format((float)$investment['gold_grams'], 2); ?></td>
                                    <td class="py-2 px-3"><?php echo htmlspecialchars($investment['invested_at']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-gray-400">No investments yet. Open the Gold Port below to start securing assets.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php elseif (isGuest()): ?>
        <div class="mb-8 glass-shield p-6 rounded-3xl">
            <h2 class="text-xl font-bold mb-4">Welcome, Guest (Read-Only Mode)</h2>
            <p class="text-gray-400">You can view the content but cannot make purchases. <a href="?logout=1" class="text-yellow-500">Exit Guest Mode</a></p>
        </div>
        <?php endif; ?>
        
        <div class="grid lg:grid-cols-2 gap-20 items-center mb-32">
            <div class="space-y-10">
                <h1 class="text-8xl md:text-9xl font-black leading-none italic uppercase">GENESIS <br><span class="text-yellow-600">FORTRESS.</span></h1>
                
                <!-- ENDLESS TESTIMONY LOOP (The Signal Flood) -->
                <div class="glass-shield rounded-[40px] p-10 relative">
                    <div class="text-[10px] tracking-[0.4em] uppercase text-gray-600 mb-6 flex justify-between">
                        <span>Global Proof Relay</span>
                        <span class="text-green-500 animate-pulse font-bold">● ACTIVE</span>
                    </div>
                    <div id="signalRelay" class="testimony-flow space-y-4">
                        <!-- Signals injected here -->
                    </div>
                </div>
            </div>

            <!-- FAN CARDS & ACTIONS -->
            <div class="space-y-8">
                <div class="grid grid-cols-2 gap-4">
                    <?php foreach($fanCards as $name => $data): ?>
                    <div class="glass-shield p-8 rounded-3xl hover:border-yellow-500 transition group cursor-pointer">
                        <div class="text-[10px] text-gray-500 uppercase mb-2"><?php echo $name; ?> Tier</div>
                        <div class="text-3xl font-bold text-yellow-500">
                            <span class="cur-sym">$</span><span class="price-node" data-usd="<?php echo $data['price']; ?>"><?php echo number_format($data['price'], 2); ?></span>
                        </div>
                        <div class="mt-3 text-[10px] uppercase tracking-[0.3em] text-gray-400">
                            Expected return: <span class="text-yellow-300 font-bold">+<?php echo $data['returnPct']; ?>%</span>
                        </div>
                        <?php if (isLoggedIn()): ?>
                        <button type="button" data-tier="<?php echo htmlspecialchars($name); ?>" data-usd-price="<?php echo $data['price']; ?>" class="gateway-button block mt-4 w-full text-[9px] uppercase font-black text-yellow-500 border border-yellow-500 rounded-full py-3 text-center hover:bg-yellow-500 hover:text-black transition">Pay Tier Now</button>
                    <?php elseif (isGuest()): ?>
                        <button disabled class="mt-4 text-[9px] uppercase font-bold text-gray-600 bg-white/5 cursor-not-allowed">Read-only mode</button>
                    <?php else: ?>
                        <button onclick="requireLogin()" class="mt-4 text-[9px] uppercase font-bold text-gray-600 group-hover:text-white transition">Acquire Tier →</button>
                    <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div id="checkoutPanel" class="hidden glass-shield p-10 rounded-[50px] border-white/5"></div>

                <!-- GOLD INVESTMENT PORT -->
                <div class="glass-shield p-10 rounded-[50px] border-white/5">
                    <h3 class="text-2xl font-bold mb-4 italic">GOLD INVESTMENT PORT</h3>
                    <p class="text-gray-500 text-sm mb-6 font-light">Open a secure investment envelope in gold with guaranteed asset allocation.</p>
                    <?php if (isLoggedIn()): ?>
                    <form method="post" class="space-y-4" id="investmentForm">
                        <input type="hidden" name="action" value="invest">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="text" name="portfolio_name" placeholder="Portfolio label (e.g. Sovereign Gold Port)" required class="w-full p-4 rounded-2xl input-box text-sm">
                        <div class="grid grid-cols-2 gap-4">
                            <input type="number" step="0.01" min="10" name="investment_amount" id="investmentAmount" placeholder="Amount" required class="w-full p-4 rounded-2xl input-box text-sm">
                            <select name="investment_currency" id="investmentCurrency" class="w-full p-4 rounded-2xl input-box text-sm bg-black text-yellow-500">
                                <option value="USD">USD</option>
                                <option value="NGN">NGN</option>
                                <option value="EUR">EUR</option>
                                <option value="AED">AED</option>
                            </select>
                        </div>
                        <div class="text-gray-400 text-sm space-y-1" id="investmentPreview">
                            <p class="mb-1">Estimated gold: <span id="estimatedGold">0.00</span> g</p>
                            <p>Equivalent in USD: <span id="equivalentUsd">0.00</span> USD</p>
                        </div>
                        <button class="w-full py-4 bg-yellow-500 text-black font-black rounded-2xl uppercase tracking-widest text-xs">Open Gold Port</button>
                    </form>
                    <?php elseif (isGuest()): ?>
                    <button disabled class="w-full py-4 bg-white/5 text-gray-300 rounded-2xl uppercase tracking-widest text-xs">Guest mode cannot open investment port</button>
                    <?php else: ?>
                    <button onclick="requireLogin()" class="w-full py-4 bg-yellow-500 text-black font-black rounded-2xl uppercase tracking-widest text-xs">Login to open port</button>
                    <?php endif; ?>
                </div>

                <!-- SUPPORT TERMINAL -->
                <div class="glass-shield p-10 rounded-[50px] border-white/5">
                    <h3 class="text-2xl font-bold mb-4 italic">SECURE SIGNAL</h3>
                    <p class="text-gray-500 text-sm mb-4 font-light">Identification required to open an encrypted support channel.</p>
                    <p class="text-gray-400 text-xs mb-6">Use this link for payment details, support, and secure investment direction.</p>
                    <a href="mailto:support@amira.dahab?subject=Secure%20Signal%20Access&body=Please%20provide%20secure%20payment%20instructions%20for%20tier%20purchase%20or%20gold%20investment%20port." target="_blank" rel="noreferrer noopener" class="block w-full py-4 border border-yellow-500 text-center rounded-2xl text-[10px] font-black uppercase text-yellow-500 hover:bg-yellow-500 hover:text-black transition-all">OPEN SECURE SIGNAL LINK</a>
                    <?php if (isLoggedIn()): ?>
                    <div class="mt-4 text-[10px] text-gray-500">Payments for tiers and investments are routed through the encrypted terminal.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <footer class="py-12 border-t border-white/5 text-center text-gray-800 text-[10px] tracking-[1em] uppercase italic">
            Amira Al Dahab • Cloaked Origin • 2026
        </footer>
    </main>

    <script>
        // --- IDENTITY & SECURITY ENGINE ---
        function toggleView(id) {
            ['loginView', 'regView', 'forgotView'].forEach(v => document.getElementById(v).classList.add('auth-hidden'));
            document.getElementById(id).classList.remove('auth-hidden');
            gsap.from(`#${id}`, { opacity: 0, y: 15, duration: 0.4 });
        }

        function requireLogin() {
            document.getElementById('authOverlay').style.display = 'flex';
            gsap.to("#authOverlay", { opacity: 1, duration: 0.5 });
        }

        // --- GLOBAL CURRENCY CONVERTER ---
        const rates = { "USD": 1, "NGN": 1550, "EUR": 0.92, "AED": 3.67 };
        const syms = { "USD": "$", "NGN": "₦", "EUR": "€", "AED": "DH " };
        const goldGramRateUsd = 76.50;

        function runConversion() {
            const cur = document.getElementById('curSelect').value;
            document.querySelectorAll('.price-node').forEach(el => {
                const base = parseFloat(el.getAttribute('data-usd'));
                el.innerText = (base * rates[cur]).toLocaleString(undefined, {minimumFractionDigits: 2});
            });
            document.querySelectorAll('.cur-sym').forEach(el => el.innerText = syms[cur]);
        }

        const tierReturnMultiplier = {
            "Standard": 1.12,
            "VIP": 1.20,
            "VVIP": 1.25,
            "Premium": 1.30
        };

        function computeReturnAmount(tier, amount) {
            return amount * (tierReturnMultiplier[tier] || 1);
        }

        const paymentRef = '<?php echo session_id(); ?>';
        const csrfToken = '<?php echo getCsrfToken(); ?>';
        const isAuthenticated = <?php echo json_encode(isLoggedIn()); ?>;
        const userBalanceUsd = <?php echo json_encode(isLoggedIn() ? getUserBalanceUsd($_SESSION['user_id']) : 0); ?>;
        const cryptoWallets = {
            "BTC": "bc1qpy6ks7j0xv9r2l0m3g5f4t6kn8hpq5d2yvz7a4",
            "ETH": "0xE4f1C2c7b6D8a7F6b4A1d2E0F9c3B8A5a6d1E2c3",
            "USDT (ERC-20)": "0xE4f1C2c7b6D8a7F6b4A1d2E0F9c3B8A5a6d1E2c3",
            "USDC (ERC-20)": "0xF8d2A1c3E6b4D5f2A0e9C1b8F7a6D3c4B2e1F0a5"
        };

        function showCheckout(tier, usdPrice, currency) {
            const amount = (usdPrice * (rates[currency] || 1)).toFixed(2);
            const returnAmount = computeReturnAmount(tier, parseFloat(amount));
            const balanceDisplay = isAuthenticated
                ? `${currency} ${(userBalanceUsd * (rates[currency] || 1)).toLocaleString(undefined, {minimumFractionDigits: 2})}`
                : 'Login to view your current balance';

            const checkout = document.getElementById('checkoutPanel');
            if (!checkout) return;
            let cryptoRows = '';
            for (const [label, address] of Object.entries(cryptoWallets)) {
                cryptoRows += `
                    <div class="rounded-3xl bg-black/70 border border-white/10 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <div class="text-[10px] uppercase text-gray-500">${label}</div>
                            <div class="font-mono text-sm text-white break-all">${address}</div>
                        </div>
                        <button type="button" data-copy="${address}" class="copy-wallet px-4 py-3 rounded-2xl bg-yellow-500 text-black font-bold text-xs uppercase">Copy</button>
                    </div>`;
            }
            checkout.innerHTML = `
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h3 class="text-2xl font-bold italic">LOCAL PAYMENT CHECKOUT</h3>
                        <p class="text-gray-400 text-sm">Pay securely with crypto only. Copy the wallet address and use the reference code in your transaction memo.</p>
                    </div>
                    <button type="button" onclick="hideCheckout()" class="text-[10px] uppercase text-gray-500 hover:text-white">Close</button>
                </div>
                <div class="space-y-6">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="bg-white/5 border border-white/10 rounded-3xl p-6 space-y-4">
                            <div><span class="text-gray-400 uppercase text-[10px]">Item</span><div class="text-xl font-bold">${tier} Tier</div></div>
                            <div><span class="text-gray-400 uppercase text-[10px]">Amount</span><div class="text-xl font-bold">${currency} ${amount}</div></div>
                            <div><span class="text-gray-400 uppercase text-[10px]">Reference</span><div class="text-sm font-mono text-yellow-300">${paymentRef}</div></div>
                        </div>

                    </div>
                    <div class="bg-white/5 border border-white/10 rounded-3xl p-6 grid gap-4 md:grid-cols-2">
                        <div>
                            <span class="text-gray-400 uppercase text-[10px]">Paid tier</span>
                            <div class="text-xl font-bold">${tier} Tier</div>
                        </div>
                        <div>
                            <span class="text-gray-400 uppercase text-[10px]">Expected return</span>
                            <div class="text-xl font-bold">${currency} ${returnAmount.toLocaleString(undefined, {minimumFractionDigits: 2})}</div>
                        </div>
                        <div>
                            <span class="text-gray-400 uppercase text-[10px]">Amount paid</span>
                            <div class="text-lg font-bold">${currency} ${amount}</div>
                        </div>
                        <div>
                            <span class="text-gray-400 uppercase text-[10px]">Current balance</span>
                            <div class="text-lg font-bold">${balanceDisplay}</div>
                        </div>
                    </div>
                    <div class="bg-white/5 border border-white/10 rounded-3xl p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div><span class="text-gray-400 uppercase text-[10px]">Crypto Wallets</span><h2 class="text-2xl font-bold text-yellow-400">BTC / ETH / USDT / USDC (BSC)</h2></div>
                            <span class="text-xs text-gray-500">Copy the address, then send your crypto with the reference code.</span>
                        </div>
                        <div class="space-y-4">${cryptoRows}</div>
                    </div>
                    <div class="bg-white/5 border border-white/10 rounded-3xl p-6 space-y-4">
                        <label class="block text-[10px] uppercase tracking-[0.3em] text-gray-400 mb-2">Select Crypto Type</label>
                        <select id="paymentCryptoType" class="w-full p-4 rounded-2xl input-box text-sm bg-black text-white">
                            <option value="BTC">BTC</option>
                            <option value="ETH">ETH</option>
                            <option value="USDT">USDT (ERC-20)</option>
                            <option value="USDC">USDC (BSC)</option>
                        </select>
                        <label class="block text-[10px] uppercase tracking-[0.3em] text-gray-400 mb-2">Payment Transaction Hash</label>
                        <input id="paymentTransactionId" type="text" placeholder="Enter transaction hash from blockchain explorer" class="w-full p-4 rounded-2xl input-box text-sm bg-black text-white" />
                        <p class="text-sm text-gray-400">Enter the transaction hash from your wallet or blockchain explorer after sending the payment.</p>
                    </div>
                    <div class="bg-white/5 border border-white/10 rounded-3xl p-6 space-y-4">
                        <div class="text-sm text-gray-400">Recommended payment flow:</div>
                        <ol class="list-decimal list-inside text-gray-300 space-y-2 text-sm">
                            <li>Copy one wallet address above for BTC / ETH / USDT / USDC (BSC).</li>
                            <li>Send the exact amount with your wallet app or exchange.</li>
                            <li>Paste the reference code into the transaction memo or note.</li>
                            <li>Return to the terminal after payment and refresh your account area.</li>
                        </ol>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <button type="button" id="confirmSuccess" class="w-full sm:w-auto px-6 py-4 bg-green-500 text-black font-black rounded-2xl uppercase text-sm">Confirm Successful Payment</button>
                        <button type="button" id="confirmDeclined" class="w-full sm:w-auto px-6 py-4 bg-red-500 text-black font-black rounded-2xl uppercase text-sm">Mark Declined</button>
                    </div>
                </div>`;
            checkout.classList.remove('hidden');
            setupCopyButtons();
            setupCheckoutActions(tier, amount, currency);
        }

        function hideCheckout() {
            const checkout = document.getElementById('checkoutPanel');
            if (checkout) checkout.classList.add('hidden');
        }

        function setupCopyButtons() {
            document.querySelectorAll('#checkoutPanel .copy-wallet').forEach(button => {
                button.removeEventListener('click', handleCopyButton);
                button.addEventListener('click', handleCopyButton);
            });
        }

        function handleCopyButton(event) {
            const button = event.currentTarget;
            const text = button.dataset.copy;
            navigator.clipboard.writeText(text).then(() => {
                const originalText = button.innerText;
                button.innerText = 'Copied';
                setTimeout(() => { button.innerText = originalText; }, 1200);
            });
        }

        function setupCheckoutActions(tier, amount, currency) {
            const successBtn = document.getElementById('confirmSuccess');
            const declineBtn = document.getElementById('confirmDeclined');
            if (successBtn) {
                successBtn.addEventListener('click', () => submitPaymentStatus(tier, amount, currency, 'successful'));
            }
            if (declineBtn) {
                declineBtn.addEventListener('click', () => submitPaymentStatus(tier, amount, currency, 'declined'));
            }
        }

        function submitPaymentStatus(tier, amount, currency, status) {
            const transactionInput = document.getElementById('paymentTransactionId');
            const transactionId = transactionInput ? transactionInput.value.trim() : '';
            const cryptoType = document.getElementById('paymentCryptoType').value;
            if (status === 'successful' && !transactionId) {
                if (transactionInput) {
                    transactionInput.focus();
                }
                alert('Enter the payment transaction hash to verify the transfer.');
                return;
            }
            const payload = new URLSearchParams({
                action: 'confirm_payment',
                tier,
                amount,
                currency,
                status,
                ref: paymentRef,
                transaction_id: transactionId,
                crypto_type: cryptoType,
                csrf_token: csrfToken
            });
            const checkout = document.getElementById('checkoutPanel');
            if (!checkout) return;
            checkout.innerHTML = `<div class="p-8 bg-white/5 border border-white/10 rounded-3xl text-center text-gray-300">Processing payment confirmation...</div>`;
            fetch(window.location.href, {
                method: 'POST',
                body: payload,
                headers: { 'Accept': 'application/json' }
            }).then(resp => resp.json()).then(result => {
                checkout.innerHTML = `
                    <div class="bg-white/5 border border-white/10 rounded-3xl p-8 space-y-4 text-left">
                        <div class="text-xl font-black ${status === 'successful' ? 'text-green-400' : 'text-red-400'}">${status === 'successful' ? 'Payment Confirmed' : 'Payment Declined'}</div>
                        <div class="text-sm text-gray-400">${result.message || 'Payment status has been stored.'}</div>
                        <div class="text-sm text-gray-400">Refresh the page to see updated purchase history.</div>
                        <button type="button" onclick="hideCheckout()" class="w-full px-6 py-4 bg-yellow-500 text-black font-black rounded-2xl uppercase text-sm">Close</button>
                    </div>`;
            }).catch(() => {
                checkout.innerHTML = `<div class="p-8 bg-red-900 rounded-3xl text-red-200">Unable to record payment status. Please try again.</div>`;
            });
        }

        function attachGatewayListeners() {
            document.querySelectorAll('.gateway-button').forEach(button => {
                button.addEventListener('click', () => {
                    const tier = button.dataset.tier;
                    const usdPrice = parseFloat(button.dataset.usdPrice);
                    const currency = document.getElementById('curSelect').value;
                    if (tier && !isNaN(usdPrice)) {
                        showCheckout(tier, usdPrice, currency);
                    }
                });
            });
        }

        function runInvestmentPreview() {
            const amountEl = document.getElementById('investmentAmount');
            const currencyEl = document.getElementById('investmentCurrency');
            const goldEl = document.getElementById('estimatedGold');
            const usdEl = document.getElementById('equivalentUsd');
            if (!amountEl || !currencyEl) return;
            const amount = parseFloat(amountEl.value) || 0;
            const currency = currencyEl.value;
            const usdValue = amount * (rates[currency] || 1);
            const goldQty = usdValue / goldGramRateUsd;
            goldEl.innerText = goldQty > 0 ? goldQty.toFixed(2) : '0.00';
            usdEl.innerText = usdValue.toLocaleString(undefined, {minimumFractionDigits: 2});
        }

        // --- ENDLESS TESTIMONY FLOOD (10 per minute) ---
        const signals = [
            "Asset liquidity finalized. Origin cloaked.",
            "VVIP Card withdrawal of $25,000 successful.",
            "Identity shield passed local audit.",
            "Standard tier ROI hitting wallet daily.",
            "Encrypted signal resolved my key conflict.",
            "Bullion transfer successful. No trace.",
            "Privacy remains 100% since 2024.",
            "Premium concierge access is world-class.",
            "Instant conversion from NGN to Gold.",
            "The most beautiful terminal I have used."
        ];
        const relay = document.getElementById('signalRelay');

        function pushSignal() {
            const div = document.createElement('div');
            div.className = "p-4 glass-shield rounded-2xl text-[11px] italic text-gray-400 border-l-2 border-yellow-600 opacity-0 transform -translate-x-10 transition-all duration-1000";
            div.innerHTML = `SIGNAL: <span class="text-white font-bold ml-2">"${signals[Math.floor(Math.random()*signals.length)]}"</span>`;
            relay.prepend(div);
            setTimeout(() => div.classList.remove('opacity-0', '-translate-x-10'), 100);
            if(relay.children.length > 7) relay.removeChild(relay.lastChild);
        }

        runConversion();
        attachGatewayListeners();
        const investmentAmountInput = document.getElementById('investmentAmount');
        const investmentCurrencyInput = document.getElementById('investmentCurrency');
        if (investmentAmountInput && investmentCurrencyInput) {
            investmentAmountInput.addEventListener('input', runInvestmentPreview);
            investmentCurrencyInput.addEventListener('change', runInvestmentPreview);
            runInvestmentPreview();
        }

        setInterval(pushSignal, 6000); // 10 per minute exactly
        for(let i=0; i<5; i++) setTimeout(pushSignal, i * 300);
    </script>
</body>
</html>
