# Soul Coffeemate — Akses Build Produksi (v1.4.5)

> **v1.4.0 — cara masuk berubah.** Sekali seorang pengguna membuat PIN 6 angka di menu
> Pengaturan, **kata sandi tidak lagi bisa dipakai untuk masuk** — hanya PIN itu. Membuat PIN juga
> mengeluarkan semua sesi di semua perangkat, dan aplikasi langsung meminta masuk kembali dengan
> PIN baru supaya penggunanya membuktikan PIN-nya benar selagi masih ingat.
>
> Kalau PIN lupa, tidak ada jalan pintas: tombol **"Lupa PIN?"** di halaman masuk mengirim nomor
> HP + email + kata sandi ke Administrator. Permintaan itu muncul di panel admin pada menu
> **Permintaan Reset PIN** (dengan badge merah berisi jumlah yang menunggu) dan sebagai push
> notification ke HP setiap Administrator. Administrator mengetik sendiri kata sandi barunya;
> menerapkannya sekaligus menghapus PIN lama dan mengeluarkan semua sesi pengguna itu.
>
> Semua akun di tabel bawah saat ini **tanpa PIN**, jadi kelimanya masuk memakai kata sandi
> seperti biasa — sudah diverifikasi langsung ke API live pada 2026-09-06.
>
> Satu batas yang disengaja: aturan ini berlaku untuk **API mobile saja**. Panel `/admin` tetap
> memakai kata sandi, karena panel tidak punya kolom PIN dan mengunciNya akan menutup satu-satunya
> pintu masuk Administrator.

## Sebelumnya (v1.0.3)

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
| Administrator | `0811000001` | `admin123` |
| Finance | `0811000002` | `finance123` |
| Barista | `0811000003` | `barista123` |
| Rider | `0811000004` | `rider123` |
| Staff (Maufu) | `0811000005` | `staff123` |
| Content Creator | `0811000006` | `contentcreator123` |

These are the numbers **actually stored on the live server** — confirmed 2026-09-03 by reading
`users` directly over SSH. They are shorter than `database/seeders/UserSeeder.php`'s own numbers
(`0811000001` vs `081100000001`): production's five original demo accounts were seeded before
that file's current numbering existed, so a fresh local install and this live server do not use
byte-identical demo numbers. Both normalise and log in the same way; this table is what to type
against `soulcoffee.rafancloud.com`, not a description of the seeder.

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

**Berkas:** `dist/soul-coffeemate-v1.4.5.apk`

**Unduh langsung:**
`https://github.com/rizalvalry/soul_coffe.backend/raw/main/dist/soul-coffeemate-v1.4.5.apk`

| Properti | Nilai |
|---|---|
| Ukuran | 24.1 MB (25.314.052 byte) |
| Package | `id.soulcoffeemate.ops.demo` |
| Versi | 1.4.5 (versionCode 19) |
| Min Android | **7.0** (API 24) |
| Target | Android 16 (API 36) |
| Arsitektur | `arm64-v8a`, `armeabi-v7a` |
| SHA-256 | `8ce5b0c9fe14bcc6e79d5fce3ce0dd79774ca7d65c7c8a9527de0c9b60c1758a` |
| Tanda tangan | SHA-256 `fac61745dc0903786fb9ede62a962b399f7348f0bb6f899b8332667591033b9c` — **sama dengan v1.0.x–v1.4.4**, jadi cukup install di atas versi lama, tidak perlu uninstall |

`npm run apk:verify` 13/13 lolos.

**Beda dari v1.4.4:** tombol **"Tandai Semua Dibaca"** di layar Notifikasi (muncul hanya kalau ada
yang belum dibaca). Badge lonceng kosong **seketika** saat tombol ditekan — angkanya diubah di
cache sebelum request ke server selesai, bukan menunggu response, supaya lonceng yang menumpuk
puluhan notifikasi (satu shift sibuk, HP yang lupa di-logout semalaman) tidak memaksa pengguna
tap satu-satu hanya untuk menghilangkan angkanya.

⚠️ Butuh `POST /api/v1/notifications/read-all` di backend, yang **belum live di server** — masih
menunggu SSH seperti perubahan backend lain di bawah. Sebelum server di-deploy, tombol ini tetap
tampil dan badge tetap kosong seketika di HP (optimistic update-nya jalan di sisi aplikasi), tapi
permintaan ke server akan gagal (404) dan status "sudah dibaca" itu **tidak tersimpan** — begitu
layar dimuat ulang, notifikasi yang sama akan tampil belum dibaca lagi. Aman dicoba untuk demo
tampilan, tapi belum bisa diandalkan sampai server ikut di-deploy.

