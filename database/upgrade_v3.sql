-- AutoSouq upgrade: customer accounts and the AI assistant.
--
-- @author  Nasser Alshammari (Database Developer)
-- @file    database/upgrade_v3.sql
--
-- For a database created before accounts and the assistant were added (for
-- example the live site). Select the database in phpMyAdmin, open the SQL
-- tab and run this file once. A fresh import of car_dealership.sql already
-- contains these changes, so it does not need this file.
--
-- Nothing is deleted: existing orders keep their data and get user_id NULL
-- (a guest order).

-- users: customer accounts. A customer can sign up with an email and a
-- password, or with Google. password_hash is NULL for an account that only
-- ever signed in with Google; google_sub is Google's permanent id for the
-- person and is NULL until they use Google. Both columns are UNIQUE, and
-- MySQL allows any number of NULLs in a UNIQUE column.
CREATE TABLE IF NOT EXISTS users (
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

ALTER TABLE orders
    ADD COLUMN user_id INT NULL AFTER customer_id,
    ADD INDEX idx_orders_user (user_id),
    ADD CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- login_attempts: failed customer sign-ins, used to lock an account or an
-- address for a short time after repeated failures. Kept in the database,
-- not the session, because an attacker can simply start a new session for
-- every guess. Only hashes of the email and the IP address are stored.
CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id INT AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    ip_hash    CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_email (email_hash, created_at),
    INDEX idx_login_ip    (ip_hash, created_at)
) ENGINE=InnoDB;

-- kb_faq: the knowledge base the AI assistant answers from, besides the
-- cars themselves. Managers edit these articles in admin_knowledge.php.
CREATE TABLE IF NOT EXISTS kb_faq (
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
CREATE TABLE IF NOT EXISTS kb_chunks (
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
CREATE TABLE IF NOT EXISTS chat_rate (
    rate_id    INT AUTO_INCREMENT PRIMARY KEY,
    client_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_client (client_key, created_at)
) ENGINE=InnoDB;

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
