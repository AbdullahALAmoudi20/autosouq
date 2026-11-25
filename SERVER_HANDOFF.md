# AutoSouq server handoff

This update adds customer account and showroom features. Merge the branch containing this guide into the team's current code, then deploy it to the existing PHP and MySQL/MariaDB installation. The live URL in the project README is https://autosouq.infinityfreeapp.com.

## What changed

| Area | What users see | Main files |
|---|---|---|
| Customer sign-in | Email and password sign-in now requires a six-digit email code. The code expires after five minutes. The verification screen accepts typing or pasting into six boxes and offers a resend after 60 seconds. | `app/login.php`, `app/verify_email.php`, `app/includes/customer_auth.php`, `app/includes/customer_email_auth.php`, `app/js/verify-code.js` |
| Password recovery | **Forgot password?** opens a dialog on sign-in and in My account. The email has a single-use link that expires after 15 minutes. | `app/reset_password.php`, `app/includes/password_reset.php`, `app/includes/forgot_password_dialog.php` |
| Branded email | Sign-in, resend, and password-reset messages share the blue AS header, greeting, and footer. | `app/includes/email_template.php` |
| Car ratings and reviews | Customers can post and edit one review per car. The car page shows the average with five stars, the score, and the review count. | `app/product_details.php`, `app/includes/car_reviews.php`, `app/includes/functions.php` |
| Favorites | Heart controls save cars. My account displays saved cars as photo cards with links and Remove buttons. | `app/account.php`, `app/includes/car_card.php`, `app/includes/favorite_button.php`, `app/includes/customer_showroom.php` |
| Test drives | Customers request a one-hour showroom appointment from the car page and track or cancel it in My account. Managers confirm or cancel requests from the dashboard. | `app/product_details.php`, `app/account.php`, `app/admin_dashboard.php`, `app/includes/customer_showroom.php`, `app/includes/test_drive_list.php` |
| Interface | Section jumps briefly highlight the destination and respect reduced motion. The browser tab uses the same blue AS branding as the site. | `app/css/style.css`, `app/js/site.js`, `app/js/validation.js`, `app/js/action-dialogs.js`, `app/includes/header.php`, `app/images/brands/favicon.svg` |

Customer email verification runs in the password sign-in flow. Google sign-in and manager sign-in have their own flows.

## Deploy to the existing server

1. **Review the branch and back up the site.** Compare against the leader's latest branch and preserve any server-side fixes during the merge. Export the existing database and keep a private copy of the deployed files and `app/includes/secrets.php`.
2. **Check the database prerequisites.** Confirm the existing database has `cars`, `users`, and `login_attempts`. If the server is missing the customer-account schema, inspect `database/upgrade_v3.sql` and apply only the missing changes. In particular, it contains an `ALTER TABLE orders`, so check the live schema first.
3. **Import the two additive migrations** into the existing database, using the SQL import screen in the hosting control panel or your usual migration method.
   - `database/upgrade_reviews.sql` adds `car_reviews`.
   - `database/upgrade_customer_features.sql` adds `customer_favorites`, `test_drives`, and `password_resets`.
   - Keep `database/car_dealership.sql` for fresh installations. It starts with `DROP DATABASE IF EXISTS car_dealership` and would erase existing data if run against that database.
4. **Set up production email before switching over customer password sign-in.** Use a Resend sending key and an address on the team's verified sending domain. Add these settings to the server's existing private `app/includes/secrets.php`, preserving its database, AI, and Google settings. Use the deployed HTTPS directory containing `login.php` as `CUSTOMER_APP_URL`. Confirm the host's PHP cURL can reach `https://api.resend.com/emails`.

   ```php
   define('CUSTOMER_EMAIL_RESEND_API_KEY', '<server Resend sending key>');
   define('CUSTOMER_EMAIL_FROM', 'sign-in@your-verified-domain.example');
   define('CUSTOMER_APP_URL', 'https://autosouq.infinityfreeapp.com');
   ```

   Keep the real key in the private server config. `secrets.php` is Git-ignored. The local `onboarding@resend.dev` sender is for testing with the Resend account's own email address.
5. **Publish the merged `app/` files together.** Include the new PHP pages, includes, JavaScript, CSS, and favicon. Preserve the host's private settings and uploaded car images. On the current hosted site, the contents of `app/` are served from the domain root.
6. **Verify with a test customer and manager.** Use two roles in separate browser sessions.
   - Register a test customer, sign in with email and password, receive a branded code, and complete sign-in. Request a replacement code after 60 seconds and verify with the newest code.
   - Request a password reset. Confirm that the email button opens the live HTTPS site, that the password changes, and that the link works only once.
   - Save and remove a car, post and edit a review, and confirm the car's average changes.
   - Request a test drive, confirm it on the manager dashboard, and check that the customer sees the updated status. Try the section jump links in My account.

If deployment needs to be rolled back, restore the last working application files and keep the additive tables in place while the team investigates.

## Paste into Claude

```text
You're helping me merge and deploy our AutoSouq PHP/MySQL project. Read SERVER_HANDOFF.md first. Compare the contributor's branch with our current branch and inspect the existing hosting layout and database schema. Preserve our newer changes, existing data, uploaded images, and private server settings.

Prepare a merge and deployment checklist based on the actual repo and host. Identify conflicts, review the two additive SQL migrations, check prerequisites for customer login, and tell me where to set the server-only Resend and CUSTOMER_APP_URL settings. Never import the fresh-install car_dealership.sql into an existing database. Use placeholders for secrets.

After any local merge, lint the PHP and JavaScript and run the smoke checks in the guide where possible. Report what passed and what still needs live testing. Wait for my authorization before changing the live site or database, and ask me before sending a real test email.
```

## Paste into Kimi

```text
Help me integrate an AutoSouq branch into our existing hosted PHP and MySQL/MariaDB site. Read SERVER_HANDOFF.md in the repository. Inspect the branch diff, deployment paths and schema. Give me a file-by-file list of any merge conflicts and an ordered checklist for backing up, merging, importing upgrade_reviews.sql and upgrade_customer_features.sql, configuring Resend and CUSTOMER_APP_URL in the private server secrets.php, uploading, and testing.

Preserve existing customer and order data, uploads, and provider settings. car_dealership.sql is for new installs and drops the existing database. Check the users and login_attempts tables first and review any upgrade_v3.sql changes against the live schema. Keep API keys out of chat and Git.

Use the proposed checklist to prepare the integration locally if the project is available. Stop before changing the live server, running a live import, or sending test email until I authorize that step. Tell me which smoke checks were completed and which require the live host.
```
