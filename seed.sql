-- NekiRot Quetta seed data for current schema
-- Donors, recipients, and riders are stored in the users table.

USE nekirot;

-- DONORS
INSERT INTO users (name, email, password_hash, user_type, phone, latitude, longitude, is_active) VALUES
('Quetta Serena Hotel', 'serena@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor', '0811234567', 30.179800, 66.975000, 1),
('Bolan Marriage Hall', 'bolan@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor', '0812345678', 30.170000, 66.965000, 1),
('Al-Harmain Marriage Hall', 'alharmain@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor', '0813456789', 30.177000, 66.972000, 1),
('Crown Marriage Hall', 'crown@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor', '0814567890', 30.182000, 66.978000, 1),
('Royal Palace Banquet', 'royalpalace@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor', '0815678901', 30.185000, 66.980000, 1),
('Marco Polo Restaurant', 'marcopolo@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor', '0816789012', 30.185000, 66.980000, 1),
('Usmania Restaurant', 'usmania@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor', '0817890123', 30.188000, 66.982000, 1),
('Tabaq Restaurant', 'tabaq@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor', '0818901234', 30.190000, 66.990000, 1),
('Zameer Kabab House', 'zameer@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor', '0819012345', 30.170000, 66.965000, 1),
('Quetta Darbar', 'darbar@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor', '0810123456', 30.182000, 66.978000, 1);

-- RECIPIENTS
INSERT INTO users (name, email, password_hash, user_type, phone, latitude, longitude, is_active) VALUES
('Al-Khidmat Orphanage', 'alkhidmat@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recipient', '0811234567', 30.175000, 66.970000, 1),
('SOS Children Village', 'soschildren@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recipient', '0812345678', 30.165000, 66.960000, 1),
('Bilal Orphanage', 'bilal@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recipient', '0813456789', 30.195000, 66.995000, 1),
('Madina Orphanage', 'madina@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recipient', '0814567890', 30.190000, 66.990000, 1),
('Darul Uloom Quetta', 'darululoom@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recipient', '0815678901', 30.195000, 66.995000, 1),
('Jamia Islamia', 'jamia@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recipient', '0816789012', 30.170000, 66.965000, 1),
('Saylani Welfare Quetta', 'saylani@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recipient', '0817890123', 30.177000, 66.972000, 1),
('Edhi Center Quetta', 'edhi@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recipient', '0818901234', 30.185000, 66.980000, 1);

-- RIDERS
INSERT INTO users (name, email, password_hash, user_type, phone, latitude, longitude, is_active) VALUES
('Ahmed Khan', 'ahmed@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rider', '0811234567', 30.179800, 66.975000, 1),
('Saeed Baloch', 'saeed@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rider', '0812345678', 30.185000, 66.980000, 1),
('Ali Shah', 'ali@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rider', '0813456789', 30.175000, 66.970000, 1),
('Rashid Mengal', 'rashid@nekirot.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rider', '0814567890', 30.190000, 66.990000, 1);
