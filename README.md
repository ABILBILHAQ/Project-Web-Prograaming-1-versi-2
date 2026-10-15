# Sistem Evaluasi Kegiatan Mahasiswa terhadap PPKMB Berbasis Analisis Sentimen

Aplikasi web Full-Stack berbasis **PHP 8+, MySQL/MariaDB, Bootstrap 5, Chart.js, dan Python Machine Learning (TF-IDF + Multinomial Logistic Regression & Naive Bayes)** untuk mengumpulkan evaluasi mahasiswa baru terhadap pelaksanaan PPKMB serta menganalisis sentimen komentar Bahasa Indonesia secara otomatis.

---

## 1. Fitur Utama Sistem

1. **Privasi & Anonimitas Mahasiswa**:
   - Mendukung pengisian evaluasi dalam **Mode Anonim Penuh** tanpa wajib mengisi identitas pribadi.
   - Tabel `evaluations` dirancang **tanpa kolom identitas mahasiswa (`user_id` / NIM)** sehingga dashboard admin 100% bebas dari identitas individu.
   - Dilengkapi **PII Masking Otomatis** (di PHP dan Python) yang menyamarkan NIM, nomor telepon/WA, alamat email, dan perkenalan nama eksplisit dari komentar sebelum disimpan atau dilatih.
2. **Formulir Evaluasi 7 Indikator (Skala 1–5) & Komentar Terbuka**:
   - Penilaian kuantitatif terhadap: **(1) Materi Kegiatan, (2) Pemateri/Narasumber, (3) Panitia, (4) Fasilitas, (5) Konsumsi, (6) Ketepatan Jadwal, dan (7) Keseluruhan Kegiatan**.
   - Kolom Komentar Pengalaman serta Kritik & Saran dengan validasi, penghitung kata, dan modal konfirmasi sebelum pengiriman.
3. **Analisis Sentimen NLP Bahasa Indonesia (`Positif`, `Negatif`, `Netral`)**:
   - **Preprocessing Teks Bahasa Indonesia**: Case folding, pembersihan URL/HTML/tanda baca, normalisasi huruf berulang, kamus singkatan/slang Bahasa Indonesia (*yg, gak, bgt, maba, narsum, molor, ngaret*, dll.), penanganan kata negasi (*tidak_jelas, kurang_ramah, tidak_membosankan*), dan stemming ringan.
   - **Model Klasifikasi Terbimbing (Supervised ML)**: Ekstraksi fitur **TF-IDF (Unigram + Bigram, Sublinear TF, Smooth IDF)** dikombinasikan dengan **L2-Regularized Multinomial Logistic Regression & Multinomial Naive Bayes**.
   - **Pemisahan Indikator**: Rating angka dan sentimen teks dianalisis sebagai dua indikator berbeda, dilengkapi pendeteksi **Diskrepansi** (jika rating tinggi tetapi sentimen komentar negatif, atau sebaliknya).
   - **Mekanisme Fallback Jujur & Transparan**: Jika file model `.pkl`/`.json` atau layanan Python belum tersedia di host, sistem otomatis beralih ke fallback pencocokan leksikon dan memberi label eksplisit `Fallback Kata (Bukan AI/ML)` tanpa mengklaim analisis berbasis AI.
4. **Dashboard Statistik Admin & 5 Grafik Dinamis Chart.js**:
   - 6 Kartu Statistik Utama (Total Evaluasi, Total Komentar Dianalisis, Sentimen Positif, Negatif, Netral, dan Rata-rata Rating).
   - 5 Grafik Dinamis dari Database: Diagram Donat Sentimen, Diagram Batang Sentimen per Kategori Kegiatan, Grafik Garis Tren Sentimen per Tanggal, Grafik Perbandingan Rata-rata 7 Aspek, dan Grafik Distribusi Rating (Skala 1–5).
