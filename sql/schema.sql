-- ===========================================================================
--  FlatMate — Apartment / Shared Flat Management System
--  SCHEMA  ·  MySQL 8.0+ / MariaDB 10.5+
-- ---------------------------------------------------------------------------
--  Usage (phpMyAdmin → SQL tab, or CLI):
--      mysql -u root -p < sql/schema.sql
--
--  Engine  : InnoDB (transactions + foreign keys)
--  Charset : utf8mb4 / utf8mb4_unicode_ci  (emoji-safe)
--  Money   : DECIMAL(12,2)  — never FLOAT
--  Dates   : DATE for calendar days, DATETIME for events
--  Week    : ISO-8601, day_of_week 1=Monday .. 7=Sunday
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------------
--  DATABASE SELECTION
-- ---------------------------------------------------------------------------
--  The name below must match 'database' in config/config.php.
--  For an existing database you do not own the name of (e.g. shared hosting),
--  comment out BOTH lines and instead select your database before importing:
--      phpMyAdmin: tick the database in the left sidebar, then Import
--      CLI       : mysql -u USER -p YOUR_DB < sql/schema.sql
--
--  1. CREATE DATABASE ...   skip when the DB already exists / is managed
--  2. USE `...`             sets the default for every statement below
-- ---------------------------------------------------------------------------

-- CREATE DATABASE IF NOT EXISTS `flatmate_db`
--   DEFAULT CHARACTER SET utf8mb4
--   COLLATE utf8mb4_unicode_ci;

-- USE `flatmate_db`;

DROP VIEW  IF EXISTS `vw_balance_sheet`;
DROP VIEW  IF EXISTS `vw_today_chores`;
DROP VIEW  IF EXISTS `vw_meal_coverage`;
DROP TABLE IF EXISTS `announcement_reads`;
DROP TABLE IF EXISTS `announcements`;
DROP TABLE IF EXISTS `reminders`;
DROP TABLE IF EXISTS `activity_log`;
DROP TABLE IF EXISTS `offboarding_tasks`;
DROP TABLE IF EXISTS `chore_tasks`;
DROP TABLE IF EXISTS `chore_areas`;
DROP TABLE IF EXISTS `settlements`;
DROP TABLE IF EXISTS `expense_splits`;
DROP TABLE IF EXISTS `expenses`;
DROP TABLE IF EXISTS `expense_categories`;
DROP TABLE IF EXISTS `suggestion_votes`;
DROP TABLE IF EXISTS `meal_suggestions`;
DROP TABLE IF EXISTS `meal_participants`;
DROP TABLE IF EXISTS `meals`;
DROP TABLE IF EXISTS `meal_plans`;
DROP TABLE IF EXISTS `invites`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `magic_links`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `duty_groups`;
DROP TABLE IF EXISTS `rooms`;
DROP TABLE IF EXISTS `apartments`;


-- ===========================================================================
--  1. apartments — the tenant / building
-- ===========================================================================
CREATE TABLE `apartments` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120)  NOT NULL,
  `address_line`  VARCHAR(255)  NULL,
  `city`          VARCHAR(80)   NULL,
  `currency_code` CHAR(3)       NOT NULL DEFAULT 'BDT',
  `currency_symbol`   VARCHAR(8) NOT NULL DEFAULT '৳',
  `week_starts_on`    TINYINT UNSIGNED NOT NULL DEFAULT 1
                    COMMENT '1=Monday (ISO-8601). Editable so the week can be localised.',
  `meal_deadline_hr`  TINYINT UNSIGNED NOT NULL DEFAULT 10
                    COMMENT 'Hour (0-23) after which opt-in locks for the next day.',
  `settings`      JSON          NULL,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_apartments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per managed flat / hostel block.';


