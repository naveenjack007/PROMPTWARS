<?php
// De-Addiction Web App Global Configuration

// 1. Session start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 2. Database Type Configuration
// Options: 'sqlite' or 'mysql'
define('DB_TYPE', 'sqlite');

// MySQL Configuration (only used if DB_TYPE is 'mysql')
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'deaddiction_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// 3. Hugging Face Inference API Configuration
// Users can provide their API token here or input it via the Settings UI (saved in DB).
define('DEFAULT_HF_TOKEN', ''); // Leave blank to prompt user in UI
define('DEFAULT_HF_MODEL', 'Qwen/Qwen2.5-7B-Instruct'); // High-quality free model

// Helper function to get Hugging Face Token (checks DB settings first, then config, then session)
function get_hf_token($pdo = null) {
    if (defined('DEFAULT_HF_TOKEN') && DEFAULT_HF_TOKEN !== '') {
        return DEFAULT_HF_TOKEN;
    }
    
    // Check session
    if (isset($_SESSION['hf_token']) && $_SESSION['hf_token'] !== '') {
        return $_SESSION['hf_token'];
    }
    
    // Check Database if PDO is provided
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'hf_token' LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch();
            if ($result && !empty($result['setting_value'])) {
                $_SESSION['hf_token'] = $result['setting_value'];
                return $result['setting_value'];
            }
        } catch (PDOException $e) {
            // Table might not exist yet
        }
    }
    
    return null;
}

// Helper function to get the current AI Model
function get_ai_model($pdo = null) {
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'hf_model' LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch();
            if ($result && !empty($result['setting_value'])) {
                return $result['setting_value'];
            }
        } catch (PDOException $e) {
            // Ignore
        }
    }
    return DEFAULT_HF_MODEL;
}

// Helper function to get the active AI Provider
function get_ai_provider($pdo = null) {
    if (isset($_SESSION['ai_provider']) && $_SESSION['ai_provider'] !== '') {
        return $_SESSION['ai_provider'];
    }
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ai_provider' LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch();
            if ($result && !empty($result['setting_value'])) {
                $_SESSION['ai_provider'] = $result['setting_value'];
                return $result['setting_value'];
            }
        } catch (PDOException $e) {}
    }
    return 'huggingface';
}

// Helper function to get Gemini API Key
function get_gemini_api_key($pdo = null) {
    if (isset($_SESSION['gemini_api_key']) && $_SESSION['gemini_api_key'] !== '') {
        return $_SESSION['gemini_api_key'];
    }
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch();
            if ($result && !empty($result['setting_value'])) {
                $_SESSION['gemini_api_key'] = $result['setting_value'];
                return $result['setting_value'];
            }
        } catch (PDOException $e) {}
    }
    return null;
}
?>