**Beda dari v1.4.3 — `google-services.json` terpasang, FCM aktif di sisi aplikasi.** Firebase
project `soulcoffee-fb389`, app Android `id.soulcoffeemate.ops.demo` — dikonfirmasi langsung di
dalam APK yang sudah dibuild: `google_app_id` dan `project_id` di resource
`processReleaseGoogleServices` cocok persis dengan file yang diberikan
(`1:1067852115776:android:8ed7dd8d7b8c79be8114a5`). File itu sendiri **tidak** ikut ke git —
`app.config.js` sudah lama dirancang untuk memasangnya secara kondisional begitu file-nya ada,
jadi tidak perlu perubahan kode apa pun untuk rilis ini.

Push masih belum benar-benar terkirim: sisi server (`FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH`,
file service-account) menunggu deploy — lihat bagian "FCM Push Notification" di bawah. Selama itu
belum jalan, notifikasi tetap sampai lewat Pusher (dan lonceng di v1.4.3) saat aplikasi terbuka.

**Beda dari v1.4.2 — lonceng notifikasi akhirnya ada isinya:** backend (`GET /notifications`),
mapper, dan query hook-nya sudah lama ada tapi **tidak ada satu layar pun yang memanggilnya** —
komentar di kode sendiri sudah menyebut ini ("Currently inert: no screen calls
useNotifications() yet."). Sekarang ada ikon lonceng di header menu utama (dengan badge angka
belum-dibaca) yang membuka daftar notifikasi lengkap, tap untuk menandai terbaca dan berpindah
ke layar yang relevan.

Sambil memperbaiki itu, ditemukan penyebab kedua kenapa notifikasi terasa tidak real-time: socket
Pusher sebelumnya hanya tersambung selagi salah satu dari empat layar tertentu (Permintaan Refill
barista, Approval Finance, Rider, Permintaan Refill staff) sedang dibuka — masing-masing membuka
koneksinya sendiri. Kejadian yang terjadi saat pengguna berada di menu utama atau layar lain tidak
sampai ke mana pun. Socket sekarang tersambung satu kali untuk seluruh sesi (`RealtimeProvider`,
dipasang di layout utama), sehingga badge dan lonceng ikut hidup di layar mana pun.

Perubahan yang menyertai rilis ini ada di sisi server dan **sudah live tanpa perlu update APK
sebelumnya**: broadcast Pusher dikirim langsung setelah transaksi commit (bukan lewat antrean
yang tidak pernah ada yang menjalankan), dan penugasan staff kini disalin otomatis dari hari
sebelumnya — lihat bagian "Dashboard & Laporan" dan "Penugasan Staff Otomatis" di bawah.

### v1.4.4 (sebelumnya)

| Properti | Nilai |
|---|---|
| Versi | 1.4.4 (versionCode 18) |
| SHA-256 | `711b3513a8ef48ce655e07014573f65667b10f665ce478f5e6ef786cf89057d6` |

**Beda dari v1.4.3:** `google-services.json` terpasang, FCM aktif di sisi aplikasi.

### v1.4.3 (sebelumnya)

| Properti | Nilai |
|---|---|
| Versi | 1.4.3 (versionCode 17) |
| SHA-256 | `fe04a3c19d0f1e16ff08536878868e39c53bb8a8d86127060a85fa3acc37e818` |

**Beda dari v1.4.2:** lonceng notifikasi + `RealtimeProvider` (satu koneksi Pusher untuk seluruh
sesi, bukan hanya empat layar tertentu).

### v1.4.2 (sebelumnya)

| Properti | Nilai |
|---|---|
| Versi | 1.4.2 (versionCode 16) |
| SHA-256 | `4174c2961d3adcb6335b695012fd666f0b28b4bde4dc48c1e46cbc7c3d72c1ce` |

**Beda dari v1.4.1:** satu refetch pada saat socket Pusher baru tersambung.

### v1.4.1 (sebelumnya)

| Properti | Nilai |
|---|---|
| Versi | 1.4.1 (versionCode 15) |
| SHA-256 | `339c257439b8754a2d7fabf28dda453c3568812ecfecff26c9a7a3b3b05895f3` |

