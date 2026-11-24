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
