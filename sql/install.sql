-- Installation du module KioskGuestReset
-- @author Cyrille Mohr - Digital Food System

CREATE TABLE IF NOT EXISTS `PREFIX_kgr_pin_attempts` (
    `id`           INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip`           VARCHAR(45)      NOT NULL,
    `id_shop`      INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `attempts`     TINYINT(3)       NOT NULL DEFAULT 0,
    `last_attempt` DATETIME         NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ip_shop` (`ip`, `id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