**Beda dari v1.4.0:** angka badge di menu (Approval Refill, Permintaan Refill, Siap Diambil,
Status Permintaan) sekarang benar-benar tampil. Sebelumnya `useMenuBadges()` mengembalikan objek
kosong — sengaja, sejak sebelum lapisan realtime ada — sehingga tile-nya selalu terlihat sepi
berapa pun antrean yang menunggu.

### v1.4.0 (sebelumnya)

| Properti | Nilai |
|---|---|
| Versi | 1.4.0 (versionCode 14) |
| SHA-256 | `46f03d22df90ff835ab4d490bbab24c29651b89a10f5ba47de39fe876bd3fd13` |

**Beda dari v1.3.3:** cara masuk (PIN menggantikan kata sandi, lihat catatan di atas), tombol
"Lupa PIN?", dan push notification untuk seluruh alur bisnis. Izin baru yang diminta:
`POST_NOTIFICATIONS` (dialog sistem muncul sekali setelah masuk), plus `VIBRATE` dan
`RECEIVE_BOOT_COMPLETED` yang dipakai FCM.

**Push notification belum aktif sampai dua berkas Firebase dipasang** — keduanya sengaja tidak
ada di repo:

1. `google-services.json` di folder `soul_coffe.mobile`, lalu APK di-build ulang. Ambil dari
   Firebase console untuk aplikasi Android dengan package `id.soulcoffeemate.ops.demo`.
2. Service-account JSON di server (`storage/app/private/fcm-service-account.json`), lalu isi
   `FCM_PROJECT_ID` dan `FCM_CREDENTIALS_PATH` di `.env` dan jalankan
   `php artisan config:clear && php artisan config:cache`.

Tanpa keduanya aplikasi tetap berjalan penuh: notifikasi masih sampai lewat socket Pusher dan
fallback polling 10 detik, hanya tidak muncul di layar terkunci. APK ini **sudah** dibuild tanpa
`google-services.json`, jadi bagian push-nya belum bisa diuji sampai langkah 1 dikerjakan.

### Versi sebelumnya

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

1. Buka repositori ini dari browser HP → folder `dist/` → unduh `soul-coffeemate-v1.4.5.apk`.
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

## Realtime (Pusher) — ✅ AKTIF

Notifikasi lonceng/badge tanpa reload (requirement 3) **sudah jalan di produksi** per 2026-09-06.
Tidak ada langkah manual yang tersisa untuk bagian ini. Tidak ada nilai rahasia di bagian ini —
App Secret/App ID Pusher diberikan langsung ke Anda di luar dokumen ini (bukan di repo, publik),
supaya tidak ikut ter-commit.

Ada **dua** penyebab yang saling menutupi kenapa sebelumnya tidak pernah sampai:

**Penyebab 1 — broadcaster diarahkan ke `log`.** `.env` produksi berisi
`BROADCAST_CONNECTION=log` dan **tidak punya satu pun baris `PUSHER_*`**. Setiap broadcast ditulis
ke `storage/logs` dan tidak pernah dikirim ke klien mana pun. Sudah diperbaiki:
`BROADCAST_CONNECTION=pusher` plus enam baris `PUSHER_*` (app `2191062`, cluster `ap1`), diikuti
`php artisan config:clear && php artisan config:cache`. Diverifikasi langsung: `trigger` ke
`api-ap1.pusher.com` diterima, dan `POST /api/v1/broadcasting/auth` mengembalikan tanda tangan
yang sah untuk `private-user.10` maupun `private-role.STAFF`.

**Penyebab 2 — broadcast-nya diantrekan, dan tidak ada yang menjalankan antrean.**
`EventPublisher` dulu memanggil `PublishOutboxEvent::dispatch()` dan berhenti di situ. Hosting ini
tidak punya `supervisorctl`/`systemd`, dan cron-nya belum terpasang, jadi setiap event menumpuk di
tabel `jobs` selamanya (ditemukan: 30 job, 30 outbox belum terkirim). Yang membuat bug ini bertahan
lama justru fallback-nya bekerja — kelihatannya "realtime lambat", padahal realtime tidak pernah
jalan sama sekali.

Sudah diperbaiki di commit *"Broadcast realtime events inline instead of only via the queue"*:
`EventPublisher::broadcast()` menjalankan `handle()`-nya job itu **langsung** setelah transaksi
commit, dan antrean tinggal jadi jalur ulang kalau panggilan HTTP-nya gagal. Ini pola yang sama
dengan yang sudah dipakai `PushNotifier` untuk FCM, dengan alasan yang sama: notifikasi yang baru
tiba setelah cron berikutnya tidak ada gunanya untuk "kopi Anda siap".

