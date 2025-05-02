-- Create schema for user management
CREATE SCHEMA IF NOT EXISTS user_management;

-- Core user management tables
CREATE TABLE IF NOT EXISTS user_management.roles (
    id SERIAL PRIMARY KEY,
    role_name VARCHAR(50) UNIQUE NOT NULL
);

CREATE TABLE IF NOT EXISTS user_management.permissions (
    id SERIAL PRIMARY KEY,
    permission_name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS user_management.users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INTEGER,
    FOREIGN KEY (role_id) REFERENCES user_management.roles(id)
);

CREATE TABLE IF NOT EXISTS user_management.role_permissions (
    role_id INTEGER,
    permission_id INTEGER,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES user_management.roles(id),
    FOREIGN KEY (permission_id) REFERENCES user_management.permissions(id)
);

-- Group chat tables
CREATE TABLE IF NOT EXISTS user_management.group_chats (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_by INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES user_management.users(id)
);

CREATE TABLE IF NOT EXISTS user_management.group_members (
    group_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES user_management.group_chats(id),
    FOREIGN KEY (user_id) REFERENCES user_management.users(id)
);

CREATE TABLE IF NOT EXISTS user_management.group_invitations (
    id SERIAL PRIMARY KEY,
    group_id INTEGER NOT NULL,
    inviter_id INTEGER NOT NULL,
    invitee_id INTEGER NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES user_management.group_chats(id),
    FOREIGN KEY (inviter_id) REFERENCES user_management.users(id),
    FOREIGN KEY (invitee_id) REFERENCES user_management.users(id)
);

CREATE TABLE IF NOT EXISTS user_management.group_messages (
    id SERIAL PRIMARY KEY,
    group_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    message TEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES user_management.group_chats(id),
    FOREIGN KEY (user_id) REFERENCES user_management.users(id)
);

CREATE TABLE IF NOT EXISTS user_management.group_message_reads (
    group_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    last_read_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES user_management.group_chats(id),
    FOREIGN KEY (user_id) REFERENCES user_management.users(id)
);

CREATE TABLE IF NOT EXISTS user_management.pinned_messages (
    id SERIAL PRIMARY KEY,
    group_id INTEGER NOT NULL,
    message_id INTEGER NOT NULL,
    pinned_by INTEGER NOT NULL,
    pinned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES user_management.group_chats(id),
    FOREIGN KEY (message_id) REFERENCES user_management.group_messages(id),
    FOREIGN KEY (pinned_by) REFERENCES user_management.users(id),
    UNIQUE(group_id, message_id)
);

INSERT INTO user_management.roles (id, role_name) VALUES 
    (1, 'Admin'), 
    (2, 'User'), 
    (3, 'Guest');

INSERT INTO user_management.permissions (id, permission_name) VALUES 
    (1, 'manage_users'), 
    (2, 'edit_profile'), 
    (3, 'view_dashboard');

INSERT INTO user_management.role_permissions (role_id, permission_id) VALUES 
    (1, 1), (1, 2), (1, 3),
    (2, 2), (2, 3),
    (3, 3);

-- Reset sequences
ALTER SEQUENCE user_management.roles_id_seq RESTART WITH 4;
ALTER SEQUENCE user_management.permissions_id_seq RESTART WITH 4;

ALTER TABLE user_management.group_messages 
ADD COLUMN is_system BOOLEAN DEFAULT FALSE;
ALTER TABLE user_management.group_messages 
ADD COLUMN edited BOOLEAN DEFAULT FALSE;