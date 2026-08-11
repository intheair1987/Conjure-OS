<?php
if (!defined('CJOS_PATH_ROOT')) {
    exit('No direct script access allowed');
}

$db_path = __DIR__ . '/../app.db';
$db_exists = file_exists($db_path);

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if (!$db_exists) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                package_name TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER,
                file_path TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
            );
            
            CREATE TABLE IF NOT EXISTS build_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER,
                build_status TEXT,
                log_output TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
            );
        ");
    }

    // Safe, transactional migration check for newly added columns
    try {
        $db->query("SELECT project_type, wrapper_url FROM projects LIMIT 1");
    } catch (Exception $e) {
        $db->exec("ALTER TABLE projects ADD COLUMN project_type TEXT DEFAULT 'standard'");
        $db->exec("ALTER TABLE projects ADD COLUMN wrapper_url TEXT");
    }
} catch (PDOException $e) {
    die("ApkStudio DB Error: " . $e->getMessage());
}
?>