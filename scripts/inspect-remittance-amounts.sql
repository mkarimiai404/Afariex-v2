-- SELECT-only diagnostic for IRT-to-AFN remittances.
-- Shows both candidate rate directions beside the saved amount; it changes no data.
SELECT
    r.id AS remittance_id,
    r.created_at,
    r.amount_toman,
    r.amount_afghani AS saved_amount_afghani,
    COALESCE(r.source_currency, 'IRT') AS source_currency,
    COALESCE(r.destination_currency, 'AFN') AS destination_currency,
    r.exchange_rate_id,
    r.exchange_rate_value AS saved_rate_value,
    er.exchange_rate AS linked_rate_value,
    er.afn_to_toman AS linked_legacy_toman_per_afn,
    r.exchange_commission_percentage AS commission_percentage,
    CASE
        WHEN COALESCE(r.exchange_rate_value, er.exchange_rate) > 0 THEN
            ROUND(
                r.amount_toman * COALESCE(r.exchange_rate_value, er.exchange_rate)
                * (1 - COALESCE(r.exchange_commission_percentage, 0) / 100),
                2
            )
        ELSE NULL
    END AS candidate_if_rate_is_afn_per_toman,
    CASE
        WHEN COALESCE(er.afn_to_toman, r.exchange_rate_value, er.exchange_rate) > 0 THEN
            ROUND(
                r.amount_toman / COALESCE(er.afn_to_toman, r.exchange_rate_value, er.exchange_rate)
                * (1 - COALESCE(r.exchange_commission_percentage, 0) / 100),
                2
            )
        ELSE NULL
    END AS candidate_if_rate_is_toman_per_afn
FROM remittances AS r
LEFT JOIN exchange_rates AS er
    ON er.id = r.exchange_rate_id
WHERE COALESCE(r.source_currency, 'IRT') = 'IRT'
  AND COALESCE(r.destination_currency, 'AFN') = 'AFN'
ORDER BY r.id DESC;
