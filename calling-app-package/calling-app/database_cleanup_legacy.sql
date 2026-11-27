-- Optional migration only for databases imported from the original seeded schema.
-- Select the application database in phpMyAdmin before running this statement.
-- Requires DELETE permission on agents.
DELETE FROM `agents`
WHERE `email` IN ('mail.tatapowersolar.shop', 'Agent@tatapowersolar.shop')
  AND `password_hash` = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
