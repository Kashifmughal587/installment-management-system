-- =====================================================================
--  Installment Management System - Database Schema
--  Engine   : InnoDB
--  Charset  : utf8mb4 / utf8mb4_unicode_ci
--  Target   : MySQL 5.7+ / MariaDB 10.4+ (Hostinger shared hosting)
--
--  Import via phpMyAdmin. The script creates the database if missing.
--  IMPORTANT: the seed owner uses a DEVELOPMENT password. Change it
--  immediately after first login. NEVER use seed data in production.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- NOTE: This script does not issue CREATE DATABASE / USE so it can be
-- imported into any database selected in phpMyAdmin or configured in
-- config.php (DB_NAME). Create/select the database first, then import.

-- ---------------------------------------------------------------------
-- 1. users
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name`      VARCHAR(150)    NOT NULL,
    `username`       VARCHAR(60)     NOT NULL,
    `email`          VARCHAR(190)    DEFAULT NULL,
    `password_hash`  VARCHAR(255)    NOT NULL,
    `role`           ENUM('owner','staff') NOT NULL DEFAULT 'staff',
    `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `last_login_at`  DATETIME        DEFAULT NULL,
    `created_by`     INT UNSIGNED    DEFAULT NULL,
    `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role` (`role`),
    KEY `idx_users_status` (`status`),
    CONSTRAINT `fk_users_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. login_attempts  (simple, dependency-free rate limiting)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`     VARCHAR(190) NOT NULL,
    `ip_address`   VARCHAR(45)  NOT NULL,
    `success`      TINYINT(1)   NOT NULL DEFAULT 0,
    `attempted_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attempts_user_time` (`username`, `attempted_at`),
    KEY `idx_attempts_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. business_settings  (single active row; extensible for branches)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `business_settings`;
CREATE TABLE `business_settings` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `branch_name`        VARCHAR(150) NOT NULL DEFAULT 'Main Branch',
    `shop_name`          VARCHAR(190) NOT NULL DEFAULT '',
    `owner_name`         VARCHAR(150) NOT NULL DEFAULT '',
    `father_name`        VARCHAR(150) DEFAULT NULL,
    `cnic`               VARCHAR(20)  DEFAULT NULL,
    `phone`              VARCHAR(30)  DEFAULT NULL,
    `whatsapp`           VARCHAR(30)  DEFAULT NULL,
    `email`              VARCHAR(190) DEFAULT NULL,
    `address`            VARCHAR(255) DEFAULT NULL,
    `city`               VARCHAR(100) DEFAULT NULL,
    `district`           VARCHAR(100) DEFAULT NULL,
    `logo_path`          VARCHAR(255) DEFAULT NULL,
    `signature_path`     VARCHAR(255) DEFAULT NULL,
    `agreement_header`   TEXT         DEFAULT NULL,
    `agreement_footer`   TEXT         DEFAULT NULL,
    `default_terms`      TEXT         DEFAULT NULL,
    `currency`           VARCHAR(10)  NOT NULL DEFAULT 'PKR',
    `default_due_day`    TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `document_language`  ENUM('en','ur') NOT NULL DEFAULT 'en',
    `receipt_prefix`     VARCHAR(20)  NOT NULL DEFAULT 'RCP',
    `agreement_prefix`   VARCHAR(20)  NOT NULL DEFAULT 'AGR',
    `is_active`          TINYINT(1)   NOT NULL DEFAULT 1,
    `updated_by`         INT UNSIGNED DEFAULT NULL,
    `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bs_active` (`is_active`),
    CONSTRAINT `fk_bs_updated_by` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. customers
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_code`     VARCHAR(30)  NOT NULL,
    `full_name`         VARCHAR(190) NOT NULL,
    `guardian_name`     VARCHAR(190) DEFAULT NULL,
    `guardian_relation` VARCHAR(50)  DEFAULT NULL,
    `cnic`              VARCHAR(20)  DEFAULT NULL,
    `dob`               DATE         DEFAULT NULL,
    `gender`            ENUM('male','female','other') DEFAULT NULL,
    `occupation`        VARCHAR(120) DEFAULT NULL,
    `monthly_income`    DECIMAL(12,2) DEFAULT NULL,
    `photo_path`        VARCHAR(255) DEFAULT NULL,
    `mobile`            VARCHAR(30)  DEFAULT NULL,
    `whatsapp`          VARCHAR(30)  DEFAULT NULL,
    `email`             VARCHAR(190) DEFAULT NULL,
    `current_address`   VARCHAR(500) DEFAULT NULL,
    `permanent_address` VARCHAR(500) DEFAULT NULL,
    `city`              VARCHAR(100) DEFAULT NULL,
    `district`          VARCHAR(100) DEFAULT NULL,
    `cnic_front_path`   VARCHAR(255) DEFAULT NULL,
    `cnic_back_path`    VARCHAR(255) DEFAULT NULL,
    `notes`             TEXT         DEFAULT NULL,
    `status`            ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    `created_by`        INT UNSIGNED DEFAULT NULL,
    `updated_by`        INT UNSIGNED DEFAULT NULL,
    `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_customers_code` (`customer_code`),
    KEY `idx_customers_name` (`full_name`),
    KEY `idx_customers_cnic` (`cnic`),
    KEY `idx_customers_mobile` (`mobile`),
    KEY `idx_customers_status` (`status`),
    CONSTRAINT `fk_customers_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_customers_updated_by` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. agreements
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `agreements`;
CREATE TABLE `agreements` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agreement_no`         VARCHAR(30)  NOT NULL,
    `agreement_date`       DATE         NOT NULL,
    `customer_id`          INT UNSIGNED NOT NULL,
    `status`               ENUM('draft','active','completed','cancelled') NOT NULL DEFAULT 'draft',

    `total_cash_price`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `agreed_sale_price`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `additional_charges`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `discount`             DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `advance_payment`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `financed_amount`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `remaining_balance`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,

    `installment_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `frequency`            ENUM('monthly','weekly','biweekly','custom') NOT NULL DEFAULT 'monthly',
    `installment_amount`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `installment_start_date` DATE       DEFAULT NULL,
    `first_due_date`       DATE         DEFAULT NULL,
    `due_day`              TINYINT UNSIGNED DEFAULT NULL,
    `payment_method_pref`  VARCHAR(40)  DEFAULT NULL,
    `terms`                TEXT         DEFAULT NULL,
    `notes`                TEXT         DEFAULT NULL,

    `activated_at`         DATETIME     DEFAULT NULL,
    `completed_at`         DATETIME     DEFAULT NULL,
    `cancelled_at`         DATETIME     DEFAULT NULL,
    `cancel_reason`        VARCHAR(500) DEFAULT NULL,
    `cancelled_by`         INT UNSIGNED DEFAULT NULL,

    `created_by`           INT UNSIGNED DEFAULT NULL,
    `updated_by`           INT UNSIGNED DEFAULT NULL,
    `created_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_agreements_no` (`agreement_no`),
    KEY `idx_agreements_customer` (`customer_id`),
    KEY `idx_agreements_status` (`status`),
    KEY `idx_agreements_date` (`agreement_date`),
    CONSTRAINT `fk_agreements_customer` FOREIGN KEY (`customer_id`)
        REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_agreements_cancelled_by` FOREIGN KEY (`cancelled_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_agreements_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_agreements_updated_by` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. agreement_products
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `agreement_products`;
CREATE TABLE `agreement_products` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agreement_id`    INT UNSIGNED NOT NULL,
    `product_name`    VARCHAR(190) NOT NULL,
    `category`        VARCHAR(120) DEFAULT NULL,
    `brand`           VARCHAR(120) DEFAULT NULL,
    `model`           VARCHAR(120) DEFAULT NULL,
    `serial_no`       VARCHAR(120) DEFAULT NULL,
    `quantity`        DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    `unit_cash_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `line_total`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `notes`           VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_products_agreement` (`agreement_id`),
    CONSTRAINT `fk_products_agreement` FOREIGN KEY (`agreement_id`)
        REFERENCES `agreements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. guarantors
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `guarantors`;
CREATE TABLE `guarantors` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agreement_id`    INT UNSIGNED NOT NULL,
    `full_name`       VARCHAR(190) NOT NULL,
    `father_name`     VARCHAR(190) DEFAULT NULL,
    `cnic`            VARCHAR(20)  DEFAULT NULL,
    `mobile`          VARCHAR(30)  DEFAULT NULL,
    `address`         VARCHAR(500) DEFAULT NULL,
    `relationship`    VARCHAR(100) DEFAULT NULL,
    `signature`       VARCHAR(255) DEFAULT NULL,
    `attachment_path` VARCHAR(255) DEFAULT NULL,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_guarantors_agreement` (`agreement_id`),
    KEY `idx_guarantors_cnic` (`cnic`),
    CONSTRAINT `fk_guarantors_agreement` FOREIGN KEY (`agreement_id`)
        REFERENCES `agreements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. installments
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `installments`;
CREATE TABLE `installments` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agreement_id`     INT UNSIGNED NOT NULL,
    `installment_no`   SMALLINT UNSIGNED NOT NULL,
    `due_date`         DATE         NOT NULL,
    `scheduled_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `paid_amount`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `remaining_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status`           ENUM('pending','partially_paid','paid','overdue') NOT NULL DEFAULT 'pending',
    `notes`            VARCHAR(500) DEFAULT NULL,
    `paid_at`          DATETIME     DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_installment_no` (`agreement_id`, `installment_no`),
    KEY `idx_installments_due` (`due_date`),
    KEY `idx_installments_status` (`status`),
    KEY `idx_installments_agreement` (`agreement_id`),
    CONSTRAINT `fk_installments_agreement` FOREIGN KEY (`agreement_id`)
        REFERENCES `agreements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. payments
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `receipt_no`       VARCHAR(30)  NOT NULL,
    `customer_id`      INT UNSIGNED NOT NULL,
    `agreement_id`     INT UNSIGNED NOT NULL,
    `installment_id`   INT UNSIGNED DEFAULT NULL,
    `payment_date`     DATE         NOT NULL,
    `amount`           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `payment_type`     ENUM('advance','installment','other') NOT NULL DEFAULT 'installment',
    `method`           ENUM('cash','bank_transfer','mobile_wallet','cheque','other') NOT NULL DEFAULT 'cash',
    `transaction_ref`  VARCHAR(190) DEFAULT NULL,
    `received_by`      INT UNSIGNED DEFAULT NULL,
    `notes`            VARCHAR(500) DEFAULT NULL,
    `attachment_path`  VARCHAR(255) DEFAULT NULL,
    `status`           ENUM('active','void') NOT NULL DEFAULT 'active',
    `void_reason`      VARCHAR(500) DEFAULT NULL,
    `void_by`          INT UNSIGNED DEFAULT NULL,
    `void_at`          DATETIME     DEFAULT NULL,
    `prev_balance`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `new_balance`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `created_by`       INT UNSIGNED DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payments_receipt` (`receipt_no`),
    KEY `idx_payments_customer` (`customer_id`),
    KEY `idx_payments_agreement` (`agreement_id`),
    KEY `idx_payments_date` (`payment_date`),
    KEY `idx_payments_status` (`status`),
    KEY `idx_payments_method` (`method`),
    CONSTRAINT `fk_payments_customer` FOREIGN KEY (`customer_id`)
        REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_payments_agreement` FOREIGN KEY (`agreement_id`)
        REFERENCES `agreements` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_payments_installment` FOREIGN KEY (`installment_id`)
        REFERENCES `installments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_payments_received_by` FOREIGN KEY (`received_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_payments_void_by` FOREIGN KEY (`void_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_payments_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. payment_allocations  (one payment -> many installments)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `payment_allocations`;
