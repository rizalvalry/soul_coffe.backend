# Soul Coffeemate — Akses Build Produksi (v1.5.0)

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
> **Diperiksa ulang langsung di server, 2026-09-10:** lima dari enam akun masih **tanpa PIN** dan
> masuk memakai kata sandi seperti biasa. Satu tidak: **Staff (Mufit) sudah punya PIN**, jadi
> `POST /auth/login` untuk akun itu menjawab `409 PIN_REQUIRED` dan kata sandinya tidak lagi bisa
> dipakai. PIN-nya bukan lagi `123456` — dicoba dan ditolak. Untuk menguji layar staff, pakai PIN
> yang dipegang pemilik akun, atau tombol **"Lupa PIN?"** lalu reset dari menu Permintaan Reset PIN.
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

**PIN staff:** ~~`123456`~~ — **tidak berlaku lagi.** Akun Staff (Mufit) memang punya PIN di
server (diperiksa 2026-09-10), tetapi bukan angka ini; login dengan `123456` ditolak. Lihat catatan
di bagian atas dokumen ini.

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

**Berkas:** `dist/soul-coffeemate-v1.5.0.apk`

**Unduh langsung:**
`https://github.com/rizalvalry/soul_coffe.backend/raw/main/dist/soul-coffeemate-v1.5.0.apk`

| Properti | Nilai |
|---|---|
| Ukuran | 24.15 MB (25.319.760 byte) |
| Package | `id.soulcoffeemate.ops.demo` |
| Versi | 1.5.0 (versionCode 20) |
| Min Android | **7.0** (API 24) |
| Target | Android 16 (API 36) |
| Arsitektur | `arm64-v8a`, `armeabi-v7a` |
| SHA-256 | `7d67fc5888bc4a78313676820b07f0acb2fc35429a851af74e2c41f46a56cb8d` |
| Tanda tangan | SHA-256 `fac61745dc0903786fb9ede62a962b399f7348f0bb6f899b8332667591033b9c` — **sama dengan v1.0.x–v1.4.5**, jadi cukup install di atas versi lama, tidak perlu uninstall |

`npm run apk:verify` 13/13 lolos.

**Beda dari v1.4.5 — empat perubahan alur:**

1. **Catat Penjualan** (staff) — tile pertama di menu staff. Cups terjual, cara bayar, simpan;
   stok gerobak berkurang otomatis. Wajib absen dulu. Lihat bagian "Penjualan Gerobak" di bawah.
2. **Lokasi staff dilaporkan** ke CMS selagi aplikasi terbuka (hanya role STAFF), plus titik
   presisi di setiap transaksi. Lihat "Aktivitas Staff" untuk batasannya yang sebenarnya.
3. **Pengiriman: foto wajib, tanda tangan opsional.** Tiga bentuk sah — foto saja, foto + paraf,
   foto + PIN. Placeholder PNG 1×1 untuk jalur PIN sudah tidak dipakai lagi.
4. **Laporkan Insiden** (rider) — foto kerusakan + jumlah rusak per produk. Keputusan lanjut atau
   batal diambil Finance/Administrator di CMS, bukan oleh rider.

Dan dua layar **dihapus**: Alokasi Harian (barista) dan Alokasi Hari Ini (staff) — lihat bagian
"Alokasi Harian dihapus dari alur".

**Beda v1.4.5 dari v1.4.4:** tombol **"Tandai Semua Dibaca"** di layar Notifikasi (muncul hanya kalau ada
yang belum dibaca). Badge lonceng kosong **seketika** saat tombol ditekan — angkanya diubah di
cache sebelum request ke server selesai, bukan menunggu response, supaya lonceng yang menumpuk
puluhan notifikasi (satu shift sibuk, HP yang lupa di-logout semalaman) tidak memaksa pengguna
tap satu-satu hanya untuk menghilangkan angkanya.

`POST /api/v1/notifications/read-all` sudah live di server (2026-09-07) — dicoba langsung lewat
HTTPS sungguhan ke `soulcoffee.rafancloud.com`, mengembalikan 204 dan `read_at` benar-benar
berubah di database. Status "sudah dibaca" tersimpan sepenuhnya, bukan cuma tampilan di HP.

