<?php
declare(strict_types=1);

require_once __DIR__ . '/../API/includes/remittance_currencies.php';

function exchange_rate_supported_currencies(): array
{
    return array_merge(remittance_source_currencies(), remittance_destination_currencies());
}

function exchange_rate_decimal_is_valid(string $value, bool $allowZero = false): bool
{
    if (strlen($value) > 32 || preg_match('/^(?:0|[1-9]\d{0,17})(?:\.\d{1,12})?$/', $value) !== 1) return false;
    $number = (float)$value;
    return is_finite($number) && ($allowZero ? $number >= 0 : $number > 0);
}

/**
 * Convert a rate row to the directional convention used by app APIs:
 * destination currency units per one source currency unit.
 * The legacy IRT/AFN field afn_to_toman stores Toman per one Afghani.
 */
function exchange_rate_normalize_directional_row(array $row): array
{
    $source = strtoupper(trim((string)($row['source_currency'] ?? '')));
    $destination = strtoupper(trim((string)($row['destination_currency'] ?? '')));
    $legacyTomanPerAfn = $row['toman_per_afn'] ?? null;
    if ($source === 'IRT' && $destination === 'AFN' && is_numeric($legacyTomanPerAfn)) {
        $legacyRate = (float)$legacyTomanPerAfn;
        if (is_finite($legacyRate) && $legacyRate > 0) {
            $row['exchange_rate'] = number_format(1 / $legacyRate, 12, '.', '');
            $row['toman_per_afn'] = (string)$legacyTomanPerAfn;
        }
    }
    return $row;
}

function exchange_rate_greatest_common_divisor(int $left, int $right): int
{
    while ($right !== 0) {
        [$left, $right] = [$right, $left % $right];
    }
    return max(1, $left);
}

function exchange_rate_parse_minor_units(string $value, int $precision = 2): ?int
{
    $precision = max(0, min(2, $precision));
    $fractionPattern = $precision === 0 ? '' : '(?:\\.(\\d{1,' . $precision . '}))?';
    if (preg_match('/^(0|[1-9]\\d{0,15})' . $fractionPattern . '$/', trim($value), $parts) !== 1) return null;
    $whole = (int)$parts[1];
    $fraction = $precision > 0 ? (string)($parts[2] ?? '') : '';
    $scale = 10 ** $precision;
    $fractionValue = $precision > 0 ? (int)str_pad($fraction, $precision, '0') : 0;
    if ($whole > intdiv(PHP_INT_MAX - $fractionValue, $scale)) return null;
    return ($whole * $scale) + $fractionValue;
}

function exchange_rate_client_amount_matches_calculation(string $clientAmount, string $calculatedAmount, int $toleranceMinorUnits = 5): bool
{
    $clientMinorUnits = exchange_rate_parse_minor_units($clientAmount, 2);
    $calculatedMinorUnits = exchange_rate_parse_minor_units($calculatedAmount, 2);
    return $clientMinorUnits !== null
        && $calculatedMinorUnits !== null
        && abs($clientMinorUnits - $calculatedMinorUnits) <= max(0, $toleranceMinorUnits);
}

