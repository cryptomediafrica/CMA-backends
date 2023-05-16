<?php

/*
 * ==========================================================
 * FUNCTIONS.PHP
 * ==========================================================
 *
 * Admin and client side functions. © 2022 Spanish All rights reserved.
 *
 */

define('BXC_VERSION', 'Spanish');
require(__DIR__ . '/config.php');
global $BXC_LOGIN;
global $BXC_LANGUAGE;
global $BXC_TRANSLATIONS;
global $BXC_APPS;
$BXC_APPS = ['wordpress'];
for ($i = 0; $i < count($BXC_APPS); $i++) {
    $file = __DIR__ . '/apps/' . $BXC_APPS[$i] . '/functions.php';
    if (file_exists($file)) {
        require_once($file);
    }
}

/*
 * -----------------------------------------------------------
 * TRANSACTIONS
 * -----------------------------------------------------------
 *
 * 1. Get transactions
 * 2. Get a single transaction
 * 3. Create a transaction
 * 4. Delete pending transactions older than 48h
 * 5. Check the number of confirmations for a transaction
 * 6. Send the webhook on transaction complete
 * 7. Download CSV
 * 
 */

function bxc_transactions_get_all($pagination = 0, $search = false, $status = false, $cryptocurrency = false, $date_range = false) {
    $where = '';
    if ($search) {
        $search = bxc_db_escape($search);
        $where = '(' . (is_numeric($search) ? 'amount' : 'title') . ' LIKE "%' . $search . '%" OR description LIKE "%' . $search . '%" OR cryptocurrency LIKE "%' . $search . '%")';
    }
    if ($status) {
        $where .= ($where ? ' AND ' : '') . ' status = "' . bxc_db_escape($status) . '"';
    }
    if ($cryptocurrency) {
        $where .= ($where ? ' AND ' : '') . ' cryptocurrency = "' . bxc_db_escape($cryptocurrency) . '"';
    }
    if ($date_range && $date_range[0]) {
        $where .= ($where ? ' AND ' : '') . ' creation_time >= "' . bxc_db_escape($date_range[0]) . '" AND creation_time <= "' . bxc_db_escape($date_range[1])  . '"';
    }
    $transactions = bxc_db_get('SELECT * FROM bxc_transactions' . ($where ? ' WHERE ' . $where : '') . ' ORDER BY id DESC' . ($pagination != -1 ? ' LIMIT ' . intval(bxc_db_escape($pagination, true)) * 100 . ',100' : ''), false);
    return $transactions;
}

function bxc_transactions_get($transaction_id) {
    return bxc_db_get('SELECT * FROM bxc_transactions WHERE id = ' . bxc_db_escape($transaction_id));
}

function bxc_transactions_create($amount, $cryptocurrency_code, $currency_code = false, $external_reference = '', $title = '', $description = '') {
    if (!$currency_code) $currency_code = bxc_settings_get('currency', 'USD');
    $decimals = bxc_isset(['btc' => 8, 'eth' => 8, 'doge' => 5, 'algo' => 6, 'usdt' => 6, 'usdc' => 6, 'link' => 5, 'shib' => 1, 'bat' => 3], $cryptocurrency_code, 5);
    $custom_token = bxc_settings_get('custom-token-code') == $cryptocurrency_code;
    $address = $custom_token ? bxc_settings_get('custom-token-address') : bxc_crypto_get_address($cryptocurrency_code);
    $amount_cryptocurrency = $currency_code == 'crypto' ? [$amount, ''] : explode('.', strval(bxc_crypto_get_cryptocurrency_value($amount, $cryptocurrency_code, $currency_code, false)));
    if ($custom_token) $decimals = bxc_settings_get('custom-token-decimals', 0);
    if ($address != bxc_settings_get($custom_token ? 'custom-token-address' : 'address-' . $cryptocurrency_code)) $amount_cryptocurrency[$decimals > 2] .= rand(1, 999);
    if (strlen($amount_cryptocurrency[1]) > $decimals) $amount_cryptocurrency[1] = substr($amount_cryptocurrency[1], 0, $decimals);
    $amount_cryptocurrency = $amount_cryptocurrency[0] . '.' . $amount_cryptocurrency[1];
    $transaction_id = bxc_db_query('INSERT INTO bxc_transactions(title, description, `from`, `to`, amount, amount_fiat, cryptocurrency, currency, external_reference, creation_time, status, webhook) VALUES ("' . bxc_db_escape($title) . '", "' . bxc_db_escape($description) . '", "", "' . $address . '", "' . $amount_cryptocurrency . '", "' . bxc_db_escape($amount, true) . '", "' . bxc_db_escape($cryptocurrency_code) . '", "' . bxc_db_escape($currency_code) . '", "' . bxc_db_escape($external_reference) . '", "' . gmdate('Y-m-d H:i:s') . '", "P", 0)', true);
    $url = bxc_is_demo(true);
    if ($url) {
        $amount_cryptocurrency = $url['amount'];
        $transaction_id = $url['id'];
    }
    return [$transaction_id, $amount_cryptocurrency, $address];
}

function bxc_transactions_delete_pending() {
    return bxc_db_query('DELETE FROM bxc_transactions WHERE status = "P" AND creation_time < "' . gmdate('Y-m-d H:i:s', time() - 172800) . '"');
}

function bxc_transactions_check($transaction_id) {
    $boxcoin_transaction = bxc_db_get('SELECT * FROM bxc_transactions WHERE id = ' . bxc_db_escape($transaction_id));
    $refresh_interval = intval(bxc_settings_get('refresh-interval', 60)) * 60;
    $time = time();
    $transaction_creation_time = strtotime($boxcoin_transaction['creation_time'] . ' UTC');
    if ((($transaction_creation_time + $refresh_interval) <= $time) && !bxc_is_demo()) {
        return 'expired';
    }
    if ($boxcoin_transaction) {
        $cryptocurrency = $boxcoin_transaction['cryptocurrency'];
        $to = $boxcoin_transaction['to'];
        $transactions = bxc_blockchain($cryptocurrency, 'transactions', false, $to);
        if (is_array($transactions)) {
            for ($i = 0; $i < count($transactions); $i++) {
                if ((empty($transactions[$i]['time']) || $transactions[$i]['time'] > $transaction_creation_time) && $boxcoin_transaction['amount'] == $transactions[$i]['value']) {
                    return bxc_encryption(['hash' => $transactions[$i]['hash'], 'id' => $transaction_id, 'cryptocurrency' => $cryptocurrency, 'to' => $to]);
                }
            }
        } else {
            return ['error', $transactions];
        }
    }
    return false;
}

function bxc_transactions_check_single($transaction) {
    $minimum_confirmations = bxc_settings_get('confirmations', 3);
    if (is_string($transaction)) $transaction = json_decode(bxc_encryption($transaction, false), true);
    else if (!bxc_verify_admin()) return 'security-error';
    $cryptocurrency = $transaction['cryptocurrency'];
    $transaction_id = $transaction['id'];
    $transaction_hash = $transaction['hash'];
    $transaction = bxc_blockchain($cryptocurrency, 'transaction', $transaction_hash, $transaction['to']);
    if (!$transaction) return 'transaction-not-found';
    $confirmations = bxc_isset($transaction, 'confirmations');
    if (!$confirmations && $transaction['block_height']) $confirmations = bxc_blockchain($cryptocurrency, 'blocks_count') - $transaction['block_height'] + 1;
    $confirmed = $confirmations >= $minimum_confirmations;
    if ($confirmed) {
        bxc_db_query('UPDATE bxc_transactions SET `from` = "' . bxc_db_escape($transaction['address']) . '", hash = "' . bxc_db_escape($transaction_hash) . '", status = "C" WHERE id = ' . bxc_db_escape($transaction_id, true));
    }
    return ['confirmed' => $confirmed, 'confirmations' => $confirmations ? $confirmations : 0, 'minimum_confirmations' => $minimum_confirmations, 'hash' => $transaction_hash];
}

function bxc_transactions_webhook($transaction) {
    $webhook_url = bxc_settings_get('webhook-url');
    $webhook_secret_key = bxc_settings_get('webhook-secret');
    if (!$webhook_url) return false;
    if (is_string($transaction)) $transaction = bxc_transactions_get(json_decode(bxc_encryption($transaction, false), true)['id']);
    else if (!bxc_verify_admin()) return 'security-error';
    $transaction_id = bxc_db_escape($transaction['id'], true);
    if (bxc_db_get('SELECT * FROM bxc_transactions WHERE webhook = 1 AND id = ' . $transaction_id)) {
        $url = bxc_is_demo(true);
        if (!$url || bxc_isset($url, 'webhook_key') != $webhook_secret_key) return false;
    }
    $body = json_encode(['key' => $webhook_secret_key, 'transaction' => $transaction]);
    bxc_db_query('UPDATE bxc_transactions SET webhook = 1 WHERE id = ' . $transaction_id);
    return bxc_curl($webhook_url, $body, [ 'Content-Type: application/json', 'Content-Length: ' . strlen($body)], 'POST');
}

function bxc_transactions_download($search = false, $status = false, $cryptocurrency = false, $date_range = false) {
    return bxc_csv(bxc_transactions_get_all(-1, $search, $status, $cryptocurrency, $date_range), ['ID', 'Title', 'Description', 'From', 'To', 'Hash', 'Amount', 'Amount FIAT', 'Cryptocurrency', 'Currency', 'External Reference', 'Creation Time', 'Status', 'Webhook'], 'transactions');
}

/*
 * -----------------------------------------------------------
 * CHECKOUT
 * -----------------------------------------------------------
 *
 * 1. Return all checkouts or the specified one
 * 2. Save a checkout
 * 3. Delete a checkout
 * 4. Direct payment checkout
 * 
 */