**Beda dari v1.4.3 — `google-services.json` terpasang, FCM aktif di sisi aplikasi.** Firebase
project `soulcoffee-fb389`, app Android `id.soulcoffeemate.ops.demo` — dikonfirmasi langsung di
dalam APK yang sudah dibuild: `google_app_id` dan `project_id` di resource
`processReleaseGoogleServices` cocok persis dengan file yang diberikan
(`1:1067852115776:android:8ed7dd8d7b8c79be8114a5`). File itu sendiri **tidak** ikut ke git —
`app.config.js` sudah lama dirancang untuk memasangnya secara kondisional begitu file-nya ada,
jadi tidak perlu perubahan kode apa pun untuk rilis ini.

Push sudah benar-benar terkirim — sisi server (`FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH`, file
service-account) sudah di-deploy dan dibuktikan langsung ke perangkat Rider dan Staff (Mufit) yang
sungguhan terdaftar; lihat bagian "FCM Push Notification" di bawah untuk buktinya.

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

1. Buka repositori ini dari browser HP → folder `dist/` → unduh `soul-coffeemate-v1.5.0.apk`.
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

## Penugasan Staff Otomatis — ✅ AKTIF

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

7 test di `tests/Feature/StaffAssignmentCarryForwardTest.php`. Sudah di-deploy dan dikonfirmasi
terdaftar di scheduler produksi lewat `php artisan schedule:list` (2026-09-07).

---

## Dashboard & Laporan — ✅ AKTIF

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

Sudah di-deploy (2026-09-07) dan dikonfirmasi langsung: `https://soulcoffee.rafancloud.com/admin`
dan `/admin/reports` merespons tanpa error (redirect ke login untuk tamu, seperti seharusnya).

**Dependensi baru:** `maatwebsite/excel` (menarik `phpoffice/phpspreadsheet`). Ini satu-satunya
paket composer baru sejak awal proyek.

⚠️ **Koreksi (2026-09-08).** Catatan sebelumnya di sini mengklaim hosting ini tidak punya akses
internet keluar sehingga `vendor/` harus dikirim manual lewat tarball. **Klaim itu salah** — yang
timeout waktu itu adalah SSH-nya, bukan koneksi server ke packagist. Sudah diuji dari server:
`repo.packagist.org` balas 200, dan `composer 2.9.8` tersedia.

Yang lebih penting: **cara manual itu diam-diam rusak.** Menyalin folder `vendor/maatwebsite`
lewat tarball tidak memperbarui `vendor/composer/installed.json` — dan itulah sumber data yang
dipakai `composer dump-autoload`. Akibatnya file paketnya ada di disk, tapi autoloader tidak
mengenalnya: `class_exists('Maatwebsite\Excel\Facades\Excel')` mengembalikan **false**, sehingga
setiap tombol ekspor Excel (Laporan & Ekspor sejak 2026-09-07 dan Laporan Absensi) akan gagal
begitu diklik, walau halamannya tampil normal. Cacat ini baru tertangkap oleh smoke test
2026-09-08 dan sudah diperbaiki dengan `composer install --no-dev --optimize-autoloader` di
server, lalu dibuktikan: kedua kelas resolve, dan ekspor absensi (7.008 byte) serta ekspor
pendapatan (6.592 byte) menghasilkan `.xlsx` valid.

**Aturan deploy sekarang:** kalau sebuah rilis mengubah `composer.json`/`composer.lock`, jalankan
`composer install --no-dev --optimize-autoloader` di server setelah upload — jangan pernah
menempelkan folder `vendor/` per paket.

---

## Absensi — ✅ AKTIF (satu menu, 2026-09-09)

Reproduksi "Laporan Absensi" dari `docs/screenshots/bisnisproses/excel-absensi.jpeg`, tapi bisa
diisi langsung di panel: **Absensi → Laporan Absensi** (`/admin/attendance-sheet`).

⚠️ **Koreksi besar dari versi 2026-09-08.** Versi itu punya dua cacat rancangan yang ditemukan
saat review, dan keduanya sudah dibongkar:

1. **Ada menu "Data Partner" berisi roster kedua.** Barisnya menggambarkan orang yang sudah ada
   di menu Pengguna, jadi Dimas dan Mufit harus diketik dua kali sebelum laporannya memuat
   mereka — produksi waktu itu berisi 6 pengguna dan 0 partner, bentuk tabel yang tidak mungkin
   dipakai. Menu itu **dihapus total**, dan kolom profil kepegawaian (**NIK, SIZE, Jatah
   Klibur**) sekarang jadi kolom di **Master Data → Pengguna & Role**, karena itu memang sifat
   orang yang sudah diwakili baris tersebut.
