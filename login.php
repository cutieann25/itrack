
<?php
require_once __DIR__ . '/auth.php';

if (current_user()) {
    redirect_for_role(current_user()['role']);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $conn = db_connection();

        $sql = 'SELECT id, username, display_name, role, password_hash
                FROM users
                WHERE username = ? AND is_active = 1
                LIMIT 1';

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log('Login SQL prepare failed: ' . $conn->error);
            $error = 'Login is temporarily unavailable. Please try again later.';
        } else {

            $stmt->bind_param('s', $username);

            if (!$stmt->execute()) {
                error_log('Login SQL execution failed: ' . $stmt->error);
                $error = 'Login is temporarily unavailable. Please try again later.';
            } else {

                $result = $stmt->get_result();
                $account = $result ? $result->fetch_assoc() : null;

                if ($account && password_verify($password, $account['password_hash'])) {

                    session_regenerate_id(true);

                    $_SESSION['user'] = [
                        'id' => $account['id'],
                        'username' => $account['username'],
                        'display_name' => $account['display_name'],
                        'role' => $account['role']
                    ];

                    $stmt->close();

                    redirect_for_role($account['role']);

                } else {
                    $error = 'Invalid username or password.';
                }
            }

            $stmt->close();
        }

    } catch (Throwable $e) {

        error_log('Login error: ' . $e->getMessage());

        $error = 'Unable to process your login. Please try again later.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - i-Tracker</title>

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background:
                linear-gradient(rgba(15,23,42,.22), rgba(15,23,42,.22)),
                url("img/logo_img_login.png") center / cover no-repeat fixed;
            font-family: "Book Antiqua", Georgia, serif;
            color: #172554;
        }

        .login-card {
            width: min(420px, calc(100% - 40px));
            padding: 36px;
            border-radius: 24px;
            background: rgba(255,255,255,.24);
            border: 1px solid rgba(255,255,255,.42);
            box-shadow: 0 28px 80px rgba(15,23,42,.25);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }

        h1 {
            margin: 0 0 8px;
            text-align: center;
        }

        p {
            color: #64748b;
            text-align: center;
        }

        label {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-top: 18px;
            font-weight: 700;
        }

        label svg {
            width: 17px;
            height: 17px;
            color: #1d4ed8;
        }

        input {
            width: 100%;
            box-sizing: border-box;
            margin-top: 8px;
            padding: 13px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font: inherit;
        }

        button {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: 0;
            border-radius: 10px;
            background: #1d4ed8;
            color: white;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #1e40af;
        }

        .error {
            padding: 12px;
            border-radius: 8px;
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>

<body>

    <main class="login-card">

        <h1>i-Tracker</h1>

        <p>Sign in to continue to your dashboard.</p>

        <?php if ($error): ?>
            <div class="error">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php">

            <label for="username">
                <svg aria-hidden="true" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round">
                    <path d="M20 21a8 8 0 0 0-16 0"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
                Username
            </label>

            <input
                id="username"
                name="username"
                required
                autocomplete="username"
            >

            <label for="password">
                <svg aria-hidden="true" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round">
                    <rect width="18" height="11" x="3" y="11" rx="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                Password
            </label>

            <input
                id="password"
                name="password"
                type="password"
                required
                autocomplete="current-password"
            >

            <button type="submit">Sign in</button>

        </form>

    </main>

</body>
</html>
