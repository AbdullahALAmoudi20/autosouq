<?php
/**
 * AutoSouq - one-time manager password setup.
 *
 * car_dealership.sql inserts the manager accounts with password_hash set
 * to 'NEEDS_HASH', because MySQL cannot produce a bcrypt hash; only PHP
 * password_hash() can. This script writes the real hashes.
 *
 * Run once at http://localhost/autosouq/database/setup_admins.php, then
 * delete the file. It skips any account that already has a real hash.
 * This is a setup utility, not one of the shop pages.
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    database/setup_admins.php
 * @task    supports Task 8
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/db.php';

/** username => plain password. Change these before you run the script. */
const DEFAULT_ACCOUNTS = [
    'abdullah' => 'Abdullah@209',
    'nasser'   => 'Nasser@209',
    'ahmed'    => 'Ahmed@209',
    'fadel'    => 'Fadel@209',
    'ali'      => 'Ali@209',
];

const SENTINEL = 'NEEDS_HASH';

$report = [];

foreach (DEFAULT_ACCOUNTS as $username => $plainPassword) {

    $stmt = db()->prepare(
        'SELECT admin_id, username, full_name, password_hash
           FROM admins WHERE username = :username'
    );
    $stmt->execute([':username' => $username]);
    $admin = $stmt->fetch();

    if ($admin === false) {
        $report[] = [
            'user'   => $username,
            'status' => 'missing',
            'note'   => 'No such row in admins. Import car_dealership.sql first.',
        ];
        continue;
    }

    if ($admin['password_hash'] !== SENTINEL) {
        $report[] = [
            'user'   => $username,
            'status' => 'skipped',
            'note'   => 'This account already has a password. Nothing was changed.',
        ];
        continue;
    }

    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

    db()->prepare('UPDATE admins SET password_hash = :hash WHERE admin_id = :id')
        ->execute([':hash' => $hash, ':id' => (int) $admin['admin_id']]);

    $report[] = [
        'user'   => $username,
        'status' => 'created',
        'note'   => 'Password set for ' . $admin['full_name'] . '.',
        'pass'   => $plainPassword,
    ];
}

/** Local escape helper so this file does not depend on functions.php. */
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AutoSouq manager setup</title>
<style>
    body { font-family: system-ui, sans-serif; background: #f6f8fb; color: #16202e;
           margin: 0; padding: 2.5rem 1.25rem; line-height: 1.6; }
    .card { max-width: 640px; margin-inline: auto; background: #fff; padding: 2rem;
            border: 1px solid #d8dfe9; border-radius: 12px;
            box-shadow: 0 4px 14px rgba(16,32,55,.1); }
    h1 { margin-top: 0; font-size: 1.5rem; }
    table { border-collapse: collapse; width: 100%; margin-block: 1.25rem; }
    th, td { text-align: left; padding: .6rem .5rem; border-bottom: 1px solid #e4e9f0;
             vertical-align: top; }
    th { font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7788; }
    code { background: #eef2f7; padding: .12em .4em; border-radius: 4px; }
    .tag { display: inline-block; padding: .1rem .5rem; border-radius: 999px;
           font-size: .74rem; font-weight: 700; text-transform: uppercase; }
    .created { background: #e7f6ec; color: #10633a; }
    .skipped { background: #e8effa; color: #1c3f7c; }
    .missing { background: #fdeaea; color: #96201f; }
    .warn { background: #fdf3e0; border: 1px solid #f0d1a0; color: #8a5300;
            padding: .85rem 1rem; border-radius: 8px; }
    a.btn { display: inline-block; margin-top: 1rem; padding: .7rem 1.2rem;
            background: #d97706; color: #0b1524; font-weight: 700;
            text-decoration: none; border-radius: 6px; }
</style>
</head>
<body>
<div class="card">
    <h1>Manager accounts</h1>

    <table>
        <thead>
            <tr><th>Account</th><th>Result</th><th>Detail</th></tr>
        </thead>
        <tbody>
        <?php foreach ($report as $row): ?>
            <tr>
                <td><code><?= h($row['user']) ?></code></td>
                <td><span class="tag <?= h($row['status']) ?>"><?= h($row['status']) ?></span></td>
                <td>
                    <?= h($row['note']) ?>
                    <?php if (isset($row['pass'])): ?>
                        <br>Password: <code><?= h($row['pass']) ?></code>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="warn">
        Write these passwords down, then <strong>delete this file</strong>
        (<code>database/setup_admins.php</code>). Anyone who can reach it can read
        the default passwords.
    </p>

    <a class="btn" href="../app/admin_login.php">Go to the manager sign in page</a>
</div>
</body>
</html>