/** Calculate destination minor units with integer ratio arithmetic and one half-up rounding. */
function exchange_rate_calculate_destination_amount(string $sourceAmount, string $rate, string $commission = '0'): ?string
{
    $sourceMinorUnits = exchange_rate_parse_minor_units($sourceAmount, 2);
    if ($sourceMinorUnits === null || $sourceMinorUnits <= 0) return null;
    if (preg_match('/^(0|[1-9]\\d{0,17})(?:\\.(\\d{1,12}))?$/', trim($rate), $rateParts) !== 1) return null;
    if (preg_match('/^(0|[1-9]\\d{0,2})(?:\\.(\\d{1,4}))?$/', trim($commission), $commissionParts) !== 1) return null;

    $rateFraction = (string)($rateParts[2] ?? '');
    $rateScale = 10 ** strlen($rateFraction);
    $rateNumeratorText = ltrim($rateParts[1] . $rateFraction, '0');
    $maxIntegerText = (string)PHP_INT_MAX;
    if ($rateNumeratorText === '' || strlen($rateNumeratorText) > strlen($maxIntegerText)
        || (strlen($rateNumeratorText) === strlen($maxIntegerText) && strcmp($rateNumeratorText, $maxIntegerText) > 0)) return null;
    $rateNumerator = (int)$rateNumeratorText;
    if ($rateNumerator <= 0) return null;

    $commissionFraction = (string)($commissionParts[2] ?? '');
    $commissionScale = 10 ** strlen($commissionFraction);
    $commissionNumerator = (int)($commissionParts[1] . $commissionFraction);
    $commissionDenominator = 100 * $commissionScale;
    $commissionFactor = $commissionDenominator - $commissionNumerator;
    if ($commissionFactor < 0) return null;

    // Reduce before multiplying so valid financial values stay within PHP_INT_MAX.
    $numeratorFactors = [$sourceMinorUnits, $rateNumerator, $commissionFactor];
    $denominatorFactors = [$rateScale, $commissionDenominator];
    foreach ($numeratorFactors as $numeratorIndex => $numeratorFactor) {
        foreach ($denominatorFactors as $denominatorIndex => $denominatorFactor) {
            $gcd = exchange_rate_greatest_common_divisor($numeratorFactor, $denominatorFactor);
            $numeratorFactor = intdiv($numeratorFactor, $gcd);
            $denominatorFactors[$denominatorIndex] = intdiv($denominatorFactor, $gcd);
        }
        $numeratorFactors[$numeratorIndex] = $numeratorFactor;
    }

    $numerator = 1;
    foreach ($numeratorFactors as $factor) {
        if ($factor !== 0 && $numerator > intdiv(PHP_INT_MAX, $factor)) return null;
        $numerator *= $factor;
    }
    $denominator = 1;
    foreach ($denominatorFactors as $factor) {
        if ($factor !== 0 && $denominator > intdiv(PHP_INT_MAX, $factor)) return null;
        $denominator *= $factor;
    }
    if ($denominator <= 0) return null;

    $minorUnits = intdiv($numerator, $denominator);
    $remainder = $numerator % $denominator;
    if ($remainder >= $denominator - $remainder) $minorUnits++;
    if ($minorUnits <= 0) return null;

    return intdiv($minorUnits, 100) . '.' . str_pad((string)($minorUnits % 100), 2, '0', STR_PAD_LEFT);
}

function exchange_rate_current_pair(string $source, string $destination, bool $forUpdate = false): ?array
{
    $columns = db()->query('SHOW COLUMNS FROM exchange_rates')->fetchAll(PDO::FETCH_COLUMN, 0);
    $legacySelect = in_array('afn_to_toman', $columns, true) ? ', afn_to_toman AS toman_per_afn' : '';
    $sql = 'SELECT id, source_currency, destination_currency, exchange_rate, commission_percentage, effective_date, is_active' . $legacySelect . ' FROM exchange_rates WHERE source_currency = ? AND destination_currency = ? AND is_active = 1 AND effective_date <= CURRENT_DATE ORDER BY effective_date DESC, id DESC LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = db()->prepare($sql);
    $stmt->execute([strtoupper(trim($source)), strtoupper(trim($destination))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return $row ? exchange_rate_normalize_directional_row($row) : null;
}

function exchange_rate_lock_pair(string $source, string $destination): void
{
    $stmt = db()->prepare('SELECT id FROM exchange_rates WHERE source_currency = ? AND destination_currency = ? ORDER BY id FOR UPDATE');
    $stmt->execute([strtoupper(trim($source)), strtoupper(trim($destination))]);
    $stmt->fetchAll(PDO::FETCH_COLUMN);
}
