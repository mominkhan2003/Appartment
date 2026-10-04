-- ===========================================================================
--  FlatMate - runtime fix patch (safe to re-run, never deletes data)
-- ===========================================================================
--  Run this instead of re-importing sql/schema.sql when you only need the
--  latest view definitions. Importing schema.sql DROPs every table first, so
--  running it to apply a fix wipes your residents, expenses and chores.
--
--  What this file does:
--    * CREATE OR REPLACE VIEW for all three views, verbatim from schema.sql
--    * no DROP TABLE, no DELETE, no TRUNCATE, no schema migration
--
--  Why the views changed:
--    vw_balance_sheet built its settlement totals as a UNION ALL of
--    sender-grouped and receiver-grouped aggregates and joined that straight
--    on. A resident who both paid and received produced TWO rows, so the join
--    duplicated their paid/owed totals and keying by user_id dropped a row.
--    The net balances then failed to sum to zero and DebtSimplifier rejected
--    the ledger, which is why the dashboard answered HTTP 400. The union is
--    now collapsed with an outer GROUP BY, giving exactly one row per user.
--
--  Re-running is safe: CREATE OR REPLACE VIEW swaps the definition in place and
--  keeps the data the view reads. Nothing here can lose a row.
--
--  Run it with:  mysql -u USER -p DBNAME < sql/patch.sql
--  or paste into phpMyAdmin -> SQL. Select the database first.
-- ===========================================================================


-- ---------------------------------------------------------------------------
--  0. Before you start: confirm you are in the right database.
-- ---------------------------------------------------------------------------
SELECT DATABASE() AS db_in_use, VERSION() AS mysql_version;


-- ---------------------------------------------------------------------------
--  1. Views
-- ---------------------------------------------------------------------------

-- vw_balance_sheet -- replaces any older definition in place
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

-- vw_today_chores -- replaces any older definition in place
CREATE OR REPLACE VIEW `vw_today_chores` AS
SELECT
  t.`id`            AS `task_id`,
  t.`task_date`,
  t.`status`,
  t.`completed_at`,
  t.`assigned_user_id`,
  u.`full_name`     AS `assignee_name`,
  u.`participant_code`,
  u.`avatar_color`,
  a.`id`            AS `area_id`,
  a.`name`          AS `area_name`,
  a.`icon`          AS `area_icon`,
  a.`scope`,
  a.`points`,
  a.`apartment_id`,
  g.`name`          AS `duty_group`,
  r.`code`          AS `room_code`
FROM `chore_tasks` t
JOIN `chore_areas` a  ON a.`id` = t.`chore_area_id`
LEFT JOIN `users` u      ON u.`id` = t.`assigned_user_id`
LEFT JOIN `duty_groups` g ON g.`id` = a.`duty_group_id`
LEFT JOIN `rooms` r       ON r.`id` = a.`room_id`;

-- vw_meal_coverage -- replaces any older definition in place
CREATE OR REPLACE VIEW `vw_meal_coverage` AS
SELECT
  m.`id`             AS `meal_id`,
  m.`meal_plan_id`,
  m.`day_of_week`,
  m.`meal_type`,
  m.`menu_title`,
  COALESCE(SUM(mp.`status` = 'eating'), 0)        AS `eaters`,
  COALESCE(SUM(mp.`status` = 'opting_out'), 0)    AS `opting_out`,
  COUNT(mp.`id`)                                  AS `responded`,
  (SELECT COUNT(*) FROM `users` x
     WHERE x.`apartment_id` = (SELECT p.`apartment_id` FROM `meal_plans` p WHERE p.`id` = m.`meal_plan_id`)
       AND x.`status` = 'active')                 AS `total_residents`
FROM `meals` m
LEFT JOIN `meal_participants` mp ON mp.`meal_id` = m.`id`
GROUP BY m.`id`, m.`meal_plan_id`, m.`day_of_week`, m.`meal_type`, m.`menu_title`;


-- ---------------------------------------------------------------------------
--  2. Verify
-- ---------------------------------------------------------------------------
-- Every net balance must cancel out. The `balanced` column must read OK
-- (BALANCED) -- if it reads BROKEN, the old view is still installed.
SELECT
  COUNT(*)                                    AS rows_returned,
  CAST(COALESCE(SUM(`net_balance`), 0) AS DECIMAL(14,2)) AS net_total,
  IF(COALESCE(SUM(`net_balance`), 0) = 0, 'BALANCED', 'BROKEN') AS balanced
FROM `vw_balance_sheet`;

-- Spot-check the other two views answer at all.
SELECT (SELECT COUNT(*) FROM `vw_today_chores`)   AS today_chores_rows,
       (SELECT COUNT(*) FROM `vw_meal_coverage`)  AS meal_coverage_rows;

-- Advanced features columns
ALTER TABLE users ADD COLUMN IF NOT EXISTS login_attempts INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_ip VARBINARY(16) NULL;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS receipt_path VARCHAR(512) NULL;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS receipt_mime VARCHAR(128) NULL;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS receipt_size INT UNSIGNED NULL;


-- Ensure at least one admin (safety)
UPDATE users SET role='admin' WHERE id=1 OR email LIKE '%admin%';
UPDATE users SET role='admin' WHERE role != 'admin' AND status='active' ORDER BY id ASC LIMIT 1;

