-- TymOS — struktura bazy MariaDB (bez danych).
--
-- Utworzenie bazy:
--   mysql -e "CREATE DATABASE tymos CHARACTER SET utf8mb4"
--   mysql tymos < schema.sql
--
-- Tabele device<ID> i stat<ID> (po jednej na urzadzenie) NIE sa tu wszystkie — tworzy je sam kod:
--   device<ID> — surowe odczyty, daemons/tymos.py przy pierwszym odczycie z wlaczona statystyka,
--   stat<ID>   — agregaty (avg/min/max), actions/aggregate.php.
-- Kolumny zaleza od pol urzadzenia. Ponizej po jednym przykladzie (gniazdko z pomiarem mocy).

CREATE TABLE `settings` (
  `k` varchar(64) NOT NULL,
  `v` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device` varchar(128) NOT NULL,
  `ieee` varchar(64) DEFAULT NULL,
  `model` varchar(64) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `onvif_port` smallint(5) unsigned DEFAULT NULL,
  `onvif_user` varchar(64) DEFAULT NULL,
  `onvif_pass` varchar(64) DEFAULT NULL,
  `channel_labels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`channel_labels`)),
  `fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`fields`)),
  `enabled_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`enabled_fields`)),
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `available` tinyint(1) DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `last_state` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ieee` (`ieee`),
  KEY `idx_device` (`device`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `helpers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `label` varchar(128) DEFAULT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'text',
  `value` text DEFAULT NULL,
  `min_val` double DEFAULT NULL,
  `max_val` double DEFAULT NULL,
  `step_val` double DEFAULT NULL,
  `unit` varchar(16) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `actions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `trigger_type` enum('event','threshold','cron','sun','state_change','state') NOT NULL,
  `trigger_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`trigger_config`)),
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`conditions`)),
  `actions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`actions`)),
  `min_interval` int(11) DEFAULT NULL,
  `last_triggered` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bufor_ai` (
  `ts` datetime NOT NULL,
  `temp` decimal(4,1) NOT NULL,
  `temp_dol` decimal(5,2) DEFAULT NULL,
  `power1` decimal(6,1) DEFAULT NULL,
  `power2` decimal(6,1) DEFAULT NULL,
  `decision` tinyint(4) DEFAULT NULL,
  `target_temp` tinyint(3) unsigned DEFAULT NULL,
  `pralka` tinyint(4) DEFAULT NULL,
  `zmywarka` tinyint(4) DEFAULT NULL,
  `zmywarka_zawor` tinyint(4) DEFAULT NULL,
  `nagrzewnica` tinyint(4) DEFAULT NULL,
  `podlogowka` tinyint(4) DEFAULT NULL,
  `prysznic` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `debug_payload` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `payload` text NOT NULL,
  `ts` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_device_ts` (`device_id`,`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `energa` (
  `ts` datetime NOT NULL,
  `kwh_pstryk` decimal(10,6) DEFAULT NULL,
  `kwh_energa_operator` decimal(10,6) DEFAULT NULL,
  `full_price_pstryk` decimal(10,6) DEFAULT NULL,
  `cost_full_pstryk` decimal(10,4) DEFAULT NULL,
  `cost_energy_net_pstryk` decimal(10,6) DEFAULT NULL,
  `cost_full_g11` decimal(10,4) DEFAULT NULL,
  `cost_full_g12r` decimal(10,4) DEFAULT NULL,
  `fixing1_tge` decimal(10,6) DEFAULT NULL,
  `full_price_pstryk_est` decimal(10,6) DEFAULT NULL,
  `continuous_tge` decimal(10,6) DEFAULT NULL,
  `kwh_grzalki` double DEFAULT NULL,
  `cost_full_g12r_grz` double DEFAULT NULL,
  PRIMARY KEY (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `energa_vs_pellet` (
  `ts` datetime NOT NULL,
  `kwh` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `cost_electric` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `cost_pellet` decimal(10,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `irrigation_zones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT 'Strefa',
  `dev_channel` varchar(32) DEFAULT NULL COMMENT 'format: dev_id:state_lX',
  `duration_min` int(11) NOT NULL DEFAULT 10,
  `mode` enum('rano','wieczor','oba') NOT NULL DEFAULT 'oba',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `days` varchar(7) NOT NULL DEFAULT '1111111',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `klimat_ai` (
  `ts` datetime NOT NULL DEFAULT current_timestamp(),
  `chlodnica_salon` tinyint(1) DEFAULT NULL,
  `nawiew` tinyint(1) DEFAULT NULL,
  `freecool` tinyint(1) DEFAULT NULL,
  KEY `ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ts` datetime NOT NULL DEFAULT current_timestamp(),
  `level` varchar(8) NOT NULL,
  `source` varchar(32) DEFAULT NULL,
  `message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pellet` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ts` datetime NOT NULL DEFAULT current_timestamp(),
  `bags` int(11) NOT NULL,
  `kg` decimal(10,2) NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tymos_sounds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sound` varchar(32) NOT NULL,
  `ts` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `waste_calendar` (
  `dt` date NOT NULL,
  `type` varchar(20) NOT NULL,
  PRIMARY KEY (`dt`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `weather_daily` (
  `d` date NOT NULL,
  `tmin` decimal(4,1) DEFAULT NULL,
  `tmax` decimal(4,1) DEFAULT NULL,
  `precip_sum` decimal(5,1) DEFAULT NULL,
  `sunrise` time DEFAULT NULL,
  `sunset` time DEFAULT NULL,
  `fetched_at` datetime NOT NULL,
  PRIMARY KEY (`d`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `weather_hourly` (
  `ts` datetime NOT NULL,
  `temp` decimal(4,1) DEFAULT NULL,
  `clouds` tinyint(3) unsigned DEFAULT NULL,
  `wind` decimal(4,1) DEFAULT NULL,
  `gust` decimal(4,1) DEFAULT NULL,
  `wdir` smallint(5) unsigned DEFAULT NULL,
  `precip` decimal(4,1) DEFAULT NULL,
  `snow` decimal(4,1) DEFAULT NULL,
  `pprob` tinyint(3) unsigned DEFAULT NULL,
  `fetched_at` datetime NOT NULL,
  PRIMARY KEY (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- PRZYKLAD: device<ID> — surowe odczyty jednego urzadzenia (tworzy daemons/tymos.py)
CREATE TABLE `device1` (
  `ts` datetime NOT NULL DEFAULT current_timestamp(),
  `energy` double DEFAULT NULL,
  `power` double DEFAULT NULL,
  `linkquality` double DEFAULT NULL,
  KEY `idx_ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- PRZYKLAD: stat<ID> — agregaty jednego urzadzenia (tworzy actions/aggregate.php)
CREATE TABLE `stat1` (
  `ts` datetime NOT NULL,
  `energy_avg` double DEFAULT NULL,
  `energy_min` double DEFAULT NULL,
  `energy_max` double DEFAULT NULL,
  `power_avg` double DEFAULT NULL,
  `power_min` double DEFAULT NULL,
  `power_max` double DEFAULT NULL,
  `state_last` varchar(64) DEFAULT NULL,
  `samples` int(11) DEFAULT 0,
  `power_on_behavior_last` varchar(64) DEFAULT NULL,
  `linkquality_avg` double DEFAULT NULL,
  `linkquality_min` double DEFAULT NULL,
  `linkquality_max` double DEFAULT NULL,
  PRIMARY KEY (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