2. **Laporannya mengabaikan absen dari aplikasi.** Tabel `attendances` sudah memuat absen
   sungguhan sejak alur absen mobile jalan, tapi grid itu hanya membaca tabel isian manualnya
   sendiri — jadi staff yang sudah absen dari HP tetap tampil sel kosong, dan kantor mengetik
   ulang fakta yang sistemnya sudah punya.

Kata **"partner" juga dibebaskan** — bisnis ini membutuhkannya untuk arti yang sebenarnya (mitra,
investor, franchise, perusahaan luar yang bergabung), bukan untuk "karyawan yang punya NIK".
Seluruh kelas dan tabelnya kini bernama `Attendance*` / `attendance_marks`.

### Dari mana angka tiap sel berasal

| Sumber | Kapan dipakai | Tanda di layar |
|---|---|---|
| **Absen aplikasi** (`attendances`) | Barista & Staff yang menekan absen sendiri — jam server, R16 | titik tinta kecil di sudut sel; hover memperlihatkan jamnya |
| **Isian manual** (`attendance_marks`) | L/S/T untuk siapa pun, dan **semua** sel role yang tak bisa absen di app | tanpa titik |

Isian manual **menimpa** absen aplikasi, karena libur/sakit/berangkat-siang adalah penilaian yang
tidak bisa dilaporkan sendiri oleh siapa pun. Sebaliknya, **mengosongkan isian manual tidak
menghapus absennya** — selnya kembali menampilkan M dari aplikasi. Fakta absennya bisa dianotasi,
tidak bisa dihapus dari sini.

Catatan penting: **RIDER tidak bisa absen dari aplikasi sama sekali** (`CLOCKING_ROLES` hanya
Barista dan Staff), dan sheet acuan Anda justru sheet RIDER — itulah alasan lembar manual ini
tetap perlu ada.

Kode selnya sama seperti sheet: **M** hadir, **T** berangkat siang/tidak target, **L** libur,
**S** sakit, kosong = belum diisi. Sel kosong disimpan sebagai *tidak ada baris*, bukan kode
kelima, supaya "belum diisi" tak pernah tertukar dengan "sudah diputuskan".

### Kolom rekap

Rumusnya **tidak dikarang** — dibaca ulang dari angka yang tercetak di sheet itu sendiri, lalu
diuji terhadap 6 baris aslinya (`AttendanceSheetServiceTest`):

| Kolom | Rumus | Bukti dari sheet |
|---|---|---|
| Lebih dari Jatah / Tidak Masuk | `Libur − Jatah Klibur` | AGUNG 5−4=1 · Endi 18−4=14 · RANGGA 30−4=26 · ADIT 2−4=**−2** (negatif memang muncul di sheet) |
| Presentase Kehadiran | `Hadir ÷ 26 × 100%` | AGUNG 23/26=88% · Endi 13/26=50% · AZIS 30/26=**115%** (di atas 100% sengaja tidak dipotong) |

Pembagi 26 itu konvensi payroll, disimpan di `config/soul.php` (`attendance_working_days`), bukan
ditanam di kode. **Jatah klibur kini per orang** (kolom di menu Pengguna), jadi dua orang di satu
lembar boleh berbeda.

Tombol **Ekspor Excel** menghasilkan `.xlsx` sungguhan dengan bentuk kolom sama seperti sheet
aslinya. Kode yang berasal dari absen aplikasi ditulis **huruf kecil** (`m`), yang manual huruf
besar (`M`) — jadi asal-usul angkanya ikut terbawa ke file, tidak diratakan hilang. Presentase
ditulis sebagai angka, bukan teks "88%", agar tetap bisa dipakai formula.

### Siapa boleh apa

**FINANCE sengaja TIDAK diberi menu Pengguna.** Kolom profil pindah ke layar yang juga mengubah
role, kata sandi, dan PIN — memberi Finance hak ubah di sana hanya supaya bisa menyetel jatah
klibur berarti memberi Finance kemampuan menjadikan dirinya Administrator. Finance mencatat
harinya; Administrator yang merawat profilnya.

### Terverifikasi di produksi (2026-09-09)

Setelah deploy, absen nyata yang sudah ada langsung muncul di laporan tanpa satu pun isian manual:

```
BARISTA  Dimas Barista   tgl 7 = M (app 10:36)   hadir=1
STAFF    Mufit           tgl 7 = M (app 13:19)   hadir=1
RIDER    Agus Rider      (kosong)                hadir=0   <- rider tak bisa absen di app
marks manual di DB : 0
```

