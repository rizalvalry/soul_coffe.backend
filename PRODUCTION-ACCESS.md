# Soul Coffeemate — Akses Build Produksi (v1.0.3)

Ini **bukan** build demo. `dist/soul-coffeemate-v1.0.3.apk` bicara langsung ke API produksi di
`https://soulcoffee.rafancloud.com/api/v1` — setiap alokasi, refill request, foto, dan tanda
tangan yang dibuat lewat APK ini **tersimpan sungguhan** di database live. Kalau butuh alur yang
aman diulang-ulang tanpa konsekuensi, pakai `dist/soul-coffeemate-DEMO-v1.0.1.apk` (lihat
`DEMO-ACCESS.md`) — itu berjalan offline dengan data fiktif.

---

## ⚠️ Kredensial di bawah ini publik

Keenam akun di tabel berikut dibuat oleh `database/seeders/UserSeeder.php`, yang ada di
repositori ini — dan repositori ini **publik**. Saya sudah membuktikan langsung: kredensial
Administrator di bawah berhasil login ke API produksi live pada 2026-09-02, lalu saya logout
token itu segera setelah verifikasi. Siapa pun yang menemukan repo ini punya akses yang sama.

Ini bukan potensi risiko — ini eksposur aktif, sejak API-nya hidup. Rotasi password keenam akun
ini (`php artisan tinker` atau lewat query langsung) adalah langkah yang disarankan sebelum akun
ini dipakai untuk operasional sungguhan, atau jadikan repo ini private.

## Akun login

Nomor HP disimpan dalam format lokal (`08...`), bukan E.164 — lihat `app/Support/PhoneNumber.php`.
Login masih menerima ketikan `08...`, `62...`, atau `+62...`; ketiganya dinormalisasi ke bentuk
yang sama sebelum dicocokkan.

| Role | Nomor HP | Password |
|---|---|---|
| Administrator | `081100000001` | `admin123` |
| Finance | `081100000002` | `finance123` |
| Barista | `081100000003` | `barista123` |
| Rider | `081100000004` | `rider123` |
| Staff (Maufu) | `081100000005` | `staff123` |
| Content Creator | `081100000006` | `contentcreator123` |

**PIN staff:** `123456`.

**Staff Maufu bertugas di gerobak `0018`, lokasi Sudirman** — dikonfirmasi langsung dari
`GET /me` pada akun ini, bukan asumsi dari data seed.

**Content Creator tidak punya akses ke API mobile sama sekali** — satu-satunya pintu masuknya
adalah panel Filament (`/admin`), untuk menulis dan mengedit News Feed. Ia sengaja tidak ambil
bagian dalam alur refill/alokasi operasional (lihat `Role::isOperational()`), jadi tidak tercakup
oleh verifikasi API di bawah ini.

Kelima akun operasional ini dikonfirmasi bisa login ke API produksi pada 2026-09-02
(`POST /auth/login` mengembalikan 200 dan role yang benar untuk semuanya; setiap token probe
langsung di-revoke lewat `POST /auth/logout` setelah diperiksa). Tidak ada akun lain di luar
keenam ini — belum ada API untuk membuat user baru (lihat bagian "Menambah akun" di bawah).

---

## APK

**Berkas:** `dist/soul-coffeemate-v1.0.3.apk`

| Properti | Nilai |
|---|---|
| Ukuran | 22.7 MB |
| Package | `id.soulcoffeemate.ops.demo` |
| Versi | 1.0.3 (versionCode 4) |
| Min Android | **7.0** (API 24) |
| Target | Android 16 (API 36) |
| Arsitektur | `arm64-v8a`, `armeabi-v7a` |
| SHA-256 | `b2f7240f331a86c015071c31a8a8456285fd6e3970a16ee308fa901b014ed222` |
| Tanda tangan | Sama dengan v1.0.0/v1.0.1/v1.0.2 — kalau sudah pasang salah satu, tinggal install ini, tidak perlu uninstall dulu |

**Beda dari v1.0.2:** hanya lapisan realtime — Reverb (tidak bisa jalan di hosting ini, lihat
bagian "Realtime (Pusher)" di bawah) diganti Pusher Channels, dan layar Approval Finance kini
ikut auto-refresh. Tidak ada perubahan pada login, refill, alokasi, atau stok — semua verifikasi
API live dari v1.0.2 di bawah ini tetap berlaku apa adanya untuk v1.0.3.

Package masih diberi akhiran `.demo` (peninggalan penamaan awal) meski build ini sudah bicara ke
data nyata — akan diganti sebelum rilis Play Store yang sesungguhnya.

### Yang sudah diverifikasi, dan yang belum

Sudah dikonfirmasi langsung terhadap API live sebelum APK ini dibuild:

- `POST /auth/login` mengembalikan bentuk `{ data: { token, user } }` yang persis dibaca kode
  mobile — bug sebelumnya (`auth/api.ts` tidak pernah unwrap `data`) sudah diperbaiki dan
  terbukti benar terhadap server sungguhan, bukan cuma lolos pengecekan tipe.
- `GET /products`, `GET /refills`, `GET /badges`, `GET /me`, `GET /me/allocation/today` semuanya
  mengembalikan bentuk JSON yang sudah dipetakan `src/lib/mappers.ts` di `soul_coffe.mobile`.
- Kelima akun seed berhasil login dan mendapat `role` yang benar.
- Sertifikat APK sama dengan build sebelumnya (upgrade in-place), R8/shrinkResources tidak
  menghapus apa pun yang dibutuhkan runtime (`npm run apk:verify` — 10/10 lolos).

