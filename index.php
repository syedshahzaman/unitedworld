<?php
// ============================================================================
// UNITED WORLD TRADING PLATFORM - COMPLETE PHP CONVERSION
// ============================================================================

session_start();

// ============================================================================
// CONFIGURATION & CONSTANTS
// ============================================================================

define('PRICE_FLOOR', 1.0);
define('TAX_RATE', 0.05);
define('MAX_SINGLE_ORDER', 1000.00);
define('DAILY_TRADING_LIMIT', 10000.00);
define('MIN_INR_POOL', 1000.00);

define('DATA_DIR', __DIR__ . '/data/');
define('USERS_FILE', DATA_DIR . 'users.tsv');
define('TXN_FILE', DATA_DIR . 'transactions.tsv');
define('MARKET_FILE', DATA_DIR . 'market.tsv');
define('WITHDRAW_REQUEST_FILE', DATA_DIR . 'withdraw_request.tsv');
define('DEPOSIT_REQUEST_FILE', DATA_DIR . 'deposit_request.tsv');
define('ADMIN_LOG_FILE', DATA_DIR . 'admin_log.tsv');
define('TAX_COLLECTION_FILE', DATA_DIR . 'tax_collection.tsv');
define('ORDERS_FILE', DATA_DIR . 'orders.tsv');
define('INTERNAL_MRX_FILE', DATA_DIR . 'internal_mrx.tsv');
define('DAILY_TRADES_FILE', DATA_DIR . 'daily_trades.tsv');

define('USERS_HEADER', "user_id\tfull_name\temail\tmobile\tpassword\treferral\tinr_balance\tmrx_balance\tcreated_at\n");
define('TXN_HEADER', "txn_id\tuser_email\ttype\tamount_inr\tamount_mrx\tprice\ttimestamp\tstatus\n");
define('MARKET_HEADER', "inr_pool\tmrx_pool\tlast_updated\n");
define('WITHDRAW_REQUEST_HEADER', "request_id\tuser_email\tuser_name\tamount\tstatus\tbank_name\taccount_number\tifsc_code\tcreated_at\tprocessed_at\tremarks\n");
define('DEPOSIT_REQUEST_HEADER', "request_id\tuser_email\tamount\ttransaction_id\tphone\tpayment_method\tstatus\tcreated_at\n");
define('ADMIN_LOG_HEADER', "log_id\tadmin_email\taction\ttarget_id\ttarget_type\tdetails\ttimestamp\tip_address\n");
define('TAX_COLLECTION_HEADER', "tax_id\tuser_email\tuser_name\torder_type\torder_amount\ttax_amount\torder_worth\torder_date\ttimestamp\tremarks\n");
define('ORDERS_HEADER', "order_id\tuser_email\tuser_name\torder_type\torder_amount_inr\torder_amount_mrx\tprice_at_order\ttax_amount\tstatus\tcreated_at\tremarks\n");
define('INTERNAL_MRX_HEADER', "user_email\tinternal_mrx_balance\tlast_updated\n");
define('DAILY_TRADES_HEADER', "date\tuser_email\ttotal_amount\ttransaction_count\tlast_updated\n");

// ============================================================================
// CORE FUNCTIONS
// ============================================================================