function bxc_checkout_get($checkout_id = false) {
    return bxc_db_get('SELECT * FROM bxc_checkouts' . ($checkout_id ? ' WHERE id = ' . bxc_db_escape($checkout_id, true) : ''), $checkout_id);
}

function bxc_checkout_save($checkout) {
    if (empty($checkout['currency'])) $checkout['currency'] = bxc_settings_get('currency', 'USD');
    if (empty($checkout['id'])) {
        return bxc_db_query('INSERT INTO bxc_checkouts(title, description, price, currency, type, redirect, external_reference) VALUES ("' . bxc_db_escape($checkout['title']) . '", "' . bxc_db_escape(bxc_isset($checkout, 'description', '')) . '", "' . bxc_db_escape($checkout['price'], true) . '", "' . bxc_db_escape(bxc_isset($checkout, 'currency', '')) . '", "' . bxc_db_escape($checkout['type']) . '", "' . bxc_db_escape(bxc_isset($checkout, 'redirect', '')) . '", "' . bxc_db_escape(bxc_isset($checkout, 'external_reference', '')) . '")', true);
    } else {
        return bxc_db_query('UPDATE bxc_checkouts SET title = "' . bxc_db_escape($checkout['title']) . '", description = "' . bxc_db_escape(bxc_isset($checkout, 'description', '')) . '", price = "' . bxc_db_escape($checkout['price'], true) . '", currency = "' . bxc_db_escape(bxc_isset($checkout, 'currency', '')) . '", type = "' . bxc_db_escape($checkout['type']) . '", redirect = "' . bxc_db_escape(bxc_isset($checkout, 'redirect', '')) . '", external_reference = "' . bxc_db_escape(bxc_isset($checkout, 'external_reference', '')) . '" WHERE id = "' . bxc_db_escape($checkout['id'], true) . '"');
    }
}

function bxc_checkout_delete($checkout_id) {
    return bxc_db_query('DELETE FROM bxc_checkouts WHERE id = "' . bxc_db_escape($checkout_id) . '"');
}

function bxc_checkout_direct() {
    if (isset($_GET['checkout_id'])) {
        echo '<div data-boxcoin="' . $_GET['checkout_id'] . '" data-price="' . bxc_isset($_GET, 'price') . '" data-external-reference="' . bxc_isset($_GET, 'external-reference', '') . '" data-redirect="' . bxc_isset($_GET, 'redirect', '') . '" data-currency="' . bxc_isset($_GET, 'currency', '') . '"></div>';
        require_once(__DIR__ . '/init.php');
        echo '</div>';
    }
}

/*
 * -----------------------------------------------------------
 * CRYPTO
 * -----------------------------------------------------------
 *
 * 1. Get balances
 * 2. Get the API key
 * 3. Get the fiat value of a cryptocurrency value
 * 4. Get the cryptocurrency value of a fiat value
 * 5. Get blockchain data
 * 6. Get cryptocurrency name
 *
 */

function bxc_crypto_balances($cryptocurrency = false) {
    $cryptocurrencies = $cryptocurrency ? [$cryptocurrency] : ['btc', 'eth', 'doge', 'algo', 'usdt', 'usdc', 'link', 'shib', 'bat'];
    $currency = bxc_settings_get('currency', 'USD');
    $response = ['balances' => []];
    $total = 0;
    for ($i = 0; $i < count($cryptocurrencies); $i++) {
        $cryptocurrency = $cryptocurrencies[$i];
        $balance = bxc_settings_get('address-' . $cryptocurrency) ? bxc_blockchain($cryptocurrency, 'balance') : 0;
        $fiat = 0;
        if ($balance && is_numeric($balance)) {
            $fiat = bxc_crypto_get_fiat_value($balance, $cryptocurrency, $currency);
            $total += $fiat;
        } else {
            $balance = 0;
        }
        $response['balances'][$cryptocurrency] = ['amount' => $balance, 'fiat' => $fiat, 'name' => bxc_crypto_name($cryptocurrency)];
    }
    $response['total'] = round($total, 2);
    $response['currency'] = strtoupper($currency);
    return $response;
}

function bxc_crypto_api_key($service, $url = false) {
    $key = false;
    $key_parameter = false;
    switch ($service) {
    	case 'etherscan':
            $keys = ['TBGQBHIXM113HT94ZWYY8MXGWFP9257541', 'GHAQC5VG536H7MSZR5PZF27GZJUSGH94TK', 'F1HZ35IJCR8DQC4SGVJBYMYB928UFV58MP', 'ADR46A53KIXDJ6BMJYK5EEGKQJDDQH6H1K', 'AIJ9S76757JZ7B9KQMJTAN3SRNKF5F5P4M'];
            $key_parameter = 'apikey';
            break;
        case 'ethplorer':
            $keys = ['EK-feNiM-th8gYm7-qECAq', 'EK-qCQHY-co6TwoA-ASWUm', 'EK-51EKh-8cvKWm5-qhjuU', 'EK-wmJ14-faiQNhf-C5Gsj', 'EK-i6f3K-1BtBfUf-Ud7Lo'];
            $key_parameter = 'apiKey';
            break;
    }
    if ($key_parameter) {
        $key = bxc_settings_get($service . '-key');
        if (!$key) $key = $keys[rand(0, 4)];
    }
    return $key ? ($url ? $url . (strpos($url, '?') ? '&' : '?') . $key_parameter . '=' . $key : $key) : ($url ? $url : false);
}

function bxc_crypto_get_fiat_value($amount, $cryptocurrency, $currency_code) {
    global $BXC_EXCHANGE_RATE;
    if (!is_numeric($amount)) return $amount;
    if (!$BXC_EXCHANGE_RATE) $BXC_EXCHANGE_RATE = json_decode(bxc_curl('https://api.coinbase.com/v2/exchange-rates?currency=' . $currency_code), true)['data']['rates'];
    return round((1 / floatval($BXC_EXCHANGE_RATE[strtoupper($cryptocurrency)])) * floatval($amount), 2);
}

function bxc_crypto_get_cryptocurrency_value($amount, $cryptocurrency_code, $currency_code) {
    $exchange_rate = json_decode(bxc_curl('https://api.coinbase.com/v2/exchange-rates?currency=' . $currency_code), true)['data']['rates'];
    return bxc_decimal_number(floatval($exchange_rate[strtoupper($cryptocurrency_code)]) * floatval($amount));
}

