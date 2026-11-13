-- AutoSouq upgrade: photo gallery and picture framing.
--
-- @author  Nasser Alshammari (Database Developer)
-- @file    database/upgrade_gallery.sql
--
-- For a database that was created before the gallery was added (for
-- example the live site). Select the database in phpMyAdmin, open the SQL
-- tab and run this file once. A fresh import of car_dealership.sql already
-- contains these changes, so it does not need this file.
--
-- Nothing is deleted: existing cars keep their picture and get the default
-- framing (fill the frame, centred, no zoom).

ALTER TABLE cars
    ADD COLUMN image_fit   ENUM('cover','contain') NOT NULL DEFAULT 'cover' AFTER picture,
    ADD COLUMN image_pos_x TINYINT UNSIGNED  NOT NULL DEFAULT 50  AFTER image_fit,
    ADD COLUMN image_pos_y TINYINT UNSIGNED  NOT NULL DEFAULT 50  AFTER image_pos_x,
    ADD COLUMN image_zoom  SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER image_pos_y;

CREATE TABLE IF NOT EXISTS car_images (
    image_id   INT AUTO_INCREMENT PRIMARY KEY,
    car_id     INT          NOT NULL,
    file_name  VARCHAR(120) NOT NULL,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ci_car
        FOREIGN KEY (car_id) REFERENCES cars(car_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_ci_car (car_id, sort_order)
) ENGINE=InnoDB;
