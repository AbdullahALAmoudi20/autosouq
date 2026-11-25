AutoSouq - multi-brand car dealership
CIS209 Web-Based Systems, Group 2

Live site: https://autosouq.infinityfreeapp.com

Team
  Abdullah Alamoudi   Team Leader, Back-End        (Tasks 5, 6, 7, 12)
  Nasser Alshammari   Database                     (Tasks 1, 16, 17)
  Ahmed Muaidi        Front-End, Accessibility     (Tasks 2, 11, 15)
  Fadel Al Saif       Admin Module                 (Tasks 8, 9, 10)
  Ali Alshehri        Product Pages, JavaScript    (Tasks 3, 4, 13, 14)

Folders
  app/          the website (PHP, CSS, JavaScript, images)
  database/     car_dealership.sql, setup_admins.php, upgrade_gallery.sql
  Milestone 1-4 the reports and the presentation


How to run it on XAMPP

1. Download this repository (green "Code" button > "Download ZIP"),
   unzip it and rename the folder to autosouq. Put it here:
       C:\xampp\htdocs\autosouq
   Inside it you should see the app and database folders.

2. Open the XAMPP Control Panel and click Start for Apache and MySQL.

3. Open http://localhost/phpmyadmin
   Click "Import" at the top, choose
       C:\xampp\htdocs\autosouq\database\car_dealership.sql
   and click "Import" (or "Go") at the bottom.

4. Open this link once to create the manager passwords:
       http://localhost/autosouq/database/setup_admins.php

5. Open the website:
       http://localhost/autosouq/app/

Manager sign-in (click "Manager" in the top menu):
   abdullah / Abdullah@209
   nasser   / Nasser@209
   ahmed    / Ahmed@209
   fadel    / Fadel@209
   ali      / Ali@209

Customer email verification

After the password, AutoSouq sends a single-use code. The customer must
enter it within five minutes. Set up delivery before using customer password sign-in:

1. Register with an email address where you can receive codes.
2. Create a sending API key in Resend and verify a sending domain.
3. Copy app/includes/secrets.example.php to app/includes/secrets.php if
   needed. Set CUSTOMER_EMAIL_RESEND_API_KEY and CUSTOMER_EMAIL_FROM. Keep this
   file private and use a sender address on the verified domain.
4. If your database predates customer accounts, apply upgrade_v3.sql to
   install the shared login_attempts table. No additional tables are
   needed for email verification.

Customer features on an existing installation
---------------------------------------------
Import database/upgrade_customer_features.sql into the current database before
deploying the updated PHP files. It adds favorites, test drives and password
reset tokens. For the earlier car reviews feature also apply upgrade_reviews.sql.
Fresh installs using car_dealership.sql already have these tables.

Set CUSTOMER_APP_URL in the live app/includes/secrets.php to the HTTPS URL of
the directory containing login.php (for the current site use
https://autosouq.infinityfreeapp.com). Reset links use this trusted address.
Both reset links and sign-in codes use the existing Resend key and sender.
The local default matches localhost/autosouq-main/autosouq/app.

Customers can save cars, remove them in My account, and request one-hour test
drives during Riyadh showroom hours. Requests reserve the time until cancelled.
Managers confirm or cancel future appointments from the dashboard. Customers
can see their status and cancel their own future appointments in My account.

The listed passwords are for local demos. Use unique manager passwords
before deploying the manager area publicly.

If you see "Database connection failed":
 - Make sure MySQL is green (running) in XAMPP.
 - If your MySQL root user has a password, or MySQL uses a port other
   than 3306, edit app/includes/config.php (DB_PASS and DB_PORT).

Already have an older copy of the database? Either import
car_dealership.sql again (this replaces the old data), or keep your data
and run these once from the phpMyAdmin SQL tab, in this order:
    database/upgrade_gallery.sql   (photo gallery)
    database/upgrade_v3.sql        (customer accounts and the AI assistant)


AI assistant and Google sign-in (optional)

The site works without any keys: the assistant then answers with a plain
search of the catalogue, and the "Continue with Google" button is hidden.
To switch them on:

1. Copy app/includes/secrets.example.php to app/includes/secrets.php
   (secrets.php is ignored by git, so your keys are never uploaded).
2. Put the OpenAI and Anthropic keys in it.
3. For Google: in Google Cloud Console create an OAuth client of type
   "Web application" and add this Authorised redirect URI:
       http://localhost/autosouq/app/google_callback.php
   then copy the client id and secret into secrets.php, with
   GOOGLE_REDIRECT_URI set to exactly the same address.
4. Sign in as a manager and press "Rebuild AI index" on the dashboard.