5. **Laporan Evaluasi, Rekomendasi Perbaikan & Ekspor Multi-Format**:
   - Ekstraksi topik yang sering mendapat apresiasi dan topik yang sering dikeluhkan, rekomendasi perbaikan berbasis data aktual (dengan catatan verifikasi manusia), perbandingan antarperiode PPKMB (2025/2026 vs 2026/2027), serta fitur **Ekspor CSV**, **Ekspor Excel (`.xls`)**, dan **Cetak Laporan**.

---

## 2. Struktur Folder Proyek

```text
ppkmb-evaluation/
├── index.php                     # Halaman utama (Landing Page & ringkasan metrik)
├── login.php                     # Halaman autentikasi (Admin, Panitia, Mahasiswa)
├── logout.php                    # Penghapusan session aman
├── .env.example                  # Contoh konfigurasi environment tanpa kredensial rahasia
├── README.md                     # Dokumentasi instalasi dan penggunaan
├── config/
│   └── database.php              # Koneksi PDO MySQL, CSRF, Rate Limit, PII Masking, & Bridge Python
├── includes/
│   ├── auth.php                  # Middleware Role-Based Access Control (RBAC)
│   ├── header.php                # Header HTML5, Navbar Biru Navy, & Flash Notification
│   ├── sidebar.php               # Sidebar navigasi Dashboard Admin/Panitia
│   └── footer.php                # Footer & pemuatan JS (Bootstrap 5 + Chart.js)
├── admin/
│   ├── dashboard.php             # Dashboard 6 kartu statistik + 5 grafik Chart.js + filter
│   ├── evaluations.php           # Tabel evaluasi, filter komentar negatif, detail modal, re-analyze
│   ├── sentiment.php             # Evaluasi metrik ML (Accuracy, Precision, Recall, F1, Confusion Matrix)
│   ├── datasets.php              # CRUD dataset berlabel, impor CSV, & tombol Latih Ulang Model
│   ├── periods.php               # Manajemen periode PPKMB, pengaturan privasi, & manajemen user
│   ├── activities.php            # Manajemen jenis dan sesi kegiatan PPKMB
│   └── reports.php               # Laporan komprehensif, rekomendasi, ekspor CSV/Excel, & cetak
├── student/
│   ├── evaluation-form.php       # Formulir evaluasi mahasiswa (Mode Anonim / Akun)
│   └── evaluation-history.php    # Cek kode bukti evaluasi & ringkasan hasil evaluasi umum
├── api/
│   └── analyze-sentiment.php     # Endpoint REST API internal terproteksi (CSRF / X-API-KEY)
├── python/
│   ├── text_preprocessor.py      # Preprocessing Bahasa Indonesia, kamus slang, negasi, & PII scrubber
│   ├── ml_engine.py              # Engine TF-IDF + Logistic Regression & Naive Bayes + Fallback
│   ├── train_model.py            # Skrip pelatihan model dari CSV dataset berlabel
│   ├── evaluate_model.py         # Evaluasi Stratified 5-Fold Cross-Validation & 80/20 Split
│   ├── predict_sentiment.py      # CLI & STDIN JSON predictor (tunggal & batch)
│   ├── dataset_ppkmb_id.csv      # Dataset pelatihan Bahasa Indonesia (180 sampel berlabel seimbang)
│   ├── requirements.txt          # Daftar pustaka Python (numpy, scipy, pandas, scikit-learn)
│   └── models/
│       ├── sentiment_model.pkl   # Artefak model ML terlatih (siap pakai)
│       ├── sentiment_model.json  # Parameter bobot TF-IDF & klasifikasi portabel
│       └── metrics.json          # Hasil pengujian metrik aktual model
├── assets/
│   ├── css/style.css             # Desain tema Biru Navy (#0d254c), Putih, & Biru Muda (#38bdf8)
│   └── js/app.js                 # Loading indicator, toggle sidebar, & penghitung karakter
└── database/
    ├── schema.sql                # Struktur tabel MySQL/MariaDB (8+ tabel berelasi)
    └── seed.sql                  # Data awal pengujian (berpenanda is_dummy = 1)
```

---

## 3. Panduan Instalasi & Menjalankan Proyek di Laragon (Windows)

