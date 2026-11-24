-- AutoSouq, multi-brand car dealership.
-- CIS209 Web-Based Systems, Task 1: database design.
--
-- @author  Nasser Alshammari (Database Developer)
-- @file    database/car_dealership.sql
--
-- Sections: A tables, B seed data, C admin accounts, D sample orders,
-- E verification queries.
--
-- Load with: mysql -u root -p < database/car_dealership.sql
-- (or import through phpMyAdmin).

DROP DATABASE IF EXISTS car_dealership;
CREATE DATABASE car_dealership
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE car_dealership;


-- SECTION A: TABLES

-- brands: one row per manufacturer.
-- brand_name, country and logo depend on the brand, not on the car, so
-- holding them in `cars` would be a transitive dependency
-- (car_id -> brand_name -> country) and break 3NF.
CREATE TABLE brands (
    brand_id    INT AUTO_INCREMENT PRIMARY KEY,
    brand_name  VARCHAR(50)  NOT NULL UNIQUE,
    country     VARCHAR(50)  NOT NULL,
    logo        VARCHAR(100) NOT NULL,          -- file name only, lives in images/brands/
    founded     SMALLINT     NULL
) ENGINE=InnoDB;

-- categories: body style. Sedan, SUV, Pickup, Hatchback, Coupe.
CREATE TABLE categories (
    category_id   INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(40) NOT NULL UNIQUE,
    description   VARCHAR(180) NULL
) ENGINE=InnoDB;

-- colors: colour catalogue. Cars and colours are many-to-many, resolved by
-- the car_colors junction table below so `cars` has no repeating groups.
CREATE TABLE colors (
    color_id   INT AUTO_INCREMENT PRIMARY KEY,
    color_name VARCHAR(30) NOT NULL UNIQUE,
    hex_code   CHAR(7)     NOT NULL            -- e.g. #1B1B1B
) ENGINE=InnoDB;