Migrasinya **menolak menghapus** tabel lama selama masih berisi baris, jadi tidak mungkin diam-diam
membuang pekerjaan orang di environment lain. Di produksi keduanya kosong — diperiksa lebih dulu
(`partners=0 entries=0`) — lalu `partners` dan `partner_attendance_entries` dihapus, `users.nik`
ditambahkan, dan baris izin FINANCE otomatis dipindahkan ke nama modul baru
(`FINANCE->attendance:view,create,edit,delete`).

---

## Matriks Akses Peran — ✅ AKTIF (menu baru, 2026-09-08)

Sebelum ini, hak akses panel ditanam di kode: `AdministratorOnly` di setiap resource. Sekarang ada
tabel `role_permissions` dan satu halaman pengaturan (**Pengaturan → Matriks Akses Peran**) tempat
Administrator menentukan, per peran, menu mana yang boleh dilihat/ditambah/diubah/dihapus.

Dua sifat yang dijaga ketat:

- **Administrator tidak bisa dibatasi dari sini** dan tidak muncul di daftar peran yang bisa
  diedit. `PermissionMatrix` meloloskannya sebelum tabelnya dibaca sama sekali — peran yang
  mengelola matriks tidak boleh punya cara mengunci dirinya sendiri keluar.
- **Halaman matriksnya sendiri bukan bagian dari matriks** (hardcoded Administrator). Halaman yang
  membagikan izin tidak boleh ikut dibagikan lewat izin yang ia atur.

Peran tanpa centang apa pun tidak melihat menunya sama sekali — jadi tabel kosong = kondisi
teraman. Mencentang Tambah/Ubah/Hapus otomatis menyertakan Lihat.

**Pintu masuk panel sekarang mengikuti matriks:** peran operasional bisa masuk `/admin` begitu
diberi minimal satu menu (Administrator dan Content Creator tetap seperti sebelumnya). Ini yang
membuat izin dan pintu tidak saling bertentangan.

**Default yang ikut terpasang:** `FINANCE` mendapat **Laporan Absensi** penuh
(lihat/tambah/ubah/hapus) — sesuai permintaan agar modul absensi bisa di-CRUD oleh Administrator
*dan* Finance. Finance **tidak** diberi menu lain, terutama bukan Pengguna & Role: lihat alasannya
di bagian Absensi di atas. Sisanya diatur sendiri lewat halaman matriks.

Menu yang sudah masuk matriks: Dashboard, Pengguna & Role, Produk, Gerobak, Lokasi, Dapur Pusat,
Target Harian, Penugasan Staff, Stok Terpusat, **Penjualan Gerobak**, **Insiden Pengiriman**,
**Aktivitas Staff**, Laporan Absensi, Laporan & Ekspor, Audit Trail, Permintaan Reset PIN,
Pengaturan AI.

Empat di antaranya **hanya bisa "Lihat"** karena tidak ada yang bisa dibuat atau diubah di sana:
Dashboard, Laporan & Ekspor, Audit Trail, Stok Terpusat — ditambah **Penjualan Gerobak** dan
**Aktivitas Staff**, yang read-only karena keduanya adalah catatan kejadian, bukan formulir.

Satu pengecualian yang perlu diketahui: **Insiden Pengiriman** ikut matriks dan dua tombol
keputusannya dikaitkan ke hak **Ubah**, bukan ke hak Lihat. Peran yang hanya diberi "Lihat" bisa
membaca laporannya tetapi tidak melihat tombolnya sama sekali — dan `DeliveryIncidentService`
memeriksa perannya lagi di belakang, karena tombol yang disembunyikan bukan pengamanan.

Yang **belum** ikut matriks dan masih hardcoded: News Feed (khusus Content Creator, pasangan peran
itu memang fiturnya) dan halaman **Management Users Role** itu sendiri.

---

## FCM Push Notification — ✅ AKTIF (diverifikasi langsung ke perangkat nyata, 2026-09-07)

Kode-nya sudah lengkap dan sudah ada test-nya (`tests/Feature/PushNotificationTest.php`).
`PushNotifier` dipanggil inline setelah transaksi commit, jadi **tidak** butuh cron juga.

Project Firebase: `soulcoffee-fb389`. App Android: `1:1067852115776:android:8ed7dd8d7b8c79be8114a5`
(package `id.soulcoffeemate.ops.demo`).

