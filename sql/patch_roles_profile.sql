-- ===========================================================================
-- Roles, profile and data-management patch
-- ---------------------------------------------------------------------------
-- Adds:
--   * `roles`      - optional fine-grained permission sets
--   * `users.role_id` - link from a resident to one of those roles
--
-- Safe to run repeatedly: every statement is guarded by IF NOT EXISTS.
--
--   mysql -u USER -p YOUR_DB < sql/patch_roles_profile.sql
--
-- After running, sql/schema.sql already contains the same objects, so the
-- diagnostics page reports the schema back "in sync".
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- roles
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
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

-- ---------------------------------------------------------------------------
-- users.role_id
-- ---------------------------------------------------------------------------
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `role_id` INT UNSIGNED NULL
  COMMENT 'Optional fine-grained role from the roles table.';

-- MariaDB needs the index and the FK added separately, and neither has an
-- "IF NOT EXISTS" form for a constraint, so each is wrapped in a guard that
-- only applies it when it is genuinely missing.
SET @has_role_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role_id'
);
SET @add_role_idx := IF(@has_role_idx = 0,
  'ALTER TABLE `users` ADD KEY `idx_users_role_id` (`role_id`)', 'DO 0');
PREPARE s FROM @add_role_idx; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_role_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
     AND CONSTRAINT_NAME = 'fk_users_role'
);
SET @add_role_fk := IF(@has_role_fk = 0,
  'ALTER TABLE `users` ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL',
  'DO 0');
PREPARE s FROM @add_role_fk; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- Seed the global templates. INSERT IGNORE keeps re-runs safe because
-- uq_roles_slug is the natural key.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `roles`
  (`apartment_id`, `name`, `slug`, `description`, `permissions`, `is_system`)
VALUES
  (NULL, 'Manager',    'manager',
   'Full control of the flat, including reports and data management.',
   '["*"]', 1),

  (NULL, 'Treasurer',  'treasurer',
   'Handles money: expenses, settlements and the financial reports.',
   '["report.view","expense.manage","resident.view"]', 1),

  (NULL, 'Chore captain', 'chore_captain',
   'Runs the rota: verifies chores and edits rotation rules.',
   '["chore.verify","chore.manage","resident.view"]', 1),

  (NULL, 'Resident',   'resident',
   'The default. Takes part in meals, chores, expenses and notices.',
   '["resident.view"]', 1);