-- ===========================================================================
--  2. rooms — physical allocation unit (Room 101, Room 102 …)
-- ===========================================================================
CREATE TABLE `rooms` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id` INT UNSIGNED NOT NULL,
  `code`         VARCHAR(32)  NOT NULL COMMENT '101, 102, A-3 …',
  `name`         VARCHAR(80)  NULL,
  `floor`        TINYINT      NULL,
  `capacity`     TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `rent_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00
                 COMMENT 'Private rent — tracked for context, never enters the shared ledger.',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `notes`        VARCHAR(255) NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rooms_apartment_code` (`apartment_id`, `code`),
  KEY `idx_rooms_active` (`apartment_id`, `is_active`),
  CONSTRAINT `fk_rooms_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bedroom / room allocation. A resident may live in at most one.';


-- ===========================================================================
--  3. duty_groups — hard rotation membership (Washroom A, Kitchen Crew …)
--     This is the key to "Washroom A rotates only among its 3 users".
-- ===========================================================================
CREATE TABLE `duty_groups` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id` INT UNSIGNED NOT NULL,
  `name`         VARCHAR(80)  NOT NULL COMMENT 'Washroom A',
  `slug`         VARCHAR(80)  NOT NULL COMMENT 'washroom-a',
  `room_id`      INT UNSIGNED NULL COMMENT 'Optional anchor room.',
  `description`  VARCHAR(255) NULL,
  `color`        CHAR(7)      NOT NULL DEFAULT '#6366f1',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`   SMALLINT     NOT NULL DEFAULT 0
                 COMMENT 'Deterministic rotation order = sort_order, then user id.',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_duty_groups_slug` (`apartment_id`, `slug`),
  KEY `idx_duty_groups_room` (`room_id`),
  CONSTRAINT `fk_duty_groups_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_duty_groups_room` FOREIGN KEY (`room_id`)
    REFERENCES `rooms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Exclusive rotation cohorts. Membership is via users.duty_group_id.';


-- ===========================================================================
--  4. roles — optional fine-grained permissions layered on top of
--     users.role.
--
--     users.role stays the coarse gate (admin|resident) because every
--     route guard reads it. A user may additionally be given a role from
--     this table, which carries a JSON list of permission keys. Auth::can()
--     consults it; Auth::isAdmin() still wins if role='admin'.
--
--     permissions = '["*"]' means "everything", which is what a system
--     admin role carries. Roles with apartment_id NULL are global
--     templates offered to every flat and cannot be edited or removed.
-- ===========================================================================
CREATE TABLE `roles` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id` INT UNSIGNED NULL COMMENT 'NULL = global template available to all flats.',
  `name`         VARCHAR(60)  NOT NULL,
  `slug`         VARCHAR(60)  NOT NULL COMMENT 'Stable key used in URLs and logs.',
  `description`  VARCHAR(255) NULL,
  `permissions`  JSON         NOT NULL COMMENT 'List of permission keys, or ["*"] for all.',
  `is_system`    TINYINT(1)   NOT NULL DEFAULT 0
                 COMMENT 'Global template: cannot be renamed, edited or deleted.',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`),
  KEY `idx_roles_apartment` (`apartment_id`),
  CONSTRAINT `fk_roles_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Optional permission sets. users.role remains the coarse admin/resident gate.';


-- ===========================================================================
--  5. users — residents + admin. Auto-generated unique participant IDs.
-- ===========================================================================
CREATE TABLE `users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id`    INT UNSIGNED NOT NULL,
  `participant_code` VARCHAR(12) NOT NULL COMMENT 'Human-friendly unique id, e.g. FM-4K7Q2',
  `full_name`       VARCHAR(120) NOT NULL,
  `email`           VARCHAR(190) NOT NULL,
  `phone`           VARCHAR(32)  NULL,
  `password_hash`   VARCHAR(255) NULL COMMENT 'NULL for magic-link-only accounts.',
  `role`            ENUM('admin','resident') NOT NULL DEFAULT 'resident'
                    COMMENT 'Coarse gate read by every route guard.',
  `role_id`         INT UNSIGNED NULL
                    COMMENT 'Optional fine-grained role from the roles table.',
  `status`          ENUM('invited','active','suspended','offboarded')
                    NOT NULL DEFAULT 'active',
  `room_id`         INT UNSIGNED NULL,
  `duty_group_id`   INT UNSIGNED NULL,
  `avatar_color`    CHAR(7)      NOT NULL DEFAULT '#6366f1',
  `bio`             VARCHAR(255) NULL,
  `favourite_food`  VARCHAR(120) NULL,
  `joined_on`       DATE         NULL,
  `offboarded_on`   DATE         NULL,
  `last_seen_at`    DATETIME     NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email`       (`email`),
  UNIQUE KEY `uq_users_code`        (`participant_code`),
  KEY `idx_users_apartment_status` (`apartment_id`, `status`),
  KEY `idx_users_duty_group`       (`duty_group_id`),
  KEY `idx_users_room`             (`room_id`),
  KEY `idx_users_name`             (`full_name`),
  CONSTRAINT `fk_users_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_users_room` FOREIGN KEY (`room_id`)
    REFERENCES `rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_duty_group` FOREIGN KEY (`duty_group_id`)
    REFERENCES `duty_groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`)
    REFERENCES `roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='All actors. Chores apply to every status=active row regardless of meal opt-in.';


-- ===========================================================================
--  6. magic_links — passwordless sign-in tokens
-- ===========================================================================
CREATE TABLE `magic_links` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64)    NOT NULL COMMENT 'sha256(token) — raw token never stored.',
  `purpose`     ENUM('login','invite') NOT NULL DEFAULT 'login',
  `expires_at`  DATETIME    NOT NULL,
  `used_at`     DATETIME    NULL,
  `ip_address`  VARBINARY(16) NULL,
  `created_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_magic_token` (`token_hash`),
  KEY `idx_magic_user` (`user_id`),
  CONSTRAINT `fk_magic_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  7. sessions — persistent (remember-me) login rows