**Terpasang di kedua sisi:**
- Service-account JSON (server → Google) — `storage/app/private/fcm-service-account.json`, di luar
  git, `FCM_PROJECT_ID`/`FCM_CREDENTIALS_PATH` di `.env` produksi.
- `google-services.json` (aplikasi ← Google) — masuk ke APK sejak v1.4.4.

**Bukan cuma "config-nya ada" — sudah dibuktikan ujung ke ujung, dan notifikasinya benar-benar
tampil di layar HP** (dikonfirmasi langsung oleh pemilik akun, 2026-09-07). Alurnya: deploy →
`device_push_tokens` produksi berisi token asli (Rider dan Staff Mufit, keduanya Android,
terdaftar sendiri oleh APK) → dua pesan uji dikirim lewat `EventPublisher` (jalur bisnis
sesungguhnya, bukan jalur pintas) ke kedua token Mufit → Google FCM membalas `Sent` untuk
semuanya → **notifikasi muncul di HP**. `POST /notifications/read-all` juga sudah dicoba lewat
HTTPS sungguhan ke `soulcoffee.rafancloud.com` dan mengembalikan 204 dengan `read_at` yang
benar-benar berubah di database.

Aplikasi mendaftarkan token-nya sendiri lewat `POST /api/v1/me/devices` saat login — tidak ada
langkah manual yang tersisa. `event_id` yang sama dipakai untuk membuang duplikat antara socket
dan push (E15) sehingga satu kejadian tidak muncul dua kali.

**Kalau setelah ini push masih belum muncul di HP tertentu**, tiga hal yang lazim menjadi
penyebabnya di sisi perangkat (bukan di sisi server, yang sudah terbukti terkirim):
- Izin notifikasi (`POST_NOTIFICATIONS`, Android 13+) ditolak saat pertama kali diminta —
  cek Setelan Android → Aplikasi → Soul Coffeemate → Notifikasi.
- Google Play Services tidak terpasang/aktif di perangkat itu (jarang, tapi mungkin di emulator
  tanpa Play Store).
- Optimasi baterai/"App tidur" pada beberapa merk Android (Xiaomi/Oppo/Vivo) yang membekukan
  proses background aplikasi — perlu dikecualikan manual dari pengaturan baterai per merk.

---

## Penjualan Gerobak & notifikasi suspect — ✅ AKTIF (2026-09-10)

Staff sekarang mencatat penjualan dari HP: **Catat Penjualan**, tile pertama di menu staff.
Pilih cups, pilih cara bayar, simpan. Stok gerobak berkurang lewat buku besar stok yang sama
dipakai seluruh sistem (`SALE_OUT`), jadi angka di "Stok Gerobak", "Stok Terpusat", dan laporan
tidak mungkin berbeda dari transaksi yang membentuknya.

Empat aturan, dan hanya empat:

1. **Absen dulu.** Menjual adalah bagian dari shift, dan shift dimulai dari absen. Penjualan dari
   orang yang belum absen akan membuat dua laporan saling bertentangan soal pagi yang sama.
2. **Harus punya gerobak hari ini** (dari Penugasan Staff).
3. **Stok harus cukup.** Ditolak dengan nama produk dan sisa sebenarnya — "stok tidak cukup"
   tidak berguna bagi orang yang sedang dikerumuni pembeli.
4. **Transaksi besar DITANDAI, tidak pernah ditolak.**

Poin 4 sesuai permintaan, dan memang satu-satunya rancangan yang bisa dipertahankan: menolak
transaksi besar akan menghukum staff jujur di jam ramai, sementara yang tidak jujur cukup
membaginya jadi dua tap. Jadi: di atas **15 cups dalam satu transaksi** (bisa diubah lewat
`SOUL_SALE_SUSPECT_QTY_THRESHOLD`), transaksinya **tetap tercatat penuh**, stok tetap berkurang,
lalu laporannya dikirim ke **Administrator dan Finance saja** — bukan ke staff lain, dan bukan ke
staff yang melakukannya. Penandaan bukan tuduhan; ia hanya minta seseorang melihat.

**Pengecualian per gerobak:** Master Data → Gerobak → **"Zona ramai — jangan tandai transaksi
besar"**. Menyalakannya menghilangkan laporan untuk gerobak itu dan tidak mengubah apa pun soal
diterima atau tidaknya transaksi.

