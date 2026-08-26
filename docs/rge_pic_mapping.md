# Roster PIC RGE — Bali & Nusa Tenggara

Daftar PIC lapangan per branch (brand 3 ID). Belum dipetakan ke akun apa pun.

Akun yang di-seed (`seed_bn_users.sql`) memakai skema generik
`<branch>.<brand>.<n>` — 2 akun per branch per brand, total 36.
Nama PIC di bawah dipakai saat admin mau mengganti `users.nama`
atau membuat akun personal lewat **Admin → Import Users**.

| Branch | PIC |
|---|---|
| BALI BARAT | DISWANSA & AFANDI |
| BALI TIMUR | MARIA & DEWA |
| FLORES BARAT | INDRA TOBA & YANUARI DODI |
| FLORES TIMUR | BOY / TOMI |
| LOMBOK BARAT | LALU ILHAM SUKRI Z & ZEFRIYAN J D |
| LOMBOK TIMUR | FAHRURROZI / ABROR |
| SUMBA | FARHAN / FEDRO |
| SUMBAWA | TSABIT / HEDI |
| TIMOR | DENY R ABANAT & ARIEL |

## Cara pakai saat data personal siap

Template Excel Import Users (kolom A–G):

| A nama | B username | C password | D role | E brand | F nama_branch | G mc_list |
|---|---|---|---|---|---|---|
| DISWANSA | diswansa | <password> | user | 3ID | BALI BARAT | (kosong = akses se-branch) |

`mc_list` dikosongkan supaya user melihat seluruh micro cluster di branch-nya.