function ensureFiles() {
    if (!file_exists(DATA_DIR)) {
        mkdir(DATA_DIR, 0777, true);
    }

    if (!file_exists(USERS_FILE)) {
        $handle = fopen(USERS_FILE, 'w');
        flock($handle, LOCK_EX);
        fwrite($handle, USERS_HEADER);
        fwrite($handle, generateId(8) . "\tJohn Doe\tjohn@example.com\t1234567890\tpassword123\tREF001\t150000\t0\t" . time() . "\n");
        fwrite($handle, "ADMIN001\tAdmin Wilson\tadmin@unitedworld.com\t9876543210\tadmin123\tADMIN001\t1000000\t0\t" . time() . "\n");
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    $files = [
        TXN_FILE => TXN_HEADER,
        MARKET_FILE => MARKET_HEADER . "2000.00\t1000.000000\t0\n",
        WITHDRAW_REQUEST_FILE => WITHDRAW_REQUEST_HEADER,
        DEPOSIT_REQUEST_FILE => DEPOSIT_REQUEST_HEADER,
        ADMIN_LOG_FILE => ADMIN_LOG_HEADER,
        TAX_COLLECTION_FILE => TAX_COLLECTION_HEADER,
        ORDERS_FILE => ORDERS_HEADER,
        INTERNAL_MRX_FILE => INTERNAL_MRX_HEADER,
        DAILY_TRADES_FILE => DAILY_TRADES_HEADER
    ];

    foreach ($files as $file => $header) {
        if (!file_exists($file)) {
            $handle = fopen($file, 'w');
            flock($handle, LOCK_EX);
            fwrite($handle, $header);
            flock($handle, LOCK_UN);
            fclose($file);
        }
    }
}

function generateId($length = 8) {
    return substr(str_replace('.', '', uniqid('', true)), 0, $length);
}

function getUser($email) {
    if (!file_exists(USERS_FILE)) return null;
    
    $handle = fopen(USERS_FILE, 'r');
    flock($handle, LOCK_SH);
    
    while (($line = fgets($handle)) !== false) {
        if (strpos($line, 'user_id') === 0) continue;
        $cols = explode("\t", trim($line));
        if (count($cols) >= 3 && $cols[2] == $email) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return $cols;
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    return null;
}

function getAllUsers() {
    $users = [];
    if (!file_exists(USERS_FILE)) return $users;
    
    $handle = fopen(USERS_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $cols = explode("\t", trim($line));
        if (count($cols) >= 8) {
            $users[] = [
                'user_id' => $cols[0],
                'full_name' => $cols[1],
                'email' => $cols[2],
                'mobile' => $cols[3],
                'inr_balance' => floatval($cols[6]),
                'created_at' => intval($cols[8])
            ];
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    return $users;
}

function updateUserBalances($email, $newInr, $newMrx) {
    if (!file_exists(USERS_FILE)) return false;
    
    $rows = file(USERS_FILE);
    $updated = false;
    
    $handle = fopen(USERS_FILE, 'w');
    flock($handle, LOCK_EX);
    
    foreach ($rows as $line) {
        if (strpos($line, 'user_id') === 0) {
            fwrite($handle, $line);
            continue;
        }
        
        $cols = explode("\t", trim($line));
        if (count($cols) >= 3 && $cols[2] == $email) {
            $cols[6] = round($newInr, 2);
            $cols[7] = "0";
            fwrite($handle, implode("\t", $cols) . "\n");
            $updated = true;
        } else {
            fwrite($handle, $line);
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    return $updated;
}

function isAdmin($email) {
    $user = getUser($email);
    if (!$user) return false;
    return stripos($email, 'admin') !== false || strpos($user[0], 'ADM') === 0;
}

// Market functions
function readMarket() {
    if (!file_exists(MARKET_FILE)) return [2000.0, 1000.0];
    
    $handle = fopen(MARKET_FILE, 'r');
    flock($handle, LOCK_SH);
    
    fgets($handle); // Skip header
    $line = fgets($handle);
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    if (!$line) return [2000.0, 1000.0];
    
    $cols = explode("\t", trim($line));
    return [floatval($cols[0]), floatval($cols[1])];
}

function writeMarket($inrPool, $mrxPool) {
    if ($mrxPool > 0) {
        $newPrice = $inrPool / $mrxPool;
        if ($newPrice < PRICE_FLOOR) {
            throw new Exception("Cannot set market: Price would be ₹{$newPrice} which is below ₹" . PRICE_FLOOR . " floor");
        }
    }
    
    $handle = fopen(MARKET_FILE, 'w');
    flock($handle, LOCK_EX);
    fwrite($handle, MARKET_HEADER);
    fwrite($handle, round($inrPool, 2) . "\t" . round($mrxPool, 6) . "\t" . time() . "\n");
    flock($handle, LOCK_UN);
    fclose($handle);
}

// Daily trading limit functions
function getUserDailyTrades($userEmail) {
    ensureFiles();
    $today = date('Y-m-d');
    
    if (!file_exists(DAILY_TRADES_FILE)) return 0.0;
    
    $handle = fopen(DAILY_TRADES_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $cols = explode("\t", trim($line));
        if (count($cols) >= 3 && $cols[0] == $today && $cols[1] == $userEmail) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return floatval($cols[2]);
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    return 0.0;
}

function updateUserDailyTrades($userEmail, $amount) {
    ensureFiles();
    $today = date('Y-m-d');
    $rows = file_exists(DAILY_TRADES_FILE) ? file(DAILY_TRADES_FILE) : [];
    $updated = false;
    
    $handle = fopen(DAILY_TRADES_FILE, 'w');
    flock($handle, LOCK_EX);
    
    if (!empty($rows) && strpos($rows[0], 'date') === 0) {
        fwrite($handle, $rows[0]);
    } else {
        fwrite($handle, DAILY_TRADES_HEADER);
    }
    
    foreach (array_slice($rows, 1) as $line) {
        if (empty(trim($line))) continue;
        $cols = explode("\t", trim($line));
        if (count($cols) >= 3 && $cols[0] == $today && $cols[1] == $userEmail) {
            $currentTotal = floatval($cols[2]);
            $currentCount = isset($cols[3]) ? intval($cols[3]) : 0;
            $newTotal = $currentTotal + $amount;
            $newCount = $currentCount + 1;
            
            $cols[2] = round($newTotal, 2);
            $cols[3] = $newCount;
            $cols[4] = isset($cols[4]) ? time() : time();
            
            fwrite($handle, implode("\t", $cols) . "\n");
            $updated = true;
        } else {
            fwrite($handle, $line);
        }
    }
    
    if (!$updated) {
        fwrite($handle, "{$today}\t{$userEmail}\t" . round($amount, 2) . "\t1\t" . time() . "\n");
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
}

function checkDailyTradingLimit($userEmail, $amount) {
    $dailyTotal = getUserDailyTrades($userEmail);
    $newTotal = $dailyTotal + $amount;
    
    if ($newTotal > DAILY_TRADING_LIMIT) {
        $remaining = DAILY_TRADING_LIMIT - $dailyTotal;
        return [false, "Daily trading limit exceeded. Limit: ₹" . number_format(DAILY_TRADING_LIMIT, 2) . ", Used: ₹" . number_format($dailyTotal, 2) . ", Remaining: ₹" . number_format($remaining, 2)];
    }
    
    return [true, "Daily usage: ₹" . number_format($dailyTotal, 2) . "/" . number_format(DAILY_TRADING_LIMIT, 2)];
}

// Internal MRX functions
function getInternalMrxBalance($userEmail) {
    ensureFiles();
    if (!file_exists(INTERNAL_MRX_FILE)) return 0.0;
    
    $handle = fopen(INTERNAL_MRX_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $cols = explode("\t", trim($line));
        if (count($cols) >= 2 && $cols[0] == $userEmail) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return floatval($cols[1]);
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    return 0.0;
}

function updateInternalMrxBalance($userEmail, $newMrxBalance) {
    ensureFiles();
    $rows = file_exists(INTERNAL_MRX_FILE) ? file(INTERNAL_MRX_FILE) : [];
    $updated = false;
    
    $handle = fopen(INTERNAL_MRX_FILE, 'w');
    flock($handle, LOCK_EX);
    
    if (!empty($rows) && strpos($rows[0], 'user_email') === 0) {
        fwrite($handle, $rows[0]);
    } else {
        fwrite($handle, INTERNAL_MRX_HEADER);
    }
    
    foreach (array_slice($rows, 1) as $line) {
        if (empty(trim($line))) continue;
        $cols = explode("\t", trim($line));
        if (count($cols) >= 2 && $cols[0] == $userEmail) {
            $cols[1] = round($newMrxBalance, 6);
            $cols[2] = isset($cols[2]) ? time() : time();
            fwrite($handle, implode("\t", $cols) . "\n");
            $updated = true;
        } else {
            fwrite($handle, $line);
        }
    }
    
    if (!$updated) {
        fwrite($handle, "{$userEmail}\t" . round($newMrxBalance, 6) . "\t" . time() . "\n");
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    return $newMrxBalance;
}

function getTotalInternalMrx() {
    ensureFiles();
    if (!file_exists(INTERNAL_MRX_FILE)) return 0.0;
    
    $total = 0.0;
    $handle = fopen(INTERNAL_MRX_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $cols = explode("\t", trim($line));
        if (count($cols) >= 2 && !empty($cols[1])) {
            $total += floatval($cols[1]);
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    return $total;
}

// Tax functions
function logTaxCollection($userEmail, $userName, $orderType, $orderAmount, $taxAmount, $orderWorth, $remarks = "") {
    ensureFiles();
    
    $taxId = "TAX" . time() . generateId(6);
    $timestamp = time();
    $orderDate = date('Y-m-d H:i:s', $timestamp);
    
    $handle = fopen(TAX_COLLECTION_FILE, 'a');
    flock($handle, LOCK_EX);
    fwrite($handle, "{$taxId}\t{$userEmail}\t{$userName}\t{$orderType}\t{$orderAmount}\t{$taxAmount}\t{$orderWorth}\t{$orderDate}\t{$timestamp}\t{$remarks}\n");
    flock($handle, LOCK_UN);
    fclose($handle);
    
    return $taxId;
}

function getTaxCollectionStats() {
    ensureFiles();
    
    $stats = [
        'total_tax' => 0,
        'total_transactions' => 0,
        'buy_tax' => 0,
        'withdrawal_tax' => 0,
        'sell_tax' => 0,
        'deposit_tax' => 0
    ];
    
    if (!file_exists(TAX_COLLECTION_FILE)) return $stats;
    
    $handle = fopen(TAX_COLLECTION_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $line = trim($line);
        if (empty($line)) continue;
        
        $cols = explode("\t", $line);
        if (count($cols) >= 9) {
            $stats['total_transactions']++;
            $taxAmount = floatval($cols[5]);
            $stats['total_tax'] += $taxAmount;
            
            $orderType = $cols[3] ?? '';
            if ($orderType == 'buy') $stats['buy_tax'] += $taxAmount;
            elseif ($orderType == 'withdrawal') $stats['withdrawal_tax'] += $taxAmount;
            elseif ($orderType == 'sell') $stats['sell_tax'] += $taxAmount;
            elseif ($orderType == 'deposit') $stats['deposit_tax'] += $taxAmount;
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    $stats['total_tax'] = round($stats['total_tax'], 2);
    $stats['buy_tax'] = round($stats['buy_tax'], 2);
    $stats['withdrawal_tax'] = round($stats['withdrawal_tax'], 2);
    $stats['sell_tax'] = round($stats['sell_tax'], 2);
    $stats['deposit_tax'] = round($stats['deposit_tax'], 2);
    
    return $stats;
}

// Order functions
function saveOrderRecord($userEmail, $userName, $orderType, $orderAmountInr, $orderAmountMrx, $priceAtOrder, $taxAmount, $remarks = "") {
    ensureFiles();
    
    $orderId = "ORD" . time() . generateId(6);
    $createdAt = time();
    
    $handle = fopen(ORDERS_FILE, 'a');
    flock($handle, LOCK_EX);
    fwrite($handle, "{$orderId}\t{$userEmail}\t{$userName}\t{$orderType}\t{$orderAmountInr}\t{$orderAmountMrx}\t{$priceAtOrder}\t{$taxAmount}\tcompleted\t{$createdAt}\t{$remarks}\n");
    flock($handle, LOCK_UN);
    fclose($handle);
    
    return $orderId;
}

// Transaction functions
function saveTransaction($userEmail, $txnType, $amountInr, $amountMrx, $price) {
    $txnId = "TXN" . time() . generateId(6);
    $timestamp = time();
    
    $handle = fopen(TXN_FILE, 'a');
    flock($handle, LOCK_EX);
    fwrite($handle, "{$txnId}\t{$userEmail}\t{$txnType}\t{$amountInr}\t" . round($amountMrx, 6) . "\t" . round($price, 4) . "\t{$timestamp}\tcompleted\n");
    flock($handle, LOCK_UN);
    fclose($handle);
    
    return $txnId;
}

function getUserTransactions($userEmail, $limit = 50) {
    $transactions = [];
    if (!file_exists(TXN_FILE)) return $transactions;
    
    $handle = fopen(TXN_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $cols = explode("\t", trim($line));
        if (count($cols) >= 8 && $cols[1] == $userEmail) {
            $transactions[] = [
                'txn_id' => $cols[0],
                'type' => $cols[2],
                'amount_inr' => floatval($cols[3]),
                'amount_mrx' => floatval($cols[4]),
                'price' => floatval($cols[5]),
                'timestamp' => intval($cols[6]),
                'status' => $cols[7]
            ];
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    usort($transactions, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    return array_slice($transactions, 0, $limit);
}

// Deposit functions
function saveDepositRequest($userEmail, $amount, $txnId, $phone, $method) {
    ensureFiles();
    
    $requestId = "DPR" . time() . generateId(6);
    $createdAt = time();
    
    $handle = fopen(DEPOSIT_REQUEST_FILE, 'a');
    flock($handle, LOCK_EX);
    fwrite($handle, "{$requestId}\t{$userEmail}\t{$amount}\t{$txnId}\t{$phone}\t{$method}\tpending\t{$createdAt}\n");
    flock($handle, LOCK_UN);
    fclose($handle);
    
    return $requestId;
}

function getUserDepositRequests($userEmail, $limit = 10) {
    $deposits = [];
    if (!file_exists(DEPOSIT_REQUEST_FILE)) return $deposits;
    
    $handle = fopen(DEPOSIT_REQUEST_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $cols = explode("\t", trim($line));
        if (count($cols) >= 8 && $cols[1] == $userEmail) {
            $deposits[] = [
                'request_id' => $cols[0],
                'amount' => floatval($cols[2]),
                'transaction_id' => $cols[3],
                'phone' => $cols[4],
                'payment_method' => $cols[5],
                'status' => $cols[6],
                'created_at' => intval($cols[7])
            ];
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    usort($deposits, function($a, $b) {
        return $b['created_at'] - $a['created_at'];
    });
    
    return array_slice($deposits, 0, $limit);
}

function getAllDepositRequests($limit = 50) {
    $deposits = [];
    if (!file_exists(DEPOSIT_REQUEST_FILE)) return $deposits;
    
    $handle = fopen(DEPOSIT_REQUEST_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $cols = explode("\t", trim($line));
        if (count($cols) >= 8) {
            $deposits[] = [
                'request_id' => $cols[0],
                'user_email' => $cols[1],
                'amount' => floatval($cols[2]),
                'transaction_id' => $cols[3],
                'phone' => $cols[4],
                'payment_method' => $cols[5],
                'status' => $cols[6],
                'created_at' => intval($cols[7])
            ];
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    usort($deposits, function($a, $b) {
        return $b['created_at'] - $a['created_at'];
    });
    
    return array_slice($deposits, 0, $limit);
}

function updateDepositRequestStatus($requestId, $status, $adminEmail = "") {
    if (!file_exists(DEPOSIT_REQUEST_FILE)) return false;
    
    $rows = file(DEPOSIT_REQUEST_FILE);
    $updated = false;
    $depositInfo = null;
    
    $handle = fopen(DEPOSIT_REQUEST_FILE, 'w');
    flock($handle, LOCK_EX);
    
    foreach ($rows as $line) {
        if (strpos($line, 'request_id') === 0) {
            fwrite($handle, $line);
            continue;
        }
        
        $cols = explode("\t", trim($line));
        if ($cols[0] == $requestId) {
            if ($status == 'approved' && $cols[6] != 'approved') {
                $depositInfo = [
                    'user_email' => $cols[1],
                    'amount' => floatval($cols[2])
                ];
            }
            $cols[6] = $status;
            $updated = true;
            fwrite($handle, implode("\t", $cols) . "\n");
        } else {
            fwrite($handle, $line);
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    if ($updated && $status == 'approved' && $depositInfo) {
        $user = getUser($depositInfo['user_email']);
        if ($user) {
            $currentInr = floatval($user[6]);
            $newInr = $currentInr + $depositInfo['amount'];
            updateUserBalances($depositInfo['user_email'], $newInr, 0);
            saveTransaction($depositInfo['user_email'], 'deposit_approved', $depositInfo['amount'], 0, 0);
            
            if ($adminEmail) {
                logAdminAction($adminEmail, 'deposit_approved', $requestId, 'deposit_request', "Approved deposit of ₹{$depositInfo['amount']} for {$depositInfo['user_email']}");
            }
        }
    }
    
    return $updated;
}

// Withdrawal functions
function saveWithdrawRequest($userEmail, $userName, $amount, $bankName, $accountNumber, $ifscCode) {
    list($inrPool, $mrxPool) = readMarket();
    $newInrPool = $inrPool - $amount;
    
    if ($newInrPool < MIN_INR_POOL) {
        return null;
    }
    
    $requestId = "WDR" . time() . generateId(6);
    $createdAt = time();
    
    $handle = fopen(WITHDRAW_REQUEST_FILE, 'a');
    flock($handle, LOCK_EX);
    fwrite($handle, "{$requestId}\t{$userEmail}\t{$userName}\t{$amount}\tpending\t{$bankName}\t{$accountNumber}\t{$ifscCode}\t{$createdAt}\t0\t\n");
    flock($handle, LOCK_UN);
    fclose($handle);
    
    $user = getUser($userEmail);
    if ($user) {
        $currentInr = floatval($user[6]);
        if ($currentInr >= $amount) {
            $newInr = $currentInr - $amount;
            updateUserBalances($userEmail, $newInr, 0);
            saveTransaction($userEmail, 'withdrawal_requested', -$amount, 0, 0);
        } else {
            return null;
        }
    }
    
    return $requestId;
}

function getUserWithdrawalRequests($userEmail) {
    $requests = [];
    if (!file_exists(WITHDRAW_REQUEST_FILE)) return $requests;
    
    $handle = fopen(WITHDRAW_REQUEST_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $line = trim($line);
        if (empty($line)) continue;
        
        $cols = explode("\t", $line);
        if (count($cols) >= 8 && $cols[1] == $userEmail) {
            while (count($cols) < 11) $cols[] = '';
            
            $amount = floatval($cols[3] ?? 0);
            $createdAt = preg_replace('/[^0-9]/', '', $cols[8] ?? '');
            $createdAt = $createdAt ? intval($createdAt) : time();
            
            $accountNum = $cols[6] ?? '';
            $lastFour = strlen($accountNum) >= 4 ? substr($accountNum, -4) : '';
            
            $requests[] = [
                'request_id' => $cols[0],
                'amount' => $amount,
                'status' => $cols[4] ?? 'pending',
                'bank_name' => $cols[5] ?? '',
                'account_number' => $lastFour,
                'ifsc_code' => $cols[7] ?? '',
                'created_at' => $createdAt,
                'processed_at' => isset($cols[9]) && $cols[9] ? intval($cols[9]) : null,
                'remarks' => $cols[10] ?? ''
            ];
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    usort($requests, function($a, $b) {
        return $b['created_at'] - $a['created_at'];
    });
    
    return $requests;
}

function getAllWithdrawalRequests($limit = 1000) {
    ensureFiles();
    $requests = [];
    if (!file_exists(WITHDRAW_REQUEST_FILE)) return $requests;
    
    $handle = fopen(WITHDRAW_REQUEST_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $line = trim($line);
        if (empty($line)) continue;
        
        $cols = explode("\t", $line);
        if (count($cols) >= 8) {
            while (count($cols) < 11) $cols[] = '';
            
            $amount = floatval($cols[3] ?? 0);
            $createdAt = preg_replace('/[^0-9]/', '', $cols[8] ?? '');
            $createdAt = $createdAt ? intval($createdAt) : time();
            $processedAt = isset($cols[9]) ? intval($cols[9]) : 0;
            
            $requests[] = [
                'request_id' => $cols[0],
                'user_email' => $cols[1],
                'user_name' => $cols[2],
                'amount' => $amount,
                'status' => $cols[4] ?? 'pending',
                'bank_name' => $cols[5] ?? '',
                'account_number' => $cols[6] ?? '',
                'ifsc_code' => $cols[7] ?? '',
                'created_at' => $createdAt,
                'processed_at' => $processedAt ?: null,
                'remarks' => $cols[10] ?? ''
            ];
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    usort($requests, function($a, $b) {
        return $b['created_at'] - $a['created_at'];
    });
    
    return array_slice($requests, 0, $limit);
}

function updateWithdrawalRequestStatus($requestId, $status, $adminEmail = "", $remarks = "") {
    if (!file_exists(WITHDRAW_REQUEST_FILE)) return false;
    
    $rows = file(WITHDRAW_REQUEST_FILE);
    $updated = false;
    $withdrawalInfo = null;
    $previousStatus = null;
    
    $handle = fopen(WITHDRAW_REQUEST_FILE, 'w');
    flock($handle, LOCK_EX);
    
    foreach ($rows as $line) {
        if (strpos($line, 'request_id') === 0) {
            fwrite($handle, $line);
            continue;
        }
        
        $cols = explode("\t", trim($line));
        if ($cols[0] == $requestId) {
            $previousStatus = $cols[4];
            $withdrawalInfo = [
                'user_email' => $cols[1],
                'user_name' => $cols[2],
                'amount' => floatval($cols[3] ?? 0)
            ];
            
            $cols[4] = $status;
            if (in_array($status, ['processed', 'rejected', 'approved'])) {
                $cols[9] = time();
            }
            if ($remarks) {
                while (count($cols) < 11) $cols[] = '';
                $cols[10] = $remarks;
            }
            $updated = true;
            fwrite($handle, implode("\t", $cols) . "\n");
        } else {
            fwrite($handle, $line);
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    if ($updated && $withdrawalInfo) {
        $user = getUser($withdrawalInfo['user_email']);
        if ($user) {
            $currentInternalMrx = getInternalMrxBalance($withdrawalInfo['user_email']);
            list($inrPool, $mrxPool) = readMarket();
            $currentPrice = $mrxPool > 0 ? $inrPool / $mrxPool : 0;
            
            if ($status == 'approved' && $previousStatus == 'pending') {
                $newInrPool = $inrPool - $withdrawalInfo['amount'];
                if ($newInrPool < MIN_INR_POOL) {
                    $currentInr = floatval($user[6]);
                    $newInr = $currentInr + $withdrawalInfo['amount'];
                    updateUserBalances($withdrawalInfo['user_email'], $newInr, 0);
                    updateWithdrawalRequestStatus($requestId, 'rejected', $adminEmail, "Withdrawal would bring INR pool below ₹" . MIN_INR_POOL);
                    return true;
                }
                
                if ($currentPrice > 0) {
                    $mrxToSell = $withdrawalInfo['amount'] / $currentPrice;
                    
                    if ($currentInternalMrx < $mrxToSell) {
                        $currentInr = floatval($user[6]);
                        $newInr = $currentInr + $withdrawalInfo['amount'];
                        updateUserBalances($withdrawalInfo['user_email'], $newInr, 0);
                        updateWithdrawalRequestStatus($requestId, 'rejected', $adminEmail, "Insufficient internal MRX for withdrawal");
                        saveTransaction($withdrawalInfo['user_email'], 'withdrawal_rejected_insufficient_mrx', $withdrawalInfo['amount'], 0, $currentPrice);
                        return true;
                    }
                    
                    $newInrPool = $inrPool - $withdrawalInfo['amount'];
                    $newMrxPool = $mrxPool + $mrxToSell;
                    $newPrice = $newMrxPool > 0 ? $newInrPool / $newMrxPool : $currentPrice;
                    
                    if ($newPrice < PRICE_FLOOR) {
                        $currentInr = floatval($user[6]);
                        $newInr = $currentInr + $withdrawalInfo['amount'];
                        updateUserBalances($withdrawalInfo['user_email'], $newInr, 0);
                        updateWithdrawalRequestStatus($requestId, 'rejected', $adminEmail, "Withdrawal violates price floor ₹" . PRICE_FLOOR);
                        return true;
                    }
                    
                    writeMarket($newInrPool, $newMrxPool);
                    $newInternalMrx = $currentInternalMrx - $mrxToSell;
                    updateInternalMrxBalance($withdrawalInfo['user_email'], $newInternalMrx);
                    saveTransaction($withdrawalInfo['user_email'], 'withdrawal_approved', 0, -$mrxToSell, $currentPrice);
                }
                
            } elseif ($status == 'rejected' && $previousStatus == 'pending') {
                $currentInr = floatval($user[6]);
                $newInr = $currentInr + $withdrawalInfo['amount'];
                updateUserBalances($withdrawalInfo['user_email'], $newInr, 0);
                saveTransaction($withdrawalInfo['user_email'], 'withdrawal_rejected_refund', $withdrawalInfo['amount'], 0, 0);
            }
        }
    }
    
    if ($updated && $adminEmail) {
        logAdminAction($adminEmail, "withdrawal_{$status}", $requestId, 'withdrawal_request', "Updated withdrawal from '{$previousStatus}' to '{$status}'" . ($withdrawalInfo ? " - Amount: ₹{$withdrawalInfo['amount']}" : ""));
    }
    
    return $updated;
}

function getWithdrawalStats() {
    ensureFiles();
    
    $stats = [
        'total' => 0,
        'pending' => 0,
        'approved' => 0,
        'processing' => 0,
        'processed' => 0,
        'rejected' => 0,
        'total_amount' => 0
    ];
    
    if (!file_exists(WITHDRAW_REQUEST_FILE)) return $stats;
    
    $handle = fopen(WITHDRAW_REQUEST_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $line = trim($line);
        if (empty($line)) continue;
        
        $cols = explode("\t", $line);
        if (count($cols) >= 5) {
            $stats['total']++;
            $amount = floatval($cols[3] ?? 0);
            $stats['total_amount'] += $amount;
            
            $status = $cols[4] ?? 'pending';
            if ($status == 'pending') $stats['pending']++;
            elseif ($status == 'approved') $stats['approved']++;
            elseif ($status == 'processing') $stats['processing']++;
            elseif ($status == 'processed') $stats['processed']++;
            elseif ($status == 'rejected') $stats['rejected']++;
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    $stats['total_amount'] = round($stats['total_amount'], 2);
    return $stats;
}

// Admin log functions
function logAdminAction($adminEmail, $action, $targetId, $targetType, $details = "") {
    ensureFiles();
    
    $logId = "LOG" . time() . generateId(6);
    $timestamp = time();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    $handle = fopen(ADMIN_LOG_FILE, 'a');
    flock($handle, LOCK_EX);
    fwrite($handle, "{$logId}\t{$adminEmail}\t{$action}\t{$targetId}\t{$targetType}\t{$details}\t{$timestamp}\t{$ipAddress}\n");
    flock($handle, LOCK_UN);
    fclose($handle);
    
    return $logId;
}

function getRecentAdminLogs($limit = 50) {
    ensureFiles();
    $logs = [];
    
    if (!file_exists(ADMIN_LOG_FILE)) return $logs;
    
    $handle = fopen(ADMIN_LOG_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $cols = explode("\t", trim($line));
        if (count($cols) >= 8) {
            $logs[] = [
                'log_id' => $cols[0],
                'admin_email' => $cols[1],
                'action' => $cols[2],
                'target_id' => $cols[3],
                'target_type' => $cols[4],
                'details' => $cols[5],
                'timestamp' => intval($cols[6]),
                'ip_address' => $cols[7]
            ];
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    usort($logs, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    return array_slice($logs, 0, $limit);
}

// Dashboard stats
function getDashboardStats() {
    $users = getAllUsers();
    $totalUsers = count($users);
    $totalInr = array_sum(array_column($users, 'inr_balance'));
    
    list($inrPool, $mrxPool) = readMarket();
    $price = $mrxPool > 0 ? $inrPool / $mrxPool : 0;
    
    $depositRequests = getAllDepositRequests();
    $withdrawalStats = getWithdrawalStats();
    
    $pendingDeposits = count(array_filter($depositRequests, function($d) {
        return $d['status'] == 'pending';
    }));
    
    $taxStats = getTaxCollectionStats();
    
    if ($inrPool > 0 && $mrxPool > 0) {
        $liquidityRatio = ($inrPool * $price) / ($mrxPool * $price);
        if ($liquidityRatio > 0.8) $liquidityHealth = 'healthy';
        elseif ($liquidityRatio > 0.5) $liquidityHealth = 'warning';
        else $liquidityHealth = 'critical';
    } else {
        $liquidityHealth = 'critical';
    }
    
    $recentTransactions = getRecentTransactions(10);
    $recentLogs = getRecentAdminLogs(10);
    $totalInternalMrx = getTotalInternalMrx();
    
    return [
        'total_users' => $totalUsers,
        'total_inr' => round($totalInr, 2),
        'total_mrx' => 0,
        'total_internal_mrx' => round($totalInternalMrx, 6),
        'current_price' => round($price, 4),
        'inr_pool' => round($inrPool, 2),
        'mrx_pool' => round($mrxPool, 6),
        'pending_deposits' => $pendingDeposits,
        'pending_withdrawals' => $withdrawalStats['pending'],
        'processing_withdrawals' => $withdrawalStats['processing'],
        'total_withdrawal_amount' => $withdrawalStats['total_amount'],
        'liquidity_health' => $liquidityHealth,
        'active_users' => count(array_filter($users, function($u) use ($price) {
            return $u['inr_balance'] > 1000 || getInternalMrxBalance($u['email']) > 0;
        })),
        'recent_transactions' => $recentTransactions,
        'recent_logs' => $recentLogs,
        'withdrawal_stats' => $withdrawalStats,
        'tax_stats' => $taxStats,
        'internal_mrx_reconciliation' => round($mrxPool + $totalInternalMrx, 6),
        'min_inr_pool' => MIN_INR_POOL,
        'inr_pool_above_min' => $inrPool >= MIN_INR_POOL
    ];
}

function getRecentTransactions($limit = 50) {
    $transactions = [];
    if (!file_exists(TXN_FILE)) return $transactions;
    
    $handle = fopen(TXN_FILE, 'r');
    flock($handle, LOCK_SH);
    
    $first = true;
    while (($line = fgets($handle)) !== false) {
        if ($first) { $first = false; continue; }
        $cols = explode("\t", trim($line));
        if (count($cols) >= 8) {
            $transactions[] = [
                'txn_id' => $cols[0],
                'user_email' => $cols[1],
                'type' => $cols[2],
                'amount_inr' => floatval($cols[3]),
                'amount_mrx' => floatval($cols[4]),
                'price' => floatval($cols[5]),
                'timestamp' => intval($cols[6]),
                'status' => $cols[7]
            ];
        }
    }
    
    flock($handle, LOCK_UN);
    fclose($handle);
    
    usort($transactions, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    return array_slice($transactions, 0, $limit);
}

// HTML template functions
function renderHeader($title, $isAdmin = false) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $title; ?> - United World</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0a0b0e; color: #e0e0e0; line-height: 1.6; }
            .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
            .nav { background: #1a1c23; padding: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
            .nav-container { display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto; }
            .nav-links a { color: #e0e0e0; text-decoration: none; margin-left: 20px; padding: 8px 16px; border-radius: 6px; transition: all 0.3s; }
            .nav-links a:hover { background: #2a2d36; color: #00c853; }
            .btn { display: inline-block; padding: 12px 24px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; }
            .btn-primary { background: #00c853; color: white; }
            .btn-primary:hover { background: #00a844; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,200,83,0.3); }
            .btn-danger { background: #f44336; color: white; }
            .card { background: #1e2128; border-radius: 12px; padding: 24px; margin-bottom: 20px; border: 1px solid #2a2d36; }
            .form-group { margin-bottom: 20px; }
            .form-group label { display: block; margin-bottom: 8px; color: #9e9e9e; }
            .form-group input, .form-group select { width: 100%; padding: 12px; background: #2a2d36; border: 1px solid #3a3f4a; border-radius: 6px; color: white; font-size: 16px; }
            .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
            .stat { background: #2a2d36; padding: 20px; border-radius: 8px; text-align: center; }
            .stat-value { font-size: 28px; font-weight: bold; color: #00c853; }
            .stat-label { color: #9e9e9e; margin-top: 8px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; padding: 12px; background: #2a2d36; color: #00c853; }
            td { padding: 12px; border-bottom: 1px solid #2a2d36; }
            .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
            .badge-success { background: #00c85320; color: #00c853; border: 1px solid #00c85340; }
            .badge-warning { background: #ffc10720; color: #ffc107; border: 1px solid #ffc10740; }
            .badge-danger { background: #f4433620; color: #f44336; border: 1px solid #f4433640; }
            .alert { padding: 12px 20px; border-radius: 6px; margin-bottom: 20px; }
            .alert-success { background: #00c85320; border: 1px solid #00c85340; color: #00c853; }
            .alert-danger { background: #f4433620; border: 1px solid #f4433640; color: #f44336; }
        </style>
    </head>
    <body>
        <nav class="nav">
            <div class="nav-container">
                <h2>🇺🇳 United World</h2>
                <div class="nav-links">
                    <?php if ($isAdmin): ?>
                        <a href="admin/dashboard.php">Dashboard</a>
                        <a href="admin/users.php">Users</a>
                        <a href="admin/deposits.php">Deposits</a>
                        <a href="admin/withdrawals.php">Withdrawals</a>
                        <a href="admin/pool.php">Pool Control</a>
                        <a href="admin/tax.php">Tax</a>
                        <a href="admin/logs.php">Logs</a>
                    <?php else: ?>
                        <a href="trade.php">Trade</a>
                        <a href="accelerate.php">Accelerate</a>
                        <a href="wallet.php">Wallet</a>
                        <a href="account.php">Account</a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn btn-danger" style="padding: 6px 12px;">Logout</a>
                </div>
            </div>
        </nav>
        <div class="container">
    <?php
}

function renderFooter() {
    ?>
        </div>
        <script>
            async function apiCall(url, method = 'GET', data = null) {
                const options = { method, headers: { 'Content-Type': 'application/json' } };
                if (data) options.body = JSON.stringify(data);
                const response = await fetch(url, options);
                return await response.json();
            }
        </script>
    </body>
    </html>
    <?php
}

// ============================================================================
// ROUTING HANDLER
// ============================================================================

$request = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($request, PHP_URL_PATH);
$path = str_replace('/index.php', '', $path);
$path = ltrim($path, '/');

ensureFiles();

// ============================================================================
// API ROUTES
// ============================================================================

// API: Accelerate Order
if ($path == 'api/accelerate-order' && $method == 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    
    $amount = floatval($data['amount'] ?? 0);
    $sentiment = $data['sentiment'] ?? 'bullish';
    
    if ($amount > MAX_SINGLE_ORDER) {
        echo json_encode(['success' => false, 'error' => "Single order cannot exceed ₹" . number_format(MAX_SINGLE_ORDER, 2)]);
        exit;
    }
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid amount']);
        exit;
    }
    
    list($limitCheck, $limitMsg) = checkDailyTradingLimit($_SESSION['user'], $amount);
    if (!$limitCheck) {
        echo json_encode(['success' => false, 'error' => $limitMsg]);
        exit;
    }
    
    if ($sentiment == 'bearish') {
        echo json_encode(['success' => false, 'error' => 'Sell is disabled. Withdraw to exit your position.']);
        exit;
    }
    
    if ($sentiment != 'bullish') {
        echo json_encode(['success' => false, 'error' => 'Invalid operation']);
        exit;
    }
    
    $user = getUser($_SESSION['user']);
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    $inrBalance = floatval($user[6]);
    $userName = $user[1];
    
    list($inrPool, $mrxPool) = readMarket();
    if ($mrxPool <= 0) {
        echo json_encode(['success' => false, 'error' => 'Market unavailable']);
        exit;
    }
    
    $priceBefore = $inrPool / $mrxPool;
    $taxAmount = $amount * TAX_RATE;
    $amountAfterTax = $amount - $taxAmount;
    
    if ($inrBalance < $amount) {
        echo json_encode(['success' => false, 'error' => "Insufficient INR balance. Required: ₹{$amount}, Available: ₹{$inrBalance}"]);
        exit;
    }
    
    $mrxReceived = $amountAfterTax / $priceBefore;
    $newInrPool = $inrPool + $amountAfterTax;
    $newMrxPool = $mrxPool - $mrxReceived;
    $newPrice = $newMrxPool > 0 ? $newInrPool / $newMrxPool : $priceBefore;
    
    if ($newPrice < PRICE_FLOOR) {
        echo json_encode(['success' => false, 'error' => "Cannot execute trade. Price would drop below ₹" . PRICE_FLOOR . " floor."]);
        exit;
    }
    
    if ($mrxReceived > $mrxPool * 0.95) {
        echo json_encode(['success' => false, 'error' => 'Market liquidity too low']);
        exit;
    }
    
    $mrxValueAtNewPrice = $mrxReceived * $newPrice;
    $newInr = $inrBalance - $amount + $mrxValueAtNewPrice;
    $actualProfit = $mrxValueAtNewPrice - $amount;
    
    writeMarket($newInrPool, $newMrxPool);
    
    $currentInternalMrx = getInternalMrxBalance($_SESSION['user']);
    $newInternalMrx = $currentInternalMrx + $mrxReceived;
    updateInternalMrxBalance($_SESSION['user'], $newInternalMrx);
    updateUserBalances($_SESSION['user'], $newInr, 0);
    updateUserDailyTrades($_SESSION['user'], $amount);
    
    $txnId = saveTransaction($_SESSION['user'], 'accelerate_bullish', -$amount + $mrxValueAtNewPrice, $mrxReceived, $priceBefore);
    $taxLogId = logTaxCollection($_SESSION['user'], $userName, 'buy', $amount, $taxAmount, $mrxValueAtNewPrice, "Buy order tax: " . (TAX_RATE*100) . "%, MRX allocated: {$mrxReceived}, MRX value: ₹{$mrxValueAtNewPrice}");
    $orderId = saveOrderRecord($_SESSION['user'], $userName, 'buy', $amount, $mrxReceived, $priceBefore, $taxAmount, "BUY: Invested ₹{$amount}, got {$mrxReceived} MRX worth ₹{$mrxValueAtNewPrice} at new price");
    
    $percentageReturn = $amount > 0 ? ($actualProfit / $amount) * 100 : 0;
    $dailyTotal = getUserDailyTrades($_SESSION['user']);
    $dailyRemaining = DAILY_TRADING_LIMIT - $dailyTotal;
    
    echo json_encode([
        'success' => true,
        'transaction_id' => $txnId,
        'tax_log_id' => $taxLogId,
        'order_id' => $orderId,
        'new_inr_balance' => round($newInr, 2),
        'new_mrx_balance' => 0,
        'price_before' => round($priceBefore, 4),
        'price_after' => round($newPrice, 4),
        'sentiment' => $sentiment,
        'inr_invested' => round($amount, 2),
        'tax_amount' => round($taxAmount, 2),
        'amount_to_pool' => round($amountAfterTax, 2),
        'tax_rate' => (TAX_RATE*100) . '%',
        'mrx_allocated_internally' => round($mrxReceived, 6),
        'price_floor' => PRICE_FLOOR,
        'price_floor_violation' => false,
        'single_order_limit' => MAX_SINGLE_ORDER,
        'within_limit' => $amount <= MAX_SINGLE_ORDER,
        'daily_trading_limit' => DAILY_TRADING_LIMIT,
        'daily_total_used' => round($dailyTotal, 2),
        'daily_remaining' => round($dailyRemaining, 2),
        'daily_limit_reached' => $dailyTotal >= DAILY_TRADING_LIMIT,
        'mrx_current_value' => round($mrxValueAtNewPrice, 2),
        'profit' => round($actualProfit, 2),
        'profit_calculation' => "({$mrxReceived} MRX × ₹{$newPrice}) - ₹{$amount}",
        'wallet_calculation' => "₹{$inrBalance} - ₹{$amount} + ₹{$mrxValueAtNewPrice} = ₹{$newInr}",
        'new_price' => round($newPrice, 4),
        'percentage_return' => round($percentageReturn, 2),
        'user_message' => "Invested ₹{$amount} (₹{$taxAmount} tax). Your MRX is now worth ₹{$mrxValueAtNewPrice}. Wallet: ₹{$newInr}",
        'summary' => "Investment: ₹{$amount}, Tax: ₹{$taxAmount}, MRX Value: ₹{$mrxValueAtNewPrice}, Profit: ₹{$actualProfit} (" . round($percentageReturn, 1) . "%)"
    ]);
    exit;
}

// API: User Balance
if ($path == 'api/user-balance' && $method == 'GET') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user'])) {
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
    
    $user = getUser($_SESSION['user']);
    if (!$user) {
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    
    $inrBalance = floatval($user[6]);
    $internalMrx = getInternalMrxBalance($_SESSION['user']);
    
    list($inrPool, $mrxPool) = readMarket();
    $currentPrice = $mrxPool > 0 ? $inrPool / $mrxPool : 0;
    
    $totalWalletValue = $inrBalance + ($internalMrx * $currentPrice);
    $dailyTotal = getUserDailyTrades($_SESSION['user']);
    $dailyRemaining = DAILY_TRADING_LIMIT - $dailyTotal;
    
    echo json_encode([
        'success' => true,
        'inr_balance' => floatval($user[6]),
        'mrx_balance' => 0,
        'internal_mrx_balance' => $internalMrx,
        'current_price' => round($currentPrice, 4),
        'total_wallet_value' => round($totalWalletValue, 2),
        'daily_trading_limit' => DAILY_TRADING_LIMIT,
        'daily_total_used' => round($dailyTotal, 2),
        'daily_remaining' => round($dailyRemaining, 2),
        'daily_limit_reached' => $dailyTotal >= DAILY_TRADING_LIMIT
    ]);
    exit;
}

// API: Price
if ($path == 'api/price' && $method == 'GET') {
    header('Content-Type: application/json');
    
    list($inrPool, $mrxPool) = readMarket();
    
    if ($mrxPool <= 0) {
        echo json_encode(['success' => false, 'price' => 0, 'inr_pool' => $inrPool, 'mrx_pool' => $mrxPool, 'error' => 'Invalid market state']);
        exit;
    }
    
    $price = round($inrPool / $mrxPool, 4);
    echo json_encode([
        'success' => true,
        'price' => $price,
        'inr_pool' => round($inrPool, 2),
        'mrx_pool' => round($mrxPool, 6),
        'min_inr_pool' => MIN_INR_POOL,
        'inr_pool_above_min' => $inrPool >= MIN_INR_POOL
    ]);
    exit;
}

// API: User Transactions
if ($path == 'api/user/transactions' && $method == 'GET') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user'])) {
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
    
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    $transactions = getUserTransactions($_SESSION['user'], $limit);
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions,
        'count' => count($transactions)
    ]);
    exit;
}

// API: Deposit Request
if ($path == 'api/deposit-request' && $method == 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    
    $amount = floatval($data['amount'] ?? 0);
    $txnId = trim($data['transaction_id'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $method = $data['payment_method'] ?? 'upi';
    
    if ($amount < 100 || $amount > 100000) {
        echo json_encode(['success' => false, 'error' => 'Amount must be between ₹500 and ₹100,000']);
        exit;
    }
    
    if (strlen($txnId) < 5) {
        echo json_encode(['success' => false, 'error' => 'Invalid transaction ID (min 5 characters)']);
        exit;
    }
    
    $userEmail = $_SESSION['user'];
    $user = getUser($userEmail);
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    $requestId = saveDepositRequest($userEmail, $amount, $txnId, $phone, $method);
    
    echo json_encode([
        'success' => true,
        'message' => 'Deposit request submitted successfully',
        'request_id' => $requestId,
        'status' => 'pending',
        'amount' => $amount
    ]);
    exit;
}

// API: Deposit History
if ($path == 'api/deposit-history' && $method == 'GET') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $deposits = getUserDepositRequests($_SESSION['user'], $limit);
    
    echo json_encode([
        'success' => true,
        'deposits' => $deposits,
        'count' => count($deposits)
    ]);
    exit;
}

// API: Withdraw
if ($path == 'api/withdraw' && $method == 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $amount = floatval($data['amount'] ?? 0);
    $bankName = $data['bank_name'] ?? '';
    $accountNumber = $data['account_number'] ?? '';
    $ifscCode = $data['ifsc_code'] ?? '';
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid amount']);
        exit;
    }
    
    if (!$bankName || !$accountNumber || !$ifscCode) {
        echo json_encode(['success' => false, 'error' => 'All bank details are required']);
        exit;
    }
    
    $user = getUser($_SESSION['user']);
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    $currentInr = floatval($user[6]);
    
    if ($currentInr < $amount) {
        echo json_encode(['success' => false, 'error' => 'Insufficient balance']);
        exit;
    }
    
    list($inrPool, $mrxPool) = readMarket();
    $newInrPool = $inrPool - $amount;
    
    if ($newInrPool < MIN_INR_POOL) {
        echo json_encode(['success' => false, 'error' => "Withdrawal not allowed. Would bring INR pool below ₹" . MIN_INR_POOL . ". Current pool: ₹{$inrPool}, Requested: ₹{$amount}"]);
        exit;
    }
    
    $requestId = saveWithdrawRequest($_SESSION['user'], $user[1], $amount, $bankName, $accountNumber, $ifscCode);
    
    if (!$requestId) {
        echo json_encode(['success' => false, 'error' => 'Failed to create withdrawal request']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'request_id' => $requestId,
        'message' => "Withdrawal request submitted successfully. ₹{$amount} deducted from your wallet.",
        'amount_requested' => $amount,
        'tax_amount' => 0,
        'amount_after_tax' => $amount,
        'current_balance' => round($currentInr - $amount, 2),
        'note' => 'Amount will be refunded if request is rejected by admin.'
    ]);
    exit;
}

// API: Withdrawal Requests
if ($path == 'api/withdrawal-requests' && $method == 'GET') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user'])) {
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
    
    $withdrawalRequests = getUserWithdrawalRequests($_SESSION['user']);
    
    echo json_encode([
        'success' => true,
        'withdrawal_requests' => $withdrawalRequests,
        'count' => count($withdrawalRequests)
    ]);
    exit;
}

// API: Daily Limit
if ($path == 'api/daily-limit' && $method == 'GET') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user'])) {
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
    
    $dailyTotal = getUserDailyTrades($_SESSION['user']);
    $dailyRemaining = DAILY_TRADING_LIMIT - $dailyTotal;
    
    echo json_encode([
        'success' => true,
        'daily_limit' => DAILY_TRADING_LIMIT,
        'daily_total' => round($dailyTotal, 2),
        'daily_remaining' => round($dailyRemaining, 2),
        'limit_reached' => $dailyTotal >= DAILY_TRADING_LIMIT,
        'date' => date('Y-m-d')
    ]);
    exit;
}

// ============================================================================
// ADMIN API ROUTES
// ============================================================================

// API: Admin Dashboard Stats
if ($path == 'api/admin/dashboard-stats' && $method == 'GET') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    $stats = getDashboardStats();
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'timestamp' => time()
    ]);
    exit;
}

// API: Admin Users
if ($path == 'api/admin/users' && $method == 'GET') {
    header('Content-Type: application/json');
    
    try {
        if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
        
        $users = getAllUsers();
        list($inrPool, $mrxPool) = readMarket();
        $price = $mrxPool > 0 ? $inrPool / $mrxPool : 0;
        
        foreach ($users as &$user) {
            $internalMrx = getInternalMrxBalance($user['email']);
            $user['total_value'] = round($user['inr_balance'] + ($internalMrx * $price), 2);
            $user['is_admin'] = isAdmin($user['email']);
            $user['internal_mrx_balance'] = $internalMrx;
            $user['daily_trades_today'] = round(getUserDailyTrades($user['email']), 2);
            $user['daily_remaining'] = round(DAILY_TRADING_LIMIT - $user['daily_trades_today'], 2);
            $user['daily_limit_reached'] = $user['daily_trades_today'] >= DAILY_TRADING_LIMIT;
        }
        
        echo json_encode([
            'success' => true,
            'users' => $users,
            'count' => count($users),
            'market_price' => round($price, 4),
            'daily_trading_limit' => DAILY_TRADING_LIMIT
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// API: Admin User Detail
if (preg_match('#^api/admin/user/(.+)$#', $path, $matches) && $method == 'GET') {
    header('Content-Type: application/json');
    
    try {
        if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
        
        $userEmail = urldecode($matches[1]);
        $userData = getUser($userEmail);
        if (!$userData) {
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        
        $transactions = getUserTransactions($userEmail, 20);
        $depositRequests = getUserDepositRequests($userEmail, 10);
        $withdrawalRequests = getUserWithdrawalRequests($userEmail);
        $internalMrxBalance = getInternalMrxBalance($userEmail);
        
        $dailyTrades = getUserDailyTrades($userEmail);
        $dailyRemaining = DAILY_TRADING_LIMIT - $dailyTrades;
        
        list($inrPool, $mrxPool) = readMarket();
        $marketPrice = $mrxPool > 0 ? $inrPool / $mrxPool : 0;
        
        echo json_encode([
            'success' => true,
            'user' => [
                'user_id' => $userData[0],
                'full_name' => $userData[1],
                'email' => $userData[2],
                'mobile' => $userData[3],
                'inr_balance' => floatval($userData[6]),
                'mrx_balance' => 0,
                'internal_mrx_balance' => $internalMrxBalance,
                'created_at' => intval($userData[8]),
                'referral' => $userData[5] ?? '',
                'daily_trades_today' => round($dailyTrades, 2),
                'daily_remaining' => round($dailyRemaining, 2),
                'daily_limit_reached' => $dailyTrades >= DAILY_TRADING_LIMIT
            ],
            'transactions' => $transactions,
            'deposit_requests' => $depositRequests,
            'withdrawal_requests' => $withdrawalRequests,
            'transaction_count' => count($transactions),
            'market_price' => round($marketPrice, 4)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// API: Admin Update Deposit Status
if ($path == 'api/admin/update-deposit-status' && $method == 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $requestId = trim($data['request_id'] ?? '');
    $status = trim($data['status'] ?? '');
    
    if (!$requestId) {
        echo json_encode(['success' => false, 'error' => 'Request ID required']);
        exit;
    }
    
    if (!in_array($status, ['approved', 'rejected', 'pending'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit;
    }
    
    $updated = updateDepositRequestStatus($requestId, $status, $_SESSION['user']);
    
    if (!$updated) {
        echo json_encode(['success' => false, 'error' => 'Deposit request not found']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Deposit request {$requestId} updated to '{$status}'",
        'request_id' => $requestId,
        'status' => $status
    ]);
    exit;
}

// API: Admin Update Withdrawal Status
if ($path == 'api/admin/update-withdrawal-status' && $method == 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $requestId = trim($data['request_id'] ?? '');
    $status = trim($data['status'] ?? '');
    $remarks = trim($data['remarks'] ?? '');
    
    if (!$requestId) {
        echo json_encode(['success' => false, 'error' => 'Request ID required']);
        exit;
    }
    
    if (!in_array($status, ['approved', 'rejected', 'processing', 'pending', 'processed'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit;
    }
    
    $updated = updateWithdrawalRequestStatus($requestId, $status, $_SESSION['user'], $remarks);
    
    if (!$updated) {
        echo json_encode(['success' => false, 'error' => 'Withdrawal request not found']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Withdrawal request {$requestId} updated to '{$status}'",
        'request_id' => $requestId,
        'status' => $status
    ]);
    exit;
}

// API: Admin Deposit Requests
if ($path == 'api/admin/deposit-requests' && $method == 'GET') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    $statusFilter = $_GET['status'] ?? '';
    
    $depositRequests = getAllDepositRequests($limit);
    
    if ($statusFilter) {
        $depositRequests = array_filter($depositRequests, function($req) use ($statusFilter) {
            return $req['status'] == $statusFilter;
        });
    }
    
    echo json_encode([
        'success' => true,
        'deposit_requests' => array_values($depositRequests),
        'count' => count($depositRequests),
        'filter_status' => $statusFilter
    ]);
    exit;
}

// API: Admin Withdrawal Requests
if ($path == 'api/admin/withdrawal-requests' && $method == 'GET') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 1000;
    $statusFilter = $_GET['status'] ?? '';
    $search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
    
    $allRequests = getAllWithdrawalRequests($limit);
    
    if ($search) {
        $filtered = [];
        foreach ($allRequests as $req) {
            if (strpos(strtolower($req['user_email'] ?? ''), $search) !== false ||
                strpos(strtolower($req['user_name'] ?? ''), $search) !== false ||
                strpos(strtolower($req['request_id'] ?? ''), $search) !== false ||
                strpos(strtolower($req['bank_name'] ?? ''), $search) !== false ||
                strpos(strtolower($req['account_number'] ?? ''), $search) !== false ||
                strpos(strtolower($req['ifsc_code'] ?? ''), $search) !== false) {
                $filtered[] = $req;
            }
        }
        $allRequests = $filtered;
    }
    
    if ($statusFilter && $statusFilter != 'all') {
        $allRequests = array_filter($allRequests, function($req) use ($statusFilter) {
            return ($req['status'] ?? '') == $statusFilter;
        });
    }
    
    $stats = getWithdrawalStats();
    
    $formattedRequests = [];
    foreach ($allRequests as $req) {
        $accountNumber = $req['account_number'] ?? '';
        $maskedAccount = strlen($accountNumber) >= 4 ? '****' . substr($accountNumber, -4) : '****';
        
        $formattedRequests[] = [
            'request_id' => $req['request_id'] ?? '',
            'user_email' => $req['user_email'] ?? '',
            'user_name' => $req['user_name'] ?? '',
            'amount' => floatval($req['amount'] ?? 0),
            'status' => $req['status'] ?? 'pending',
            'bank_name' => $req['bank_name'] ?? '',
            'account_number' => $req['account_number'] ?? '',
            'masked_account' => $maskedAccount,
            'ifsc_code' => $req['ifsc_code'] ?? '',
            'created_at' => intval($req['created_at'] ?? time()),
            'processed_at' => isset($req['processed_at']) ? intval($req['processed_at']) : null,
            'remarks' => $req['remarks'] ?? ''
        ];
    }
    
    echo json_encode([
        'success' => true,
        'withdrawal_requests' => $formattedRequests,
        'stats' => $stats,
        'count' => count($formattedRequests),
        'filter_status' => $statusFilter,
        'search_query' => $search
    ]);
    exit;
}

// API: Admin Tax Stats
if ($path == 'api/admin/tax-stats' && $method == 'GET') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    $taxStats = getTaxCollectionStats();
    
    echo json_encode([
        'success' => true,
        'tax_stats' => $taxStats,
        'timestamp' => time()
    ]);
    exit;
}

// API: Admin Update Pool
if ($path == 'api/admin/update-pool' && $method == 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    
    $newInrPool = floatval($data['inr_pool'] ?? 0);
    $newMrxPool = floatval($data['mrx_pool'] ?? 0);
    
    if ($newMrxPool <= 0) {
        echo json_encode(['success' => false, 'error' => 'MRX pool cannot be zero or negative']);
        exit;
    }
    
    if ($newInrPool < 0) {
        echo json_encode(['success' => false, 'error' => 'INR pool cannot be negative']);
        exit;
    }
    
    $newPrice = $newInrPool / $newMrxPool;
    
    if ($newPrice < PRICE_FLOOR) {
        echo json_encode(['success' => false, 'error' => "Cannot set pool. Price would be ₹{$newPrice} which is below ₹" . PRICE_FLOOR . " floor"]);
        exit;
    }
    
    if ($newInrPool < MIN_INR_POOL) {
        echo json_encode(['success' => false, 'error' => "Cannot set pool. INR pool would be ₹{$newInrPool} which is below minimum ₹" . MIN_INR_POOL]);
        exit;
    }
    
    list($oldInrPool, $oldMrxPool) = readMarket();
    $oldPrice = $oldMrxPool > 0 ? $oldInrPool / $oldMrxPool : 0;
    
    writeMarket($newInrPool, $newMrxPool);
    
    logAdminAction($_SESSION['user'], 'update_pool', 'market', 'pool', "Updated pool from ₹{$oldInrPool}/{$oldMrxPool} MRX (₹{$oldPrice}) to ₹{$newInrPool}/{$newMrxPool} MRX (₹{$newPrice})");
    
    echo json_encode([
        'success' => true,
        'new_price' => round($newPrice, 4),
        'inr_pool' => round($newInrPool, 2),
        'mrx_pool' => round($newMrxPool, 6),
        'old_price' => round($oldPrice, 4),
        'price_floor' => PRICE_FLOOR,
        'above_floor' => $newPrice >= PRICE_FLOOR,
        'min_inr_pool' => MIN_INR_POOL,
        'inr_pool_above_min' => $newInrPool >= MIN_INR_POOL,
        'message' => "Pool updated successfully. New price: ₹{$newPrice}"
    ]);
    exit;
}

// ============================================================================
// PAGE ROUTES
// ============================================================================

// Login Page
if ($path == '' || $path == 'login.php') {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        
        $user = getUser($email);
        if ($user && $user[4] == $password) {
            $_SESSION['user'] = $email;
            if (isAdmin($email)) {
                header('Location: admin/dashboard.php');
            } else {
                header('Location: trade.php');
            }
            exit;
        } else {
            $error = 'Invalid credentials';
        }
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Login - United World</title>
        <style>
            body { background: #0a0b0e; display: flex; justify-content: center; align-items: center; height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
            .login-box { background: #1e2128; padding: 40px; border-radius: 12px; width: 100%; max-width: 400px; border: 1px solid #2a2d36; }
            h1 { color: #00c853; margin-bottom: 30px; text-align: center; }
            .form-group { margin-bottom: 20px; }
            label { display: block; margin-bottom: 8px; color: #9e9e9e; }
            input { width: 100%; padding: 12px; background: #2a2d36; border: 1px solid #3a3f4a; border-radius: 6px; color: white; }
            button { width: 100%; padding: 14px; background: #00c853; color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; }
            .error { color: #f44336; margin-bottom: 20px; text-align: center; }
            .links { text-align: center; margin-top: 20px; }
            .links a { color: #00c853; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>🇺🇳 United World</h1>
            <?php if (isset($error)): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit">Login</button>
            </form>
            <div class="links">
                <a href="signup.php">Create Account</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Signup Page
if ($path == 'signup.php') {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $fullName = $_POST['fullName'] ?? '';
        $mobile = $_POST['mobile'] ?? '';
        $referral = $_POST['referralCode'] ?? '';
        
        if (!$email || !$password || !$fullName) {
            $error = 'Missing required fields';
        } elseif (getUser($email)) {
            $error = 'Email already registered';
        } else {
            $userId = generateId(8);
            $createdAt = time();
            
            $handle = fopen(USERS_FILE, 'a');
            flock($handle, LOCK_EX);
            fwrite($handle, "{$userId}\t{$fullName}\t{$email}\t{$mobile}\t{$password}\t{$referral}\t0\t0\t{$createdAt}\n");
            flock($handle, LOCK_UN);
            fclose($handle);
            
            $_SESSION['user'] = $email;
            header('Location: trade.php');
            exit;
        }
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Sign Up - United World</title>
        <style>
            body { background: #0a0b0e; display: flex; justify-content: center; align-items: center; min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 20px; }
            .signup-box { background: #1e2128; padding: 40px; border-radius: 12px; width: 100%; max-width: 500px; border: 1px solid #2a2d36; }
            h1 { color: #00c853; margin-bottom: 30px; text-align: center; }
            .form-group { margin-bottom: 20px; }
            label { display: block; margin-bottom: 8px; color: #9e9e9e; }
            input { width: 100%; padding: 12px; background: #2a2d36; border: 1px solid #3a3f4a; border-radius: 6px; color: white; }
            button { width: 100%; padding: 14px; background: #00c853; color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; }
            .error { color: #f44336; margin-bottom: 20px; text-align: center; }
            .links { text-align: center; margin-top: 20px; }
            .links a { color: #00c853; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="signup-box">
            <h1>🇺🇳 Join United World</h1>
            <?php if (isset($error)): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="fullName" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Mobile</label>
                    <input type="tel" name="mobile" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <div class="form-group">
                    <label>Referral Code (Optional)</label>
                    <input type="text" name="referralCode">
                </div>
                <button type="submit">Create Account</button>
            </form>
            <div class="links">
                <a href="login.php">Already have an account? Login</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Logout
if ($path == 'logout.php') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Trade Page
if ($path == 'trade.php') {
    if (!isset($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
    
    $user = getUser($_SESSION['user']);
    if (!$user) {
        header('Location: logout.php');
        exit;
    }
    
    renderHeader('Trade');
    ?>
    <div class="card">
        <h1>Trade MRX</h1>
        <div id="market-data" style="margin-bottom: 20px;">
            <div class="grid">
                <div class="stat">
                    <div class="stat-value" id="price">Loading...</div>
                    <div class="stat-label">Current Price (INR/MRX)</div>
                </div>
                <div class="stat">
                    <div class="stat-value" id="balance">Loading...</div>
                    <div class="stat-label">Your Wallet Value</div>
                </div>
                <div class="stat">
                    <div class="stat-value" id="daily-limit">Loading...</div>
                    <div class="stat-label">Daily Limit Used</div>
                </div>
            </div>
        </div>
        
        <div class="grid">
            <div class="card">
                <h2>Buy MRX</h2>
                <form id="buy-form">
                    <div class="form-group">
                        <label>Amount (INR)</label>
                        <input type="number" id="amount" min="1" max="<?php echo MAX_SINGLE_ORDER; ?>" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Tax (<?php echo TAX_RATE*100; ?>%)</label>
                        <input type="text" id="tax" readonly>
                    </div>
                    <div class="form-group">
                        <label>You Receive (MRX)</label>
                        <input type="text" id="mrx-receive" readonly>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Buy MRX</button>
                </form>
            </div>
            
            <div class="card">
                <h2>Market Info</h2>
                <table>
                    <tr>
                        <td>Price Floor:</td>
                        <td>₹<?php echo PRICE_FLOOR; ?></td>
                    </tr>
                    <tr>
                        <td>Max Single Order:</td>
                        <td>₹<?php echo number_format(MAX_SINGLE_ORDER, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Daily Limit:</td>
                        <td>₹<?php echo number_format(DAILY_TRADING_LIMIT, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Tax Rate:</td>
                        <td><?php echo TAX_RATE*100; ?>%</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        async function updateMarketData() {
            const priceData = await apiCall('api/price');
            const balanceData = await apiCall('api/user-balance');
            const limitData = await apiCall('api/daily-limit');
            
            if (priceData.success) {
                document.getElementById('price').textContent = '₹' + priceData.price;
            }
            if (balanceData.success) {
                document.getElementById('balance').textContent = '₹' + balanceData.total_wallet_value;
            }
            if (limitData.success) {
                document.getElementById('daily-limit').textContent = '₹' + limitData.daily_total + ' / ₹' + limitData.daily_limit;
            }
        }
        
        document.getElementById('amount').addEventListener('input', async function() {
            const amount = parseFloat(this.value) || 0;
            const priceData = await apiCall('api/price');
            
            if (priceData.success && amount > 0) {
                const tax = amount * <?php echo TAX_RATE; ?>;
                const afterTax = amount - tax;
                const mrx = afterTax / priceData.price;
                
                document.getElementById('tax').value = '₹' + tax.toFixed(2);
                document.getElementById('mrx-receive').value = mrx.toFixed(6) + ' MRX';
            } else {
                document.getElementById('tax').value = '';
                document.getElementById('mrx-receive').value = '';
            }
        });
        
        document.getElementById('buy-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const amount = parseFloat(document.getElementById('amount').value);
            
            if (amount > <?php echo MAX_SINGLE_ORDER; ?>) {
                alert('Amount exceeds maximum single order limit');
                return;
            }
            
            const result = await apiCall('api/accelerate-order', 'POST', {
                amount: amount,
                sentiment: 'bullish'
            });
            
            if (result.success) {
                alert('Success! ' + result.summary);
                updateMarketData();
                document.getElementById('amount').value = '';
                document.getElementById('tax').value = '';
                document.getElementById('mrx-receive').value = '';
            } else {
                alert('Error: ' + result.error);
            }
        });
        
        updateMarketData();
        setInterval(updateMarketData, 5000);
    </script>
    <?php
    renderFooter();
    exit;
}

// Wallet Page
if ($path == 'wallet.php') {
    if (!isset($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
    
    $user = getUser($_SESSION['user']);
    if (!$user) {
        header('Location: logout.php');
        exit;
    }
    
    $transactions = getUserTransactions($_SESSION['user'], 20);
    $withdrawals = getUserWithdrawalRequests($_SESSION['user']);
    $deposits = getUserDepositRequests($_SESSION['user'], 10);
    
    renderHeader('Wallet');
    ?>
    <h1>My Wallet</h1>
    
    <div class="grid">
        <div class="stat">
            <div class="stat-value">₹<?php echo number_format($user[6], 2); ?></div>
            <div class="stat-label">INR Balance</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="mrx-value">Loading...</div>
            <div class="stat-label">MRX Value</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="total-value">Loading...</div>
            <div class="stat-label">Total Value</div>
        </div>
    </div>
    
    <div class="grid">
        <div class="card">
            <h2>Quick Actions</h2>
            <div style="display: flex; gap: 10px;">
                <a href="deposit.php" class="btn btn-primary">Deposit</a>
                <a href="withdraw.php" class="btn btn-danger">Withdraw</a>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h2>Recent Transactions</h2>
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Amount (INR)</th>
                    <th>MRX</th>
                    <th>Price</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $txn): ?>
                <tr>
                    <td><?php echo $txn['type']; ?></td>
                    <td>₹<?php echo number_format($txn['amount_inr'], 2); ?></td>
                    <td><?php echo number_format($txn['amount_mrx'], 6); ?></td>
                    <td>₹<?php echo number_format($txn['price'], 4); ?></td>
                    <td><?php echo date('Y-m-d H:i', $txn['timestamp']); ?></td>
                    <td><span class="badge badge-success"><?php echo $txn['status']; ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                <tr><td colspan="6" style="text-align: center;">No transactions yet</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div class="card">
        <h2>Recent Deposits</h2>
        <table>
            <thead>
                <tr>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Transaction ID</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deposits as $dep): ?>
                <tr>
                    <td>₹<?php echo number_format($dep['amount'], 2); ?></td>
                    <td><?php echo $dep['payment_method']; ?></td>
                    <td><?php echo $dep['transaction_id']; ?></td>
                    <td><?php echo date('Y-m-d H:i', $dep['created_at']); ?></td>
                    <td><span class="badge badge-<?php echo $dep['status'] == 'approved' ? 'success' : ($dep['status'] == 'pending' ? 'warning' : 'danger'); ?>"><?php echo $dep['status']; ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="card">
        <h2>Withdrawal Requests</h2>
        <table>
            <thead>
                <tr>
                    <th>Amount</th>
                    <th>Bank</th>
                    <th>Account</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($withdrawals as $wd): ?>
                <tr>
                    <td>₹<?php echo number_format($wd['amount'], 2); ?></td>
                    <td><?php echo $wd['bank_name']; ?></td>
                    <td><?php echo $wd['account_number']; ?></td>
                    <td><?php echo date('Y-m-d H:i', $wd['created_at']); ?></td>
                    <td><span class="badge badge-<?php echo $wd['status'] == 'processed' ? 'success' : ($wd['status'] == 'pending' ? 'warning' : 'danger'); ?>"><?php echo $wd['status']; ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <script>
        async function updateWalletValue() {
            const data = await apiCall('api/user-balance');
            if (data.success) {
                document.getElementById('mrx-value').textContent = '₹' + (data.total_wallet_value - data.inr_balance).toFixed(2);
                document.getElementById('total-value').textContent = '₹' + data.total_wallet_value;
            }
        }
        updateWalletValue();
    </script>
    <?php
    renderFooter();
    exit;
}

// Admin Dashboard
if ($path == 'admin/dashboard.php') {
    if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
        header('Location: ../login.php');
        exit;
    }
    
    renderHeader('Admin Dashboard', true);
    ?>
    <h1>Admin Dashboard</h1>
    
    <div class="grid" id="stats-grid">
        <div class="stat">
            <div class="stat-value" id="total-users">Loading...</div>
            <div class="stat-label">Total Users</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="current-price">Loading...</div>
            <div class="stat-label">MRX Price</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="inr-pool">Loading...</div>
            <div class="stat-label">INR Pool</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="mrx-pool">Loading...</div>
            <div class="stat-label">MRX Pool</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="pending-deposits">Loading...</div>
            <div class="stat-label">Pending Deposits</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="pending-withdrawals">Loading...</div>
            <div class="stat-label">Pending Withdrawals</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="total-tax">Loading...</div>
            <div class="stat-label">Total Tax Collected</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="liquidity">Loading...</div>
            <div class="stat-label">Liquidity Health</div>
        </div>
    </div>
    
    <div class="grid">
        <div class="card">
            <h2>Quick Actions</h2>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="deposits.php" class="btn btn-primary">Manage Deposits</a>
                <a href="withdrawals.php" class="btn btn-primary">Manage Withdrawals</a>
                <a href="pool.php" class="btn btn-primary">Pool Control</a>
                <a href="users.php" class="btn btn-primary">Manage Users</a>
                <a href="tax.php" class="btn btn-primary">Tax Reports</a>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h2>Recent Admin Logs</h2>
        <table id="logs-table">
            <thead>
                <tr>
                    <th>Admin</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Details</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="5">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    
    <div class="card">
        <h2>Recent Transactions</h2>
        <table id="transactions-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Price</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="5">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    
    <script>
        async function loadDashboard() {
            const data = await apiCall('api/admin/dashboard-stats');
            if (data.success) {
                const s = data.stats;
                document.getElementById('total-users').textContent = s.total_users;
                document.getElementById('current-price').textContent = '₹' + s.current_price;
                document.getElementById('inr-pool').textContent = '₹' + s.inr_pool;
                document.getElementById('mrx-pool').textContent = s.mrx_pool;
                document.getElementById('pending-deposits').textContent = s.pending_deposits;
                document.getElementById('pending-withdrawals').textContent = s.pending_withdrawals;
                document.getElementById('total-tax').textContent = '₹' + s.tax_stats.total_tax;
                document.getElementById('liquidity').textContent = s.liquidity_health;
                
                // Logs table
                let logsHtml = '';
                s.recent_logs.forEach(log => {
                    logsHtml += `<tr>
                        <td>${log.admin_email}</td>
                        <td>${log.action}</td>
                        <td>${log.target_type}: ${log.target_id}</td>
                        <td>${log.details}</td>
                        <td>${new Date(log.timestamp * 1000).toLocaleString()}</td>
                    </tr>`;
                });
                document.querySelector('#logs-table tbody').innerHTML = logsHtml || '<tr><td colspan="5">No logs</td></tr>';
                
                // Transactions table
                let txnHtml = '';
                s.recent_transactions.forEach(txn => {
                    txnHtml += `<tr>
                        <td>${txn.user_email}</td>
                        <td>${txn.type}</td>
                        <td>₹${txn.amount_inr}</td>
                        <td>₹${txn.price}</td>
                        <td>${new Date(txn.timestamp * 1000).toLocaleString()}</td>
                    </tr>`;
                });
                document.querySelector('#transactions-table tbody').innerHTML = txnHtml || '<tr><td colspan="5">No transactions</td></tr>';
            }
        }
        
        loadDashboard();
        setInterval(loadDashboard, 10000);
    </script>
    <?php
    renderFooter();
    exit;
}

// Admin Pool Control
if ($path == 'admin/pool.php') {
    if (!isset($_SESSION['user']) || !isAdmin($_SESSION['user'])) {
        header('Location: ../login.php');
        exit;
    }
    
    list($inrPool, $mrxPool) = readMarket();
    $currentPrice = $mrxPool > 0 ? $inrPool / $mrxPool : 0;
    
    renderHeader('Pool Control', true);
    ?>
    <h1>Pool Control</h1>
    
    <div class="grid">
        <div class="stat">
            <div class="stat-value">₹<?php echo number_format($inrPool, 2); ?></div>
            <div class="stat-label">Current INR Pool</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?php echo number_format($mrxPool, 6); ?></div>
            <div class="stat-label">Current MRX Pool</div>
        </div>
        <div class="stat">
            <div class="stat-value">₹<?php echo number_format($currentPrice, 4); ?></div>
            <div class="stat-label">Current Price</div>
        </div>
    </div>
    
    <div class="card">
        <h2>Adjust Pool</h2>
        <form id="pool-form">
            <div class="form-group">
                <label>INR Pool (₹)</label>
                <input type="number" id="inr-pool" value="<?php echo $inrPool; ?>" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label>MRX Pool</label>
                <input type="number" id="mrx-pool" value="<?php echo $mrxPool; ?>" step="0.000001" min="0.000001" required>
            </div>
            <div class="form-group">
                <label>New Price (calculated)</label>
                <input type="text" id="new-price" readonly>
            </div>
            <div class="form-group">
                <label>Status</label>
                <div id="price-status"></div>
            </div>
            <button type="submit" class="btn btn-primary">Update Pool</button>
        </form>
    </div>
    
    <script>
        const priceFloor = <?php echo PRICE_FLOOR; ?>;
        const minInrPool = <?php echo MIN_INR_POOL; ?>;
        
        function calculatePrice() {
            const inr = parseFloat(document.getElementById('inr-pool').value) || 0;
            const mrx = parseFloat(document.getElementById('mrx-pool').value) || 0.000001;
            const price = inr / mrx;
            document.getElementById('new-price').value = '₹' + price.toFixed(4);
            
            const statusEl = document.getElementById('price-status');
            if (price < priceFloor) {
                statusEl.innerHTML = '<span class="badge badge-danger">⚠️ Below price floor (₹' + priceFloor + ')</span>';
            } else if (inr < minInrPool) {
                statusEl.innerHTML = '<span class="badge badge-danger">⚠️ Below minimum INR pool (₹' + minInrPool + ')</span>';
            } else {
                statusEl.innerHTML = '<span class="badge badge-success">✅ Valid</span>';
            }
        }
        
        document.getElementById('inr-pool').addEventListener('input', calculatePrice);
        document.getElementById('mrx-pool').addEventListener('input', calculatePrice);
        calculatePrice();
        
        document.getElementById('pool-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const inr = parseFloat(document.getElementById('inr-pool').value);
            const mrx = parseFloat(document.getElementById('mrx-pool').value);
            
            const result = await apiCall('api/admin/update-pool', 'POST', {
                inr_pool: inr,
                mrx_pool: mrx
            });
            
            if (result.success) {
                alert('Pool updated successfully! New price: ₹' + result.new_price);
                location.reload();
            } else {
                alert('Error: ' + result.error);
            }
        });
    </script>
    <?php
    renderFooter();
    exit;
}

// 404 Not Found
header("HTTP/1.0 404 Not Found");
echo "<h1>404 Not Found</h1>";
exit;