-- ===========================================================================
CREATE TABLE `sessions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `token_hash`    CHAR(64)     NOT NULL,
  `user_agent`    VARCHAR(255) NULL,
  `ip_address`    VARBINARY(16) NULL,
  `last_used_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`    DATETIME     NOT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_token` (`token_hash`),
  KEY `idx_sessions_user` (`user_id`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  8. invites — onboarding lifecycle
-- ===========================================================================
CREATE TABLE `invites` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id`  INT UNSIGNED NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `full_name`     VARCHAR(120) NULL,
  `role`          ENUM('admin','resident') NOT NULL DEFAULT 'resident',
  `room_id`       INT UNSIGNED NULL,
  `duty_group_id` INT UNSIGNED NULL,
  `token_hash`    CHAR(64)     NOT NULL,
  `status`        ENUM('pending','accepted','revoked','expired')
                  NOT NULL DEFAULT 'pending',
  `invited_by`    INT UNSIGNED NULL,
  `expires_at`    DATETIME     NOT NULL,
  `accepted_at`   DATETIME     NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invites_token` (`token_hash`),
  KEY `idx_invites_apartment_status` (`apartment_id`, `status`),
  KEY `idx_invites_email` (`email`),
  CONSTRAINT `fk_invites_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invites_room` FOREIGN KEY (`room_id`)
    REFERENCES `rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invites_group` FOREIGN KEY (`duty_group_id`)
    REFERENCES `duty_groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invites_inviter` FOREIGN KEY (`invited_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  9. meal_plans — one row per ISO week
-- ===========================================================================
CREATE TABLE `meal_plans` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id` INT UNSIGNED NOT NULL,
  `week_start`   DATE         NOT NULL COMMENT 'Always the apartment.week_starts_on day.',
  `status`       ENUM('draft','locked','archived') NOT NULL DEFAULT 'draft',
  `locked_at`    DATETIME     NULL,
  `locked_by`    INT UNSIGNED NULL,
  `notes`        VARCHAR(255) NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_meal_plans_week` (`apartment_id`, `week_start`),
  CONSTRAINT `fk_meal_plans_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_meal_plans_locker` FOREIGN KEY (`locked_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  10. meals — 21 slots per week (7 days x 3 meal types)
-- ===========================================================================
CREATE TABLE `meals` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `meal_plan_id`  INT UNSIGNED NOT NULL,
  `day_of_week`   TINYINT UNSIGNED NOT NULL COMMENT '1=Mon .. 7=Sun',
  `meal_type`     ENUM('breakfast','lunch','dinner') NOT NULL,
  `menu_title`    VARCHAR(160) NULL COMMENT 'Winning / confirmed dish',
  `menu_notes`    VARCHAR(255) NULL,
  `cook_user_id`  INT UNSIGNED NULL COMMENT 'Optional chef assignment.',
  `locked`        TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_meals_slot` (`meal_plan_id`, `day_of_week`, `meal_type`),
  KEY `idx_meals_cook` (`cook_user_id`),
  CONSTRAINT `chk_meals_day` CHECK (`day_of_week` BETWEEN 1 AND 7),
  CONSTRAINT `fk_meals_plan` FOREIGN KEY (`meal_plan_id`)
    REFERENCES `meal_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_meals_cook` FOREIGN KEY (`cook_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  11. meal_participants — per-slot OPT-IN / OPT-OUT
--     Drives grocery quantities AND the "split only among eaters" expense mode.
-- ===========================================================================
CREATE TABLE `meal_participants` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `meal_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `status`     ENUM('eating','opting_out') NOT NULL DEFAULT 'eating',
  `responded_at` DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_meal_participant` (`meal_id`, `user_id`),
  KEY `idx_mp_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_mp_meal` FOREIGN KEY (`meal_id`)
    REFERENCES `meals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mp_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Chores NEVER read this table — duties are decoupled from food.';


-- ===========================================================================
--  12. meal_suggestions — proposed dishes
-- ===========================================================================
CREATE TABLE `meal_suggestions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `meal_id`       INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `title`         VARCHAR(160) NOT NULL,
  `notes`         VARCHAR(255) NULL,
  `estimated_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `is_winner`     TINYINT(1)   NOT NULL DEFAULT 0,
  `status`        ENUM('open','chosen','rejected') NOT NULL DEFAULT 'open',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sugg_meal` (`meal_id`, `status`),
  KEY `idx_sugg_user` (`user_id`),
  CONSTRAINT `fk_sugg_meal` FOREIGN KEY (`meal_id`)
    REFERENCES `meals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sugg_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  13. suggestion_votes — up / down
-- ===========================================================================
CREATE TABLE `suggestion_votes` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `suggestion_id` INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `vote`          TINYINT      NOT NULL COMMENT '-1 down, +1 up',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vote_once` (`suggestion_id`, `user_id`),
  KEY `idx_vote_user` (`user_id`),
  CONSTRAINT `chk_vote_sign` CHECK (`vote` IN (-1, 1)),
  CONSTRAINT `fk_vote_suggestion` FOREIGN KEY (`suggestion_id`)
    REFERENCES `meal_suggestions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vote_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  14. expense_categories
-- ===========================================================================
CREATE TABLE `expense_categories` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id`  INT UNSIGNED NOT NULL,
  `name`          VARCHAR(60)  NOT NULL,
  `slug`          VARCHAR(60)  NOT NULL,
  `icon`          VARCHAR(40)  NOT NULL DEFAULT 'bi-basket',
  `is_meal_related` TINYINT(1) NOT NULL DEFAULT 0
                   COMMENT 'Grocery / dining categories are eligible for meal-based splitting.',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`    SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_slug` (`apartment_id`, `slug`),
  CONSTRAINT `fk_cat_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  15. expenses — the ledger header
-- ===========================================================================
CREATE TABLE `expenses` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id`   INT UNSIGNED NOT NULL,
  `reference_no`   VARCHAR(24)  NULL COMMENT 'EX-2026-000123',
  `title`          VARCHAR(160) NOT NULL,
  `description`    TEXT         NULL,
  `amount`         DECIMAL(12,2) NOT NULL CHECK (`amount` > 0),
  `currency_code`  CHAR(3)      NOT NULL DEFAULT 'BDT',
  `paid_by_user_id` INT UNSIGNED NOT NULL,
  `category_id`    INT UNSIGNED NULL,
  `split_type`     ENUM('equal','selective','shares','meal_based') NOT NULL DEFAULT 'equal',
  `split_meta`     JSON         NULL
                   COMMENT 'For split_type=shares: {userId: weight}. For meal_based: window config.',
  `expense_date`   DATE         NOT NULL,
  `receipt_ref`    VARCHAR(255) NULL,
  `is_meal_related` TINYINT(1)  NOT NULL DEFAULT 0,
  `is_disputed`    TINYINT(1)   NOT NULL DEFAULT 0,
  `dispute_note`   VARCHAR(500) NULL,
  `is_deleted`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Soft delete for audit.',
  `created_by`     INT UNSIGNED NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_expenses_ref` (`reference_no`),
  KEY `idx_exp_apartment_date` (`apartment_id`, `expense_date`),
  KEY `idx_exp_payer`   (`paid_by_user_id`, `expense_date`),
  KEY `idx_exp_category`(`category_id`),
  KEY `idx_exp_active`  (`apartment_id`, `is_deleted`, `expense_date`),
  FULLTEXT KEY `ft_expenses_title` (`title`, `description`),
  CONSTRAINT `fk_exp_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exp_payer` FOREIGN KEY (`paid_by_user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exp_category` FOREIGN KEY (`category_id`)
    REFERENCES `expense_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_exp_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Header only. Per-person liability lives in expense_splits.';


-- ===========================================================================
--  16. expense_splits — materialised per-user share
--     Denormalised on purpose: the ledger must be auditable and O(1) per user.
-- ===========================================================================
CREATE TABLE `expense_splits` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_id`   INT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `share_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `weight`       DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
  `is_settled`   TINYINT(1)   NOT NULL DEFAULT 0
                 COMMENT 'Set when a settlement covers this row.',
  `settled_at`   DATETIME     NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_split_once` (`expense_id`, `user_id`),
  KEY `idx_split_user` (`user_id`, `is_settled`),
  CONSTRAINT `fk_split_expense` FOREIGN KEY (`expense_id`)
    REFERENCES `expenses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_split_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='SUM(share_amount) per expense MUST equal expenses.amount (asserted in app layer).';


-- ===========================================================================
--  17. settlements — logged payouts that clear net balances
--     Modelled as synthetic zero-liability rows so the ledger stays additive.
-- ===========================================================================
CREATE TABLE `settlements` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id`   INT UNSIGNED NOT NULL,
  `from_user_id`   INT UNSIGNED NOT NULL COMMENT 'Payer (the debtor).',
  `to_user_id`     INT UNSIGNED NOT NULL COMMENT 'Payee (the creditor).',
  `amount`         DECIMAL(12,2) NOT NULL CHECK (`amount` > 0),
  `method`         ENUM('cash','bkash','nagad','bank','other') NOT NULL DEFAULT 'cash',
  `reference`      VARCHAR(120) NULL,
  `note`           VARCHAR(500) NULL,
  `covers_expense_ids` JSON NULL COMMENT 'Optional explicit link to expenses.',
  `settled_at`     DATE         NOT NULL,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_settle_from` (`from_user_id`, `settled_at`),
  KEY `idx_settle_to`   (`to_user_id`, `settled_at`),
  KEY `idx_settle_apartment` (`apartment_id`, `settled_at`),
  CONSTRAINT `fk_settle_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_settle_from` FOREIGN KEY (`from_user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_settle_to` FOREIGN KEY (`to_user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_settle_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_settle_distinct` CHECK (`from_user_id` <> `to_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  18. chore_areas — a cleanable zone + its rotation rule
-- ===========================================================================
CREATE TABLE `chore_areas` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id`    INT UNSIGNED NOT NULL,
  `name`            VARCHAR(100) NOT NULL COMMENT 'Kitchen, Hall, Washroom A …',
  `slug`            VARCHAR(100) NOT NULL,
  `scope`           ENUM('common','group','room') NOT NULL DEFAULT 'common',
  `room_id`         INT UNSIGNED NULL,
  `duty_group_id`   INT UNSIGNED NULL,
  `icon`            VARCHAR(40)  NOT NULL DEFAULT 'bi-stars',
  `frequency`       ENUM('daily','weekly','weekdays','weekend') NOT NULL DEFAULT 'daily',
  `weekday_mask`    TINYINT UNSIGNED NOT NULL DEFAULT 127
                    COMMENT 'Bitmask of ISO dow 1..7 (bit0=Mon). 127 = every day.',
  `rotation_offset` SMALLINT     NOT NULL DEFAULT 0
                    COMMENT 'Shifts the rotation; lets you re-sync after a join/leave.',
  `points`          SMALLINT      NOT NULL DEFAULT 10,
  `is_mandatory`    TINYINT(1)    NOT NULL DEFAULT 1
                    COMMENT 'Always true today — chores ignore meal opt-in by design.',
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `description`     VARCHAR(255)  NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_area_slug` (`apartment_id`, `slug`),
  KEY `idx_area_scope` (`apartment_id`, `scope`, `is_active`),
  KEY `idx_area_group` (`duty_group_id`),
  KEY `idx_area_room`  (`room_id`),
  CONSTRAINT `fk_area_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_area_room` FOREIGN KEY (`room_id`)
    REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_area_group` FOREIGN KEY (`duty_group_id`)
    REFERENCES `duty_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='scope=group REQUIRES duty_group_id; scope=room REQUIRES room_id.';


-- ===========================================================================
--  19. chore_tasks — the generated rotation instances
--     One row per (area, date, assignee). Re-computable & idempotent.
-- ===========================================================================
CREATE TABLE `chore_tasks` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chore_area_id`    INT UNSIGNED NOT NULL,
  `assigned_user_id` INT UNSIGNED NULL COMMENT 'NULL only if the rotation pool is empty.',
  `task_date`        DATE         NOT NULL,
  `status`           ENUM('pending','done','skipped','verified') NOT NULL DEFAULT 'pending',
  `completed_at`     DATETIME     NULL,
  `completed_by`     INT UNSIGNED NULL,
  `verified_by`      INT UNSIGNED NULL,
  `verified_at`      DATETIME     NULL,
  `notes`            VARCHAR(500) NULL,
  `proof_photo`      VARCHAR(255) NULL COMMENT 'Filename under /uploads.',
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_slot` (`chore_area_id`, `task_date`),
  KEY `idx_task_user_date` (`assigned_user_id`, `task_date`, `status`),
  KEY `idx_task_date_status` (`task_date`, `status`),
  CONSTRAINT `fk_task_area` FOREIGN KEY (`chore_area_id`)
    REFERENCES `chore_areas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_assignee` FOREIGN KEY (`assigned_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_completer` FOREIGN KEY (`completed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_verifier` FOREIGN KEY (`verified_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='UNIQUE(area,date) guarantees exactly one assignee per area per day.';


-- ===========================================================================
--  20. offboarding_tasks — departure checklist gate
-- ===========================================================================
CREATE TABLE `offboarding_tasks` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id` INT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `label`        VARCHAR(160) NOT NULL,
  `category`     ENUM('finance','dunno','property','admin') NOT NULL DEFAULT 'admin',
  `is_done`      TINYINT(1)   NOT NULL DEFAULT 0,
  `is_blocking`  TINYINT(1)   NOT NULL DEFAULT 1,
  `completed_at` DATETIME     NULL,
  `sort_order`   SMALLINT     NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_offboard_user` (`user_id`, `is_done`),
  CONSTRAINT `fk_off_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_off_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Standard template + "settle outstanding balance" (blocking when net != 0).';


-- ===========================================================================
--  21. announcements — notice board / broadcast
-- ===========================================================================
CREATE TABLE `announcements` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id` INT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NULL,
  `title`        VARCHAR(160) NOT NULL,
  `body`         TEXT         NULL,
  `category`     ENUM('general','maintenance','billing','event','alert') NOT NULL DEFAULT 'general',
  `audience`     ENUM('everyone','duty_group','room','admins') NOT NULL DEFAULT 'everyone',
  `audience_room_id` INT UNSIGNED NULL,
  `audience_group_id` INT UNSIGNED NULL,
  `is_pinned`    TINYINT(1)   NOT NULL DEFAULT 0,
  `pinned_until` DATE         NULL,
  `expires_at`   DATETIME     NULL,
  `view_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_apartment_pin` (`apartment_id`, `is_pinned`, `created_at`),
  KEY `idx_ann_category` (`category`),
  CONSTRAINT `fk_ann_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ann_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ann_room` FOREIGN KEY (`audience_room_id`)
    REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ann_group` FOREIGN KEY (`audience_group_id`)
    REFERENCES `duty_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  22. announcement_reads
-- ===========================================================================
CREATE TABLE `announcement_reads` (
  `announcement_id` INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL,
  `read_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_id`, `user_id`),
  KEY `idx_aread_user` (`user_id`),
  CONSTRAINT `fk_aread_ann` FOREIGN KEY (`announcement_id`)
    REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aread_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  23. reminders — in-app notification badges
-- ===========================================================================
CREATE TABLE `reminders` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id` INT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NULL COMMENT 'NULL = broadcast to apartment.',
  `type`         ENUM('chore','meal_optin','balance','vote','announcement','system') NOT NULL,
  `title`        VARCHAR(160) NOT NULL,
  `body`         VARCHAR(500) NULL,
  `severity`     ENUM('info','warning','danger') NOT NULL DEFAULT 'info',
  `ref_table`    VARCHAR(60)  NULL,
  `ref_id`       INT UNSIGNED NULL,
  `due_at`       DATETIME     NULL,
  `read_at`      DATETIME     NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rem_user_unread` (`user_id`, `read_at`, `due_at`),
  KEY `idx_rem_apartment` (`apartment_id`, `created_at`),
  CONSTRAINT `fk_rem_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rem_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  24. activity_log — audit trail
-- ===========================================================================
CREATE TABLE `activity_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apartment_id` INT UNSIGNED NULL,
  `user_id`      INT UNSIGNED NULL,
  `action`       VARCHAR(60)  NOT NULL COMMENT 'expense.created, chore.completed …',
  `entity`       VARCHAR(60)  NULL,
  `entity_id`    INT UNSIGNED NULL,
  `summary`      VARCHAR(400) NULL,
  `meta`         JSON         NULL,
  `ip_address`   VARBINARY(16) NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_apartment` (`apartment_id`, `created_at`),
  KEY `idx_log_user` (`user_id`, `created_at`),
  KEY `idx_log_entity` (`entity`, `entity_id`),
  CONSTRAINT `fk_log_apartment` FOREIGN KEY (`apartment_id`)
    REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================================================
--  REPORTING VIEWS
-- ===========================================================================

--  vw_balance_sheet — Net Balance = Total Paid - Total Share Owed
DROP VIEW IF EXISTS `vw_balance_sheet`;
CREATE VIEW `vw_balance_sheet` AS
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
  SELECT `paid_by_user_id` AS uid, SUM(`amount`) AS total_paid
  FROM `expenses` WHERE `is_deleted` = 0 GROUP BY `paid_by_user_id`
) p ON p.`uid` = u.`id`
LEFT JOIN (
  SELECT `user_id` AS uid, SUM(`share_amount`) AS total_owed
  FROM `expense_splits` es
  JOIN `expenses` e ON e.`id` = es.`expense_id` AND e.`is_deleted` = 0
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


--  vw_today_chores — dashboard feed
DROP VIEW IF EXISTS `vw_today_chores`;
CREATE VIEW `vw_today_chores` AS
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


--  vw_meal_coverage — how many residents eat each slot (grocery sizing)
DROP VIEW IF EXISTS `vw_meal_coverage`;
CREATE VIEW `vw_meal_coverage` AS
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