Diverifikasi di server produksi setelah deploy:

```
broadcaster    : pusher
outbox id      : 33
published_at   : 2026-09-06 18:58:07      <- langsung, tanpa worker
publish took   : 273 ms                    <- termasuk HTTP ke Pusher
jobs before/after : 0 / 0                  <- tidak ada yang mengantre
unpublished    : 0
```

`soul_coffe.mobile`'s `app.json` (`pusherKey`/`pusherCluster`) sudah diisi dengan App Key +
Cluster yang sama — App Secret **tidak pernah** masuk ke mobile app, hanya ke `.env` server ini.

### Soal "polling 10 detik"

Polling di aplikasi **tidak berjalan berbarengan** dengan Pusher. Guard-nya ada di
`src/features/realtime/useRealtime.ts`:

```ts
if (state === 'connected' || !session) return;   // timer tidak pernah dibuat
```

Begitu socket Pusher `connected`, timer-nya dibongkar dan biaya polling menjadi nol request.
Polling yang Anda lihat selama ini adalah **gejala** dari Penyebab 2 di atas, bukan desain.
Sejak v1.4.2 ada juga satu refetch pada saat socket baru tersambung — Pusher tidak punya replay,
jadi event yang terjadi saat socket putus tidak akan pernah dikirim ulang; satu refetch menutup
celah itu tanpa perlu timer.

Fallback-nya sendiri sengaja dipertahankan untuk kasus socket memang tidak bisa terbentuk (HP di
captive-portal WiFi, key belum terisi). Alternatifnya adalah layar yang diam-diam berhenti
diperbarui, dan itu lebih buruk.

### Cron hPanel — tetap dianjurkan, tapi bukan lagi syarat realtime