function bxc_blockchain($cryptocurrency, $action, $extra = false, $address = false) {
    $services = [
        'btc' => [['https://mempool.space/api/', 'address/{R}', 'address/{R}/txs', 'tx/{R}', 'blocks/tip/height', 'mempool'], ['https://chain.so/api/v2/', 'get_address_balance/btc/{R}', 'get_tx_received/btc/{R}', 'get_tx/btc/{R}', 'get_info/BTC', 'chain'], ['https://blockstream.info/api/', 'address/{R}', 'address/{R}/txs', 'tx/{R}', 'blocks/tip/height', 'blockstream'], ['https://blockchain.info/', 'q/addressbalance/{R}', 'rawaddr/{R}?limit=10', 'rawtx/{R}', 'q/getblockcount', 'blockchain']],
        'eth' => [['https://api.etherscan.io/api?', 'module=account&action=balance&address={R}', 'module=account&action=txlist&address={R}&startblock=0&endblock=99999999&offset=15&sort=asc', 'module=account&action=txlist&offset=15&address={R}&startblock=0&endblock=99999999&sort=asc', false, 'etherscan'], ['https://api.ethplorer.io/', 'getAddressInfo/{R}', 'getAddressTransactions/{R}?limit=15&showZeroValues=false', 'getTxInfo/{R}', 'getLastBlock', 'ethplorer'], ['https://blockscout.com/eth/mainnet/api?', 'module=account&action=balance&address={R}', 'module=account&action=txlist&address={R}', 'module=transaction&action=gettxinfo&txhash={R}', false, 'blockscout']],
        'doge' => [['https://dogechain.info/api/v1/', 'address/balance/{R}', 'unspent/{R}', 'transaction/{R}', false, 'dogechain'], ['https://chain.so/api/v2/', 'get_address_balance/doge/{R}', 'get_tx_received/doge/{R}', 'get_tx/doge/{R}', 'get_info/DOGE', 'chain']],
        'algo' => [['https://algoindexer.algoexplorerapi.io/v2/', 'accounts/{R}', 'accounts/{R}/transactions?limit=15', 'transactions/{R}', 'accounts/{R}', 'algoexplorerapi']]
    ];
    $address = $address ? $address : bxc_settings_get('address-' . $cryptocurrency);
    $services = bxc_settings_get('custom-token-code') == $cryptocurrency ? $services['eth'] : bxc_isset($services, $cryptocurrency);
    
    // Tokens
    $is_token = in_array($cryptocurrency, ['usdt', 'usdc', 'link', 'shib', 'bat']);
    if ($is_token) {
        $services = [['https://api.etherscan.io/api?', 'module=account&action=tokenbalance&contractaddress={A}&address={R}&tag=latest', 'module=account&action=tokentx&address={R}&startblock=0&endblock=99999999&offset=15&sort=asc', 'module=account&action=tokentx&address={R}&startblock=0&endblock=99999999&sort=asc', false, 'etherscan', 'module=account&action=tokentx&address={R}&startblock=0&endblock=99999999&sort=asc'], ['https://api.ethplorer.io/', 'getAddressInfo/{R}', 'getAddressHistory/{R}?limit=15&showZeroValues=false', 'getTxInfo/{R}', false, 'ethplorer', 'getAddressHistory/{R}?limit=15&showZeroValues=false'], ['https://blockscout.com/eth/mainnet/api?', 'module=account&action=tokenbalance&contractaddress={A}&address={R}', 'module=account&action=tokentx&address={R}&offset=15', 'module=account&action=tokentx&address={R}&offset=15', false, 'blockscout', 'module=account&action=tokenlist&address={R}']];
        $contract_address = bxc_settings_db('contract-address-' . $cryptocurrency);
    }

    $slugs = false;
    $transactions = [];
    $single_transaction = $action == 'transaction';
    $divider = 1;

    // Custom Blockchain explorer
    $custom_explorer = bxc_settings_get('custom-explorer-active') ? bxc_settings_get('custom-explorer-' . $action . '-url') : false;
    if ($custom_explorer) {
        $path = bxc_settings_get('custom-explorer-' . $action . '-path');
        $data = bxc_curl(str_replace(['{R}', '{N}', '{N2}'], [$single_transaction ? $extra : $address, $cryptocurrency, strtolower(bxc_crypto_name($cryptocurrency))], $custom_explorer));
        $data = bxc_get_array_value_by_path($action == 'transactions' ? trim(explode(',', $path)[0]) : $path, json_decode($data, true));
        if ($data) {
            $custom_explorer_divider = 1;
            if (bxc_settings_get('custom-explorer-divider')) {
                $custom_explorer_divider = $cryptocurrency == 'eth' ? 1000000000000000000 : 100000000;
            }
            switch ($action) {
                case 'balance':
                    if (is_numeric($data)) {
                        return floatval($data) / $custom_explorer_divider;
                    }
                    break;
                case 'transaction':
                    if (is_array($data) && $data[0]) {
                        return ['time' => $data[0], 'address' => $data[1], 'value' => floatval($data[2]) / $custom_explorer_divider, 'confirmations' => $data[3], 'hash' => $data[4]];
                    }
                    break;
                case 'transactions':
                    if (is_array($data)) {
                        for ($i = 0; $i < count($data); $i++) {
                            $transaction = bxc_get_array_value_by_path($path, $data[$i]);
                            array_push($transactions, ['time' => $transaction[1], 'address' => $transaction[2], 'value' => floatval($transaction[3]) / $custom_explorer_divider, 'confirmations' => $transaction[4], 'hash' => $transaction[5]]);
                        }
                        return $transactions;
                    }
                    break;
            }
        }
    }

    // Get data
    $data_original = false;
    for ($i = 0; $i < count($services); $i++) {
        $url_part = $services[$i][$action == 'balance' ? 1 : ($action == 'transactions' ? 2 : ($single_transaction ? 3 : 4))];
        if ($url_part === false) continue;
        $url = $services[$i][0] . str_replace('{R}', $single_transaction && $services[$i][5] != 'etherscan' ? $extra : $address, $url_part);
        if ($is_token) {
            if (!$contract_address) {
                if ($services[$i][6]) {
                    $url_2 = str_replace('{R}', $address, $services[$i][0] . $services[$i][6]);
                    $data = json_decode(bxc_curl(bxc_crypto_api_key($services[$i][5], $url_2)), true);
                    $items = bxc_isset($data, $i == 1 ? 'operations' : 'result', []);
                    $symbol = $i == 0 ? 'tokenSymbol' : 'symbol';
                    if (is_array($items)) {
                        for ($j = 0; $j < count($items); $j++) {
                            if (strtolower($i == 1 ? $items[$j]['tokenInfo'][$symbol] : $items[$j][$symbol]) == $cryptocurrency) {
                                $contract_address = $i == 1 ? $items[$j]['tokenInfo']['address']: $items[$j]['contractAddress'];
                                break;
                            }
                        } 
                    }
                    if ($contract_address) {
                        bxc_settings_db('contract-address-' . $cryptocurrency, $contract_address);
                    } else continue;
                } else continue;
            }
            $url = str_replace('{A}', $contract_address, $url);
        }
        $data = $data_original = bxc_curl(bxc_crypto_api_key($services[$i][5], $url));
        switch ($cryptocurrency) {
            case 'btc':
                switch ($action) {
                    case 'balance':
                        $data = json_decode($data, true);
                        switch ($i) {
                            case 0:
                            case 2:
                                if (isset($data['chain_stats'])) {
                                    return ($data['chain_stats']['funded_txo_sum'] - $data['chain_stats']['spent_txo_sum']) / 100000000;
                                }
                                break;
                            case 1:
                                if (isset($data['data'])) {
                                    return $data['data']['confirmed_balance'];
                                }
                                break;
                            case 3:
                                if (is_numeric($data)) {
                                    return intval($data) / 100000000;
                                }
                                break;
                        }
                        break;
                    case 'transaction':
                    case 'transactions':
                        $data = json_decode($data, true);
                        $input_slug = false;
                        $output_slug = false;
                        $confirmations = false;
                        $continue = false;

                        // Get transaction and verify the API is working
                        switch ($i) {
                            case 0:
                            case 2:
                                if (is_array($data)) {
                                    $output_slug = 'vout';
                                    $input_slug = 'vin';
                                    $continue = true;
                                }
                                break;
                            case 1:
                                if (isset($data['data']) && (($single_transaction && isset($data['data']['txid'])) || isset($data['data']['txs']))) {
                                    $data = $single_transaction ? $data['data'] : $data['data']['txs'];
                                    $input_slug = 'inputs';
                                    $output_slug = 'outputs';
                                    $continue = true;
                                }
                                break;
                            case 3:
                                if (($single_transaction && isset($data['inputs'])) || isset($data['txs'])) {
                                    if (!$single_transaction) $data = $data['txs'];
                                    $input_slug = 'inputs';
                                    $output_slug = 'out';
                                    $continue = true;
                                }
                                break;
                        }
                        if ($continue) {
                            $slugs = ['time', 'from', 'value', 'confirmations', 'hash', 'block_height'];
                            $sender_address = '';
                            $transaction_value = 0;
                            $time = 0;
                            $block_height = 0;
                            $hash = '';
                            if ($single_transaction) $data = [$data];

                            // Get transactions details
                            for ($j = 0; $j < count($data); $j++) {
                                switch ($i) {
                                    case 0:
                                    case 2:
                                        if (bxc_isset($data[$j]['status'], 'confirmed')) {
                                            $time = $data[$j]['status']['block_time'];
                                            $block_height = $data[$j]['status']['block_height'];
                                        }
                                        $hash = $data[$j]['txid'];
                                        break;
                                    case 1:
                                        $time = $data[$j]['time'];
                                        $block_height = false;
                                        $confirmations = $data[$j]['confirmations'];
                                        $transaction_value = $data[$j]['value'];
                                        $hash = $data[$j]['txid'];
                                        break;
                                    case 3:
                                        $time = $data[$j]['time'];
                                        $block_height = $data[$j]['block_height'];
                                        $hash = $data[$j]['hash'];
                                        break;
                                }

                                // Get transaction amount
                                $outputs = $output_slug ? $data[$j][$output_slug] : [];
                                for ($y = 0; $y < count($outputs); $y++) {
                                    switch ($i) {
                                        case 0:
                                        case 2:
                                            $value = $outputs[$y]['value'];
                                            $output_address = $outputs[$y]['scriptpubkey_address'];
                                            break;
                                        case 1:
                                            $value = $outputs[$y]['value'];
                                            $output_address = $outputs[$y]['address'];
                                            break;
                                        case 3:
                                            $value = $outputs[$y]['value'];
                                            $output_address = $outputs[$y]['addr'];
                                            break;
                                    }
                                    if ($output_address == $address) {
                                        $transaction_value += $value;
                                    }
                                    $outputs[$y] = ['value' => $value, 'address' => $output_address];
                                }

                                // Get sender address
                                $input = bxc_isset($data[$j], $input_slug);
                                if ($input && count($input)) {
                                    $input = $input[0];
                                    switch ($i) {
                                        case 0:
                                        case 2:
                                            $sender_address = $input['prevout']['scriptpubkey_address'];
                                            break;
                                        case 1:
                                            $sender_address = $input['address'];
                                            break;
                                        case 3:
                                            $sender_address = $input['prev_out']['addr'];
                                            break;
                                    }
                                }

                                // Assign transaction values
                                $data[$j]['time'] = $time;
                                $data[$j]['address'] = $sender_address;
                                $data[$j]['confirmations'] = $confirmations;
                                $data[$j]['value'] = $transaction_value / 100000000;
                                $data[$j]['hash'] = $hash;
                                $data[$j]['block_height'] = $block_height;
                            }
                        }
                        break;
                    case 'blocks_count':
                        switch ($i) {
                            case 0:
                            case 2:
                            case 3:
                                if (is_numeric($data)) {
                                    return intval($data);
                                }
                                break;
                            case 1:
                                if (isset($data['data']) && isset($data['data']['blocks'])) {
                                    return intval($data['data']['blocks']);
                                }
                                break;
                        }
                }
                break;
            case 'link':
            case 'shib':
            case 'bat':
            case 'usdt':
            case 'usdc':
            case 'eth':
                $data = json_decode($data, true);
                switch ($action) {
                    case 'balance':
                        switch ($i) {
                            case 2:
                            case 0:
                                $data = bxc_isset($data, 'result');
                                if (is_numeric($data)) {
                                    return floatval($data) / ($is_token ? 1000000 : 1000000000000000000);
                                }
                                break;
                            case 1:
                                if ($is_token) {
                                    $data = bxc_isset($data, 'tokens', []);
                                    for ($j = 0; $j < count($data); $j++) {
                                    	if (strtolower(bxc_isset(bxc_isset($data, 'tokenInfo'), 'symbol')) == $cryptocurrency) {
                                            return floatval($data['balance']) / (10 ** intval($data['tokenInfo']['decimals']));
                                        }
                                    }  
                                } else {
                                    $data = bxc_isset(bxc_isset($data, 'ETH'), 'balance');
                                    if (is_numeric($data)) {
                                        return floatval($data);
                                    }
                                }
                                break;
                        }
                        break;
                    case 'transaction':
                    case 'transactions':
                        switch ($i) {
                            case 2:
                            case 0:
                                $data = bxc_isset($data, 'result');
                                if (is_array($data)) {
                                    $slugs = ['timeStamp', 'from', 'value', 'confirmations', 'hash', 'blockNumber'];
                                    $divider = 1000000000000000000;
                                    if ($single_transaction) {
                                        if ($i === 0) {
                                            $data_single = [];
                                            for ($j = 0; $j < count($data); $j++) {
                                                if ($data[$j]['hash'] == $extra) {
                                                    $data_single = [$data[$j]];
                                                    break;
                                                }
                                            }
                                            $data = $data_single;
                                        } else {
                                            $data = [$data];
                                        }
                                    } else if ($is_token) {
                                        $data_temp = [];
                                        for ($j = 0; $j < count($data); $j++) {
                                            if (strtolower($data[$j]['tokenSymbol']) == $cryptocurrency) {
                                                array_push($data_temp, $data[$j]);
                                                $divider = 10 ** intval($data[$j]['tokenDecimal']);
                                            }
                                        }
                                        $data = $data_temp;
                                    }      
                                }
                                break;
                            case 1:
                                if ($single_transaction || is_array($data) || $is_token) {
                                    $slugs = ['timestamp', 'from', 'value', 'confirmations', 'hash', 'blockNumber'];
                                    if ($single_transaction) $data = [$data];
                                }
                                if ($is_token && !$single_transaction) {
                                    $data = bxc_isset($data, 'operations', []);
                                    $data_temp = [];
                                    for ($j = 0; $j < count($data); $j++) {
                                        if (strtolower($data[$j]['tokenInfo']['symbol']) == $cryptocurrency) {
                                            array_push($data_temp, $data[$j]);
                                            $divider = 10 ** intval($data[$j]['tokenInfo']['decimals']);
                                        }
                                    }
                                    $slugs[4] = 'transactionHash';
                                    $data = $data_temp;
                                } 
                                break;
                        }
                        if ($slugs && (!$data || (count($data) && (!isset($data[0]) || !bxc_isset($data[0], $slugs[0]))))) $slugs = false;
                        break;
                    case 'blocks_count':
                        switch ($i) {
                            case 1:
                                if (is_numeric($data['lastBlock'])) {
                                    return intval($data['lastBlock']);
                                }
                                break;
                        }
                }
                break;
            case 'doge':
                $data = json_decode($data, true);
                switch ($action) {
                    case 'balance':
                        switch ($i) {
                            case 0:
                                $data = bxc_isset($data, 'balance');
                                if (is_numeric($data)) {
                                    return floatval($data);
                                }
                                break;
                            case 1:
                                $data = bxc_isset($data, 'data');
                                if ($data && isset($data['confirmed_balance'])) {
                                    return $data['confirmed_balance'];
                                }
                                break;
                        }
                        break;
                    case 'transaction':
                    case 'transactions':
                        switch ($i) {
                            case 0:
                                $data = bxc_isset($data, $single_transaction ? 'transaction' : 'unspent_outputs');
                                if ($data) {
                                    $slugs = ['time', 'address', 'value', 'confirmations', 'tx_hash', false];
                                    if (!$single_transaction) $divider = 100000000;
                                }
                                break;
                            case 1:
                                $data = bxc_isset($data, 'data');
                                if ($data) {
                                    if (!$single_transaction) $data = bxc_isset($data, 'txs');
                                    $slugs = ['time', 'address', 'value', 'confirmations', 'txid', false];
                                }
                                break;
                        }
                        if ($slugs) {
                            if (is_array($data)) {
                                if ($single_transaction && ($i === 0 || $i === 1)) {
                                    $data['address'] = $data['inputs'][0]['address'];
                                    $outputs = $data['outputs'];
                                    $slugs[4] = 'hash';
                                    for ($j = 0; $j < count($outputs); $j++) {
                                        if ($outputs[$j]['address'] == $address) {
                                            $data['value'] = $outputs[$j]['value'];
                                            break;
                                        }
                                    }
                                    $data = [$data];
                                }
                            }
                            if (!$data || (count($data) && (!isset($data[0]) || (!bxc_isset($data[0], $slugs[0]) && !bxc_isset($data[0], $slugs[1]))))) $slugs = false;
                        }
                        break;
                    case 'blocks_count':
                        switch ($i) {
                            case 1:
                                if (is_numeric($data['lastBlock'])) {
                                    return intval($data['lastBlock']);
                                }
                                break;
                        }
                }
                break;
            case 'algo':
                $data = json_decode($data, true);
                switch ($action) {
                    case 'balance':
                        switch ($i) {
                            case 0:
                                $data = bxc_isset(bxc_isset($data, 'account'), 'amount');
                                if (is_numeric($data)) {
                                    return floatval($data) / 1000000;
                                }
                                break;
                        }
                        break;
                    case 'transaction':
                    case 'transactions':
                        switch ($i) {
                            case 0:
                                $current_round = bxc_isset($data, 'current-round');
                                $data = bxc_isset($data, $single_transaction ? 'transaction' : 'transactions');
                                if ($data) {
                                    $slugs = ['round-time', 'sender', 'amount', 'confirmations', 'id', 'confirmed-round'];
                                    $divider = 1000000;
                                    if ($single_transaction) {
                                        $data['amount'] = bxc_isset(bxc_isset($data, 'payment-transaction'), 'amount', -1);
                                        $data['confirmations'] = $current_round - bxc_isset($data, 'confirmed-round');
                                        $data = [$data];
                                    } else {
                                        for ($j = 0; $j < count($data); $j++) {
                                            $data[$j]['amount'] = bxc_isset(bxc_isset($data[$j], 'payment-transaction'), 'amount', -1);
                                            $data[$j]['confirmations'] = $current_round - bxc_isset($data[$j], 'confirmed-round');
                                        }
                                    }
                                }
                                break;
                        }
                        break;
                    case 'blocks_count':
                        switch ($i) {
                            case 1:
                                if (is_numeric($data['current-round'])) {
                                    return intval($data['current-round']);
                                }
                                break;
                        }
                }
                break;
        }

        // Add the transactions
        if ($slugs) {
            for ($j = 0; $j < count($data); $j++) {
                $transaction = $data[$j];
                array_push($transactions, ['time' => bxc_isset($transaction, $slugs[0]), 'address' => bxc_isset($transaction, $slugs[1], ''), 'value' => $transaction[$slugs[2]] / $divider, 'confirmations' => bxc_isset($transaction, $slugs[3], 0), 'hash' => $transaction[$slugs[4]], 'block_height' => bxc_isset($transaction, $slugs[5], '')]);
            }
            return $single_transaction ? $transactions[0] : $transactions;
        }
    }
    return $data_original;
}

