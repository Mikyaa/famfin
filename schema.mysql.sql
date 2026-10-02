CREATE TABLE IF NOT EXISTS members (
  telegram_id BIGINT UNSIGNED PRIMARY KEY,
  display_name VARCHAR(191) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  telegram_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('expense','topup') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  category VARCHAR(80) NOT NULL,
  category_group VARCHAR(16) NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  occurred_on DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX transactions_date_idx (occurred_on),
  INDEX transactions_member_date_idx (telegram_id, occurred_on),
  INDEX transactions_group_date_idx (category_group, occurred_on),
  CONSTRAINT transactions_member_fk FOREIGN KEY (telegram_id) REFERENCES members(telegram_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Если таблица уже существует без category_group, выполните:
-- ALTER TABLE transactions ADD COLUMN category_group VARCHAR(16) NULL AFTER category;
-- CREATE INDEX transactions_group_date_idx ON transactions(category_group, occurred_on);
