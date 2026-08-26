-- seed_bn_users.sql — 36 akun RGE (2 per branch per brand) + 1 admin
-- Jalankan SETELAH seed_bn_master.sql
-- Password RGE : Marcomm2026   (force_password_change = 1)
-- Password admin: AdminBN2026  (force_password_change = 1) -- GANTI SEGERA setelah login pertama.

USE `marcomm_event_bn`;
SET NAMES utf8mb4;
START TRANSACTION;

SET @pw_rge   = '$2y$12$/03V2LT7CIsU.TlvBFLz8eAw0SPJrdVPuiPmcZr1318MoKfq2hyJK';
SET @pw_admin = '$2y$12$qZgVS15CJQojN/Ji9QNoNe3DsEx/oa01O/851sITE3QEvLSeQoiCi';

-- ============ ADMIN ============
INSERT INTO `users` (`nama`, `username`, `password`, `role`, `force_password_change`, `brand`, `branch_id`) VALUES
  ('Admin Bali Nusra', 'admin.bn', @pw_admin, 'admin', 1, NULL, NULL);

-- ============ 36 RGE (role='user', scope branch-wide) ============
INSERT INTO `users` (`nama`, `username`, `password`, `role`, `force_password_change`, `brand`, `branch_id`) VALUES
  ('RGE BALI BARAT 3ID 1', 'balibarat.3id.1', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'BALI BARAT' AND `brand` = '3ID')),
  ('RGE BALI BARAT 3ID 2', 'balibarat.3id.2', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'BALI BARAT' AND `brand` = '3ID')),
  ('RGE BALI BARAT IM3 1', 'balibarat.im3.1', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'BALI BARAT' AND `brand` = 'IM3')),
  ('RGE BALI BARAT IM3 2', 'balibarat.im3.2', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'BALI BARAT' AND `brand` = 'IM3')),
  ('RGE BALI TIMUR 3ID 1', 'balitimur.3id.1', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'BALI TIMUR' AND `brand` = '3ID')),
  ('RGE BALI TIMUR 3ID 2', 'balitimur.3id.2', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'BALI TIMUR' AND `brand` = '3ID')),
  ('RGE BALI TIMUR IM3 1', 'balitimur.im3.1', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'BALI TIMUR' AND `brand` = 'IM3')),
  ('RGE BALI TIMUR IM3 2', 'balitimur.im3.2', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'BALI TIMUR' AND `brand` = 'IM3')),
  ('RGE FLORES BARAT 3ID 1', 'floresbarat.3id.1', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'FLORES BARAT' AND `brand` = '3ID')),
  ('RGE FLORES BARAT 3ID 2', 'floresbarat.3id.2', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'FLORES BARAT' AND `brand` = '3ID')),
  ('RGE FLORES BARAT IM3 1', 'floresbarat.im3.1', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'FLORES BARAT' AND `brand` = 'IM3')),
  ('RGE FLORES BARAT IM3 2', 'floresbarat.im3.2', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'FLORES BARAT' AND `brand` = 'IM3')),
  ('RGE FLORES TIMUR 3ID 1', 'florestimur.3id.1', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'FLORES TIMUR' AND `brand` = '3ID')),
  ('RGE FLORES TIMUR 3ID 2', 'florestimur.3id.2', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'FLORES TIMUR' AND `brand` = '3ID')),
  ('RGE FLORES TIMUR IM3 1', 'florestimur.im3.1', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'FLORES TIMUR' AND `brand` = 'IM3')),
  ('RGE FLORES TIMUR IM3 2', 'florestimur.im3.2', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'FLORES TIMUR' AND `brand` = 'IM3')),
  ('RGE LOMBOK BARAT 3ID 1', 'lombokbarat.3id.1', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'LOMBOK BARAT' AND `brand` = '3ID')),
  ('RGE LOMBOK BARAT 3ID 2', 'lombokbarat.3id.2', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'LOMBOK BARAT' AND `brand` = '3ID')),
  ('RGE LOMBOK BARAT IM3 1', 'lombokbarat.im3.1', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'LOMBOK BARAT' AND `brand` = 'IM3')),
  ('RGE LOMBOK BARAT IM3 2', 'lombokbarat.im3.2', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'LOMBOK BARAT' AND `brand` = 'IM3')),
  ('RGE LOMBOK TIMUR 3ID 1', 'lomboktimur.3id.1', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'LOMBOK TIMUR' AND `brand` = '3ID')),
  ('RGE LOMBOK TIMUR 3ID 2', 'lomboktimur.3id.2', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'LOMBOK TIMUR' AND `brand` = '3ID')),
  ('RGE LOMBOK TIMUR IM3 1', 'lomboktimur.im3.1', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'LOMBOK TIMUR' AND `brand` = 'IM3')),
  ('RGE LOMBOK TIMUR IM3 2', 'lomboktimur.im3.2', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'LOMBOK TIMUR' AND `brand` = 'IM3')),
  ('RGE SUMBA 3ID 1', 'sumba.3id.1', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'SUMBA' AND `brand` = '3ID')),
  ('RGE SUMBA 3ID 2', 'sumba.3id.2', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'SUMBA' AND `brand` = '3ID')),
  ('RGE SUMBA IM3 1', 'sumba.im3.1', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'SUMBA' AND `brand` = 'IM3')),
  ('RGE SUMBA IM3 2', 'sumba.im3.2', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'SUMBA' AND `brand` = 'IM3')),
  ('RGE SUMBAWA 3ID 1', 'sumbawa.3id.1', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'SUMBAWA' AND `brand` = '3ID')),
  ('RGE SUMBAWA 3ID 2', 'sumbawa.3id.2', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'SUMBAWA' AND `brand` = '3ID')),
  ('RGE SUMBAWA IM3 1', 'sumbawa.im3.1', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'SUMBAWA' AND `brand` = 'IM3')),
  ('RGE SUMBAWA IM3 2', 'sumbawa.im3.2', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'SUMBAWA' AND `brand` = 'IM3')),
  ('RGE TIMOR 3ID 1', 'timor.3id.1', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'TIMOR' AND `brand` = '3ID')),
  ('RGE TIMOR 3ID 2', 'timor.3id.2', @pw_rge, 'user', 1, '3ID',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'TIMOR' AND `brand` = '3ID')),
  ('RGE TIMOR IM3 1', 'timor.im3.1', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'TIMOR' AND `brand` = 'IM3')),
  ('RGE TIMOR IM3 2', 'timor.im3.2', @pw_rge, 'user', 1, 'IM3',
    (SELECT `id` FROM `branches` WHERE `nama_branch` = 'TIMOR' AND `brand` = 'IM3'));

COMMIT;

-- Verifikasi:
--   SELECT COUNT(*) FROM branches;                    -- 18
--   SELECT COUNT(*) FROM micro_clusters;              -- 18
--   SELECT COUNT(*) FROM sites;                       -- 18
--   SELECT COUNT(*) FROM users WHERE role = 'user';   -- 36
--   SELECT COUNT(*) FROM users WHERE branch_id IS NULL AND role='user'; -- 0