function bxc_crypto_name($cryptocurrency_code) {
    $names = ['btc' => 'Bitcoin', 'eth' => 'Ethereum', 'doge' => 'Dogecoin', 'algo' => 'Algorand', 'usdt' => 'Tether', 'usdc' => 'USD Coin', 'link' => 'Chainlink', 'shib' => 'Shiba Inu', 'bat' => 'Basic Attention Token'];
    return $names[$cryptocurrency_code];
}

function bxc_crypto_get_address($cryptocurrency_code) {
    $address = false;
    $address_generated = bxc_settings_get('custom-explorer-active') ? bxc_settings_get('custom-explorer-address') : false;
    if ($address_generated) {
        $data = bxc_curl(str_replace(['{N}', '{N2}'], [$cryptocurrency_code, strtolower(bxc_crypto_name($cryptocurrency_code))], $address_generated));
        $data = bxc_get_array_value_by_path(bxc_settings_get('custom-explorer-address-path'), json_decode($data, true));
        if ($data) $address = $data;
    }
    return $address ? $address : bxc_settings_get('address-' . $cryptocurrency_code);
}

/*
 * -----------------------------------------------------------
 * # ACCOUNT
 * -----------------------------------------------------------
 *
 * 1. Admin login
 * 2. Verify the admin login
 *
 */

function bxc_login($username, $password) {
    if ($username == BXC_USER && password_verify($password, BXC_PASSWORD)) {
        $data = [BXC_USER];
        $GLOBALS['BXC_LOGIN'] = $data;
        return [bxc_encryption(json_encode($data, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE))];
    }
    return false;
}

function bxc_verify_admin() {
    global $BXC_LOGIN;
    if (!defined('BXC_USER')) return false;
    if (isset($BXC_LOGIN) && $BXC_LOGIN[0] === BXC_USER) return true;
    if (isset($_COOKIE['BXC_LOGIN'])) {
        $data = json_decode(bxc_encryption($_COOKIE['BXC_LOGIN'], false), true);
        if ($data && $data[0] === BXC_USER) {
            $GLOBALS['BXC_LOGIN'] = $data;
            return true;
        }
    }
    return false;
}

/*
 * -----------------------------------------------------------
 * SETTINGS
 * -----------------------------------------------------------
 *
 * 1. Populate the admin area with the settings of the file /resources/settings.json
 * 2. Return the HTML code of a setting element
 * 3. Save all settings
 * 4. Return a single setting
 * 5. Return all settings
 * 6. Return JS settings for admin side
 * 7. Return or save a database setting
 *
 */