**Di CMS:** menu **Operasional → Penjualan Gerobak** (`/admin/sales`) — daftar transaksi per
gerobak/staff/area, dengan filter "Hari ini" dan "Hanya yang perlu ditinjau", jumlah cups dan
nilai yang dijumlahkan otomatis di bawah kolom, dan badge merah di menu berisi jumlah transaksi
hari ini yang ditandai. **Read-only, dan itu disengaja:** transaksi sudah menggerakkan stok lewat
buku besar yang append-only, jadi mengeditnya di panel akan membuat dua sumber angka pendapatan
yang tidak bisa dijelaskan. Salah catat diperbaiki seperti salah stok lainnya — lewat penyesuaian
yang mencantumkan siapa yang melakukannya.

**Dashboard** dapat tabel baru: **Penjualan gerobak** — per gerobak, di lokasinya masing-masing,
dengan transaksi, cups, nilai, jumlah yang ditandai, dan jam transaksi terakhir.

---

## Aktivitas Staff (lokasi GPS) — ✅ AKTIF (2026-09-10)

Menu **Operasional → Aktivitas Staff** (`/admin/staff-activity`) menjawab dua pertanyaan dari satu
tabel jejak: **di mana gerobak sekarang**, dan **area mana yang ramai pada jam berapa**.

Yang kedua adalah alasan fitur ini diminta, dan disajikan sebagai grid **area × jam dalam cups**
(1 hari / 7 hari / 30 hari) — inilah bahan mentah untuk klaim seperti "Pulomas ramai jam 09:00,
Cempaka Mas jam 10:00". Jamnya diambil dari **jam server** (R16), bukan jam HP, supaya jam di dua
gerobak berbeda benar-benar bisa dibandingkan. Ini juga bentuk data yang nanti dibaca AI Insight.

Peta menampilkan satu titik per staff: **terisi** = melapor kurang dari 10 menit lalu,
**kosong** = posisi terakhir yang diketahui (dilabeli, bukan disamarkan sebagai posisi sekarang).
Klik nama staff di tabel → jejak perjalanannya hari itu, dengan titik penjualan dibedakan dari
titik laporan posisi biasa.

**Yang perlu diketahui jujur soal jangkauannya:**

- Aplikasi melapor **saat aplikasi terbuka di depan**, sekitar sekali per menit, **hanya untuk
  role STAFF**. Tidak ada pelacakan background: itu butuh foreground service, satu izin Android
  tambahan dengan dialognya sendiri, dan notifikasi permanen — tidak ada yang diminta. Jadi
  deskripsi jujurnya: "di mana gerobak berada selagi HP-nya dipegang", **plus titik presisi di
  setiap transaksi** (server merekam koordinat dari transaksinya sendiri).
- **GPS mati tidak pernah menghalangi apa pun** (E10) — hanya membuat jejaknya kosong.
- Peta **menyegarkan sendiri tiap 20 detik**, bukan lewat socket. HP melapor sekali per menit, jadi
  WebSocket tidak akan mengantar apa pun yang belum didapat penyegaran 20 detik, dan itu akan
  memakan kuota Pusher yang justru dipakai notifikasi refill. Untuk tanggal lampau penyegaran
  berhenti sama sekali — riwayat tidak berubah.
- Jejak mentah disimpan **45 hari** (`soul:prune-location-pings`, terjadwal 03:10). Ini satu-satunya
  tabel yang tumbuh karena waktu, bukan karena aktivitas bisnis. Yang dipangkas hanya jejaknya;
  transaksi dan grid keramaian dihitung dari tabel `sales` dan tetap utuh.

---

## Insiden Pengiriman — ✅ AKTIF (2026-09-10)

Rider melaporkan cups yang rusak di jalan; **Finance/Administrator yang memutuskan** — bukan
rider. Menu **Operasional → Insiden Pengiriman** (`/admin/delivery-incidents`), dengan badge merah
berisi jumlah laporan yang menunggu keputusan.

Di HP rider, tombol **Laporkan Insiden** ada di kartu pengiriman yang sama: foto kerusakan
(wajib), jumlah rusak per produk, catatan. Layarnya sengaja **tidak** menanyakan apa yang harus
terjadi selanjutnya, dan menyatakan itu apa adanya di layar.

Dua keputusan, karena hanya ada dua hal yang bisa terjadi secara fisik:

