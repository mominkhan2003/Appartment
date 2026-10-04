-- ===========================================================================
--  FlatMate - shared house fund patch (safe to re-run, never deletes data)
-- ---------------------------------------------------------------------------
--  Run this to add the house fund to an EXISTING database. Importing
--  sql/schema.sql will NOT work for that: it DROPs every table first and
--  would wipe your residents, expenses, meals and chores.
--
--  What it adds:
--    * expenses.paid_from_fund  -- marks a grocery/bill as drawn from the
--      shared pot rather than from one member's own pocket
--    * contributions            -- cash a resident hands over to the pot
--    * vw_house_fund            -- per member: share owed, contributed, outstanding
--    * vw_fund_totals           -- the pot's own balance
--    * vw_balance_sheet         -- REPLACED, now excludes fund-paid expenses
--
--  Why vw_balance_sheet has to change: it credits total_paid to whoever
--  logged the expense. Without the exclusion, the member who physically shops
--  for the week is shown as a creditor for money the household owes them
--  nothing for, and the pairwise settle board starts suggesting nonsense
--  transfers. Fund-paid rows move to vw_house_fund, which is the only place
--  that arithmetic belongs.
--
--  This patch is additive and reversible: DROP the two views, DROP the
--  contributions table, and DROP the expenses.paid_from_fund column to go back.
--
--  Run it with:  mysql -u USER -p DBNAME < sql/patch_house_fund.sql
--  or paste into phpMyAdmin -> SQL. Select the database first.
-- ===========================================================================


-- ---------------------------------------------------------------------------
--  0. Before you start: confirm you are in the right database.
-- ---------------------------------------------------------------------------
SELECT DATABASE() AS db_in_use, VERSION() AS mysql_version;


-- ---------------------------------------------------------------------------
--  1. expenses.paid_from_fund
--  ADD COLUMN has no IF NOT EXISTS in MySQL, so this is guarded by probing
--  information_schema first. Re-running is therefore harmless.
-- ---------------------------------------------------------------------------
SET @has_fund_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'expenses'
     AND COLUMN_NAME  = 'paid_from_fund'
);
SET @add_fund_col := IF(
  @has_fund_col = 0,
  'ALTER TABLE `expenses` ADD COLUMN `paid_from_fund` TINYINT(1) NOT NULL DEFAULT 0',
  'DO 0'
);
PREPARE stmt_fund_col FROM @add_fund_col;
EXECUTE stmt_fund_col;
DEALLOCATE PREPARE stmt_fund_col;