function bxc_settings_populate() {
    global $BXC_APPS;
    $settings = json_decode(file_get_contents(__DIR__ . '/resources/settings.json'), true);
    $code = '';
    $language = bxc_language(true);
    $translations = [];
    for ($i = 0; $i < count($BXC_APPS); $i++) {
        $path = __DIR__ . '/apps/' . $BXC_APPS[$i] . '/settings.json';
        if (file_exists($path)) {
            $settings = array_merge($settings, json_decode(file_get_contents($path), true));
        }
    }
    if ($language) {
        $path = __DIR__ . '/resources/languages/settings/' . $language . '.json';
        if (file_exists($path)) {
            $translations = json_decode(file_get_contents($path), true);
        }
    }
    for ($i = 0; $i < count($settings); $i++) {
        $code .= bxc_settings_get_code($settings[$i], $translations);
    }
    echo $code;
}

function bxc_settings_get_code($setting, &$translations = []) {
    if (isset($setting)) {
        $id = $setting['id'];
        $type = $setting['type'];
        $title = $setting['title'];
        $content = $setting['content'];
        $code = '<div id="' . $id . '" data-type="' . $type . '" class="bxc-input"><div class="bxc-setting-content"><span>' . bxc_isset($translations, $title, $title) . '</span><p>' . bxc_isset($translations, $content, $content) . (isset($setting['help']) ? '<a href="' . $setting['help'] . '" target="_blank" class="bxc-icon-help"></a>' : '') . '</p></div><div class="bxc-setting-input">';
        switch ($type) {
            case 'color':
            case 'text':
                $code .= '<input type="text">';
                break;
            case 'password':
                $code .= '<input type="password">';
                break;
            case 'textarea':
                $code .= '<textarea></textarea>';
                break;
            case 'select':
                $values = $setting['value'];
                $code .= '<select>';
                for ($i = 0; $i < count($values); $i++) {
                    $code .= '<option value="' . $values[$i][0] . '">' . bxc_isset($translations, $values[$i][1], $values[$i][1]) . '</option>';
                }
                $code .= '</select>';
                break;
            case 'checkbox':
                $code .= '<input type="checkbox">';
                break;
            case 'number':
                $code .= '<input type="number">';
                break;
            case 'multi-input':
                $values = $setting['value'];
                for ($i = 0; $i < count($values); $i++) {
                    $sub_type = $values[$i]['type'];
                    $sub_title = $values[$i]['title'];
                    $code .= '<div id="' . $values[$i]['id'] . '" data-type="' . $sub_type . '"><span>' . bxc_isset($translations, $sub_title, $sub_title) . '</span>';
                    switch ($sub_type) {
                        case 'color':
                        case 'text':
                            $code .= '<input type="text">';
                            break;
                        case 'password':
                            $code .= '<input type="password">';
                            break;
                        case 'number':
                            $code .= '<input type="number">';
                            break;
                        case 'textarea':
                            $code .= '<textarea></textarea>';
                            break;
                        case 'checkbox':
                            $code .= '<input type="checkbox">';
                            break;
                        case 'select':
                            $code .= '<select>';
                            $items = $values[$i]['value'];
                            for ($j = 0; $j < count($items); $j++) {
                                $code .= '<option value="' . $items[$j][0] . '">' . bxc_isset($translations, $items[$j][1], $items[$j][1]) . '</option>';
                            }
                            $code .= '</select>';
                            break;
                        case 'button':
                            $code .= '<a class="bxc-btn" href="' . $values[$i]['button-url'] . '">' . bxc_isset($translations, $values[$i]['button-text'], $values[$i]['button-text']) . '</a>';
                            break;
                    }
                    $code .= '</div>';
                }
                break;
        }
        return $code . '</div></div>';
    }
    return '';
}

function bxc_settings_save($settings) {
    return bxc_settings_db('settings', json_decode($settings, true));
}

function bxc_settings_get($id, $default = false) {
    global $BXC_SETTINGS;
    if (!$BXC_SETTINGS) $BXC_SETTINGS = bxc_settings_get_all();
    return bxc_isset($BXC_SETTINGS, $id, $default);
}

function bxc_settings_get_all() {
    global $BXC_SETTINGS;
    if (!$BXC_SETTINGS) $BXC_SETTINGS = json_decode(bxc_settings_db('settings', false, '[]'), true);
    return $BXC_SETTINGS;
}

function bxc_settings_js_admin() {
    $language = bxc_language(true);
    $code = 'var BXC_LANG = "' . $language . '"; var BXC_AJAX_URL = "' . BXC_URL . 'ajax.php' . '"; var BXC_TRANSLATIONS = ' . ($language ? file_get_contents(__DIR__ . '/resources/languages/admin/' . $language . '.json') : '{}') . '; var BXC_CURRENCY = "' . bxc_settings_get('currency', 'USD') . '"; var BXC_URL = "' . BXC_URL . '"; var BXC_ADMIN = true; var BXC_ADDRESS = { btc: "' . bxc_settings_get('address-btc') . '", eth: "' . bxc_settings_get('address-eth') . '", doge: "' . bxc_settings_get('address-doge') . '", algo: "' . bxc_settings_get('address-algo') . '", tether: "' . bxc_settings_get('address-tether') . '"};';
    return $code;
}

function bxc_settings_db($name, $value = false, $default = false) {
    if ($value === false) return bxc_isset(bxc_db_get('SELECT value FROM bxc_settings WHERE name = "' . bxc_db_escape($name) . '"'), 'value', $default);
    if (is_string($value) || is_numeric($value)) {
        $value = bxc_db_escape($value);
    } else {
        $value = bxc_db_json_escape($value);
        if (json_last_error() != JSON_ERROR_NONE || !$value) return json_last_error();
    }
    return bxc_db_query('INSERT INTO bxc_settings (name, value) VALUES (\'' . bxc_db_escape($name) . '\', \'' . $value . '\') ON DUPLICATE KEY UPDATE value = \'' . $value . '\'');
}

/*
 * -----------------------------------------------------------
 * # LANGUAGE
 * -----------------------------------------------------------
 *
 * 1. Initialize the translations
 * 2. Get the active language
 * 3. Return the translation of a string
 * 4. Echo the translation of a string
 *
 */

function bxc_init_translations() {
    global $BXC_TRANSLATIONS;
    global $BXC_LANGUAGE;
    if (!empty($BXC_LANGUAGE) && $BXC_LANGUAGE[0] != 'en') {
        $path = __DIR__ . '/resources/languages/' . $BXC_LANGUAGE[1] . '/' . $BXC_LANGUAGE[0] . '.json';
        if (file_exists($path)) {
            $BXC_TRANSLATIONS = json_decode(file_get_contents($path), true);
        }  else {
            $BXC_TRANSLATIONS = false;
        }
    } else if (!isset($BXC_LANGUAGE)) {
        $BXC_LANGUAGE = false;
        $BXC_TRANSLATIONS = false;
        $admin = bxc_verify_admin();
        $language = bxc_language($admin);
        $area = $admin ? 'admin' : 'client';
        if ($language) {
            $path = __DIR__ . '/resources/languages/' . $area . '/' . $language . '.json';
            if (file_exists($path)) {
                $BXC_TRANSLATIONS = json_decode(file_get_contents($path), true);
                $BXC_LANGUAGE = [$language, $area];
            }  else {
                $BXC_TRANSLATIONS = false;
            }
        }
    }
    if ($BXC_LANGUAGE && $BXC_TRANSLATIONS && file_exists(__DIR__ . '/translations.json')) {
        $custom_translations = json_decode(file_get_contents(__DIR__ . '/translations.json'), true);
        if ($custom_translations && isset($custom_translations[$BXC_LANGUAGE[0]])) {
            $BXC_TRANSLATIONS = array_merge($BXC_TRANSLATIONS, $custom_translations[$BXC_LANGUAGE[0]]);
        }
    }
}

function bxc_language($admin = false) {
    $language = bxc_settings_get($admin ? 'language-admin' : 'language');
    if ($language == 'auto') $language = strtolower(isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : false);
    if (!$language) $language = bxc_isset($_POST, 'language');
    return $language == 'en' ? false : $language;
}

function bxc_($string) {
    global $BXC_TRANSLATIONS;
    if (!isset($BXC_TRANSLATIONS)) {
        bxc_init_translations();
    }
    return empty($BXC_TRANSLATIONS[$string]) ? $string : $BXC_TRANSLATIONS[$string];
}

function bxc_e($string) {
    echo bxc_($string);
}

/*
 * -----------------------------------------------------------
 * DATABASE
 * -----------------------------------------------------------
 *
 * 1. Connection to the database
 * 2. Get database values
 * 3. Insert or update database values
 * 4. Escape and sanatize values prior to databse insertion
 * 5. Escape a JSON string prior to databse insertion
 * 6. Set default database environment settings
 *
 */

function bxc_db_connect() {
    global $BXC_CONNECTION;
    if (!defined('BXC_DB_NAME') || !BXC_DB_NAME) return false;
    if ($BXC_CONNECTION) {
        bxc_db_init_settings();
        return true;
    }
    $BXC_CONNECTION = new mysqli(BXC_DB_HOST, BXC_DB_USER, BXC_DB_PASSWORD, BXC_DB_NAME, defined('BXC_DB_PORT') && BXC_DB_PORT ? intval(BXC_DB_PORT) : ini_get('mysqli.default_port'));
    if ($BXC_CONNECTION->connect_error) {
        echo 'Connection error. Visit the admin area for more details or open the config.php file and check the database information. Message: ' . $BXC_CONNECTION->connect_error . '.';
        return false;
    }
    bxc_db_init_settings();
    return true;
}