| Keputusan | Akibatnya |
|---|---|
| **Lanjut sebagian** | Cups rusak dihapus dari stok dapur (`WASTE_OUT`), dan `qty_prepared` baris terkait dikurangi sebanyak itu — jadi rider hanya bisa mencatat cups yang benar-benar sampai (R4 tetap berlaku). Pengantaran berjalan terus. |
| **Batalkan pengantaran** | Request menjadi `CANCELLED`, rider kembali ke dapur, cups rusak dihapus dari stok dapur. Cups yang masih layak tetap jadi stok dapur — tidak ada yang dikirim. Alasan **wajib** diisi, karena itulah yang dibaca staff pemohon ketika pesanannya tidak datang. |

Cups rusak dihapus dari **stok dapur**, bukan dari gerobak: perpindahan dapur→gerobak baru
diposting saat pengiriman selesai, jadi selama di jalan cups itu masih milik dapur.

**Siapa yang diberi tahu:** Administrator, Finance, dapur yang menyeduhnya, rider-nya, dan **staff
yang menunggu kiriman itu** — lewat channel pribadinya sendiri, bukan `role.STAFF`. Staff di
gerobak lain tidak ada urusan dengan kecelakaan di rute orang lain; pengecualian ini diminta
eksplisit dan diuji langsung (`DeliveryIncidentTest`).

---

## Bukti serah terima: foto wajib, tanda tangan opsional (2026-09-10)

Dulu terbalik: tanda tangan wajib, foto tidak dikumpulkan sama sekali. Akibatnya rider yang sedang
memegang krat di pinggir jalan bisa tidak dapat menutup pengiriman yang jelas-jelas terjadi,
sementara barang bukti yang benar-benar menyelesaikan sengketa — foto cups saat diserahkan — tidak
pernah ada.

Sekarang satu pengiriman selesai dalam salah satu dari tiga bentuk, semuanya sah:

- **foto saja** — bentuk normal;
- **foto + paraf staff** — E24 tetap berlaku: minimal tiga goresan, satu titik tidak dihitung;
- **foto + PIN staff** (E7) — PIN tetap diverifikasi, dan **tidak lagi butuh** gambar placeholder
  1×1 yang dulu harus dikarang aplikasi.

Foto diunggah lebih dulu lewat `POST /api/v1/media/handover`, lalu pengirimannya menyebut id-nya —
sama seperti foto bukti refill. Alasannya teknis dan spesifik: klien mobile mengirim **satu berkas
per request** dengan sengaja (encoder multipart React Native menebak `Content-Length` dan menandai
body-nya tidak bisa diulang, yang dulu muncul ke staff sebagai "Upload gagal" di jaringan
seluler). Foto yang sudah dipakai untuk satu pengiriman tidak bisa dipakai lagi, dan foto rider
lain ditolak.

---

## "Alokasi Harian" dihapus dari alur (2026-09-10)

Dua layar hilang dari aplikasi: **Alokasi Harian** (barista) dan **Alokasi Hari Ini** (staff),
beserta **Approval Alokasi** milik Finance.

Alasannya: **Add Stock sudah mencatat fakta yang sama** sebagai efek samping dari hal yang memang
harus dilakukan barista tiap pagi — menyerahkan cups menggerakkan stok, menulis uang harian, dan
sekaligus menempatkan gerobak di penugasan hari itu. Meminta angka yang sama sekali lagi di layar
terpisah adalah kerja dua orang untuk satu fakta, dan faktanya sudah tercatat. Approval Alokasi
ikut hilang karena ia hanya ada untuk menyetujui alokasi di atas target, dan sekarang tidak ada
yang membuatnya.

Tabel `daily_allocations` beserta datanya **tidak dihapus** — riwayatnya tetap utuh dan endpoint
`/allocations` masih ada; yang dihapus adalah langkahnya dari alur kerja sehari-hari.

---

## Kalau login gagal

| Gejala | Kemungkinan |
|---|---|
| "Tidak dapat menghubungi server" | Cek `https://soulcoffee.rafancloud.com/api/v1/auth/login` masih hidup |
| "Nomor HP atau kata sandi salah" | Password sudah dirotasi (lihat peringatan di atas) — pakai password baru |
| Daftar refill/alokasi kosong | Normal — belum ada aktivitas ditulis lewat akun ini |
| "Anda tidak bertugas di gerobak ini hari ini" | Penugasan staff hanya berlaku untuk tanggal yang di-seed; perlu penugasan baru untuk hari ini |
