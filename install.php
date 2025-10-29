<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VibeWriter Installation</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        h1 {
            color: #667eea;
            margin-bottom: 10px;
            font-size: 32px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .step {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .step h3 {
            color: #333;
            margin-bottom: 10px;
        }
        .step p {
            color: #666;
            line-height: 1.6;
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            color: #e83e8c;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>VibeWriter Installation</h1>
        <p class="subtitle">AI-Powered Book Writing & Organization Tool</p>

        <?php
        // Check if installation is being run
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_once 'config/config.php';

            $dbHost = $_POST['db_host'] ?? 'localhost';
            $dbName = $_POST['db_name'] ?? 'vibewriter';
            $dbUser = $_POST['db_user'] ?? 'root';
            $dbPass = $_POST['db_pass'] ?? '';

            echo '<div class="step">';
            echo '<h3>Installation Progress</h3>';

            try {
                // Step 1: Create database if it doesn't exist
                echo '<p>Step 1: Creating database... ';
                $pdo = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                echo '<span class="success">✓ Done</span></p>';

                // Step 2: Connect to the database
                echo '<p>Step 2: Connecting to database... ';
                $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                echo '<span class="success">✓ Connected</span></p>';

                // Step 3: Execute schema
                echo '<p>Step 3: Creating tables... ';
                $schema = file_get_contents('database/schema.sql');
                $pdo->exec($schema);
                echo '<span class="success">✓ Tables created</span></p>';

                // Step 4: Update config file
                echo '<p>Step 4: Updating configuration... ';
                $configContent = file_get_contents('config/database.php');
                $configContent = preg_replace("/define\('DB_HOST', '[^']*'\);/", "define('DB_HOST', '$dbHost');", $configContent);
                $configContent = preg_replace("/define\('DB_NAME', '[^']*'\);/", "define('DB_NAME', '$dbName');", $configContent);
                $configContent = preg_replace("/define\('DB_USER', '[^']*'\);/", "define('DB_USER', '$dbUser');", $configContent);
                $configContent = preg_replace("/define\('DB_PASS', '[^']*'\);/", "define('DB_PASS', '$dbPass');", $configContent);
                file_put_contents('config/database.php', $configContent);
                echo '<span class="success">✓ Configuration updated</span></p>';

                echo '<p style="margin-top: 20px;"><strong class="success">✓ Installation completed successfully!</strong></p>';
                echo '<a href="index.php" class="btn">Go to VibeWriter</a>';

            } catch (PDOException $e) {
                echo '<span class="error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</span></p>';
                echo '</div>';
                echo '<a href="install.php" class="btn">Try Again</a>';
            }

            echo '</div>';

        } else {
            // Show installation form
            ?>
            <div class="step">
                <h3>Database Configuration</h3>
                <p>Please provide your database connection details:</p>

                <form method="POST">
                    <div class="form-group">
                        <label for="db_host">Database Host</label>
                        <input type="text" id="db_host" name="db_host" value="localhost" required>
                    </div>

                    <div class="form-group">
                        <label for="db_name">Database Name</label>
                        <input type="text" id="db_name" name="db_name" value="vibewriter" required>
                    </div>

                    <div class="form-group">
                        <label for="db_user">Database Username</label>
                        <input type="text" id="db_user" name="db_user" value="root" required>
                    </div>

                    <div class="form-group">
                        <label for="db_pass">Database Password</label>
                        <input type="password" id="db_pass" name="db_pass">
                    </div>

                    <button type="submit" class="btn">Install VibeWriter</button>
                </form>
            </div>

            <div class="step">
                <h3>Requirements</h3>
                <p>✓ PHP <?php echo phpversion(); ?></p>
                <p>✓ PDO Extension: <?php echo extension_loaded('pdo_mysql') ? '<span class="success">Enabled</span>' : '<span class="error">Not Found</span>'; ?></p>
                <p>✓ JSON Extension: <?php echo extension_loaded('json') ? '<span class="success">Enabled</span>' : '<span class="error">Not Found</span>'; ?></p>
            </div>

            <div class="step">
                <h3>After Installation</h3>
                <p>Don't forget to:</p>
                <ul style="margin-left: 20px; margin-top: 10px; line-height: 1.8;">
                    <li>Add your AI API key in <code>config/config.php</code></li>
                    <li>Configure image generation API if needed</li>
                    <li>Delete or secure <code>install.php</code> after installation</li>
                </ul>
            </div>
            <?php
        }
        ?>
    </div>
</body>
</html>