function bxc_db_get($query, $single = true) {
    global $BXC_CONNECTION;
    $status = bxc_db_connect();
    $value = ($single ? '' : []);
    if ($status) {
        $result = $BXC_CONNECTION->query($query);
        if ($result) {
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    if ($single) {
                        $value = $row;
                    } else {
                        array_push($value, $row);
                    }
                }
            }
        } else {
            return $BXC_CONNECTION->error;
        }
    } else {
        return $status;
    }
    return $value;
}

function bxc_db_query($query, $return = false) {
    global $BXC_CONNECTION;
    $status = bxc_db_connect();
    if ($status) {
        $result = $BXC_CONNECTION->query($query);
        if ($result) {
            if ($return) {
                if (isset($BXC_CONNECTION->insert_id) && $BXC_CONNECTION->insert_id > 0) {
                    return $BXC_CONNECTION->insert_id;
                } else {
                    return $BXC_CONNECTION->error;
                }
            } else {
                return true;
            }
        } else {
            return $BXC_CONNECTION->error;
        }
    } else {
        return $status;
    }
}

function bxc_db_escape($value, $numeric = -1) {
    if (is_numeric($value)) return $value;
    else if ($numeric === true) return false;
    global $BXC_CONNECTION;
    bxc_db_connect();
    if ($BXC_CONNECTION) $value = $BXC_CONNECTION->real_escape_string($value);
    $value = str_replace(['\"', '"'], ['"', '\"'], $value);
    $value = str_replace(['<script', '</script'], ['&lt;script', '&lt;/script'], $value);
    $value = str_replace(['javascript:', 'onclick=', 'onerror='], '', $value);
    $value = htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'utf-8');
    return $value;
}

function bxc_db_json_escape($array) {
    global $BXC_CONNECTION;
    bxc_db_connect();
    $value = str_replace(['"false"', '"true"'], ['false', 'true'], json_encode($array, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE));
    $value = str_replace(['<script', '</script'], ['&lt;script', '&lt;/script'], $value);
    $value = str_replace(['javascript:', 'onclick=', 'onerror='], '', $value);
    return $BXC_CONNECTION ? $BXC_CONNECTION->real_escape_string($value) : $value;
}

function bxc_db_check_connection($name = false, $user = false, $password = false, $host = false, $port = false) {
    global $BXC_CONNECTION;
    $response = true;
    if ($name === false && defined('BXC_DB_NAME')) {
        $name = BXC_DB_NAME;
        $user = BXC_DB_USER;
        $password = BXC_DB_PASSWORD;
        $host = BXC_DB_HOST;
        $port = defined('BXC_DB_PORT') && BXC_DB_PORT ? intval(BXC_DB_PORT) : false;
    }
    try {
        set_error_handler(function() {}, E_ALL);
    	$BXC_CONNECTION = new mysqli($host, $user, $password, $name, $port === false ? ini_get('mysqli.default_port') : intval($port));
    }
    catch (Exception $e) {
        $response = $e->getMessage();
    }
    if ($BXC_CONNECTION->connect_error) {
        $response = $BXC_CONNECTION->connect_error;
    }
    restore_error_handler();
    return $response;
}

function bxc_db_init_settings() {
    global $BXC_CONNECTION;
    $BXC_CONNECTION->set_charset('utf8mb4');
    $BXC_CONNECTION->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
}

/*
 * -----------------------------------------------------------
 * MISCELLANEOUS
 * -----------------------------------------------------------
 *
 * 1. Encryption
 * 2. Check if a key is set and return it
 * 3. Update or create config file
 * 4. Installation
 * 5. Check if database connection is working
 * 6. Curl
 * 7. Cron jobs
 * 8. Scientific number to decimal number
 * 9. Get array value by path
 * 10. Updates
 * 11. Check if demo URL
 * 12. Check if RTL
 * 13. Admin area
 * 14. Debug 
 * 15. CSV
 * 
 */

function bxc_encryption($string, $encrypt = true) {
    $output = false;
    $encrypt_method = 'AES-256-CBC';
    $secret_key = BXC_PASSWORD . BXC_USER;
    $key = hash('sha256', $secret_key);
    $iv = substr(hash('sha256', BXC_PASSWORD), 0, 16);
    if ($encrypt) {
        $output = openssl_encrypt(is_string($string) ? $string : json_encode($string, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE), $encrypt_method, $key, 0, $iv);
        $output = base64_encode($output);
        if (substr($output, -1) == '=') $output = substr($output, 0, -1);
    } else {
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    }
    return $output;
}

function bxc_isset($array, $key, $default = false) {
    return !empty($array) && isset($array[$key]) && $array[$key] !== '' ? $array[$key] : $default;
}

function bxc_config($content) {
    $file = fopen(__DIR__ . '/config.php', 'w');
    fwrite($file, $content);
    fclose($file);
    return true;
}

function bxc_installation($data) {
    if (!defined('BXC_USER') || !defined('BXC_DB_HOST')) {
        if (is_string($data)) $data = json_decode($data, true);
        $connection_check = bxc_db_check_connection($data['db-name'], $data['db-user'], $data['db-password'], $data['db-host'], $data['db-port']);
        if ($connection_check === true) {

            // Create the config.php file
            $code = '<?php' . PHP_EOL;
            if (empty($data['db-host'])) $data['db-host'] = 'localhost';
            if (empty($data['db-port'])) $data['db-port'] = ini_get('mysqli.default_port');
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password-check']);
            foreach ($data as $key => $value) {
                if (!$value && $key != 'db-password') return 'Empty ' . $key;
                $code .= 'define(\'BXC_' . str_replace('-', '_', strtoupper($key)) . '\', \'' . str_replace('\'', '\\\'', $value) . '\');' . PHP_EOL;
            }
            $file = fopen(__DIR__ . '/config.php', 'w');
            fwrite($file, $code . '?>');
            fclose($file);

            // Create the database
            $connection = new mysqli($data['db-host'], $data['db-user'], $data['db-password'], $data['db-name'], $data['db-port']);
            $connection->set_charset('utf8mb4');
            $connection->query('CREATE TABLE IF NOT EXISTS bxc_transactions (id INT NOT NULL AUTO_INCREMENT, `from` VARCHAR(255) NOT NULL DEFAULT "", `to` VARCHAR(255), hash VARCHAR(255) NOT NULL DEFAULT "", `title` VARCHAR(500) NOT NULL DEFAULT "", description VARCHAR(1000) NOT NULL DEFAULT "", amount VARCHAR(100) NOT NULL, amount_fiat VARCHAR(100) NOT NULL, cryptocurrency VARCHAR(10) NOT NULL, currency VARCHAR(10) NOT NULL, external_reference VARCHAR(1000) NOT NULL DEFAULT "", creation_time DATETIME NOT NULL, status VARCHAR(1) NOT NULL, webhook TINYINT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci');
            $connection->query('CREATE TABLE IF NOT EXISTS bxc_checkouts (id INT NOT NULL AUTO_INCREMENT, title VARCHAR(255), description TEXT, price VARCHAR(100) NOT NULL, currency VARCHAR(10) NOT NULL, type VARCHAR(1), redirect VARCHAR(255), external_reference VARCHAR(1000) NOT NULL DEFAULT "", creation_time DATETIME NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci');
            $connection->query('CREATE TABLE IF NOT EXISTS bxc_settings (name VARCHAR(255) NOT NULL, value LONGTEXT, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci');

            return true;
        }
        return $connection_check;
    }
    return false;
}

function bxc_curl($url, $post_fields = '', $header = [], $type = 'GET') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SB');
    switch ($type) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post_fields) ? $post_fields : http_build_query($post_fields));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 7);
            if ($type != 'POST') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
            }
            break;
        case 'GET':
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 7);
            curl_setopt($ch, CURLOPT_HEADER, false);
            break;
        case 'DOWNLOAD':
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
            curl_setopt($ch, CURLOPT_TIMEOUT, 70);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            break;
        case 'FILE':
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300);
            curl_setopt($ch, CURLOPT_TIMEOUT, 400);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            if (strpos($url, '?')) $url = substr($url, 0, strpos($url, '?'));
            $file = fopen(__DIR__ . '/' . basename($url), 'wb');
            curl_setopt($ch, CURLOPT_FILE, $file);
            break;
    }
    if (!empty($header)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }
    $response = curl_exec($ch);
    if (curl_errno($ch) > 0) {
        $error = curl_error($ch);
        curl_close($ch);
        return $error;
    }
    curl_close($ch);
    return $response;
}

function bxc_download($url) {
    return bxc_curl($url, '', '', 'DOWNLOAD');
}

function bxc_cron() {
    bxc_transactions_delete_pending();
}

function bxc_decimal_number($number) {
    $number = rtrim(sprintf('%.20F', $number), '0');
    return substr($number, -1) == '.' ? substr($number, 0, -1) : $number;
}

function bxc_get_array_value_by_path($path, $array) {
    $path = str_replace(' ', '', $path);
    if (strpos($path, ',')) {
        $response = [];
        $paths = explode(',', $path);
        for ($i = 0; $i < count($paths); $i++) {
            array_push($response, bxc_get_array_value_by_path($paths[$i], $array));
        }
        return $response;
    }
    $path = explode('>', $path);
    for ($i = 0; $i < count($path); $i++) {
        $array = $array ? bxc_isset($array, $path[$i]) : false;
    }
    return $array;
}

