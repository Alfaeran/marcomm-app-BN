-- seed_bn_master.sql — master data Bali & Nusa Tenggara
-- Jalankan SETELAH marcomm_event_bn_structure.sql
-- Baris bertanda PLACEHOLDER wajib diganti saat master data asli tersedia.

USE `marcomm_event_bn`;
SET NAMES utf8mb4;
START TRANSACTION;

-- ============ 1. BRANCHES (9 branch x 2 brand = 18) ============
INSERT INTO `branches` (`nama_branch`, `brand`) VALUES
  ('BALI BARAT', '3ID'),
  ('BALI BARAT', 'IM3'),
  ('BALI TIMUR', '3ID'),
  ('BALI TIMUR', 'IM3'),
  ('FLORES BARAT', '3ID'),
  ('FLORES BARAT', 'IM3'),
  ('FLORES TIMUR', '3ID'),
  ('FLORES TIMUR', 'IM3'),
  ('LOMBOK BARAT', '3ID'),
  ('LOMBOK BARAT', 'IM3'),
  ('LOMBOK TIMUR', '3ID'),
  ('LOMBOK TIMUR', 'IM3'),
  ('SUMBA', '3ID'),
  ('SUMBA', 'IM3'),
  ('SUMBAWA', '3ID'),
  ('SUMBAWA', 'IM3'),
  ('TIMOR', '3ID'),
  ('TIMOR', 'IM3');

-- ============ 2. MICRO CLUSTERS -- PLACEHOLDER (1 per branch per brand) ============
INSERT INTO `micro_clusters` (`branch_id`, `nama_micro_cluster`, `brand`)
SELECT b.`id`, CONCAT('MC-', b.`nama_branch`), b.`brand` FROM `branches` b;

-- ============ 3. SITES -- PLACEHOLDER (1 per branch per brand) ============
-- Form input event mewajibkan Site; tanpa baris ini RGE tidak bisa submit.
INSERT INTO `sites` (`site_id`, `site_name`, `brand`, `branch_id`, `micro_cluster_id`, `region`)
SELECT CONCAT('BN-', b.`brand`, '-', REPLACE(b.`nama_branch`, ' ', '')),
       CONCAT('SITE ', b.`nama_branch`), b.`brand`, b.`id`, mc.`id`, 'BALI NUSRA'
FROM `branches` b
JOIN `micro_clusters` mc ON mc.`branch_id` = b.`id`;

-- ============ 4. EVENT CATEGORIES ============
INSERT INTO `event_categories` (`nama_kategori`) VALUES
  ('Bazar'),
  ('Car Free Day'),
  ('Community Gathering'),
  ('Door to Door'),
  ('Event Sekolah / Kampus'),
  ('Konser / Hiburan'),
  ('Pasar Tradisional'),
  ('Sales Booth'),
  ('Lainnya');

-- ============ 5. SETTINGS ============
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('app_name', 'MarComm App - Bali & Nusa Tenggara'),
  ('app_logo', ''),
  ('bulk_import_status', 'off'),
  ('bulk_import_start_time', '08:00'),
  ('bulk_import_end_time', '17:00'),
  ('bulk_import_start_day', '1'),
  ('bulk_import_end_day', '5')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

COMMIT;
