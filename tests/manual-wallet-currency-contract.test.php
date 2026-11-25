<?php
declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$users = file_get_contents(__DIR__ . '/../admin_panel/users.php');
$statement = file_get_contents(__DIR__ . '/../admin_panel/user_statement.php');
$currencies = file_get_contents(__DIR__ . '/../API/includes/remittance_currencies.php');
$assert(is_string($users) && is_string($statement) && is_string($currencies), 'manual wallet, statement, and currency sources are readable');

$manualStart = strpos($users, 'if ($action === \'manual_operation\')');
$manualEnd = strpos($users, 'if ($action === \'create\')', $manualStart === false ? 0 : $manualStart);
$manualFlow = $manualStart !== false && $manualEnd !== false ? substr($users, $manualStart, $manualEnd - $manualStart) : '';
$assert(str_contains($manualFlow, '$manualCurrencyRegistry') && str_contains($manualFlow, '!in_array($currencyCode, $manualCurrencies, true)'), 'manual operation validates currencies against the supported registry');
$irtAmountAssignment = strpos($manualFlow, '$walletAmount = $amountInput;');
$foreignConversion = strpos($manualFlow, 'if (in_array($manualAction, [\'increase\', \'decrease\'], true) && $currencyCode !== \'IRT\')');
$assert($irtAmountAssignment !== false && $foreignConversion !== false && $irtAmountAssignment < $foreignConversion
    && str_contains($manualFlow, 'debt_apply_user_credit(db(), $userId, $walletAmount')
    && str_contains($manualFlow, '$updateStmt->execute([$walletAmount, $userId, $walletAmount])'), 'IRT amount is passed unchanged to increase and decrease wallet paths');
$assert(str_contains($manualFlow, 'exchange_rate_current_pair($currencyCode, \'IRT\', true)')
    && str_contains($manualFlow, 'exchange_rate_current_pair(\'IRT\', $currencyCode, true)')
    && str_contains($manualFlow, 'exchange_rate_calculate_destination_amount(')
    && str_contains($manualFlow, '$transactionCurrencyCode = \'IRT\''), 'foreign amounts use an active direct or inverse management rate to convert into IRT');
$assert(!str_contains($manualFlow, 'currency_wallets')
    && str_contains($manualFlow, 'debt_apply_user_credit(db(), $userId, $walletAmount')
    && str_contains($manualFlow, 'UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?'), 'manual credits and guarded debits affect the main IRT balance');
$assert(str_contains($manualFlow, '$manualRequiredTransactionColumns')
    && str_contains($manualFlow, '\'user_id\' => $userId')
    && str_contains($manualFlow, '\'type\' => $transactionType')
    && str_contains($manualFlow, '\'status\' => \'approved\'')
    && substr_count($manualFlow, '$transactionStmt->execute(array_values($transactionData))') === 1
    && str_contains($manualFlow, 'MANUAL_TRANSACTION_INSERT_FAILED'), 'one verified statement-visible transaction is persisted for each manual increase or decrease');
$assert(str_contains($manualFlow, '\'amount\' => $manualAction === \'remittance\' ? $manualTotalDeduction : $walletAmount')
    && str_contains($manualFlow, '$transactionData[\'currency_code\'] = $transactionCurrencyCode')
    && str_contains($manualFlow, 'تبدیل دستی: {$amountInput} {$currencyCode} به {$walletAmount} IRT')
    && str_contains($manualFlow, '{$rateDescription}'), 'ledger stores the IRT equivalent and source amount, currency, and applied rate');
$begin = strpos($manualFlow, 'db()->beginTransaction();');
$ledgerInsert = strpos($manualFlow, "INSERT INTO transactions");
$commit = strpos($manualFlow, 'db()->commit();');
$assert($begin !== false && $ledgerInsert !== false && $commit !== false && $begin < $ledgerInsert && $ledgerInsert < $commit && str_contains($manualFlow, 'db()->rollBack();'), 'wallet changes and ledger insertion are committed or rolled back together');
$assert(str_contains($statement, '\'currency_code\', \'currency\'') && str_contains($statement, '\'manual_deposit\'') && str_contains($statement, '\'manual_withdrawal\'')
    && str_contains($statement, 'statement_money((float)$row[\'amount\'], statement_currency_code($row[\'currency\'] ?? \'IRT\'))')
    && str_contains($statement, 'statement_normalized_description($type, (string)($row[\'description\'] ?? \'\'))')
    && str_contains($statement, '$balanceBeforeSelect')
    && str_contains($statement, '$balanceAfterSelect')
    && str_contains($statement, 'statement_assign_running_balances($allRows, $currencySummaries)'), 'statement preserves transaction audit details and calculates balances from currency-specific snapshots');
$runningCalculation = strpos($statement, '$allRows = statement_assign_running_balances($allRows, $currencySummaries);');
$currencyFilter = strpos($statement, 'if ($currencyFilter !== null && $currencyFilter !== \'\')');
$assert($runningCalculation !== false && $currencyFilter !== false && $runningCalculation < $currencyFilter, 'currency filtering is applied after the full cross-currency ledger has been accumulated');
$assert(str_contains($users, 'data-money-unit="تومان"') && str_contains($users, "amountInput.dataset.moneyUnit = code === 'IRT' ? 'تومان'")
    && str_contains($users, 'data-money-unit="<?= e($currency[\'name\']) ?>"'), 'amount-in-words unit follows the selected wallet currency');
$assert(str_contains($users, 'manualOperationCurrencyHint')
    && str_contains($users, 'مبلغ واردشده مستقیماً به تومان اعمال می‌شود.')
    && str_contains($users, 'مبلغ واردشده بر اساس ارز انتخاب‌شده است و با نرخ فعال به تومان تبدیل می‌شود.'), 'amount semantics are clear for IRT and foreign currencies');
$assert(str_contains($currencies, "'AFN' =>") && str_contains($currencies, "'USD' =>"), 'AFN and USD are supported by the existing currency registry');

echo "Manual wallet currency contract tests passed ({$assertions} assertions)." . PHP_EOL;