function bxc_update($domain) {
    $envato_purchase_code = bxc_settings_get('envato-purchase-code');
    if (!$envato_purchase_code) return 'envato-purchase-code-not-found';
    if (!class_exists('ZipArchive')) return 'no-zip-archive';
    $latest_version = bxc_versions();
    if (bxc_isset($latest_version, 'boxcoin') == BXC_VERSION) return 'latest-version-installed';
    $response = json_decode(bxc_download('https://boxcoin.dev/sync/updates.php?key=' . trim($envato_purchase_code) . '&domain=' . $domain), true);
    if (empty($response['boxcoin'])) return 'invalid-envato-purchase-code';
    $zip = bxc_download('https://boxcoin.dev/sync/temp/' . $response['boxcoin']);
    if ($zip) {
        $file_path = __DIR__ . '/boxcoin.zip';
        file_put_contents($file_path, $zip);
        if (file_exists($file_path)) {
            $zip = new ZipArchive;
            if ($zip->open($file_path) === true) {
                $zip->extractTo(__DIR__);
                $zip->close();
                unlink($file_path);
                return true;
            }
            return 'zip-error';
        }
        return 'file-not-found';
    }
    return 'download-error';
}

function bxc_versions() {
    return json_decode(bxc_download('https://boxcoin.dev/sync/versions.json'), true);
}

function bxc_is_demo($attributes = false) {
    $url = bxc_isset($_SERVER, 'HTTP_REFERER');
    if (strpos($url, 'demo=true')) {
        if ($attributes) {
            parse_str($url, $url);
            return $url;
        }
        return true;
    }
    return false;
}

function bxc_is_rtl($language) {
    return in_array($language, ['ar', 'he', 'ku', 'fa', 'ur']);
}