```bash
* * * * * cd /home/u253446757/domains/rafancloud.com/public_html/soulcoffee && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Satu entri ini menjalankan dua hal: `soul:seed-daily-allowances` pukul 00:00 (jatah harian per
gerobak — **ini yang sekarang jadi alasan utama** cron dipasang), dan
`queue:work --stop-when-empty --max-time=55 --tries=3` tiap menit sebagai penyapu event yang gagal
dikirim inline saat Pusher sedang bermasalah.

Cron **tidak bisa dipasang lewat SSH** di hosting ini: shell-nya tidak punya perintah `crontab`
sama sekali (sudah dicoba). Satu-satunya jalur adalah UI hPanel → Advanced → Cron Jobs. Entri cron
lama yang memanggil `queue:work` langsung jangan dipakai — kalau itu yang dipasang, worker jalan
tapi jatah harian per gerobak tidak pernah terisi.

---

## Penugasan Staff Otomatis — ✅ AKTIF (butuh deploy)

"Penugasan Staff" tidak lagi harus diketik ulang setiap hari. `StaffAssignmentCarryForwardService`
berjalan pukul 00:00 lewat entri cron yang sama (`schedule:run`) dan menyalin penugasan kemarin
(staff → gerobak → lokasi) ke hari ini untuk setiap staff yang belum punya baris hari ini.

Yang **tidak** disalin, dengan sengaja:
- Staff atau gerobak yang **sudah** punya penugasan hari ini — keputusan manual Administrator
  tidak pernah ditimpa oleh proses otomatis ini.
- Staff yang `is_active = false` — akun yang dinonaktifkan semalam tidak ikut ditugaskan lagi
  besoknya.
- Gerobak yang bukan `status = active` — gerobak yang sedang maintenance tidak diberi staff.

`assigned_by` pada baris hasil salinan tetap menunjuk ke admin yang **aslinya** membuat penugasan
itu, bukan aktor sistem — keputusan roster yang diulang tetap keputusan orang yang sama.

Di halaman "Penugasan Staff" ada tombol **"Terapkan Penugasan Hari Ini"** untuk menjalankan proses
yang sama secara manual, kapan saja — berguna untuk hari deploy pertama (belum ada "kemarin" yang
sempat berjalan) atau kalau ingin penugasan hari ini langsung terisi tanpa menunggu jam 00:00.

7 test di `tests/Feature/StaffAssignmentCarryForwardTest.php`.

---

## Dashboard & Laporan — ✅ AKTIF (butuh deploy)

Dashboard admin (`/admin`, halaman utama setelah login) sekarang menampilkan:

- **4 kartu ringkasan hari ini** — pendapatan (dengan perbandingan ke kemarin), jumlah refill
  request (dirinci per status), staff yang sudah absen (dari total staff aktif), dan selisih kas.
- **Tren pendapatan** — grafik garis, 14 hari terakhir.
- **Volume refill request** — grafik batang, 14 hari terakhir.
- **Distribusi status refill** — grafik donat, 30 hari terakhir.
- **Tingkat kehadiran staff** — grafik garis (%), 14 hari terakhir.

Semua angka dihitung oleh satu kelas (`DashboardMetricsService`), diuji langsung terhadap database
(`tests/Feature/Reporting/DashboardMetricsServiceTest.php`, 8 test) — bukan lewat widget yang sulit
diuji — supaya angka di layar dan angka yang diuji dijamin hasil query yang sama persis.

**Ekspor Excel** ada di menu baru **"Laporan & Ekspor"**: satu form rentang tanggal (default 30
hari terakhir), tiga tombol unduh — Refill Requests, Pendapatan, Kehadiran — masing-masing file
`.xlsx` sungguhan (bukan CSV berkedok Excel), dibangun dengan `maatwebsite/excel`. Rentang tanggal
terbalik ditolak sebagai galat isian, bukan diam-diam mengunduh file kosong.

Baik dashboard maupun halaman laporan **hanya untuk Administrator** — mengikuti batas akses panel
yang sudah ada (`AdminPanelAccessTest`), bukan aturan baru.

**Dependensi baru:** `maatwebsite/excel` (menarik `phpoffice/phpspreadsheet`). Ini satu-satunya
paket composer baru sejak awal proyek. Karena hosting ini tidak punya akses internet keluar untuk
`composer require` (sudah dicoba, timeout), delta `vendor/` untuk paket ini disiapkan sebagai
bagian dari tarball deploy, bukan diinstal di server.

---

## FCM Push Notification — 🟡 Kedua file sudah ada, tinggal deploy server

Kode-nya sudah lengkap dan sudah ada test-nya (`tests/Feature/PushNotificationTest.php`).
`PushNotifier` dipanggil inline setelah transaksi commit, jadi **tidak** butuh cron juga.

**Sudah diterima dan terpasang (2026-09-07):**
- Service-account JSON (untuk server mengirim push) — di `storage/app/private/fcm-service-account.json`,
  di luar git. Diverifikasi langsung: berhasil menukar JWT-nya jadi OAuth access token asli dari
  Google sebelum dipakai.
- `google-services.json` (untuk aplikasi menerima push) — sudah masuk ke **APK v1.4.4**. Diverifikasi
  langsung di dalam APK yang sudah dibuild: `google_app_id` dan `project_id` di resource yang
  di-merge cocok persis dengan file aslinya.

Project Firebase: `soulcoffee-fb389`. App Android: `1:1067852115776:android:8ed7dd8d7b8c79be8114a5`
(package `id.soulcoffeemate.ops.demo`).

**Satu-satunya yang tersisa — menunggu SSH bisa diakses lagi:**
- Upload `fcm-service-account.json` ke server, path yang sama.
- Tambah dua baris ke `.env` produksi:
  ```
  FCM_PROJECT_ID=soulcoffee-fb389
  FCM_CREDENTIALS_PATH=/home/u253446757/domains/rafancloud.com/public_html/soulcoffee/storage/app/private/fcm-service-account.json
  ```
- `php artisan config:clear && php artisan config:cache`.

Setelah itu, tidak ada langkah manual lain: aplikasi mendaftarkan token-nya sendiri lewat `POST
/api/v1/me/devices` saat login, dan `event_id` yang sama dipakai untuk membuang duplikat antara
socket dan push (E15) sehingga satu kejadian tidak muncul dua kali.

Catatan: sampai FCM aktif, notifikasi hanya sampai saat aplikasi **sedang dibuka** (lewat Pusher).
Notifikasi yang muncul saat aplikasi tertutup/di background memang tugas FCM, bukan Pusher — itu
batas teknis, bukan bug.

---

## Kalau login gagal

| Gejala | Kemungkinan |
|---|---|
| "Tidak dapat menghubungi server" | Cek `https://soulcoffee.rafancloud.com/api/v1/auth/login` masih hidup |
| "Nomor HP atau kata sandi salah" | Password sudah dirotasi (lihat peringatan di atas) — pakai password baru |
| Daftar refill/alokasi kosong | Normal — belum ada aktivitas ditulis lewat akun ini |
| "Anda tidak bertugas di gerobak ini hari ini" | Penugasan staff hanya berlaku untuk tanggal yang di-seed; perlu penugasan baru untuk hari ini |
