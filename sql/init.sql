-- NAS Web Server Database Schema
-- Runs automatically on first container start

CREATE DATABASE IF NOT EXISTS nas_db;
USE nas_db;

-- --------------------------------------------------------
-- Users table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,         -- bcrypt hash
    email       VARCHAR(100),
    role        ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login  DATETIME
);

-- --------------------------------------------------------
-- Files/folders metadata table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS files (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    owner_id      INT          NOT NULL,
    filename      VARCHAR(255) NOT NULL,
    filepath      VARCHAR(500) NOT NULL,        -- path relative to /var/www/uploads/
    filesize      BIGINT       DEFAULT 0,        -- bytes
    filetype      VARCHAR(100),                  -- MIME type
    is_folder     TINYINT(1)   DEFAULT 0,
    parent_id     INT          DEFAULT NULL,     -- NULL = root
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES files(id) ON DELETE CASCADE
);

-- --------------------------------------------------------
-- Permissions table (who can access what)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    file_id    INT  NOT NULL,
    user_id    INT  NOT NULL,
    can_read   TINYINT(1) DEFAULT 1,
    can_write  TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    FOREIGN KEY (file_id) REFERENCES files(id)  ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE,
    UNIQUE KEY unique_file_user (file_id, user_id)
);

-- --------------------------------------------------------
-- Seed: default admin account
-- Password: admin123  (bcrypt hash — CHANGE THIS in production!)
-- --------------------------------------------------------
INSERT INTO users (username, password, email, role) VALUES
(
    'admin',
    '$2y$10$foB2CPzoTOy3HtNXDYvOl.KDQ6zsdcugeE7sCe17tmQJU4wtWFb5a',
    'admin@localhost',
    'admin'
);
