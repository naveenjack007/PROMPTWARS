-- De-Addiction Web Application Database Schema
-- Compatible with MySQL (and mapped to SQLite automatically in db.php)

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    age INT NOT NULL,
    gender VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS addictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    addiction_name VARCHAR(100) NOT NULL,
    how_started TEXT NOT NULL,
    when_started DATE NOT NULL,
    duration_months INT NOT NULL,
    severity VARCHAR(20) NOT NULL, -- Low, Medium, High
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    target_date DATE NOT NULL,
    sentiment_score REAL NOT NULL, -- Numeric rating (e.g. from -5 to +5)
    sentiment_label VARCHAR(20) NOT NULL, -- Positive, Neutral, Negative
    raw_sentiment_analysis TEXT NOT NULL, -- Empathetic breakdown of user status
    full_plan_text TEXT NOT NULL, -- The generated markdown de-addiction plan
    coach_status TEXT, -- Dynamic status message from the coach
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS daily_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    log_date DATE NOT NULL,
    craving_level INT NOT NULL, -- 1 to 10
    mood_score INT NOT NULL, -- 1 to 5
    clean_status TINYINT(1) NOT NULL, -- 1 for clean, 0 for relapse
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sender VARCHAR(10) NOT NULL, -- 'user' or 'coach'
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