**Yang belum:** APK ini **belum pernah dijalankan** di HP atau emulator fisik — mesin build tidak
punya perangkat, dan satu-satunya image emulator yang terpasang adalah x86_64, arsitektur yang
sengaja dibuang dari APK ini. Semua verifikasi di atas dilakukan lewat `curl` langsung ke API dan
lewat pembongkaran isi APK, bukan dengan menjalankan aplikasinya. Yang paling perlu ditekan saat
smoke test pertama, karena menyentuh modul native yang tidak tersentuh oleh verifikasi API:
login, foto refill (kamera), tanda tangan serah terima (WebView), lokasi rider (GPS).

Dua keterbatasan yang sudah diketahui dan didokumentasikan di `soul_coffe.mobile` README ("Real
backend"): `RefillRequest.location_name` dan `Allocation.barista_name` akan selalu tampil
"Tidak diketahui" — backend tidak pernah mengembalikan kedua field ini sama sekali, ini bukan
bug di APK.

### Izin yang diminta

Sama seperti build demo sebelumnya — lihat `DEMO-ACCESS.md` bagian "Izin yang diminta".

### Cara memasang

1. Buka repositori ini dari browser HP → folder `dist/` → unduh `soul-coffeemate-v1.0.3.apk`.
2. Izinkan **Install unknown apps** untuk browser yang dipakai.
3. Buka berkas yang terunduh → **Install**.
4. Play Protect akan memperingatkan karena APK ini tidak ditandatangani sertifikat Play Store —
   pilih **Install anyway**.

---

## Menambah akun

Ada panel admin di `https://soulcoffee.rafancloud.com/admin` — login dengan nomor HP + password
Administrator di tabel atas. `Users` di menu panel punya Create/Edit lengkap; hanya akun dengan
`role: ADMINISTRATOR` dan `is_active: true` yang bisa masuk panel ini
(`User::canAccessPanel()`), jadi memberi role Administrator ke akun baru lewat panel ini otomatis
memberi mereka akses membuat akun lain juga.

Panel ini pintu masuk kedua ke data yang sama dijaga API — belum pernah dites langsung
(sama seperti APK, lihat catatan verifikasi di atas), jadi treat sebagai belum diverifikasi
sampai ada yang login dan mencobanya.

---

## Realtime (Pusher) — masih perlu dua langkah manual di server

Notifikasi tanpa reload (requirement 3) butuh **dua** hal berjalan sekaligus di server; sejauh
ini belum satu pun. Tidak ada nilai rahasia di bagian ini — nilai App Secret/App ID Pusher yang
sesungguhnya diberikan langsung ke Anda di luar dokumen ini (bukan di repo, publik), supaya tidak
ikut ter-commit.

**1. Isi `.env` di server dengan kredensial Pusher.** Empat baris `PUSHER_*` di `.env.example`
sudah menjelaskan formatnya — salin ke `.env` server dan isi dari dashboard Pusher Channels
("App Keys" di app yang sudah dibuat, cluster `ap1`), lalu:
```bash
php artisan config:clear && php artisan config:cache
```
`soul_coffe.mobile`'s `app.json` (`pusherKey`/`pusherCluster`) sudah diisi dengan App Key +
Cluster yang sama — App Secret **tidak pernah** masuk ke mobile app, hanya ke `.env` server ini.

**2. Buat worker antrean berjalan.** `QUEUE_CONNECTION=database` — job yang benar-benar memanggil
broadcaster (`PublishOutboxEvent`) masuk ke tabel `jobs` dan menunggu di sana selamanya kalau
tidak ada yang memprosesnya. Shared hosting ini tidak punya `supervisorctl`/`systemd` untuk
proses persisten, jadi jalannya lewat **cron job** di hPanel (Advanced → Cron Jobs), tiap menit:
```bash
* * * * * cd /home/u253446757/domains/rafancloud.com/public_html/soulcoffee && /usr/bin/php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```
Path di atas sudah diverifikasi lewat SSH — subdomain `soulcoffee.rafancloud.com` document
root-nya menunjuk ke folder project di dalam `public_html` domain utama, bukan ke folder
`domains/soulcoffee.rafancloud.com/` tersendiri, jadi bentuk path yang tertulis di draft
sebelumnya tidak akan pernah cocok. `--stop-when-empty` membuat proses keluar begitu antrean
kosong, `--max-time=55` jadi jaring pengaman supaya tidak tumpang tindih dengan pemanggilan cron
berikutnya di menit yang sama.

Cron **tidak bisa dipasang lewat SSH** di hosting ini: shell-nya tidak punya perintah `crontab`
sama sekali (sudah dicoba). Satu-satunya jalur adalah UI hPanel → Advanced → Cron Jobs.

**Cara memastikan keduanya benar-benar jalan:** kirim satu refill request lewat APK, lalu
`SELECT * FROM jobs` harus kosong dalam &lt;1 menit (bukan menumpuk), dan HP lain yang sedang
login harus menerima notifikasi tanpa perlu menekan refresh. Sebelum kedua langkah ini selesai,
aplikasi tetap berfungsi penuh lewat fallback polling 10 detik — bukan blocker, tapi requirement
3 belum genap terpenuhi tanpa ini.

---

## Kalau login gagal

| Gejala | Kemungkinan |
|---|---|
| "Tidak dapat menghubungi server" | Cek `https://soulcoffee.rafancloud.com/api/v1/auth/login` masih hidup |
| "Nomor HP atau kata sandi salah" | Password sudah dirotasi (lihat peringatan di atas) — pakai password baru |
| Daftar refill/alokasi kosong | Normal — belum ada aktivitas ditulis lewat akun ini |
| "Anda tidak bertugas di gerobak ini hari ini" | Penugasan staff hanya berlaku untuk tanggal yang di-seed; perlu penugasan baru untuk hari ini |