-- ---------------------------------------------------------------------------
--  2. contributions -- cash handed over to the pot
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contributions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id`   INT UNSIGNED NOT NULL,
  `from_user_id`   INT UNSIGNED NOT NULL COMMENT 'Resident who handed the cash over.',
  `to_user_id`     INT UNSIGNED NULL COMMENT 'Custodian who received it; NULL = house fund.',
  `amount`         DECIMAL(12,2) NOT NULL CHECK (`amount` > 0),
  `method`         ENUM('cash','bkash','nagad','bank','other') NOT NULL DEFAULT 'cash',
  `reference`      VARCHAR(120) NULL,
  `note`           VARCHAR(500) NULL,
  `period_key`     VARCHAR(7)   NULL COMMENT 'YYYY-MM the collection this belongs to.',
  `collected_by`   INT UNSIGNED NULL COMMENT 'Set when recorded during a collection.',
  `contributed_on` DATE         NOT NULL,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contrib_apartment` (`apartment_id`, `contributed_on`),
  KEY `idx_contrib_from`      (`from_user_id`, `contributed_on`),
  KEY `idx_contrib_period`    (`apartment_id`, `period_key`),
  CONSTRAINT `fk_contrib_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_contrib_from` FOREIGN KEY (`from_user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_contrib_to` FOREIGN KEY (`to_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_contrib_collected` FOREIGN KEY (`collected_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_contrib_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cash into the shared fund. Fund balance = SUM(amount) - fund-paid expenses.';


-- ---------------------------------------------------------------------------
--  3. vw_balance_sheet -- replaces any older definition in place
--  Identical to schema.sql. Two changes from the previous definition:
--    * total_paid now ignores paid_from_fund = 1
--    * total_owed now ignores splits belonging to paid_from_fund = 1
--  Both are required, otherwise the shopper is credited for household money
--  and members are charged twice for the same grocery run.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_balance_sheet` AS
SELECT
  u.`apartment_id`,
  u.`id`                                    AS `user_id`,
  u.`full_name`,
  u.`participant_code`,
  u.`status`,
  r.`code`                                  AS `room_code`,
  g.`name`                                  AS `duty_group`,
  COALESCE(p.`total_paid`, 0)               AS `total_paid`,
  COALESCE(s.`total_owed`, 0)               AS `total_owed`,
  COALESCE(p.`total_paid`, 0) - COALESCE(s.`total_owed`, 0) - COALESCE(t.`total_sent`, 0)
                                      + COALESCE(t.`total_received`, 0) AS `net_balance`
FROM `users` u
LEFT JOIN `rooms`       r ON r.`id` = u.`room_id`
LEFT JOIN `duty_groups` g ON g.`id` = u.`duty_group_id`
LEFT JOIN (
  -- paid_from_fund = 1 is excluded on purpose. That money came out of the
  -- shared pot, not out of the member's own pocket, so crediting it here would
  -- make the shopper look like a creditor for money the household owes.
  -- vw_house_fund accounts for those rows instead.
  SELECT `paid_by_user_id` AS uid, SUM(`amount`) AS total_paid
  FROM `expenses` WHERE `is_deleted` = 0 AND `paid_from_fund` = 0
  GROUP BY `paid_by_user_id`
) p ON p.`uid` = u.`id`
LEFT JOIN (
  SELECT `user_id` AS uid, SUM(`share_amount`) AS total_owed
  FROM `expense_splits` es
  JOIN `expenses` e ON e.`id` = es.`expense_id`
                  AND e.`is_deleted` = 0 AND e.`paid_from_fund` = 0
  GROUP BY `user_id`
) s ON s.`uid` = u.`id`
LEFT JOIN (
  -- A user can both send and receive settlements, which the UNION ALL below
  -- reports as two rows. They must be collapsed before joining, otherwise the
  -- join multiplies the user's paid/owed totals AND one settlement amount is
  -- silently overwritten when results are keyed by user_id -- the net balances
  -- stop summing to zero and DebtSimplifier rejects the ledger (HTTP 400).
  SELECT uid,
         SUM(total_sent)     AS total_sent,
         SUM(total_received) AS total_received
  FROM (
    SELECT `from_user_id` AS uid, SUM(`amount`) AS total_sent, 0 AS total_received
    FROM `settlements` GROUP BY `from_user_id`
    UNION ALL
    SELECT `to_user_id`, 0, SUM(`amount`)
    FROM `settlements` GROUP BY `to_user_id`
  ) settled
  GROUP BY uid
) t ON t.`uid` = u.`id`
WHERE u.`status` IN ('active', 'invited');


-- ---------------------------------------------------------------------------
--  4. vw_house_fund -- per member collection figures
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_house_fund` AS
SELECT
  u.`apartment_id`,
  u.`id`                  AS `user_id`,
  u.`full_name`,
  u.`participant_code`,
  u.`status`,
  r.`code`                AS `room_code`,
  COALESCE(owed.`share_owed`, 0)    AS `share_owed`,
  COALESCE(paid.`contributed`, 0)   AS `contributed`,
  COALESCE(owed.`share_owed`, 0) - COALESCE(paid.`contributed`, 0)
                                        AS `outstanding`
FROM `users` u
LEFT JOIN `rooms` r ON r.`id` = u.`room_id`
LEFT JOIN (
  SELECT es.`user_id` AS uid, SUM(es.`share_amount`) AS share_owed
  FROM `expense_splits` es
  JOIN `expenses` e ON e.`id` = es.`expense_id`
                  AND e.`is_deleted` = 0 AND e.`paid_from_fund` = 1
  GROUP BY es.`user_id`
) owed ON owed.`uid` = u.`id`
LEFT JOIN (
  SELECT `from_user_id` AS uid, SUM(`amount`) AS contributed
  FROM `contributions`
  GROUP BY `from_user_id`
) paid ON paid.`uid` = u.`id`
WHERE u.`status` IN ('active', 'invited');


-- ---------------------------------------------------------------------------
--  5. vw_fund_totals -- the pot's own balance
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_fund_totals` AS
SELECT
  a.`id`                       AS `apartment_id`,
  COALESCE(ci.`total_in`, 0)   AS `total_contributed`,
  COALESCE(co.`total_out`, 0)  AS `total_spent`,
  COALESCE(ci.`total_in`, 0) - COALESCE(co.`total_out`, 0) AS `balance`
FROM `apartments` a
LEFT JOIN (
  SELECT `apartment_id`, SUM(`amount`) AS total_in
  FROM `contributions` GROUP BY `apartment_id`
) ci ON ci.`apartment_id` = a.`id`
LEFT JOIN (
  SELECT `apartment_id`, SUM(`amount`) AS total_out
  FROM `expenses`
  WHERE `is_deleted` = 0 AND `paid_from_fund` = 1
  GROUP BY `apartment_id`
) co ON co.`apartment_id` = a.`id`;


-- ---------------------------------------------------------------------------
--  6. Confirm the money still adds up.
--  net_balance must still sum to zero across all active members, otherwise
--  DebtSimplifier will reject the ledger and the expenses page returns HTTP
--  400. Members with fund activity will show 0 here by design: the pot is
--  tracked in vw_house_fund, not here.
-- ---------------------------------------------------------------------------
SELECT
  ROUND(COALESCE(SUM(`net_balance`), 0), 2) AS `net_balance_sum`,
  CASE WHEN ROUND(COALESCE(SUM(`net_balance`), 0), 2) = 0
       THEN 'OK' ELSE 'MISMATCH - investigate before using the app' END AS `check_result`
FROM `vw_balance_sheet`;

SELECT `apartment_id`, `total_contributed`, `total_spent`, `balance`
FROM `vw_fund_totals`;