### Langkah 1: Salin Folder Proyek ke Direktori Laragon
1. Ekstrak folder `ppkmb-evaluation` ke dalam direktori web root Laragon Anda:
   ```text
   C:\laragon\www\ppkmb-evaluation
   ```
2. Buka aplikasi **Laragon** dan klik tombol **Start All** (pastikan **Apache/Nginx** dan **MySQL/MariaDB** berstatus *Running*).

### Langkah 2: Konfigurasi File `.env`
1. Di dalam folder `C:\laragon\www\ppkmb-evaluation`, salin file `.env.example` menjadi `.env`:
   ```cmd
   copy .env.example .env
   ```
2. Pengaturan default di dalam `.env` sudah disesuaikan untuk Laragon (`DB_HOST=127.0.0.1`, `DB_DATABASE=ppkmb_sentiment_db`, `DB_USERNAME=root`, `DB_PASSWORD=`).

### Langkah 3: Buat Database & Impor SQL (Bisa Otomatis 1-Klik!)
Anda dapat memilih **salah satu** dari dua cara berikut:
- **Cara Otomatis (Paling Mudah)**:
  Buka browser dan kunjungi `http://localhost/ppkmb-evaluation/` (atau `http://ppkmb-evaluation.test/`). Jika database belum dibuat, halaman asisten instalasi akan muncul. Klik tombol **"Inisialisasi Database & Data Pengujian Otomatis"**, maka sistem akan otomatis menjalankan `database/schema.sql` dan `database/seed.sql`.
- **Cara Manual via Terminal Laragon / HeidiSQL / phpMyAdmin**:
  ```cmd
  mysql -u root < database/schema.sql
  mysql -u root ppkmb_sentiment_db < database/seed.sql
  ```

### Langkah 4: Konfigurasi Python untuk Modul Analisis Sentimen
1. Buka **Laragon Terminal** (`Menu -> Laragon -> Terminal`), masuk ke folder proyek:
   ```cmd
   cd C:\laragon\www\ppkmb-evaluation
   pip install -r python/requirements.txt
   ```
2. Model Machine Learning sudah dilatih sebelumnya dan tersimpan di `python/models/sentiment_model.pkl` serta `python/models/sentiment_model.json`.
3. Untuk melatih ulang model secara manual melalui terminal:
   ```cmd
   python python/train_model.py
   ```
4. Untuk menguji evaluasi performa model (Stratified 5-Fold Cross-Validation):
   ```cmd
   python python/evaluate_model.py
   ```
5. Untuk menguji prediksi komentar dari CLI:
   ```cmd
   python python/predict_sentiment.py --text "Materinya sangat bermanfaat dan panitia sangat membantu."
   ```

---

## 4. Akun Pengujian (Data Seed Demo)

| Role Pengguna | Username | Email | Password | Hak Akses Utama |
| :--- | :--- | :--- | :--- | :--- |
| **Administrator** | `admin` | `admin.ppkmb@kampus.ac.id` | `AdminPPKMB2026!` | Akses penuh seluruh fitur dashboard, evaluasi, model ML, dataset, periode, pengguna, & laporan |
| **Panitia PPKMB** | `panitia` | `panitia.inti@kampus.ac.id` | `PanitiaPPKMB2026!` | Dashboard statistik, tinjauan data evaluasi, uji sentimen, dataset, sesi kegiatan, & ekspor laporan |
| **Mahasiswa Baru** | `maba01` | `maba01@student.kampus.ac.id` | `Mahasiswa2026!` | Mengisi evaluasi (mode akun maupun mode anonim) & melihat ringkasan hasil evaluasi umum |

> **Catatan Data Pengujian (`is_dummy = 1`)**: Seluruh 27 data evaluasi contoh di dalam `database/seed.sql` diberi flag `is_dummy = 1` dan keterangan `[DATA DUMMY PENGUJIAN]` agar Anda dapat langsung menguji grafik dashboard, filter komentar negatif, dan laporan, serta dapat menyembunyikannya kapan saja melalui filter **"Hanya Data Evaluasi Nyata"** di Dashboard Admin.
