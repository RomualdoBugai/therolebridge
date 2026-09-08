<?php
declare(strict_types=1);

/**
 * Simple dashboard password protection using a shared password and a browser cookie.
 * Include this file at the TOP of any dashboard script (before any HTML output).
 */

// =========================
// CONFIG
// =========================

// Fixed password to access dashboards
const DASHBOARD_PASSWORD = '996348';

// Cookie settings
const DASHBOARD_AUTH_COOKIE_NAME = 'jld_dashboard_auth';
const DASHBOARD_AUTH_COOKIE_TTL_DAYS = 90;

// Extra secret used to sign the cookie (change this to something random)
const DASHBOARD_AUTH_PEPPER = 'muda_isto_para_um_valor_bem_aleatorio_2026';

// =========================
// HELPER: build expected token
// =========================

function jld_dashboard_build_token(): string
{
    // We don't store the password in the cookie, only an HMAC of it + pepper
    return hash_hmac('sha256', DASHBOARD_PASSWORD, DASHBOARD_AUTH_PEPPER);
}

// =========================
// HELPER: check if user is already authenticated (via cookie)
// =========================

function jld_dashboard_is_authorized(): bool
{
    if (empty($_COOKIE[DASHBOARD_AUTH_COOKIE_NAME])) {
        return false;
    }

    $expected = jld_dashboard_build_token();
    $given    = $_COOKIE[DASHBOARD_AUTH_COOKIE_NAME];

    // Use hash_equals to avoid timing attacks
    return hash_equals($expected, $given);
}

// =========================
// LOGIN FLOW
// =========================

// If already authorized, just continue
if (jld_dashboard_is_authorized()) {
    return; // allow the script that included this file to continue
}

// If password was submitted via POST, check it
$loginError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dashboard_password'])) {
    $password = (string)($_POST['dashboard_password'] ?? '');

    if ($password === DASHBOARD_PASSWORD) {
        // Correct password -> set cookie and redirect back to the same page

        $token   = jld_dashboard_build_token();
        $ttlSec  = DASHBOARD_AUTH_COOKIE_TTL_DAYS * 24 * 60 * 60;
        $expires = time() + $ttlSec;

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        // Set cookie for the whole domain
        setcookie(
            DASHBOARD_AUTH_COOKIE_NAME,
            $token,
            [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => $isHttps,   // true if you always use HTTPS
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        // Redirect to avoid resubmitting the form on refresh
        $target = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: ' . $target);
        exit;
    } else {
        $loginError = 'Invalid password.';
    }
}

// If we reach here, user is NOT authorized -> show login form and stop the rest of the script

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Access</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="assets/auth.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <h1 class="auth-title">Dashboard Access</h1>
        <p class="auth-sub">Enter the access password to view internal reports.</p>

        <?php if ($loginError !== null): ?>
            <div class="error">
                <?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8'); ?>">
            <div class="field">
                <label for="dashboard_password">Password</label>
                <input
                    type="password"
                    id="dashboard_password"
                    name="dashboard_password"
                    autocomplete="off"
                    required
                    autofocus
                >
            </div>
            <button type="submit" class="btn">Enter</button>
        </form>

        <p class="small-note">Access is stored only in this browser for a limited time.</p>
    </div>
</div>
</body>
</html>
<?php
// Stop processing the rest of the dashboard script
exit;
