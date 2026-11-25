CREATE TABLE IF NOT EXISTS customer_favorites (
    user_id INT NOT NULL,
    car_id INT NOT NULL,
    PRIMARY KEY (user_id, car_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (car_id) REFERENCES cars(car_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS test_drives (
    drive_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    car_id INT NOT NULL,
    starts_at DATETIME NOT NULL,
    status ENUM('requested','confirmed','cancelled') NOT NULL DEFAULT 'requested',
    active_slot TINYINT GENERATED ALWAYS AS (IF(status='cancelled',NULL,1)) STORED,
    UNIQUE KEY car_slot (car_id, starts_at, active_slot),
    UNIQUE KEY customer_slot (user_id, starts_at, active_slot),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (car_id) REFERENCES cars(car_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
    user_id INT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    account_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