CREATE TABLE `payment_allocations` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_id`     INT UNSIGNED NOT NULL,
    `installment_id` INT UNSIGNED DEFAULT NULL,
    `amount`         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_alloc_payment` (`payment_id`),
    KEY `idx_alloc_installment` (`installment_id`),
    CONSTRAINT `fk_alloc_payment` FOREIGN KEY (`payment_id`)
        REFERENCES `payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_alloc_installment` FOREIGN KEY (`installment_id`)
        REFERENCES `installments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. agreement_amendments  (audit of financial-term changes)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `agreement_amendments`;
CREATE TABLE `agreement_amendments` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agreement_id`  INT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED DEFAULT NULL,
    `reason`        VARCHAR(500) NOT NULL,
    `old_values`    LONGTEXT     DEFAULT NULL,
    `new_values`    LONGTEXT     DEFAULT NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_amend_agreement` (`agreement_id`),
    CONSTRAINT `fk_amend_agreement` FOREIGN KEY (`agreement_id`)
        REFERENCES `agreements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_amend_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. document_sequences  (concurrency-safe numbering)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `document_sequences`;
CREATE TABLE `document_sequences` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `doc_type`    VARCHAR(30)  NOT NULL,
    `seq_year`    SMALLINT UNSIGNED NOT NULL,
    `prefix`      VARCHAR(20)  NOT NULL DEFAULT '',
    `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sequences_type_year` (`doc_type`, `seq_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. audit_logs
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED DEFAULT NULL,
    `action`      VARCHAR(80)  NOT NULL,
    `entity_type` VARCHAR(60)  DEFAULT NULL,
    `entity_id`   INT UNSIGNED DEFAULT NULL,
    `details`     TEXT         DEFAULT NULL,
    `ip_address`  VARCHAR(45)  DEFAULT NULL,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  SEED DATA  (DEVELOPMENT ONLY - replace before production)
-- =====================================================================

-- Seed users (DEVELOPMENT ONLY - CHANGE THESE PASSWORDS IMMEDIATELY!)
--   owner / admin12345   (Abdur Rehman Ehsan)
--   admin / admin123     (Administrator)
INSERT INTO `users` (`id`, `full_name`, `username`, `email`, `password_hash`, `role`, `status`)
VALUES
    (1, 'Abdur Rehman Ehsan', 'owner', 'owner@mughalmobileshop.pk',
        '$2y$10$GWFpg/hzbMNphfvQj1Luru7lLgiBOu5TmXS057DwTcyoMwcMMEl2u',
        'owner', 'active'),
    (2, 'Administrator', 'admin', 'admin@mughalmobileshop.pk',
        '$2y$10$fAkXAiVcTvroJXvbpaWPmeG4YzaQp1wRw46YlZhyv6UONqoR52UDa',
        'owner', 'active');

INSERT INTO `business_settings`
    (`branch_name`, `shop_name`, `owner_name`, `phone`, `whatsapp`, `email`,
     `address`, `city`, `district`, `currency`, `default_due_day`,
     `document_language`, `receipt_prefix`, `agreement_prefix`,
     `agreement_header`, `default_terms`, `updated_by`)
VALUES
    ('Main Branch', 'Mughal Mobile Shop', 'Abdur Rehman Ehsan', '03098828530', '03098828530',
     NULL, 'Bismillah Colony, Pathak Wala Bazar', 'Gujranwala', 'Gujranwala', 'PKR', 5,
     'en', 'RCP', 'AGR',
     'This installment agreement is made between the business and the customer named below.',
     '1. The customer agrees to pay all installments on or before the due date.\n2. Ownership of the product remains with the shop until full payment is received.\n3. Late payments may incur charges as agreed.\n4. In case of default, the shop reserves the right to reclaim the product as per applicable law.\n5. This agreement is subject to the laws of Pakistan.',
     1);

-- Document sequences so future numbers continue after the seed rows
INSERT INTO `document_sequences` (`doc_type`, `seq_year`, `prefix`, `last_number`) VALUES
    ('agreement', 2026, 'AGR', 1),
    ('receipt',   2026, 'RCP', 1),
    ('customer',  0,    'CUS', 1);