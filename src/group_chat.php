<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
function getUserRole($user_id, $conn)
{
    $query = "SELECT user_management.roles.role_name FROM user_management.users 
              JOIN user_management.roles ON user_management.users.role_id = user_management.roles.id 
              WHERE user_management.users.id = :user_id";
    try {
        $stmt = query_safe($conn, $query, ['user_id' => $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['role_name'] ?? null;
    } catch (PDOException $e) {
        return null;
    }
}
function hasPermission($role, $permission, $conn)
{
    $query = "SELECT COUNT(*) as count FROM user_management.role_permissions 
              JOIN user_management.roles ON user_management.role_permissions.role_id = user_management.roles.id 
              JOIN user_management.permissions ON user_management.role_permissions.permission_id = user_management.permissions.id 
              WHERE user_management.roles.role_name = :role AND user_management.permissions.permission_name = :permission";
    try {
        $stmt = query_safe($conn, $query, ['role' => $role, 'permission' => $permission]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    } catch (PDOException $e) {
        return false;
    }
}
$role = getUserRole($_SESSION['user_id'], $conn);

$query = "SELECT username FROM user_management.users WHERE id = :user_id";
try {
    $stmt = query_safe($conn, $query, ['user_id' => $_SESSION['user_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $username = $result['username'] ?? 'User';
} catch (PDOException $e) {
    $username = 'User';
}

$check_tables_query = "
    SELECT EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_schema = 'user_management' 
        AND table_name = 'group_chats'
    ) AS table_exists";

try {
    $stmt = query_safe($conn, $check_tables_query, []);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result['table_exists']) {
        $create_tables_query = "
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
        ";

        query_safe($conn, $create_tables_query, []);
    }
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
$success_message = "";
$error_message = "";

if (isset($_POST['create_group'])) {
    $group_name = trim($_POST['group_name']);
    $group_description = trim($_POST['group_description']);

    if (empty($group_name)) {
        $error_message = "Group name is required";
    } else {
        try {
            $insert_query = "INSERT INTO user_management.group_chats (name, description, created_by) 
                             VALUES (:name, :description, :created_by) RETURNING id";
            $stmt = query_safe($conn, $insert_query, [
                'name' => $group_name,
                'description' => $group_description,
                'created_by' => $_SESSION['user_id']
            ]);

            $group_id = $stmt->fetch(PDO::FETCH_ASSOC)['id'];

            $add_member_query = "INSERT INTO user_management.group_members (group_id, user_id) 
                                 VALUES (:group_id, :user_id)";
            query_safe($conn, $add_member_query, [
                'group_id' => $group_id,
                'user_id' => $_SESSION['user_id']
            ]);

            $success_message = "Group chat created successfully!";
        } catch (PDOException $e) {
            $error_message = "Error creating group chat: " . $e->getMessage();
        }
    }
}
if (isset($_POST['send_invitation'])) {
    $invite_username = trim($_POST['invite_username']);
    $group_id = intval($_POST['group_id']);

    if (empty($invite_username)) {
        $error_message = "Username is required";
    } else {
        try {
            $check_user_query = "SELECT id FROM user_management.users WHERE username = :username";
            $stmt = query_safe($conn, $check_user_query, ['username' => $invite_username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error_message = "User not found";
            } else {
                $invitee_id = $user['id'];
                $check_invitation_query = "SELECT id FROM user_management.group_invitations 
                                          WHERE group_id = :group_id AND invitee_id = :invitee_id 
                                          AND status = 'pending'";
                $stmt = query_safe($conn, $check_invitation_query, [
                    'group_id' => $group_id,
                    'invitee_id' => $invitee_id
                ]);

                if ($stmt->rowCount() > 0) {
                    $error_message = "Invitation already sent to this user";
                } else {
                    $check_member_query = "SELECT user_id FROM user_management.group_members 
                                          WHERE group_id = :group_id AND user_id = :user_id";
                    $stmt = query_safe($conn, $check_member_query, [
                        'group_id' => $group_id,
                        'user_id' => $invitee_id
                    ]);

                    if ($stmt->rowCount() > 0) {
                        $error_message = "User is already a member of this group";
                    } else {
                        $send_invitation_query = "INSERT INTO user_management.group_invitations 
                                                (group_id, inviter_id, invitee_id) 
                                                VALUES (:group_id, :inviter_id, :invitee_id)";
                        query_safe($conn, $send_invitation_query, [
                            'group_id' => $group_id,
                            'inviter_id' => $_SESSION['user_id'],
                            'invitee_id' => $invitee_id
                        ]);

                        $success_message = "Invitation sent successfully!";
                    }
                }
            }
        } catch (PDOException $e) {
            $error_message = "Error sending invitation: " . $e->getMessage();
        }
    }
}
if (isset($_GET['accept_invitation'])) {
    $invitation_id = intval($_GET['accept_invitation']);

    try {
        $invitation_query = "SELECT group_id, invitee_id FROM user_management.group_invitations 
                            WHERE id = :id AND invitee_id = :user_id AND status = 'pending'";
        $stmt = query_safe($conn, $invitation_query, [
            'id' => $invitation_id,
            'user_id' => $_SESSION['user_id']
        ]);

        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invitation) {
            $add_member_query = "INSERT INTO user_management.group_members (group_id, user_id) 
                                VALUES (:group_id, :user_id)";
            query_safe($conn, $add_member_query, [
                'group_id' => $invitation['group_id'],
                'user_id' => $_SESSION['user_id']
            ]);

            $update_invitation_query = "UPDATE user_management.group_invitations 
                                       SET status = 'accepted' 
                                       WHERE id = :id";
            query_safe($conn, $update_invitation_query, ['id' => $invitation_id]);

            $success_message = "You have joined the group chat!";
        } else {
            $error_message = "Invalid invitation";
        }
    } catch (PDOException $e) {
        $error_message = "Error accepting invitation: " . $e->getMessage();
    }
}
if (isset($_GET['decline_invitation'])) {
    $invitation_id = intval($_GET['decline_invitation']);

    try {
        $update_invitation_query = "UPDATE user_management.group_invitations 
                                   SET status = 'declined' 
                                   WHERE id = :id AND invitee_id = :user_id AND status = 'pending'";
        $stmt = query_safe($conn, $update_invitation_query, [
            'id' => $invitation_id,
            'user_id' => $_SESSION['user_id']
        ]);

        if ($stmt->rowCount() > 0) {
            $success_message = "Invitation declined";
        } else {
            $error_message = "Invalid invitation";
        }
    } catch (PDOException $e) {
        $error_message = "Error declining invitation: " . $e->getMessage();
    }
}
if (isset($_POST['send_message'])) {
    $message = trim($_POST['message']);
    $group_id = intval($_POST['group_id']);

    if (empty($message)) {
        $error_message = "Message cannot be empty";
    } else {
        try {
            $check_member_query = "SELECT user_id FROM user_management.group_members 
                                  WHERE group_id = :group_id AND user_id = :user_id";
            $stmt = query_safe($conn, $check_member_query, [
                'group_id' => $group_id,
                'user_id' => $_SESSION['user_id']
            ]);

            if ($stmt->rowCount() > 0) {
                $send_message_query = "INSERT INTO user_management.group_messages 
                                      (group_id, user_id, message) 
                                      VALUES (:group_id, :user_id, :message)";
                query_safe($conn, $send_message_query, [
                    'group_id' => $group_id,
                    'user_id' => $_SESSION['user_id'],
                    'message' => $message
                ]);

            } else {
                $error_message = "You are not a member of this group";
            }
        } catch (PDOException $e) {
            $error_message = "Error sending message: " . $e->getMessage();
        }
    }
}

if (isset($_POST['update_group'])) {
    $group_id = intval($_POST['group_id']);
    $group_name = trim($_POST['edit_group_name']);
    $group_description = trim($_POST['edit_group_description']);
    $old_name = '';

    if (empty($group_name)) {
        $error_message = "Group name is required";
    } else {
        try {
            $check_creator_query = "SELECT name FROM user_management.group_chats 
                                   WHERE id = :group_id AND created_by = :user_id";
            $stmt = query_safe($conn, $check_creator_query, [
                'group_id' => $group_id,
                'user_id' => $_SESSION['user_id']
            ]);

            $group = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($group) {
                $old_name = $group['name'];

                $update_query = "UPDATE user_management.group_chats 
                               SET name = :name, description = :description 
                               WHERE id = :group_id AND created_by = :user_id";
                query_safe($conn, $update_query, [
                    'name' => $group_name,
                    'description' => $group_description,
                    'group_id' => $group_id,
                    'user_id' => $_SESSION['user_id']
                ]);

                if ($old_name !== $group_name) {
                    $system_message = "Group name changed from \"" . $old_name . "\" to \"" . $group_name . "\"";
                    $system_msg_query = "INSERT INTO user_management.group_messages 
                                       (group_id, user_id, message, is_system) 
                                       VALUES (:group_id, :user_id, :message, TRUE)";
                    query_safe($conn, $system_msg_query, [
                        'group_id' => $group_id,
                        'user_id' => $_SESSION['user_id'],
                        'message' => $system_message
                    ]);
                }

                $success_message = "Group information updated successfully!";
            } else {
                $error_message = "You don't have permission to update this group";
            }
        } catch (PDOException $e) {
            $error_message = "Error updating group: " . $e->getMessage();
        }
    }
}

if (isset($_POST['remove_member'])) {
    $group_id = intval($_POST['group_id']);
    $remove_user_id = intval($_POST['remove_user_id']);
    $remove_username = $_POST['remove_username'];

    try {
        $check_creator_query = "SELECT id FROM user_management.group_chats 
                               WHERE id = :group_id AND created_by = :user_id";
        $stmt = query_safe($conn, $check_creator_query, [
            'group_id' => $group_id,
            'user_id' => $_SESSION['user_id']
        ]);

        if ($stmt->rowCount() > 0) {
            $remove_query = "DELETE FROM user_management.group_members 
                           WHERE group_id = :group_id AND user_id = :remove_user_id";
            query_safe($conn, $remove_query, [
                'group_id' => $group_id,
                'remove_user_id' => $remove_user_id
            ]);

            $system_message = $remove_username . " was removed from the group";
            $system_msg_query = "INSERT INTO user_management.group_messages 
                               (group_id, user_id, message, is_system) 
                               VALUES (:group_id, :user_id, :message, TRUE)";
            query_safe($conn, $system_msg_query, [
                'group_id' => $group_id,
                'user_id' => $_SESSION['user_id'],
                'message' => $system_message
            ]);

            $success_message = "Member removed successfully!";
        } else {
            $error_message = "You don't have permission to remove members from this group";
        }
    } catch (PDOException $e) {
        $error_message = "Error removing member: " . $e->getMessage();
    }
}

if (isset($_POST['delete_message'])) {
    $message_id = intval($_POST['message_id']);
    try {
        $check_message_query = "SELECT user_id FROM user_management.group_messages 
                               WHERE id = :message_id";
        $stmt = query_safe($conn, $check_message_query, ['message_id' => $message_id]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($message && $message['user_id'] == $_SESSION['user_id']) {
            $delete_query = "DELETE FROM user_management.group_messages 
                            WHERE id = :message_id AND user_id = :user_id";
            query_safe($conn, $delete_query, [
                'message_id' => $message_id,
                'user_id' => $_SESSION['user_id']
            ]);
            $success_message = "Message deleted successfully!";
        }
    } catch (PDOException $e) {
        $error_message = "Error deleting message: " . $e->getMessage();
    }
}

if (isset($_POST['edit_message'])) {
    $message_id = intval($_POST['message_id']);
    $new_message = trim($_POST['edited_message']);

    if (empty($new_message)) {
        $error_message = "Message cannot be empty";
    } else {
        try {
            $check_message_query = "SELECT user_id FROM user_management.group_messages 
                                   WHERE id = :message_id";
            $stmt = query_safe($conn, $check_message_query, ['message_id' => $message_id]);
            $message = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($message && $message['user_id'] == $_SESSION['user_id']) {
                $update_query = "UPDATE user_management.group_messages 
                                SET message = :message, edited = TRUE 
                                WHERE id = :message_id AND user_id = :user_id";
                query_safe($conn, $update_query, [
                    'message' => $new_message,
                    'message_id' => $message_id,
                    'user_id' => $_SESSION['user_id']
                ]);
                $success_message = "Message updated successfully!";
            }
        } catch (PDOException $e) {
            $error_message = "Error updating message: " . $e->getMessage();
        }
    }
}

if (isset($_POST['pin_message'])) {
    $message_id = intval($_POST['message_id']);
    $group_id = intval($_POST['group_id']);

    try {
        $insert_query = "INSERT INTO user_management.pinned_messages (group_id, message_id, pinned_by) 
                        VALUES (:group_id, :message_id, :user_id)";
        query_safe($conn, $insert_query, [
            'group_id' => $group_id,
            'message_id' => $message_id,
            'user_id' => $_SESSION['user_id']
        ]);
        $success_message = "Message pinned successfully!";
    } catch (PDOException $e) {
        $error_message = "Error pinning message: " . $e->getMessage();
    }
}

if (isset($_POST['unpin_message'])) {
    $message_id = intval($_POST['message_id']);
    $group_id = intval($_POST['group_id']);

    try {
        $delete_query = "DELETE FROM user_management.pinned_messages 
                        WHERE group_id = :group_id AND message_id = :message_id";
        query_safe($conn, $delete_query, [
            'group_id' => $group_id,
            'message_id' => $message_id
        ]);
        $success_message = "Message unpinned successfully!";
    } catch (PDOException $e) {
        $error_message = "Error unpinning message: " . $e->getMessage();
    }
}

$user_groups = [];
try {
    $groups_query = "SELECT 
        g.id, 
        g.name,
        (SELECT COUNT(*) FROM user_management.group_messages 
         WHERE group_id = g.id 
         AND sent_at > COALESCE(
             (SELECT last_read_time 
              FROM user_management.group_message_reads 
              WHERE user_id = :user_id AND group_id = g.id), 
             '1970-01-01'
         )) as unread_count,
        (SELECT message 
         FROM user_management.group_messages 
         WHERE group_id = g.id 
         ORDER BY sent_at DESC 
         LIMIT 1) as latest_message,
        (SELECT sent_at 
         FROM user_management.group_messages 
         WHERE group_id = g.id 
         ORDER BY sent_at DESC 
         LIMIT 1) as last_message_time
    FROM user_management.group_chats g 
    JOIN user_management.group_members m ON g.id = m.group_id 
    WHERE m.user_id = :user_id 
    ORDER BY last_message_time DESC NULLS LAST, g.created_at DESC";
    $stmt = query_safe($conn, $groups_query, ['user_id' => $_SESSION['user_id']]);
    $user_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching groups: " . $e->getMessage();
}

$pending_invitations = [];
try {
    $invitations_query = "SELECT i.id, g.name as group_name, u.username as inviter_name, i.created_at 
                         FROM user_management.group_invitations i 
                         JOIN user_management.group_chats g ON i.group_id = g.id 
                         JOIN user_management.users u ON i.inviter_id = u.id 
                         WHERE i.invitee_id = :user_id AND i.status = 'pending' 
                         ORDER BY i.created_at DESC";
    $stmt = query_safe($conn, $invitations_query, ['user_id' => $_SESSION['user_id']]);
    $pending_invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching invitations: " . $e->getMessage();
}

$selected_group = null;
$group_members = [];
$group_messages = [];

if (isset($_GET['group_id'])) {
    $selected_group_id = intval($_GET['group_id']);

    try {
        $group_query = "SELECT g.*, u.username as creator_name 
                       FROM user_management.group_chats g 
                       JOIN user_management.users u ON g.created_by = u.id 
                       WHERE g.id = :group_id";
        $stmt = query_safe($conn, $group_query, ['group_id' => $selected_group_id]);
        $selected_group = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($selected_group) {
            $check_member_query = "SELECT user_id FROM user_management.group_members 
                                  WHERE group_id = :group_id AND user_id = :user_id";
            $stmt = query_safe($conn, $check_member_query, [
                'group_id' => $selected_group_id,
                'user_id' => $_SESSION['user_id']
            ]);

            $is_member = $stmt->rowCount() > 0;

            if ($is_member) {
                $members_query = "SELECT u.id, u.username, m.joined_at 
                                 FROM user_management.group_members m 
                                 JOIN user_management.users u ON m.user_id = u.id 
                                 WHERE m.group_id = :group_id 
                                 ORDER BY m.joined_at";
                $stmt = query_safe($conn, $members_query, ['group_id' => $selected_group_id]);
                $group_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $messages_query = "SELECT m.id, m.message, m.sent_at, m.is_system, m.edited, u.username 
                                  FROM user_management.group_messages m 
                                  JOIN user_management.users u ON m.user_id = u.id 
                                  WHERE m.group_id = :group_id 
                                  ORDER BY m.sent_at";
                $stmt = query_safe($conn, $messages_query, ['group_id' => $selected_group_id]);
                $group_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                try {
                    $update_read_time = "INSERT INTO user_management.group_message_reads 
                                        (group_id, user_id, last_read_time) 
                                        VALUES (:group_id, :user_id, CURRENT_TIMESTAMP)
                                        ON CONFLICT (group_id, user_id) 
                                        DO UPDATE SET last_read_time = CURRENT_TIMESTAMP";
                    query_safe($conn, $update_read_time, [
                        'group_id' => $selected_group['id'],
                        'user_id' => $_SESSION['user_id']
                    ]);
                } catch (PDOException $e) {
                }
            } else {
                $error_message = "You are not a member of this group";
                $selected_group = null;
            }
        }
    } catch (PDOException $e) {
        $error_message = "Error fetching group details: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Chat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 30px;
        }

        .header h1 {
            color: #fff;
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }

        .user-info {
            display: flex;
            align-items: center;
        }

        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
            object-fit: cover;
            border: 2px solid #fff;
        }

        .user-info .details {
            color: #fff;
        }

        .user-info .username {
            font-weight: 500;
            font-size: 16px;
        }

        .user-info .role {
            font-size: 12px;
            opacity: 0.8;
        }

        .nav {
            display: flex;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 5px;
            margin-bottom: 30px;
            justify-content: flex-start;
        }

        .nav a {
            color: #fff;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            transition: background-color 0.3s;
            font-weight: 500;
            margin-right: 10px;
        }

        .nav a:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .nav a.active {
            background-color: rgba(255, 255, 255, 0.25);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.2);
            border: 1px solid #28a745;
            color: #fff;
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.2);
            border: 1px solid #dc3545;
            color: #fff;
        }

        .chat-container {
            display: grid;
            grid-template-columns: 1fr 3fr;
            gap: 20px;
        }

        .sidebar {
            background-color: #fff;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .main-content {
            background-color: #fff;
            border-radius: 15px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            height: 70vh;
        }

        .group-list {
            margin-bottom: 20px;
        }

        .group-list h3 {
            margin-top: 0;
            color: #444;
            font-size: 18px;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .group-item {
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .group-item:hover {
            background-color: #f8f9fa;
        }

        .group-item.active {
            background-color: #e9ecef;
            border-left: 3px solid #667eea;
        }

        .group-item h4 {
            margin: 0 0 5px;
            color: #444;
            font-size: 16px;
        }

        .group-item p {
            margin: 0;
            color: #666;
            font-size: 12px;
        }

        .unread-count {
            display: inline-block;
            background-color: #ff4444;
            color: white;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 12px;
            margin-left: 5px;
        }

        .latest-message {
            color: #666;
            font-size: 13px;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .invitations {
            margin-bottom: 20px;
        }

        .invitations h3 {
            margin-top: 0;
            color: #444;
            font-size: 18px;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .invitation-item {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
        }

        .invitation-item p {
            margin: 0 0 10px;
            color: #444;
            font-size: 14px;
        }

        .invitation-actions {
            display: flex;
            gap: 10px;
        }

        .invitation-actions a {
            padding: 5px 10px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            color: #fff;
        }

        .btn-accept {
            background-color: #28a745;
        }

        .btn-accept:hover {
            background-color: #218838;
        }

        .btn-decline {
            background-color: #dc3545;
        }

        .btn-decline:hover {
            background-color: #c82333;
        }

        .create-group {
            margin-top: 20px;
        }

        .create-group h3 {
            margin-top: 0;
            color: #444;
            font-size: 18px;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #444;
            font-size: 14px;
        }

        .form-control {
            box-sizing: border-box;
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }

        .btn {
            display: inline-block;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: background-color 0.3s;
        }

        .btn-primary {
            background-color: #667eea;
            color: #fff;
        }

        .btn-primary:hover {
            background-color: #5a6fd1;
        }

        .chat-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-header h2 {
            margin: 0;
            color: #444;
            font-size: 20px;
        }

        .chat-header .members {
            font-size: 14px;
            color: #666;
        }

        .invite-button {
            background-color: #667eea;
            color: #fff;
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .chat-messages {
            flex-grow: 1;
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .message {
            display: flex;
            max-width: 80%;
        }

        .message-self {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .message-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 10px;
        }

        .message-avatar span {
            font-size: 14px;
            color: #444;
            font-weight: 500;
        }

        .message-content {
            background-color: #f8f9fa;
            padding: 10px 15px;
            border-radius: 10px;
            position: relative;
        }

        .message-self .message-content {
            background-color: #667eea;
            color: #fff;
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 12px;
        }

        .message-header .username {
            font-weight: 500;
            color: #444;
        }

        .message-self .message-header .username {
            color: #fff;
        }

        .message-header .time {
            color: #666;
        }

        .message-self .message-header .time {
            color: rgba(255, 255, 255, 0.8);
        }

        .message-text {
            font-size: 14px;
            word-break: break-word;
        }

        .chat-input {
            padding: 15px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
        }

        .chat-input input {
            flex-grow: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }

        .chat-input button {
            background-color: #667eea;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 500;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: #fff;
            border-radius: 10px;
            padding: 20px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .modal-header h3 {
            margin: 0;
            color: #444;
            font-size: 18px;
        }

        .close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #666;
        }

        .close:hover {
            color: #444;
        }

        .no-content {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .no-content h3 {
            margin-top: 0;
            color: #444;
            font-size: 18px;
            margin-bottom: 10px;
        }

        .no-content p {
            margin-bottom: 20px;
        }

        .member-list {
            margin-top: 20px;
        }

        .member-list h3 {
            margin-top: 0;
            color: #444;
            font-size: 18px;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .member-item {
            display: flex;
            align-items: center;
            padding: 5px 0;
        }

        .member-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
        }

        .member-avatar span {
            font-size: 14px;
            color: #444;
            font-weight: 500;
        }

        .member-name {
            font-size: 14px;
            color: #444;
        }

        .group-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .group-list-header h3 {
            margin: 0;
            color: #444;
            font-size: 18px;
        }

        .create-group-btn {
            background-color: #667eea;
            color: #fff;
            border: none;
            padding: 6px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
        }

        .create-group-btn:hover {
            background-color: #5a6fd1;
        }

        .menu-dots {
            display: inline-block;
            margin-left: 10px;
            font-size: 20px;
            cursor: pointer;
            vertical-align: middle;
            color: #666;
        }

        .menu-dots:hover {
            color: #444;
        }

        .search-menu {
            display: none;
            position: absolute;
            top: 60px;
            right: 20px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            z-index: 100;
            width: 320px;
            padding: 10px;
        }

        .search-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .search-container input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .search-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .search-controls button {
            background-color: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 14px;
        }

        .search-controls button:hover {
            background-color: #e0e0e0;
        }

        #searchResults {
            font-size: 14px;
            color: #666;
        }

        .highlighted-text {
            background-color: #ffeb3b;
            color: #000;
        }

        .current-result {
            background-color: #ff9800;
            color: #000;
        }

        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            background-color: white;
            min-width: 120px;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            z-index: 1;
            border-radius: 5px;
            right: 0;
        }

        .dropdown-content a {
            color: #444;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            font-size: 14px;
        }

        .dropdown-content a:hover {
            background-color: #f1f1f1;
            border-radius: 5px;
        }

        .show {
            display: block;
        }

        .message-system {
            justify-content: center;
            margin: 10px 0;
            max-width: 100%;
        }

        .system-message-content {
            background-color: rgba(0, 0, 0, 0.05);
            color: #666;
            font-style: italic;
            font-size: 13px;
            text-align: center;
            padding: 8px 15px;
        }

        .system-time {
            font-size: 11px;
            color: #999;
            margin-top: 3px;
            text-align: right;
        }

        .message-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-btn {
            background: none;
            border: none;
            color: #666;
            font-size: 12px;
            cursor: pointer;
            padding: 0;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .message:hover .action-btn {
            opacity: 1;
        }

        .edited-tag {
            font-size: 11px;
            color: #666;
            margin-left: 4px;
        }

        .message-self .action-btn {
            color: rgba(255, 255, 255, 0.8);
        }

        .message-self .edited-tag {
            color: rgba(255, 255, 255, 0.8);
        }

        .message-actions-below {
            display: none;
            margin-top: 5px;
            gap: 8px;
            justify-content: flex-start;
            padding-top: 5px;
        }

        .message-self .message-actions-below {
            justify-content: flex-end;
        }

        .message:hover .message-actions-below {
            display: flex;
        }

        .action-btn {
            background: none;
            border: none;
            color: #666;
            font-size: 14px;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.2s ease;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .message-self .action-btn {
            color: rgba(255, 255, 255, 0.8);
        }

        .action-btn:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .message-self .action-btn:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .action-btn.edit-btn:hover {
            color: #2196F3;
        }

        .action-btn.delete-btn:hover {
            color: #f44336;
        }

        .pinned-messages-container {
            max-height: 400px;
            overflow-y: auto;
            padding: 10px;
        }

        .pinned-message {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
        }

        .pinned-message-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 12px;
        }

        .pin-btn.pinned {
            color: #007bff;
        }

        .fa-thumbtack.pinned {
            transform: rotate(45deg);
        }

        .no-pins {
            text-align: center;
            color: #666;
            padding: 20px;
        }

        .action-btn.pin-btn {
            color: #666;
        }

        .action-btn.pin-btn:hover {
            color: #007bff;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
            }

            .user-info {
                margin-top: 15px;
            }

            .nav {
                justify-content: flex-start;
                overflow-x: auto;
            }

            .nav a {
                margin: 5px 10px 5px 0;
            }

            .chat-container {
                grid-template-columns: 1fr;
            }

            .main-content {
                height: 60vh;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>Group Chat</h1>
            <div class="user-info">
                <img src="default-profile.jpg" alt="Profile Picture">
                <div class="details">
                    <div class="username"><?php echo htmlspecialchars($username); ?></div>
                    <div class="role">
                        <?php echo htmlspecialchars($role); ?>
                        <span
                            class="role-badge role-<?php echo strtolower(htmlspecialchars($role)); ?>"><?php echo htmlspecialchars($role); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="profile_management.php">My Profile</a>
            <?php if ($role === 'Admin'): ?>
                <a href="admin_page.php">Admin Panel</a>
            <?php endif; ?>
            <a href="group_chat.php" class="active">Group Chat</a>
            <a href="group_info.php">Group Info</a>
            <a href="logout.php">Logout</a>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="chat-container">
            <div class="sidebar">
                <div class="group-list">
                    <div class="group-list-header">
                        <h3>My Groups</h3>
                        <button class="create-group-btn" onclick="openCreateGroupModal()">+ New Group</button>
                        <div id="createGroupModal" class="modal">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h3>Create New Group</h3>
                                    <button class="close" onclick="closeCreateGroupModal()">&times;</button>
                                </div>
                                <form method="post" action="group_chat.php">
                                    <div class="form-group">
                                        <label for="group_name">Group Name</label>
                                        <input type="text" id="group_name" name="group_name" class="form-control"
                                            required>
                                    </div>
                                    <div class="form-group">
                                        <label for="group_description">Description</label>
                                        <textarea id="group_description" name="group_description" class="form-control"
                                            rows="3" style="resize: none;"></textarea>
                                    </div>
                                    <button type="submit" name="create_group" class="btn btn-primary">Create
                                        Group</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php if (count($user_groups) > 0): ?>
                        <?php foreach ($user_groups as $group): ?>
                            <div
                                class="group-item <?php echo (isset($_GET['group_id']) && $_GET['group_id'] == $group['id']) ? 'active' : ''; ?>">
                                <a href="group_chat.php?group_id=<?php echo $group['id']; ?>"
                                    style="text-decoration: none; color: inherit;">
                                    <h4>
                                        <?php echo htmlspecialchars($group['name']); ?>
                                        <?php if ($group['unread_count'] > 0): ?>
                                            <span class="unread-count"><?php echo $group['unread_count']; ?></span>
                                        <?php endif; ?>
                                    </h4>
                                    <p class="latest-message">
                                        <?php
                                        if (!empty($group['latest_message'])) {
                                            echo htmlspecialchars(substr($group['latest_message'], 0, 50)) .
                                                (strlen($group['latest_message']) > 50 ? '...' : '');
                                        } else {
                                            echo 'No messages yet';
                                        }
                                        ?>
                                    </p>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>You are not a member of any group chats yet.</p>
                    <?php endif; ?>
                </div>

                <?php if (count($pending_invitations) > 0): ?>
                    <div class="invitations">
                        <h3>Pending Invitations</h3>
                        <?php foreach ($pending_invitations as $invitation): ?>
                            <div class="invitation-item">
                                <p><strong><?php echo htmlspecialchars($invitation['inviter_name']); ?></strong> invited you to
                                    join <strong><?php echo htmlspecialchars($invitation['group_name']); ?></strong></p>
                                <div class="invitation-actions">
                                    <a href="group_chat.php?accept_invitation=<?php echo $invitation['id']; ?>"
                                        class="btn-accept">Accept</a>
                                    <a href="group_chat.php?decline_invitation=<?php echo $invitation['id']; ?>"
                                        class="btn-decline">Decline</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="main-content">
                <?php if ($selected_group): ?>
                    <div class="chat-header">
                        <div>
                            <h2>
                                <?php echo htmlspecialchars($selected_group['name']); ?>
                                <div class="dropdown">
                                    <span class="menu-dots" onclick="toggleDropdown()">&#8942;</span>
                                    <div id="dropdownMenu" class="dropdown-content">
                                        <a href="#"
                                            onclick="toggleSearchMenu(); event.stopPropagation(); return false;">Search</a>
                                        <a href="#"
                                            onclick="openPinnedMessages(); event.stopPropagation(); return false;">Pinned
                                            Messages</a>
                                        <?php if ($selected_group && $selected_group['created_by'] == $_SESSION['user_id']): ?>
                                            <a href="#"
                                                onclick="openEditGroupModal(); event.stopPropagation(); return false;">Edit
                                                Group</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </h2>
                            <div class="members"><?php echo count($group_members); ?> members</div>
                        </div>
                        <button class="invite-button" onclick="openInviteModal()">Invite</button>
                    </div>

                    <div id="searchMenu" class="search-menu" style="display: none;">
                        <div class="search-container">
                            <input type="text" id="messageSearch" placeholder="Search in messages..."
                                onkeyup="searchMessages()">
                            <div class="search-controls">
                                <button onclick="findPrevious()" title="Previous result">&uarr;</button>
                                <span id="searchResults">0/0</span>
                                <button onclick="findNext()" title="Next result">&darr;</button>
                                <button onclick="closeSearchMenu()" title="Close search">✕</button>
                            </div>
                        </div>
                    </div>

                    <div class="chat-messages" id="chat-messages">
                        <?php if (count($group_messages) > 0): ?>
                            <?php foreach ($group_messages as $message): ?>
                                <div class="message <?php echo ($message['username'] === $username) ? 'message-self' : ''; ?>"
                                    id="message-<?php echo $message['id']; ?>">
                                    <div class="message-avatar">
                                        <span><?php echo strtoupper(substr($message['username'], 0, 1)); ?></span>
                                    </div>
                                    <div class="message-content">
                                        <div class="message-header">
                                            <span class="username"><?php echo htmlspecialchars($message['username']); ?></span>
                                            <span class="time">
                                                <?php echo date('M j, g:i a', strtotime($message['sent_at'] . ' UTC') + 6 * 3600); ?>
                                                <?php if ($message['edited']): ?>
                                                    <span class="edited-tag">(edited)</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="message-text"><?php echo nl2br(htmlspecialchars($message['message'])); ?></div>
                                        <?php if ($message['username'] === $username): ?>
                                            <div class="message-actions-below">
                                                <?php if ($message['username'] === $username): ?>
                                                    <button
                                                        onclick="openEditMessage(<?php echo $message['id']; ?>, `<?php echo htmlspecialchars($message['message'], ENT_QUOTES); ?>`)"
                                                        class="action-btn edit-btn" title="Edit message">
                                                        <i class="fas fa-pencil-alt"></i>
                                                    </button>
                                                    <button onclick="deleteMessage(<?php echo $message['id']; ?>)"
                                                        class="action-btn delete-btn" title="Delete message">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php
                                                $is_pinned_query = "SELECT id FROM user_management.pinned_messages 
                        WHERE group_id = :group_id AND message_id = :message_id";
                                                $stmt = query_safe($conn, $is_pinned_query, [
                                                    'group_id' => $selected_group['id'],
                                                    'message_id' => $message['id']
                                                ]);
                                                $is_pinned = $stmt->rowCount() > 0;
                                                ?>
                                                <button
                                                    onclick="togglePin(<?php echo $message['id']; ?>, <?php echo $selected_group['id']; ?>, <?php echo $is_pinned ? 'true' : 'false'; ?>)"
                                                    class="action-btn pin-btn"
                                                    title="<?php echo $is_pinned ? 'Unpin message' : 'Pin message'; ?>">
                                                    <i
                                                        class="fas <?php echo $is_pinned ? 'fa-thumbtack pinned' : 'fa-thumbtack'; ?>"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-content">
                                <h3>No messages yet</h3>
                                <p>Be the first to send a message in this group!</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="group_chat.php?group_id=<?php echo $selected_group['id']; ?>"
                        class="chat-input">
                        <input type="hidden" name="group_id" value="<?php echo $selected_group['id']; ?>">
                        <input type="text" name="message" placeholder="Type a message..." autocomplete="off" required>
                        <button type="submit" name="send_message">Send</button>
                    </form>

                <?php else: ?>
                    <div class="no-content">
                        <h3>No group selected</h3>
                        <p>Select a group from the sidebar or create a new one to start chatting.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="inviteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Invite to Group</h3>
                <button class="close" onclick="closeInviteModal()">&times;</button>
            </div>
            <form method="post"
                action="group_chat.php?group_id=<?php echo isset($_GET['group_id']) ? $_GET['group_id'] : ''; ?>">
                <input type="hidden" name="group_id"
                    value="<?php echo isset($_GET['group_id']) ? $_GET['group_id'] : ''; ?>">
                <div class="form-group">
                    <label for="invite_username">Username</label>
                    <input type="text" id="invite_username" name="invite_username" class="form-control" required>
                </div>
                <button type="submit" name="send_invitation" class="btn btn-primary">Send Invitation</button>
            </form>
        </div>
    </div>
    <div id="editGroupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Group</h3>
                <button class="close" onclick="closeEditGroupModal()">&times;</button>
            </div>
            <form method="post"
                action="group_chat.php?group_id=<?php echo isset($_GET['group_id']) ? $_GET['group_id'] : ''; ?>">
                <input type="hidden" name="group_id"
                    value="<?php echo isset($_GET['group_id']) ? $_GET['group_id'] : ''; ?>">
                <div class="form-group">
                    <label for="edit_group_name">Group Name</label>
                    <input type="text" id="edit_group_name" name="edit_group_name" class="form-control"
                        value="<?php echo isset($selected_group) ? htmlspecialchars($selected_group['name']) : ''; ?>"
                        required>
                </div>
                <div class="form-group">
                    <label for="edit_group_description">Description</label>
                    <textarea id="edit_group_description" name="edit_group_description" class="form-control" rows="3"
                        style="resize: none;"><?php echo isset($selected_group) ? htmlspecialchars($selected_group['description']) : ''; ?></textarea>
                </div>
                <button type="submit" name="update_group" class="btn btn-primary">Update Group</button>
            </form>

            <?php if (isset($selected_group) && $selected_group['created_by'] == $_SESSION['user_id'] && count($group_members) > 1): ?>
                <div class="member-management" style="margin-top: 20px;">
                    <h4>Manage Members</h4>
                    <div class="member-list" style="max-height: 200px; overflow-y: auto;">
                        <?php foreach ($group_members as $member): ?>
                            <?php if ($member['id'] != $_SESSION['user_id']): ?>
                                <div class="member-item"
                                    style="display: flex; justify-content: space-between; align-items: center; padding: 5px 0;">
                                    <div style="display: flex; align-items: center;">
                                        <div class="member-avatar">
                                            <span><?php echo strtoupper(substr($member['username'], 0, 1)); ?></span>
                                        </div>
                                        <span class="member-name"><?php echo htmlspecialchars($member['username']); ?></span>
                                    </div>
                                    <form method="post" action="group_chat.php?group_id=<?php echo $selected_group['id']; ?>"
                                        style="margin: 0;">
                                        <input type="hidden" name="group_id" value="<?php echo $selected_group['id']; ?>">
                                        <input type="hidden" name="remove_user_id" value="<?php echo $member['id']; ?>">
                                        <input type="hidden" name="remove_username"
                                            value="<?php echo htmlspecialchars($member['username']); ?>">
                                        <button type="submit" name="remove_member" class="btn-decline"
                                            style="padding: 3px 8px; font-size: 12px;">Remove</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div id="editMessageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Message</h3>
                <button class="close" onclick="closeEditMessageModal()">&times;</button>
            </div>
            <form method="post" id="editMessageForm">
                <input type="hidden" name="message_id" id="edit_message_id">
                <div class="form-group">
                    <textarea name="edited_message" id="edit_message_text" class="form-control" rows="3"
                        required></textarea>
                </div>
                <button type="submit" name="edit_message" class="btn btn-primary">Save Changes</button>
            </form>
        </div>
    </div>
    <div id="pinnedMessagesModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Pinned Messages</h3>
                <button class="close" onclick="closePinnedMessagesModal()">&times;</button>
            </div>
            <div class="pinned-messages-container">
                <?php
                if ($selected_group) {
                    $pinned_messages_query = "SELECT m.*, u.username, p.pinned_at 
                                        FROM user_management.pinned_messages p
                                        JOIN user_management.group_messages m ON p.message_id = m.id
                                        JOIN user_management.users u ON m.user_id = u.id
                                        WHERE p.group_id = :group_id
                                        ORDER BY p.pinned_at DESC";
                    $stmt = query_safe($conn, $pinned_messages_query, ['group_id' => $selected_group['id']]);
                    $pinned_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (count($pinned_messages) > 0):
                        foreach ($pinned_messages as $pinned):
                            ?>
                            <div class="pinned-message">
                                <div class="pinned-message-content">
                                    <div class="pinned-message-header">
                                        <span class="username"><?php echo htmlspecialchars($pinned['username']); ?></span>
                                        <span class="time"><?php echo date('M j, g:i a', strtotime($pinned['sent_at'])); ?></span>
                                    </div>
                                    <div class="message-text"><?php echo nl2br(htmlspecialchars($pinned['message'])); ?></div>
                                </div>
                            </div>
                            <?php
                        endforeach;
                    else:
                        ?>
                        <p class="no-pins">No pinned messages yet</p>
                        <?php
                    endif;
                }
                ?>
            </div>
        </div>
    </div>

    <script>
        window.onload = function () {
            const chatMessages = document.getElementById('chat-messages');
            if (chatMessages) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        };

        function openInviteModal() {
            document.getElementById('inviteModal').style.display = 'flex';
        }

        function closeInviteModal() {
            document.getElementById('inviteModal').style.display = 'none';
        }

        function openCreateGroupModal() {
            document.getElementById('createGroupModal').style.display = 'flex';
        }

        function closeCreateGroupModal() {
            document.getElementById('createGroupModal').style.display = 'none';
        }

        window.onclick = function (event) {
            const inviteModal = document.getElementById('inviteModal');
            const createGroupModal = document.getElementById('createGroupModal');
            const searchMenu = document.getElementById('searchMenu');
            const menuDots = document.querySelector('.menu-dots');
            const dropdownMenu = document.getElementById('dropdownMenu');

            if (event.target === inviteModal) {
                inviteModal.style.display = 'none';
            }

            if (event.target === createGroupModal) {
                createGroupModal.style.display = 'none';
            }

            if (searchMenu &&
                searchMenu.style.display === 'block' &&
                event.target !== searchMenu &&
                !searchMenu.contains(event.target) &&
                event.target !== menuDots) {
                searchMenu.style.display = 'none';
                clearSearch();
            }

            if (!event.target.matches('.menu-dots') &&
                dropdownMenu &&
                dropdownMenu.classList.contains('show')) {
                dropdownMenu.classList.remove('show');
            }
        };

        let searchResults = [];
        let currentResultIndex = -1;

        function toggleSearchMenu() {
            const searchMenu = document.getElementById('searchMenu');
            const dropdownMenu = document.getElementById('dropdownMenu');

            if (dropdownMenu.classList.contains('show')) {
                dropdownMenu.classList.remove('show');
            }

            searchMenu.style.display = 'block';
            document.getElementById('messageSearch').focus();
        }

        function closeSearchMenu() {
            document.getElementById('searchMenu').style.display = 'none';
            clearSearch();
        }

        function searchMessages() {
            clearSearch();

            const searchText = document.getElementById('messageSearch').value.trim().toLowerCase();
            if (searchText === '') {
                document.getElementById('searchResults').textContent = '0/0';
                return;
            }

            const chatMessages = document.getElementById('chat-messages');
            const messageElements = chatMessages.querySelectorAll('.message-text');

            searchResults = [];

            messageElements.forEach((messageElement, index) => {
                const messageText = messageElement.textContent.toLowerCase();
                const originalHTML = messageElement.innerHTML;

                if (messageText.includes(searchText)) {
                    searchResults.push({
                        element: messageElement,
                        originalHTML: originalHTML
                    });

                    const highlightedHTML = originalHTML.replace(
                        new RegExp(`(${escapeRegExp(searchText)})`, 'gi'),
                        '<span class="highlighted-text">$1</span>'
                    );
                    messageElement.innerHTML = highlightedHTML;
                }
            });

            document.getElementById('searchResults').textContent =
                searchResults.length > 0 ? `1/${searchResults.length}` : '0/0';

            if (searchResults.length > 0) {
                currentResultIndex = 0;
                highlightCurrentResult();
            }
        }

        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        function findNext() {
            if (searchResults.length === 0) return;

            removeCurrentHighlight();

            currentResultIndex = (currentResultIndex + 1) % searchResults.length;

            document.getElementById('searchResults').textContent =
                `${currentResultIndex + 1}/${searchResults.length}`;

            highlightCurrentResult();
        }

        function findPrevious() {
            if (searchResults.length === 0) return;

            removeCurrentHighlight();

            currentResultIndex = (currentResultIndex - 1 + searchResults.length) % searchResults.length;

            document.getElementById('searchResults').textContent =
                `${currentResultIndex + 1}/${searchResults.length}`;

            // Highlight current result
            highlightCurrentResult();
        }

        function removeCurrentHighlight() {
            if (currentResultIndex >= 0 && searchResults.length > 0) {
                const result = searchResults[currentResultIndex];
                const searchText = document.getElementById('messageSearch').value.trim();

                result.element.innerHTML = result.element.innerHTML.replace(
                    /<span class="current-result">(.*?)<\/span>/gi,
                    '<span class="highlighted-text">$1</span>'
                );
            }
        }

        function highlightCurrentResult() {
            if (currentResultIndex >= 0 && searchResults.length > 0) {
                const result = searchResults[currentResultIndex];

                result.element.innerHTML = result.element.innerHTML.replace(
                    /<span class="highlighted-text">(.*?)<\/span>/i,
                    '<span class="current-result">$1</span>'
                );
                result.element.parentElement.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }
        }

        function clearSearch() {
            if (searchResults.length > 0) {
                searchResults.forEach(result => {
                    result.element.innerHTML = result.originalHTML;
                });
            }

            searchResults = [];
            currentResultIndex = -1;
            document.getElementById('searchResults').textContent = '0/0';
        }

        function toggleDropdown() {
            document.getElementById("dropdownMenu").classList.toggle("show");
            event.stopPropagation();
        }

        function openEditGroupModal() {
            document.getElementById('editGroupModal').style.display = 'flex';
            document.getElementById('dropdownMenu').classList.remove('show');
        }

        function closeEditGroupModal() {
            document.getElementById('editGroupModal').style.display = 'none';
        }

        window.onclick = function (event) {
            const inviteModal = document.getElementById('inviteModal');
            const createGroupModal = document.getElementById('createGroupModal');
            const editGroupModal = document.getElementById('editGroupModal');
            const searchMenu = document.getElementById('searchMenu');
            const menuDots = document.querySelector('.menu-dots');
            const dropdownMenu = document.getElementById('dropdownMenu');

            if (event.target === inviteModal) {
                inviteModal.style.display = 'none';
            }

            if (event.target === createGroupModal) {
                createGroupModal.style.display = 'none';
            }

            if (event.target === editGroupModal) {
                editGroupModal.style.display = 'none';
            }
        };

        function openEditMessage(messageId, messageText) {
            document.getElementById('editMessageModal').style.display = 'flex';
            document.getElementById('edit_message_id').value = messageId;
            document.getElementById('edit_message_text').value = messageText;
        }

        function closeEditMessageModal() {
            document.getElementById('editMessageModal').style.display = 'none';
        }

        function deleteMessage(messageId) {
            if (confirm('Are you sure you want to delete this message?')) {
                const form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = `
            <input type="hidden" name="message_id" value="${messageId}">
            <input type="hidden" name="delete_message" value="1">
        `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function openPinnedMessages() {
            document.getElementById('pinnedMessagesModal').style.display = 'flex';
            document.getElementById('dropdownMenu').classList.remove('show');
        }

        function closePinnedMessagesModal() {
            document.getElementById('pinnedMessagesModal').style.display = 'none';
        }

        function togglePin(messageId, groupId, isPinned) {
            const form = document.createElement('form');
            form.method = 'post';
            form.innerHTML = `
        <input type="hidden" name="message_id" value="${messageId}">
        <input type="hidden" name="group_id" value="${groupId}">
        <input type="hidden" name="${isPinned ? 'unpin_message' : 'pin_message'}" value="1">
        `;
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>