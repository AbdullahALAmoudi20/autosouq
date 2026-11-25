<?php
declare(strict_types=1);

/** Callers supply trusted body markup with dynamic values escaped. */
function customer_email_layout(string $name, string $title, string $bodyHtml, string $footer): string
{
    $brand = htmlspecialchars(SITE_NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $name = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $footer = htmlspecialchars($footer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return <<<HTML
    <!doctype html><html lang="en"><body style="margin:0;padding:24px 12px;background:#eef2f7;color:#182032;font-family:Arial,sans-serif">
    <table role="presentation" width="100%" align="center" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:12px">
      <tr><td style="background:#101728;color:#ffffff;padding:22px 28px;border-radius:12px 12px 0 0;font-size:22px;font-weight:bold">
        <span style="display:inline-block;padding:8px;background:#536dcb;border-radius:8px;font-size:18px">AS</span> &nbsp;{$brand}
      </td></tr>
      <tr><td style="padding:24px 28px;font-size:16px;line-height:1.5">
        <h1 style="margin:0;font-size:24px">{$title}</h1>
        <p>Hi {$name},</p>
        {$bodyHtml}
      </td></tr>
      <tr><td style="padding:18px 28px;border-top:1px solid #dce1eb;color:#465269;font-size:14px">
        {$footer}
      </td></tr>
    </table></body></html>
    HTML;
}

function customer_code_email_html(string $name, string $code): string
{
    $code = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $brand = htmlspecialchars(SITE_NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = <<<HTML
        <p>Enter this code to finish signing in to {$brand}.</p>
        <p style="margin:20px 0;font-family:monospace;font-size:32px;font-weight:bold;letter-spacing:5px;color:#182032">{$code}</p>
        <p>This code expires in five minutes. Keep it private.</p>
        HTML;
    return customer_email_layout($name, 'Your sign-in code', $body, 'If you didn’t try to sign in, you can ignore this email.');
}

function customer_reset_email_html(string $name, string $url): string
{
    $url = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = <<<HTML
        <p>Use the link below to choose a new password for your account.</p>
        <p style="margin:20px 0"><a href="{$url}" style="display:inline-block;padding:12px 20px;background:#4258a9;color:#ffffff;border-radius:8px;font-weight:bold;text-decoration:none">Reset my password</a></p>
        <p>This link expires in 15 minutes and can be used once.</p>
        HTML;
    return customer_email_layout($name, 'Reset your password', $body, 'If you didn’t request a password reset, you can ignore this email.');
}
