CREATE DATABASE IF NOT EXISTS `salon-db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `salon-db`;

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS audit_logs, password_reset_requests, favorites, ratings,
loyalty_cards, barber_withdrawals, barber_earnings, booking_items, bookings,
barber_schedules, services, tools, salon_settings, users, salons;

CREATE TABLE salons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_salons_deleted (deleted_at)
) ENGINE=InnoDB;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(150) NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('customer','barber','salon_admin','admin','super_admin') NOT NULL DEFAULT 'customer',
    salon_id INT UNSIGNED NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    rating DECIMAL(3,1) NOT NULL DEFAULT 5.0,
    location VARCHAR(150) NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_users_phone (phone),
    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_role (role),
    INDEX idx_users_salon (salon_id),
    CONSTRAINT fk_users_salon FOREIGN KEY (salon_id) REFERENCES salons(id)
      ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barber_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    duration SMALLINT UNSIGNED NOT NULL,
    is_vip TINYINT(1) NOT NULL DEFAULT 0,
    flash_price DECIMAL(10,2) NULL,
    flash_sale_end DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_services_barber (barber_id),
    INDEX idx_services_flash (flash_sale_end),
    CONSTRAINT fk_services_barber FOREIGN KEY (barber_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_services_price CHECK (price >= 0),
    CONSTRAINT chk_services_duration CHECK (duration BETWEEN 1 AND 1440)
) ENGINE=InnoDB;

CREATE TABLE barber_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barber_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_off TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_schedule (barber_id, day_of_week),
    CONSTRAINT fk_schedule_barber FOREIGN KEY (barber_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_schedule_day CHECK (day_of_week BETWEEN 0 AND 6)
) ENGINE=InnoDB;

CREATE TABLE bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    barber_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NULL,
    date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    total_duration SMALLINT UNSIGNED NOT NULL,
    status ENUM('pending','confirmed','completed','cancelled','rejected') NOT NULL DEFAULT 'pending',
    payment_status ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bookings_barber_time (barber_id,date,start_time,end_time,status),
    INDEX idx_bookings_customer (customer_id,date),
    CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_barber FOREIGN KEY (barber_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services(id)
      ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE booking_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    service_name VARCHAR(100) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    duration SMALLINT UNSIGNED NOT NULL,
    INDEX idx_booking_items_booking (booking_id),
    CONSTRAINT fk_booking_items_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_booking_items_service FOREIGN KEY (service_id) REFERENCES services(id)
      ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    barber_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rating_booking (booking_id),
    CONSTRAINT fk_rating_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_rating_customer FOREIGN KEY (customer_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_rating_barber FOREIGN KEY (barber_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE favorites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    barber_id INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_favorite (customer_id, barber_id),
    CONSTRAINT fk_favorite_customer FOREIGN KEY (customer_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_favorite_barber FOREIGN KEY (barber_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE loyalty_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    stamps TINYINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loyalty_customer (customer_id),
    CONSTRAINT fk_loyalty_customer FOREIGN KEY (customer_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_loyalty_stamps CHECK (stamps BETWEEN 0 AND 5)
) ENGINE=InnoDB;

CREATE TABLE salon_settings (
    salon_id INT UNSIGNED PRIMARY KEY,
    barber_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    salon_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    CONSTRAINT fk_settings_salon FOREIGN KEY (salon_id) REFERENCES salons(id)
      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_settings_percent CHECK (barber_percent BETWEEN 0 AND 100 AND salon_percent BETWEEN 0 AND 100)
) ENGINE=InnoDB;

CREATE TABLE barber_earnings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barber_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    booking_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    salon_amount DECIMAL(10,2) NOT NULL,
    note VARCHAR(255) NULL,
    paid_at DATETIME NOT NULL,
    UNIQUE KEY uq_earning_booking (booking_id),
    INDEX idx_earnings_barber (barber_id),
    CONSTRAINT fk_earnings_barber FOREIGN KEY (barber_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_earnings_salon FOREIGN KEY (salon_id) REFERENCES salons(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_earnings_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
      ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE barber_withdrawals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barber_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_type VARCHAR(50) NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_withdrawals_barber (barber_id),
    CONSTRAINT fk_withdrawals_barber FOREIGN KEY (barber_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawals_salon FOREIGN KEY (salon_id) REFERENCES salons(id)
      ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE tools (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tools_salon FOREIGN KEY (salon_id) REFERENCES salons(id)
      ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_reset_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','approved','rejected','used') NOT NULL DEFAULT 'pending',
    token_hash CHAR(64) NULL,
    expires_at DATETIME NULL,
    INDEX idx_reset_status (status,requested_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id,created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS=1;