-- cars: the product table. The first five columns are the ones the brief
-- requires for every product.
CREATE TABLE cars (
    car_id        INT AUTO_INCREMENT PRIMARY KEY,      -- unique id
    car_name      VARCHAR(100)   NOT NULL,             -- product name
    picture       VARCHAR(120)   NOT NULL,             -- file name only
    -- How the main picture sits in its frame, set by the manager:
    -- cover crops to fill the frame, contain shows the whole photo.
    -- pos_x / pos_y are the focus point in percent, zoom is in percent.
    image_fit     ENUM('cover','contain') NOT NULL DEFAULT 'cover',
    image_pos_x   TINYINT UNSIGNED  NOT NULL DEFAULT 50,
    image_pos_y   TINYINT UNSIGNED  NOT NULL DEFAULT 50,
    image_zoom    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    stock         INT UNSIGNED   NOT NULL DEFAULT 0,   -- units available
    price         DECIMAL(10,2)  NOT NULL,             -- unit price in SAR

    brand_id      INT            NOT NULL,
    category_id   INT            NOT NULL,

    model_year    SMALLINT       NOT NULL,
    engine        VARCHAR(60)    NOT NULL,
    horsepower    SMALLINT       NOT NULL,
    transmission  ENUM('Automatic','Manual','CVT','Dual-Clutch') NOT NULL DEFAULT 'Automatic',
    fuel_type     ENUM('Petrol','Diesel','Hybrid','Electric')    NOT NULL DEFAULT 'Petrol',
    seats         TINYINT        NOT NULL DEFAULT 5,
    warranty_yrs  TINYINT        NOT NULL DEFAULT 3,
    description   TEXT           NULL,
    is_featured   TINYINT(1)     NOT NULL DEFAULT 0,
    created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_cars_brand
        FOREIGN KEY (brand_id)    REFERENCES brands(brand_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cars_category
        FOREIGN KEY (category_id) REFERENCES categories(category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,

    CONSTRAINT chk_price CHECK (price > 0),
    INDEX idx_cars_brand    (brand_id),
    INDEX idx_cars_category (category_id),
    INDEX idx_cars_name     (car_name)
) ENGINE=InnoDB;

-- car_colors: junction table for the M:N between cars and colors.
-- The primary key is both foreign keys together.
CREATE TABLE car_colors (
    car_id   INT NOT NULL,
    color_id INT NOT NULL,
    PRIMARY KEY (car_id, color_id),
    CONSTRAINT fk_cc_car
        FOREIGN KEY (car_id)   REFERENCES cars(car_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cc_color
        FOREIGN KEY (color_id) REFERENCES colors(color_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- car_images: extra photos for the gallery on the car page. 1:N with cars.
-- The main photo stays in cars.picture, so every query that only needs one
-- image is unchanged. Rows go with the car (ON DELETE CASCADE).
CREATE TABLE car_images (
    image_id   INT AUTO_INCREMENT PRIMARY KEY,
    car_id     INT          NOT NULL,
    file_name  VARCHAR(120) NOT NULL,              -- file name only
    sort_order INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ci_car
        FOREIGN KEY (car_id) REFERENCES cars(car_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_ci_car (car_id, sort_order)
) ENGINE=InnoDB;

-- admins: shop managers (Task 8). password_hash holds a bcrypt hash from
-- PHP password_hash(), never a plain-text password.
CREATE TABLE admins (
    admin_id      INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(40)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(80)  NOT NULL,
    email         VARCHAR(120) NOT NULL UNIQUE,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login    DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- customers: buyer details captured on the checkout form.
CREATE TABLE customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name   VARCHAR(80)  NOT NULL,
    email       VARCHAR(120) NOT NULL,
    phone       VARCHAR(25)  NOT NULL,
    city        VARCHAR(60)  NOT NULL,
    address     VARCHAR(200) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cust_email (email)
) ENGINE=InnoDB;

-- users: customer accounts. A customer can sign up with an email and a
-- password, or with Google. password_hash is NULL for an account that only
-- ever signed in with Google; google_sub is Google's permanent id for the
-- person and is NULL until they use Google. Both columns are UNIQUE, and
-- MySQL allows any number of NULLs in a UNIQUE column.
CREATE TABLE users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(80)  NOT NULL,
    email         VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    google_sub    VARCHAR(64)  NULL UNIQUE,
    phone         VARCHAR(25)  NULL,
    city          VARCHAR(60)  NULL,
    address       VARCHAR(200) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME     NULL
) ENGINE=InnoDB;

-- orders: one completed checkout (Task 7).
-- user_id is filled in when the buyer was signed in, so the order shows on
-- their account page. Guests can still buy, so it may be NULL, and deleting
-- an account keeps the order (ON DELETE SET NULL).
CREATE TABLE orders (
    order_id     INT AUTO_INCREMENT PRIMARY KEY,
    customer_id  INT           NOT NULL,
    user_id      INT           NULL,
    order_date   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(12,2) NOT NULL,
    status       ENUM('Placed','Preparing','Delivered','Cancelled')
                 NOT NULL DEFAULT 'Placed',
    CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_orders_customer (customer_id),
    INDEX idx_orders_user     (user_id),
    INDEX idx_orders_date     (order_date)
) ENGINE=InnoDB;

-- order_items: the line items of an order.
-- unit_price is copied in rather than read from cars.price, because it
-- records what the customer actually paid. cars.price can change later and
-- must not alter past invoices.
CREATE TABLE order_items (
    order_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id      INT           NOT NULL,
    car_id        INT           NOT NULL,
    quantity      INT UNSIGNED  NOT NULL,
    unit_price    DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_items_order
        FOREIGN KEY (order_id) REFERENCES orders(order_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_items_car
        FOREIGN KEY (car_id)   REFERENCES cars(car_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_qty CHECK (quantity > 0),
    INDEX idx_items_order (order_id),
    INDEX idx_items_car   (car_id)
) ENGINE=InnoDB;

-- login_attempts: failed customer sign-ins, used to lock an account or an
-- address for a short time after repeated failures. Kept in the database,
-- not the session, because an attacker can simply start a new session for
-- every guess. Only hashes of the email and the IP address are stored.
CREATE TABLE login_attempts (
    attempt_id INT AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    ip_hash    CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_email (email_hash, created_at),
    INDEX idx_login_ip    (ip_hash, created_at)
) ENGINE=InnoDB;

-- kb_faq: the knowledge base the AI assistant answers from, besides the
-- cars themselves. Managers edit these articles in admin_knowledge.php.
CREATE TABLE kb_faq (
    faq_id     INT AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(150) NOT NULL,
    body       TEXT         NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- kb_chunks: the search index for the AI assistant (RAG). One row per car
-- and per active article: the text that is searched, and its embedding (a
-- list of numbers from the OpenAI embeddings API, stored as JSON). The
-- index is rebuilt from cars and kb_faq, so it holds no data of its own.
-- content_hash lets a rebuild skip rows whose text has not changed.
CREATE TABLE kb_chunks (
    chunk_id     INT AUTO_INCREMENT PRIMARY KEY,
    source_type  ENUM('car','faq') NOT NULL,
    source_id    INT          NOT NULL,
    title        VARCHAR(150) NOT NULL,
    content      TEXT         NOT NULL,
    content_hash CHAR(64)     NOT NULL,
    embedding    MEDIUMTEXT   NOT NULL,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chunk_source (source_type, source_id)
) ENGINE=InnoDB;

-- chat_rate: one row per question sent to the assistant, so a visitor can
-- be limited to a number of questions per hour. Only a hash of the IP
-- address is stored, never the address itself or the question.
CREATE TABLE chat_rate (
    rate_id    INT AUTO_INCREMENT PRIMARY KEY,
    client_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_client (client_key, created_at)
) ENGINE=InnoDB;


-- SECTION B: SEED DATA

INSERT INTO brands (brand_id, brand_name, country, logo, founded) VALUES
(1,'Toyota',    'Japan',         'toyota.png',    1937),
(2,'Ford',      'United States', 'ford.png',      1903),
(3,'Chevrolet', 'United States', 'chevrolet.png', 1911),
(4,'Nissan',    'Japan',         'nissan.png',    1933),
(5,'Hyundai',   'South Korea',   'hyundai.png',   1967),
(6,'BMW',       'Germany',       'bmw.png',       1916);

INSERT INTO categories (category_id, category_name, description) VALUES
(1,'Sedan',    'Four-door saloon, comfortable family transport.'),
(2,'SUV',      'Sport utility vehicle with high ground clearance.'),
(3,'Pickup',   'Open cargo bed, built for work and towing.'),
(4,'Hatchback','Compact city car with a rear liftgate.'),
(5,'Coupe',    'Two-door performance-oriented body style.');

INSERT INTO colors (color_id, color_name, hex_code) VALUES
(1,'Pearl White','#F2F2EF'),
(2,'Midnight Black','#141414'),
(3,'Silver','#B9BDC1'),
(4,'Graphite Grey','#4A4E52'),
(5,'Ruby Red','#9B1B22'),
(6,'Deep Blue','#1B3A6B'),
(7,'Desert Beige','#C8B79A'),
(8,'Forest Green','#1F4A34');

-- Cars. picture stores the file name only; the image sits in app/images/cars/.
INSERT INTO cars
 (car_id, car_name, picture, stock, price, brand_id, category_id, model_year,
  engine, horsepower, transmission, fuel_type, seats, warranty_yrs, is_featured, description)
VALUES
(1,'Toyota Camry LE','toyota-camry.jpg', 8, 112000.00, 1,1,2025,
 '2.5L 4-Cylinder',203,'Automatic','Petrol',5,5,1,
 'The benchmark family sedan. Quiet cabin, low running cost and a five-year warranty make the Camry the safest first car purchase in our showroom.'),

(2,'Toyota Land Cruiser GXR','toyota-land-cruiser.jpg', 4, 335000.00, 1,2,2025,
 '3.5L V6 Twin-Turbo',409,'Automatic','Petrol',7,5,1,
 'Body-on-frame off-road SUV with a locking centre differential. Built for long desert trips with seven full-size seats.'),

(3,'Toyota Hilux Double Cab','toyota-hilux.jpg', 10, 128000.00, 1,3,2025,
 '2.8L Turbo Diesel',204,'Automatic','Diesel',5,5,0,
 'The workhorse pickup. 3.5-tonne towing capacity and a payload rated for daily site work.'),

(4,'Toyota Corolla Hatchback','toyota-corolla-hatchback.jpg', 12, 84000.00, 1,4,2025,
 '1.8L Hybrid',140,'CVT','Hybrid',5,5,0,
 'Hybrid hatchback returning around 4.5 L/100km in city driving. Ideal for commuters.'),

(5,'Ford F-150 XLT','ford-f150.jpg', 6, 189000.00, 2,3,2025,
 '3.5L EcoBoost V6',400,'Automatic','Petrol',5,3,1,
 'Aluminium-bodied full-size pickup with the highest payload rating in its class.'),

(6,'Ford Explorer ST','ford-explorer.jpg', 5, 245000.00, 2,2,2025,
 '3.0L EcoBoost V6',400,'Automatic','Petrol',7,3,0,
 'Three-row performance SUV with sport-tuned suspension and all-wheel drive as standard.'),

(7,'Ford Mustang GT','ford-mustang.jpg', 3, 268000.00, 2,5,2025,
 '5.0L Coyote V8',486,'Dual-Clutch','Petrol',4,3,1,
 'Naturally aspirated V8 muscle coupe. Six drive modes and an active exhaust system.'),

(8,'Ford Focus Titanium','ford-focus.jpg', 9, 76000.00, 2,4,2024,
 '1.5L EcoBoost',180,'Automatic','Petrol',5,3,0,
 'Compact hatchback with a firm European chassis tune and a well-equipped Titanium trim.'),

(9,'Chevrolet Tahoe LT','chevrolet-tahoe.jpg', 4, 289000.00, 3,2,2025,
 '5.3L V8',355,'Automatic','Petrol',8,3,1,
 'Eight-seat full-size SUV with independent rear suspension and a 2,000 kg towing rating.'),

(10,'Chevrolet Silverado LTZ','chevrolet-silverado.jpg', 7, 205000.00, 3,3,2025,
 '6.2L V8',420,'Automatic','Petrol',5,3,0,
 'Heavy-duty pickup with a ten-speed automatic and an integrated trailer brake controller.'),

(11,'Chevrolet Malibu Premier','chevrolet-malibu.jpg', 11, 98000.00, 3,1,2024,
 '2.0L Turbo',250,'Automatic','Petrol',5,3,0,
 'Mid-size sedan with a long wheelbase and generous rear legroom.'),

(12,'Chevrolet Camaro SS','chevrolet-camaro.jpg', 3, 242000.00, 3,5,2025,
 '6.2L LT1 V8',455,'Manual','Petrol',4,3,0,
 'Rear-drive coupe available with a genuine six-speed manual gearbox.'),

(13,'Nissan Patrol Platinum','nissan-patrol.jpg', 5, 312000.00, 4,2,2025,
 '5.6L V8',400,'Automatic','Petrol',8,5,1,
 'Full-size SUV with hydraulic body motion control and four terrain modes.'),

(14,'Nissan Altima SV','nissan-altima.jpg', 14, 89000.00, 4,1,2025,
 '2.5L 4-Cylinder',188,'CVT','Petrol',5,5,0,
 'Efficient mid-size sedan with zero-gravity seats and standard driver aids.'),

(15,'Nissan Navara SE','nissan-navara.jpg', 6, 115000.00, 4,3,2024,
 '2.5L Turbo Diesel',190,'Manual','Diesel',5,5,0,
 'Coil-sprung rear suspension makes this the most comfortable pickup we sell.'),

(16,'Hyundai Tucson Smart','hyundai-tucson.jpg', 13, 106000.00, 5,2,2025,
 '2.0L 4-Cylinder',156,'Automatic','Petrol',5,5,0,
 'Compact crossover with a ten-year warranty option and a large touchscreen.'),

(17,'Hyundai Elantra GLS','hyundai-elantra.jpg', 16, 72000.00, 5,1,2025,
 '1.6L 4-Cylinder',123,'Automatic','Petrol',5,5,1,
 'Our best-value sedan. Low insurance group and excellent resale value.'),

(18,'Hyundai Ioniq 5','hyundai-ioniq5.jpg', 5, 168000.00, 5,4,2025,
 '77.4 kWh Battery',225,'Automatic','Electric',5,8,1,
 'Fully electric crossover with 800V architecture — 10 to 80 percent charge in 18 minutes.'),

(19,'BMW 330i M Sport','bmw-330i.jpg', 4, 231000.00, 6,1,2025,
 '2.0L TwinPower Turbo',255,'Automatic','Petrol',5,3,1,
 'Rear-drive sport sedan with adaptive M suspension and near-perfect weight distribution.'),

(20,'BMW X5 xDrive40i','bmw-x5.jpg', 3, 398000.00, 6,2,2025,
 '3.0L Inline-6 Turbo',375,'Automatic','Petrol',5,3,1,
 'Luxury SUV with air suspension, laser headlights and a curved display cockpit.'),

(21,'BMW M4 Competition','bmw-m4.jpg', 2, 445000.00, 6,5,2025,
 '3.0L S58 Twin-Turbo',503,'Dual-Clutch','Petrol',4,3,0,
 'Track-capable coupe with carbon bucket seats and an adaptive M differential.'),

(22,'Nissan Kicks S','nissan-kicks.jpg', 15, 68000.00, 4,4,2024,
 '1.6L 4-Cylinder',122,'CVT','Petrol',5,5,0,
 'The cheapest car on our floor. Small crossover with a surprisingly large boot.');

-- Available colours per car.
INSERT INTO car_colors (car_id, color_id) VALUES
(1,1),(1,2),(1,3),(1,6),
(2,1),(2,2),(2,7),(2,4),
(3,1),(3,3),(3,8),
(4,1),(4,2),(4,5),
(5,2),(5,3),(5,6),(5,1),
(6,2),(6,4),(6,1),
(7,5),(7,2),(7,1),(7,6),
(8,1),(8,3),(8,5),
(9,1),(9,2),(9,4),
(10,2),(10,3),(10,5),
(11,1),(11,3),(11,6),
(12,5),(12,2),(12,1),
(13,1),(13,2),(13,7),(13,4),
(14,1),(14,3),(14,6),
(15,1),(15,4),(15,8),
(16,1),(16,2),(16,3),(16,6),
(17,1),(17,3),(17,5),
(18,1),(18,3),(18,4),(18,6),
(19,2),(19,1),(19,6),(19,4),
(20,1),(20,2),(20,4),
(21,5),(21,2),(21,8),
(22,1),(22,5),(22,3);

-- Knowledge base for the AI assistant. The cars are indexed straight from
-- the cars table; these articles cover everything else a buyer asks about.
INSERT INTO kb_faq (faq_id, title, body) VALUES
(1,'How buying a car on AutoSouq works',
 'Add the car you want to your cart, open Your cart and press Buy. This reserves the vehicles and takes them off our stock straight away. Nothing is charged online. Bring your national ID or Iqama to the showroom within 7 days to sign the paperwork and pay, or the reservation is released.'),
(2,'Payment methods',
 'At the showroom we accept bank transfer, mada debit cards, credit cards and certified cheques. Cash payments are limited to 60,000 SAR by Saudi regulations. A financed purchase is paid through the partner bank.'),
(3,'Car financing and monthly installments',
 'We arrange Islamic (Murabaha) financing with partner banks for salaried customers. Typical terms are 12 to 60 months with a down payment from 10 percent. The profit rate depends on the bank and your salary, usually between 3 and 6 percent a year. Every car page has a finance calculator that estimates the monthly payment; the bank confirms the final figure.'),
(4,'Warranty',
 'Every new car comes with the manufacturer warranty shown on its page, between 3 and 5 years depending on the brand, with unlimited kilometres for the first 3 years. The warranty is honoured at any authorised service centre of that brand in Saudi Arabia.'),
(5,'Registration, plates and insurance',
 'We register the car in your name, issue the plates and the Istimara (registration card) and can arrange comprehensive insurance before you collect the car. Registration takes 2 to 3 working days after payment. Insurance is mandatory in Saudi Arabia before the car can be driven.'),
(6,'Delivery and collection',
 'You can collect the car from the Riyadh showroom, or we deliver free of charge anywhere in Riyadh. Delivery to Jeddah, Dammam and other cities costs 1,500 SAR and takes 3 to 5 working days.'),
(7,'Test drives',
 'Test drives are free. Visit the showroom during opening hours with a valid driving licence, or call us to book a time so the car is ready for you.'),
(8,'Showroom location and opening hours',
 'AutoSouq is on King Fahd Road, Al Olaya District, Riyadh 12333. Phone +966 11 555 0199, email sales@autosouq.test. We are open Saturday to Thursday 09:00 to 21:00 and Friday 16:00 to 21:00.'),
(9,'Trade-in of your current car',
 'We accept trade-ins of any brand. Bring the car to the showroom for a free 30 minute inspection and we will make an offer that can be deducted from the price of your new car.'),
(10,'Cancelling a reservation',
 'You can cancel a reservation free of charge before you pay at the showroom; call us or email us with your order number. After the car is registered in your name it can no longer be returned, but it is still covered by the warranty.'),
(11,'Recall checks',
 'Each car page lists the official safety recalls published by NHTSA for that make, model and year. Any recall work is done free of charge by the brand service centre before or after delivery.'),
(12,'Customer accounts',
 'Creating an account is optional. With an account you can see every order you placed and its status (Placed, Preparing, Delivered or Cancelled), and your details are filled in for you at checkout. You can sign up with your email or with your Google account.'),
(13,'Comparing cars',
 'Use the Compare button on any car to compare up to three cars side by side: price, engine, horsepower, fuel, seats and warranty. The best value in each row is highlighted.'),
(14,'Stock and availability',
 'The stock shown on each car page is live. If a car shows as sold out, contact us: we can order it from the brand, which usually takes 4 to 8 weeks.');


-- SECTION C: ADMIN ACCOUNTS
--
-- MySQL cannot generate a bcrypt hash, only PHP password_hash() can, so
-- these rows go in with the placeholder 'NEEDS_HASH'. Run
-- database/setup_admins.php once to replace them with real hashes. Login
-- rejects any account still holding the placeholder.

-- One manager account per team member.
INSERT INTO admins (admin_id, username, password_hash, full_name, email) VALUES
(1,'abdullah','NEEDS_HASH', 'Abdullah Alamoudi', 'abdullah@autosouq.test'),
(2,'nasser',  'NEEDS_HASH', 'Nasser Alshammari', 'nasser@autosouq.test'),
(3,'ahmed',   'NEEDS_HASH', 'Ahmed Muaidi',      'ahmed@autosouq.test'),
(4,'fadel',   'NEEDS_HASH', 'Fadel Al Saif',     'fadel@autosouq.test'),
(5,'ali',     'NEEDS_HASH', 'Ali Alshehri',      'ali@autosouq.test');


-- SECTION D: SAMPLE CUSTOMERS AND ORDERS
-- Gives the admin dashboard and the reports something to show.

INSERT INTO customers (customer_id, full_name, email, phone, city, address) VALUES
(1,'Sara Al-Dossari','sara.demo@example.test','0551234567','Riyadh','Al Olaya District, Building 12'),
(2,'Omar Al-Ghamdi', 'omar.demo@example.test','0509876543','Jeddah','Al Rawdah District, Villa 7'),
(3,'Layla Al-Mutairi','layla.demo@example.test','0533344556','Dammam','Al Faisaliyah, Apt 304');

INSERT INTO orders (order_id, customer_id, order_date, total_amount, status) VALUES
(1,1,'2026-07-14 10:22:00', 224000.00,'Delivered'),
(2,2,'2026-08-02 16:40:00', 189000.00,'Preparing'),
(3,3,'2026-08-19 09:05:00', 262000.00,'Placed');

INSERT INTO order_items (order_id, car_id, quantity, unit_price) VALUES
(1, 1, 2, 112000.00),
(2, 5, 1, 189000.00),
(3,17, 1,  72000.00),
(3,16, 1, 106000.00),
(3, 4, 1,  84000.00);


-- SECTION E: VERIFICATION QUERIES
-- Run after import. The output is the table hardcopy required by Milestone 2.

-- E1. Row counts per table
-- SELECT 'brands' AS table_name, COUNT(*) AS rows_loaded FROM brands
-- UNION ALL SELECT 'categories', COUNT(*) FROM categories
-- UNION ALL SELECT 'colors',     COUNT(*) FROM colors
-- UNION ALL SELECT 'cars',       COUNT(*) FROM cars
-- UNION ALL SELECT 'car_colors', COUNT(*) FROM car_colors
-- UNION ALL SELECT 'admins',     COUNT(*) FROM admins
-- UNION ALL SELECT 'customers',  COUNT(*) FROM customers
-- UNION ALL SELECT 'orders',     COUNT(*) FROM orders
-- UNION ALL SELECT 'order_items',COUNT(*) FROM order_items;

-- E2. The catalogue as the customer sees it, joined across three tables
-- SELECT c.car_id, c.car_name, b.brand_name, cat.category_name,
--        c.price, c.stock, c.picture
-- FROM cars c
-- JOIN brands b       ON b.brand_id    = c.brand_id
-- JOIN categories cat ON cat.category_id = c.category_id
-- ORDER BY b.brand_name, c.car_name;

-- E3. Colours available for each car, through the junction table
-- SELECT c.car_name, GROUP_CONCAT(co.color_name ORDER BY co.color_name SEPARATOR ', ') AS colours
-- FROM cars c
-- JOIN car_colors cc ON cc.car_id = c.car_id
-- JOIN colors co     ON co.color_id = cc.color_id
-- GROUP BY c.car_id, c.car_name;

-- E4. Full order history with its line items
-- SELECT o.order_id, cu.full_name, o.order_date, ci.car_name,
--        oi.quantity, oi.unit_price, (oi.quantity * oi.unit_price) AS line_total
-- FROM orders o
-- JOIN customers   cu ON cu.customer_id = o.customer_id
-- JOIN order_items oi ON oi.order_id    = o.order_id
-- JOIN cars        ci ON ci.car_id      = oi.car_id
-- ORDER BY o.order_id, oi.order_item_id;

-- E5. Every declared foreign key, used to check the ER diagram
-- SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME,
--        REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
-- FROM information_schema.KEY_COLUMN_USAGE
-- WHERE TABLE_SCHEMA = 'car_dealership' AND REFERENCED_TABLE_NAME IS NOT NULL
-- ORDER BY TABLE_NAME, COLUMN_NAME;