function bxc_box_admin() { ?>
<div class="bxc-main bxc-admin bxc-area-transactions<?php if (bxc_is_rtl(bxc_language(true))) echo ' bxc-rtl'; ?>">
    <div class="bxc-sidebar">
        <div>
            <img class="bxc-logo" src="<?php echo BXC_URL ?>media/logo.svg" />
            <img class="bxc-logo-icon" src="<?php echo BXC_URL ?>media/icon.svg" />
        </div>
        <div class="bxc-nav">
            <div id="transactions" class="bxc-active">
                <i class="bxc-icon-shuffle"></i><?php bxc_e('Transactions') ?>
            </div>
            <div id="checkouts">
                <i class="bxc-icon-automation"></i><?php bxc_e('Checkouts') ?>
            </div>
            <div id="balances">
                <i class="bxc-icon-bar-chart"></i><?php bxc_e('Balances') ?>
            </div>
            <div id="settings">
                <i class="bxc-icon-settings"></i><?php bxc_e('Settings') ?>
            </div>
        </div>
        <div class="bxc-bottom">
            <div id="bxc-create-checkout" class="bxc-btn">
                <?php bxc_e('Create checkout') ?>
            </div>
            <div id="bxc-save-settings" class="bxc-btn">
                <?php bxc_e('Save settings') ?>
            </div>
            <div class="bxc-mobile-menu">
                <i class="bxc-icon-menu"></i>
                <div class="bxc-flex">
                    <div class="bxc-link" id="bxc-logout">
                        <?php bxc_e('Logout') ?>
                    </div>
                    <div id="bxc-version">
                        <?php echo BXC_VERSION ?>
                    </div>
                    
                </div>
            </div>
        </div>
    </div>
    <div class="bxc-body">
        <main>      
            <div data-area="transactions" class="bxc-active">
                <div class="bxc-nav-wide">
                    <div class="bxc-input bxc-search">
                        <input type="text" id="bxc-search-transactions" class="bxc-search-input" name="bxc-search" placeholder="<?php bxc_e('Search all transactions') ?>" autocomplete="false" />
                        <input type="text" class="bxc-hidden" />
                        <i class="bxc-icon-search"></i>
                    </div>
                    <div id="bxc-download-transitions" class="bxc-btn-icon">
                        <i class="bxc-icon-download"></i>
                    </div>
                    <div class="bxc-nav-filters">
                        <div class="bxc-input">
                            <input id="bxc-filter-date" placeholder="<?php bxc_e('Start date...') ?>" type="text" readonly>
                            <input id="bxc-filter-date-2" placeholder="<?php bxc_e('End date...') ?>" type="text" readonly>
                        </div>
                        <div id="bxc-filter-status" class="bxc-select bxc-right">
                            <p>
                                <?php bxc_e('All statuses') ?>
                            </p>
                            <ul>
                                <li data-value="" class="bxc-active">
                                    <?php bxc_e('All statuses') ?>
                                </li>
                                <li data-value="C">
                                    <?php bxc_e('Completed') ?>                                 
                                </li>
                                <li data-value="P">
                                    <?php bxc_e('Pending') ?>                                   
                                </li>
                            </ul>
                        </div>
                        <div id="bxc-filter-cryptocurrency" class="bxc-select bxc-right">
                            <p>
                                <?php bxc_e('All cryptocurrencies') ?>
                            </p>
                            <ul>
                                <li data-value="" class="bxc-active">
                                    <?php bxc_e('All cryptocurrencies') ?>
                                </li>
                                <li data-value="btc">
                                    Bitcoin                                    
                                </li>
                                <li data-value="eth">
                                    Ethereum                                   
                                </li>
                                <li data-value="doge">
                                    Dogecoin                                   
                                </li>
                                <li data-value="algo">
                                    Algorand                                   
                                </li>
                                <li data-value="usdt">
                                    Tether                                   
                                </li>
                                <li data-value="usdc">
                                    USD Coin                                   
                                </li>
                                <li data-value="link">
                                    Chainlink                                   
                                </li>
                                <li data-value="shib">
                                    Shiba Inu                                   
                                </li>
                                <li data-value="bat">
                                    Basic Attention Token                                   
                                </li>
                                <li data-value="erc-20">
                                    ERC-20                                   
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div id="bxc-filters" class="bxc-btn-icon">
                        <i class="bxc-icon-filters"></i>
                    </div>
                </div>
                <hr />
                <table id="bxc-table-transactions" class="bxc-table">
                    <thead>
                        <tr>
                            <th data-field="date">
                                <?php bxc_e('Date') ?>
                            </th>
                            <th data-field="name">
                                <?php bxc_e('Name') ?>
                            </th>
                            <th data-field="from">
                                <?php bxc_e('From') ?>
                            </th>
                                <th data-field="to">
                                <?php bxc_e('To') ?>
                            </th>
                            <th data-field="status">
                                <?php bxc_e('Status') ?>
                            </th>
                            <th data-field="amount">
                                <?php bxc_e('Amount') ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div data-area="checkouts" class="bxc-loading">
                <table id="bxc-table-checkouts" class="bxc-table">
                    <tbody></tbody>
                </table>
                <div id="bxc-checkouts-form">
                    <form>
                        <div class="bxc-info"></div>
                        <div class="bxc-top">
                            <div id="bxc-checkouts-list" class="bxc-btn bxc-btn-border">
                                <i class="bxc-icon-back"></i>
                                <?php bxc_e('Checkouts list') ?>
                            </div>
                        </div>
                        <div id="bxc-checkout-title" class="bxc-input">
                            <span>
                                <?php bxc_e('Title') ?>
                            </span>
                            <input type="text" required />
                        </div>
                        <div id="bxc-checkout-description" class="bxc-input">
                            <span>
                                <?php bxc_e('Description') ?>
                            </span>
                            <input type="text" />
                        </div>
                        <div class="bxc-flex">
                            <div id="bxc-checkout-price" data-type="select" class="bxc-input">
                                <span>
                                    <?php bxc_e('Price') ?>
                                </span>
                                <input type="number" required />
                            </div>
                            <div id="bxc-checkout-currency" data-type="select" class="bxc-input">
                                <select><option value="" selected>Default</option><option value="crypto" selected><?php bxc_e('Cryptocurrency') ?></option><option value="AED">United Arab Emirates Dirham</option><option value="AFN">Afghan Afghani</option><option value="ALL">Albanian Lek</option><option value="AMD">Armenian Dram</option><option value="ANG">Netherlands Antillean Guilder</option><option value="AOA">Angolan Kwanza</option><option value="ARS">Argentine Peso</option><option value="AUD">Australian Dollar</option><option value="AWG">Aruban Florin</option><option value="AZN">Azerbaijani Manat</option><option value="BAM">Bosnia-Herzegovina Convertible Mark</option><option value="BBD">Barbadian Dollar</option><option value="BDT">Bangladeshi Taka</option><option value="BGN">Bulgarian Lev</option><option value="BHD">Bahraini Dinar</option><option value="BIF">Burundian Franc</option><option value="BMD">Bermudan Dollar</option><option value="BND">Brunei Dollar</option><option value="BOB">Bolivian Boliviano</option><option value="BRL">Brazilian Real</option><option value="BSD">Bahamian Dollar</option><option value="BTN">Bhutanese Ngultrum</option><option value="BWP">Botswanan Pula</option><option value="BYN">Belarusian Ruble</option><option value="BZD">Belize Dollar</option><option value="CAD">Canadian Dollar</option><option value="CDF">Congolese Franc</option><option value="CHF">Swiss Franc</option><option value="CLF">Chilean Unit of Account (UF)</option><option value="CLP">Chilean Peso</option><option value="CNH">Chinese Yuan (Offshore)</option><option value="CNY">Chinese Yuan</option><option value="COP">Colombian Peso</option><option value="CRC">Costa Rican Colón</option><option value="CUC">Cuban Convertible Peso</option><option value="CUP">Cuban Peso</option><option value="CVE">Cape Verdean Escudo</option><option value="CZK">Czech Republic Koruna</option><option value="DJF">Djiboutian Franc</option><option value="DKK">Danish Krone</option><option value="DOP">Dominican Peso</option><option value="DZD">Algerian Dinar</option><option value="EGP">Egyptian Pound</option><option value="ERN">Eritrean Nakfa</option><option value="ETB">Ethiopian Birr</option><option value="EUR">Euro</option><option value="FJD">Fijian Dollar</option><option value="FKP">Falkland Islands Pound</option><option value="GBP">British Pound Sterling</option><option value="GEL">Georgian Lari</option><option value="GGP">Guernsey Pound</option><option value="GHS">Ghanaian Cedi</option><option value="GIP">Gibraltar Pound</option><option value="GMD">Gambian Dalasi</option><option value="GNF">Guinean Franc</option><option value="GTQ">Guatemalan Quetzal</option><option value="GYD">Guyanaese Dollar</option><option value="HKD">Hong Kong Dollar</option><option value="HNL">Honduran Lempira</option><option value="HRK">Croatian Kuna</option><option value="HTG">Haitian Gourde</option><option value="HUF">Hungarian Forint</option><option value="IDR">Indonesian Rupiah</option><option value="ILS">Israeli New Sheqel</option><option value="IMP">Manx pound</option><option value="INR">Indian Rupee</option><option value="IQD">Iraqi Dinar</option><option value="IRR">Iranian Rial</option><option value="ISK">Icelandic Króna</option><option value="JEP">Jersey Pound</option><option value="JMD">Jamaican Dollar</option><option value="JOD">Jordanian Dinar</option><option value="JPY">Japanese Yen</option><option value="KES">Kenyan Shilling</option><option value="KGS">Kyrgystani Som</option><option value="KHR">Cambodian Riel</option><option value="KMF">Comorian Franc</option><option value="KPW">North Korean Won</option><option value="KRW">South Korean Won</option><option value="KWD">Kuwaiti Dinar</option><option value="KYD">Cayman Islands Dollar</option><option value="KZT">Kazakhstani Tenge</option><option value="LAK">Laotian Kip</option><option value="LBP">Lebanese Pound</option><option value="LKR">Sri Lankan Rupee</option><option value="LRD">Liberian Dollar</option><option value="LSL">Lesotho Loti</option><option value="LYD">Libyan Dinar</option><option value="MAD">Moroccan Dirham</option><option value="MDL">Moldovan Leu</option><option value="MGA">Malagasy Ariary</option><option value="MKD">Macedonian Denar</option><option value="MMK">Myanma Kyat</option><option value="MNT">Mongolian Tugrik</option><option value="MOP">Macanese Pataca</option><option value="MRU">Mauritanian Ouguiya</option><option value="MUR">Mauritian Rupee</option><option value="MVR">Maldivian Rufiyaa</option><option value="MWK">Malawian Kwacha</option><option value="MXN">Mexican Peso</option><option value="MYR">Malaysian Ringgit</option><option value="MZN">Mozambican Metical</option><option value="NAD">Namibian Dollar</option><option value="NGN">Nigerian Naira</option><option value="NIO">Nicaraguan Córdoba</option><option value="NOK">Norwegian Krone</option><option value="NPR">Nepalese Rupee</option><option value="NZD">New Zealand Dollar</option><option value="OMR">Omani Rial</option><option value="PAB">Panamanian Balboa</option><option value="PEN">Peruvian Nuevo Sol</option><option value="PGK">Papua New Guinean Kina</option><option value="PHP">Philippine Peso</option><option value="PKR">Pakistani Rupee</option><option value="PLN">Polish Zloty</option><option value="PYG">Paraguayan Guarani</option><option value="QAR">Qatari Rial</option><option value="RON">Romanian Leu</option><option value="RSD">Serbian Dinar</option><option value="RUB">Russian Ruble</option><option value="RWF">Rwandan Franc</option><option value="SAR">Saudi Riyal</option><option value="SBD">Solomon Islands Dollar</option><option value="SCR">Seychellois Rupee</option><option value="SDG">Sudanese Pound</option><option value="SEK">Swedish Krona</option><option value="SGD">Singapore Dollar</option><option value="SHP">Saint Helena Pound</option><option value="SLL">Sierra Leonean Leone</option><option value="SOS">Somali Shilling</option><option value="SRD">Surinamese Dollar</option><option value="SSP">South Sudanese Pound</option><option value="STD">São Tomé and Príncipe Dobra (pre-2018)</option><option value="STN">São Tomé and Príncipe Dobra</option><option value="SVC">Salvadoran Colón</option><option value="SYP">Syrian Pound</option><option value="SZL">Swazi Lilangeni</option><option value="THB">Thai Baht</option><option value="TJS">Tajikistani Somoni</option><option value="TMT">Turkmenistani Manat</option><option value="TND">Tunisian Dinar</option><option value="TOP">Tongan Pa'anga</option><option value="TRY">Turkish Lira</option><option value="TTD">Trinidad and Tobago Dollar</option><option value="TWD">New Taiwan Dollar</option><option value="TZS">Tanzanian Shilling</option><option value="UAH">Ukrainian Hryvnia</option><option value="UGX">Ugandan Shilling</option><option value="USD">United States Dollar</option><option value="UYU">Uruguayan Peso</option><option value="UZS">Uzbekistan Som</option><option value="VEF">Venezuelan Bolívar Fuerte (Old)</option><option value="VES">Venezuelan Bolívar Soberano</option><option value="VND">Vietnamese Dong</option><option value="VUV">Vanuatu Vatu</option><option value="WST">Samoan Tala</option><option value="XAF">CFA Franc BEAC</option><option value="XAG">Silver Ounce</option><option value="XAU">Gold Ounce</option><option value="XCD">East Caribbean Dollar</option><option value="XDR">Special Drawing Rights</option><option value="XOF">CFA Franc BCEAO</option><option value="XPD">Palladium Ounce</option><option value="XPF">CFP Franc</option><option value="XPT">Platinum Ounce</option><option value="YER">Yemeni Rial</option><option value="ZAR">South African Rand</option><option value="ZMW">Zambian Kwacha</option><option value="ZWL">Zimbabwean Dollar</option></select>
                            </div>
                        </div>
                        <div id="bxc-checkout-type" data-type="select" class="bxc-input">
                            <span>
                                <?php bxc_e('Type') ?>
                            </span>
                            <select>
                                <option value="I" selected><?php bxc_e('Inline') ?></option>
                                <option value="L"><?php bxc_e('Link') ?></option>
                                <option value="P"><?php bxc_e('Popup') ?></option>
                                <option value="H"><?php bxc_e('Hidden') ?></option>
                            </select>
                        </div>
                        <div id="bxc-checkout-redirect" class="bxc-input">
                            <span>
                                <?php bxc_e('Redirect URL') ?>
                            </span>
                            <input type="url" />
                        </div>
                        <div id="bxc-checkout-external_reference" class="bxc-input">
                            <span>
                                <?php bxc_e('External reference') ?>
                            </span>
                            <input type="text" />
                        </div>
                        <?php if (defined('BXC_WP')) echo '<div id="bxc-checkout-shortcode" class="bxc-input"><span>Shortcode</span><div></div><i class="bxc-icon-copy bxc-clipboard bxc-toolip-cnt"><span class="bxc-toolip">' . bxc_('Copy to clipboard') . '</span></i></div>' ?>
                        <div id="bxc-checkout-embed-code" class="bxc-input">
                            <span>
                                <?php bxc_e('Embed code') ?>
                            </span>
                            <div></div>
                            <i class="bxc-icon-copy bxc-clipboard bxc-toolip-cnt">
                                <span class="bxc-toolip"><?php bxc_e('Copy to clipboard') ?></span>
                            </i>
                        </div>
                        <div id="bxc-checkout-payment-link" class="bxc-input">
                            <span>
                                <?php bxc_e('Payment link') ?>
                            </span>
                            <div></div>
                            <i class="bxc-icon-copy bxc-clipboard bxc-toolip-cnt">
                                <span class="bxc-toolip"><?php bxc_e('Copy to clipboard') ?></span>
                            </i>
                        </div>
                        <div class="bxc-bottom">
                            <div id="bxc-save-checkout" class="bxc-btn">
                                <?php bxc_e('Save checkout') ?>
                            </div>
                            <a id="bxc-delete-checkout" class="bxc-btn-icon bxc-btn-red">
                                <i class="bxc-icon-delete"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            <div data-area="balance">
                <div>
                    <div id="bxc-balance-total" class="bxc-title"></div>
                    <div class="bxc-text">
                        <?php bxc_e('Available balance') ?>
                    </div>
                </div>
                <table id="bxc-table-balances" class="bxc-table">
                    <thead>
                        <tr>
                            <th data-field="cryptocurrency">
                                <?php bxc_e('Crypto currency') ?>
                            </th>
                            <th data-field="balance">
                                <?php bxc_e('Balance') ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div data-area="settings" class="bxc-loading">
                <?php bxc_settings_populate() ?>
            </div>
        </main>
    </div>
    <div id="bxc-card" class="bxc-info-card"></div>
</div>
<?php } 

function bxc_debug($value) {
    $value = is_string($value) ? $value : json_encode($value);
    if (file_exists('debug.txt')) {
        $value = file_get_contents('debug.txt') . PHP_EOL . $value;
    }
    bxc_file('debug.txt', $value);
}

function bxc_file($path, $content) {
    try {
        $file = fopen($path, 'w');
        fwrite($file, $content);
        fclose($file);
        return true;
    }
    catch (Exception $e) {
        return $e->getMessage();
    }
}

function bxc_csv($rows, $header, $filename) {
    $filename .= '-' . rand(999999,999999999) . '.csv';
    $file = fopen(__DIR__ . '/' . $filename, 'w');
    if ($header) {
        fputcsv($file, $header);
    }
    for ($i = 0; $i < count($rows); $i++) {
    	fputcsv($file, $rows[$i]);
    }
    fclose($file);
    return BXC_URL . '/' . $filename;
}

?>