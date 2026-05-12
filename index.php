<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

// ==============================================================================
// 1. KONFIGURASI & KONEKSI DATABASE (TiDB CLOUD - SERVERLESS + SSL)
// ==============================================================================
$host = 'gateway01.ap-southeast-1.prod.alicloud.tidbcloud.com';
$user = 'e1aHBgKkkYs5ecU.root';
$pass = 'j8fnX6U6qQYDDicd'; 
$db   = 'nexus_gaming';
$port = 4000;

// Inisialisasi koneksi awal (Tanpa DB) untuk pembuatan otomatis
$conn_init = mysqli_init();
mysqli_ssl_set($conn_init, NULL, NULL, NULL, NULL, NULL);
mysqli_real_connect($conn_init, $host, $user, $pass, '', $port, NULL, MYSQLI_CLIENT_SSL);
if ($conn_init->connect_error) die("Fatal Error (Init): " . $conn_init->connect_error);
$conn_init->query("CREATE DATABASE IF NOT EXISTS $db");
$conn_init->close();

// Koneksi utama dengan database
$conn = mysqli_init();
mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
mysqli_real_connect($conn, $host, $user, $pass, $db, $port, NULL, MYSQLI_CLIENT_SSL);
if ($conn->connect_error) die("Fatal Error (DB): " . $conn->connect_error);

// ==============================================================================
// 2. AUTO-MIGRATION (PEMBUATAN TABEL OTOMATIS)
// ==============================================================================
$tables = [
    "CREATE TABLE IF NOT EXISTS pengguna (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        username VARCHAR(50) NOT NULL UNIQUE, 
        password VARCHAR(255) NOT NULL,
        nama_lengkap VARCHAR(100) NOT NULL,
        role ENUM('admin', 'kasir') DEFAULT 'kasir',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS kategori (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_kategori VARCHAR(50) NOT NULL UNIQUE,
        ikon VARCHAR(50) DEFAULT 'fa-box'
    )",
    "CREATE TABLE IF NOT EXISTS produk (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        kode_sku VARCHAR(20) NOT NULL UNIQUE,
        nama_produk VARCHAR(100) NOT NULL, 
        id_kategori INT, 
        harga DECIMAL(12,2) NOT NULL, 
        stok INT NOT NULL DEFAULT 0,
        deskripsi TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_kategori) REFERENCES kategori(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS pesanan (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        no_invoice VARCHAR(30) NOT NULL UNIQUE,
        id_pengguna INT, 
        nama_pelanggan VARCHAR(100) DEFAULT 'Guest',
        subtotal DECIMAL(12,2) NOT NULL, 
        diskon DECIMAL(12,2) DEFAULT 0,
        pajak DECIMAL(12,2) DEFAULT 0,
        total_akhir DECIMAL(12,2) NOT NULL,
        bayar DECIMAL(12,2) NOT NULL,
        kembalian DECIMAL(12,2) NOT NULL,
        tanggal DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_pengguna) REFERENCES pengguna(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS detail_pesanan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_pesanan INT,
        id_produk INT,
        harga_satuan DECIMAL(12,2) NOT NULL,
        kuantitas INT NOT NULL,
        subtotal DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (id_pesanan) REFERENCES pesanan(id) ON DELETE CASCADE,
        FOREIGN KEY (id_produk) REFERENCES produk(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS log_aktivitas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_pengguna INT,
        aksi VARCHAR(255) NOT NULL,
        tanggal DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_pengguna) REFERENCES pengguna(id) ON DELETE CASCADE
    )"
];

foreach ($tables as $sql) {
    $conn->query($sql);
}

// ==============================================================================
// 3. AUTO-SEEDING (DATA AWAL)
// ==============================================================================
function seedData($conn) {
    // Seed Admin
    $res = $conn->query("SELECT id FROM pengguna LIMIT 1");
    if ($res->num_rows == 0) {
        $pass = password_hash('admin123', PASSWORD_DEFAULT);
        $conn->query("INSERT INTO pengguna (username, password, nama_lengkap, role) VALUES ('admin', '$pass', 'System Administrator', 'admin')");
        $conn->query("INSERT INTO pengguna (username, password, nama_lengkap, role) VALUES ('kasir', '$pass', 'Operator Kasir', 'kasir')");
    }
    // Seed Kategori
    $res = $conn->query("SELECT id FROM kategori LIMIT 1");
    if ($res->num_rows == 0) {
        $conn->query("INSERT INTO kategori (nama_kategori, ikon) VALUES ('Mouse Gaming', 'fa-computer-mouse'), ('Keyboard Mechanical', 'fa-keyboard'), ('Headset Audio', 'fa-headphones'), ('Kursi Ergonomis', 'fa-chair'), ('Monitor 144Hz+', 'fa-desktop')");
    }
    // Seed Produk
    $res = $conn->query("SELECT id FROM produk LIMIT 1");
    if ($res->num_rows == 0) {
        $conn->query("INSERT INTO produk (kode_sku, nama_produk, id_kategori, harga, stok, deskripsi) VALUES 
        ('NX-M01', 'Razer DeathAdder V2 Pro', 1, 1500000, 25, 'Wireless ergonomic gaming mouse.'),
        ('NX-K01', 'Corsair K70 RGB MK.2', 2, 2200000, 15, 'Mechanical gaming keyboard with Cherry MX.'),
        ('NX-H01', 'HyperX Cloud II Wireless', 3, 1850000, 20, 'Legendary comfort goes wireless.'),
        ('NX-C01', 'Secretlab Titan Evo 2022', 4, 7500000, 5, 'Premium gaming chair.'),
        ('NX-D01', 'ASUS ROG Swift PG259QN', 5, 12000000, 8, '360Hz esports gaming monitor.')");
    }
}
seedData($conn);

// ==============================================================================
// 4. FUNGSI HELPER
// ==============================================================================
function setAlert($type, $title, $text) {
    $_SESSION['alert'] = ['type' => $type, 'title' => $title, 'text' => $text];
}

function logActivity($conn, $user_id, $action) {
    $stmt = $conn->prepare("INSERT INTO log_aktivitas (id_pengguna, aksi) VALUES (?, ?)");
    $stmt->bind_param("is", $user_id, $action);
    $stmt->execute();
}

function formatRupiah($angka) {
    return "Rp " . number_format($angka, 0, ',', '.');
}

function generateInvoice() {
    return "INV-" . date("Ymd") . "-" . strtoupper(substr(uniqid(), -5));
}

// ==============================================================================
// 5. ROUTING & CONTROLLER LOGIC
// ==============================================================================
$page = $_GET['page'] ?? 'dashboard';
$public_pages = ['login', 'register'];

// Proteksi Auth
if (!isset($_SESSION['user_id']) && !in_array($page, $public_pages)) {
    setAlert('warning', 'Akses Ditolak', 'Silakan login terlebih dahulu.');
    header("Location: ?page=login");
    exit;
}

// Inisialisasi Cart Session
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// --- LOGIC: POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    // AUTH: LOGIN
    if ($action == 'login') {
        $username = $conn->real_escape_string($_POST['username']);
        $password = $_POST['password'];
        $res = $conn->query("SELECT * FROM pengguna WHERE username='$username'");
        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                logActivity($conn, $user['id'], "Login ke dalam sistem");
                setAlert('success', 'Login Berhasil', 'Selamat datang, ' . $user['nama_lengkap']);
                header("Location: ?page=dashboard");
                exit;
            }
        }
        setAlert('error', 'Login Gagal', 'Username atau password salah.');
        header("Location: ?page=login");
        exit;
    }

    // AUTH: LOGOUT
    if ($action == 'logout') {
        logActivity($conn, $_SESSION['user_id'], "Logout dari sistem");
        session_unset();
        session_destroy();
        session_start();
        setAlert('success', 'Logout Berhasil', 'Anda telah keluar dari sistem.');
        header("Location: ?page=login");
        exit;
    }

    // KATEGORI: TAMBAH/EDIT
    if ($action == 'save_kategori') {
        $id = $_POST['id'] ?? '';
        $nama = $conn->real_escape_string($_POST['nama_kategori']);
        $ikon = $conn->real_escape_string($_POST['ikon']);
        
        if (empty($id)) {
            $conn->query("INSERT INTO kategori (nama_kategori, ikon) VALUES ('$nama', '$ikon')");
            logActivity($conn, $_SESSION['user_id'], "Menambah kategori: $nama");
            setAlert('success', 'Berhasil', 'Kategori baru ditambahkan.');
        } else {
            $conn->query("UPDATE kategori SET nama_kategori='$nama', ikon='$ikon' WHERE id=$id");
            logActivity($conn, $_SESSION['user_id'], "Mengubah kategori ID: $id");
            setAlert('success', 'Berhasil', 'Kategori diperbarui.');
        }
        header("Location: ?page=kategori");
        exit;
    }

    // PRODUK: TAMBAH/EDIT
    if ($action == 'save_produk') {
        $id = $_POST['id'] ?? '';
        $sku = $conn->real_escape_string($_POST['kode_sku']);
        $nama = $conn->real_escape_string($_POST['nama_produk']);
        $kategori = $_POST['id_kategori'];
        $harga = $_POST['harga'];
        $stok = $_POST['stok'];
        $deskripsi = $conn->real_escape_string($_POST['deskripsi']);

        if (empty($id)) {
            $conn->query("INSERT INTO produk (kode_sku, nama_produk, id_kategori, harga, stok, deskripsi) VALUES ('$sku', '$nama', $kategori, $harga, $stok, '$deskripsi')");
            logActivity($conn, $_SESSION['user_id'], "Menambah produk: $nama (SKU: $sku)");
            setAlert('success', 'Berhasil', 'Produk baru ditambahkan.');
        } else {
            $conn->query("UPDATE produk SET kode_sku='$sku', nama_produk='$nama', id_kategori=$kategori, harga=$harga, stok=$stok, deskripsi='$deskripsi' WHERE id=$id");
            logActivity($conn, $_SESSION['user_id'], "Mengubah produk ID: $id");
            setAlert('success', 'Berhasil', 'Produk diperbarui.');
        }
        header("Location: ?page=produk");
        exit;
    }

    // POS: ADD TO CART
    if ($action == 'add_to_cart') {
        $id_produk = $_POST['id_produk'];
        $qty = (int)$_POST['qty'];
        
        $res = $conn->query("SELECT * FROM produk WHERE id=$id_produk");
        if ($res->num_rows > 0) {
            $prod = $res->fetch_assoc();
            $current_qty = isset($_SESSION['cart'][$id_produk]) ? $_SESSION['cart'][$id_produk]['qty'] : 0;
            
            if ($prod['stok'] >= ($current_qty + $qty)) {
                if (isset($_SESSION['cart'][$id_produk])) {
                    $_SESSION['cart'][$id_produk]['qty'] += $qty;
                } else {
                    $_SESSION['cart'][$id_produk] = [
                        'nama' => $prod['nama_produk'],
                        'harga' => $prod['harga'],
                        'qty' => $qty,
                        'sku' => $prod['kode_sku']
                    ];
                }
                setAlert('success', 'Ditambahkan', $qty . 'x ' . $prod['nama_produk'] . ' masuk keranjang.');
            } else {
                setAlert('error', 'Stok Kurang', 'Stok tidak mencukupi untuk jumlah tersebut.');
            }
        }
        header("Location: ?page=pos");
        exit;
    }

    // POS: UPDATE CART
    if ($action == 'update_cart') {
        foreach ($_POST['qty'] as $id => $qty) {
            $qty = (int)$qty;
            if ($qty <= 0) {
                unset($_SESSION['cart'][$id]);
            } else {
                $res = $conn->query("SELECT stok FROM produk WHERE id=$id");
                $stok = $res->fetch_assoc()['stok'];
                if ($stok >= $qty) {
                    $_SESSION['cart'][$id]['qty'] = $qty;
                } else {
                    setAlert('warning', 'Stok Disesuaikan', 'Beberapa item melebihi stok tersedia dan telah disesuaikan.');
                    $_SESSION['cart'][$id]['qty'] = $stok;
                }
            }
        }
        header("Location: ?page=pos");
        exit;
    }

    // POS: CLEAR CART
    if ($action == 'clear_cart') {
        $_SESSION['cart'] = [];
        header("Location: ?page=pos");
        exit;
    }

    // POS: CHECKOUT
    if ($action == 'checkout') {
        if (empty($_SESSION['cart'])) {
            setAlert('error', 'Keranjang Kosong', 'Tidak ada item untuk diproses.');
            header("Location: ?page=pos");
            exit;
        }

        $pelanggan = $conn->real_escape_string($_POST['nama_pelanggan']) ?: 'Guest';
        $bayar = (float)$_POST['bayar'];
        $diskon = (float)($_POST['diskon'] ?? 0);
        $pajak_persen = 11; // PPN 11%

        $subtotal = 0;
        foreach ($_SESSION['cart'] as $item) {
            $subtotal += ($item['harga'] * $item['qty']);
        }

        $total_setelah_diskon = $subtotal - $diskon;
        $pajak = $total_setelah_diskon * ($pajak_persen / 100);
        $total_akhir = $total_setelah_diskon + $pajak;

        if ($bayar < $total_akhir) {
            setAlert('error', 'Pembayaran Kurang', 'Nominal bayar tidak mencukupi. Total: ' . formatRupiah($total_akhir));
            header("Location: ?page=pos");
            exit;
        }

        $kembalian = $bayar - $total_akhir;
        $invoice = generateInvoice();
        $id_user = $_SESSION['user_id'];

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO pesanan (no_invoice, id_pengguna, nama_pelanggan, subtotal, diskon, pajak, total_akhir, bayar, kembalian) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sisdddddd", $invoice, $id_user, $pelanggan, $subtotal, $diskon, $pajak, $total_akhir, $bayar, $kembalian);
            $stmt->execute();
            $id_pesanan = $conn->insert_id;

            $stmt_detail = $conn->prepare("INSERT INTO detail_pesanan (id_pesanan, id_produk, harga_satuan, kuantitas, subtotal) VALUES (?, ?, ?, ?, ?)");
            $stmt_stok = $conn->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?");

            foreach ($_SESSION['cart'] as $id_prod => $item) {
                $sub_item = $item['harga'] * $item['qty'];
                $stmt_detail->bind_param("iidid", $id_pesanan, $id_prod, $item['harga'], $item['qty'], $sub_item);
                $stmt_detail->execute();

                $stmt_stok->bind_param("ii", $item['qty'], $id_prod);
                $stmt_stok->execute();
            }

            $conn->commit();
            logActivity($conn, $id_user, "Memproses transaksi: $invoice");
            $_SESSION['cart'] = [];
            $_SESSION['last_invoice'] = $id_pesanan;
            setAlert('success', 'Transaksi Berhasil', 'Kembalian: ' . formatRupiah($kembalian));
            header("Location: ?page=invoice&id=" . $id_pesanan);
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            setAlert('error', 'Sistem Error', 'Gagal memproses transaksi.');
            header("Location: ?page=pos");
            exit;
        }
    }

    // PENGGUNA: TAMBAH (ADMIN ONLY)
    if ($action == 'save_pengguna' && $_SESSION['role'] == 'admin') {
        $nama = $conn->real_escape_string($_POST['nama_lengkap']);
        $uname = $conn->real_escape_string($_POST['username']);
        $role = $_POST['role'];
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $conn->query("INSERT INTO pengguna (username, password, nama_lengkap, role) VALUES ('$uname', '$pass', '$nama', '$role')");
        logActivity($conn, $_SESSION['user_id'], "Menambahkan user baru: $uname");
        setAlert('success', 'Berhasil', 'Pengguna sistem ditambahkan.');
        header("Location: ?page=pengguna");
        exit;
    }
}

// --- LOGIC: GET REQUESTS (DELETE) ---
if (isset($_GET['action'])) {
    $act = $_GET['action'];
    $id = (int)($_GET['id'] ?? 0);

    if ($act == 'del_kategori') {
        $conn->query("DELETE FROM kategori WHERE id=$id");
        logActivity($conn, $_SESSION['user_id'], "Menghapus kategori ID: $id");
        setAlert('success', 'Dihapus', 'Kategori berhasil dihapus.');
        header("Location: ?page=kategori");
        exit;
    }
    if ($act == 'del_produk') {
        $conn->query("DELETE FROM produk WHERE id=$id");
        logActivity($conn, $_SESSION['user_id'], "Menghapus produk ID: $id");
        setAlert('success', 'Dihapus', 'Produk berhasil dihapus.');
        header("Location: ?page=produk");
        exit;
    }
    if ($act == 'del_pengguna' && $_SESSION['role'] == 'admin' && $id != $_SESSION['user_id']) {
        $conn->query("DELETE FROM pengguna WHERE id=$id");
        logActivity($conn, $_SESSION['user_id'], "Menghapus user ID: $id");
        setAlert('success', 'Dihapus', 'Pengguna berhasil dihapus.');
        header("Location: ?page=pengguna");
        exit;
    }
    if ($act == 'remove_cart') {
        unset($_SESSION['cart'][$id]);
        header("Location: ?page=pos");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEXUS GAMING - ERP & POS System</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        cyber: { 900: '#070a13', 800: '#10172a', 700: '#1e293b' },
                        neon: { blue: '#00f3ff', purple: '#bc13fe', green: '#00ff66', red: '#ff003c' }
                    },
                    fontFamily: {
                        sans: ['Rajdhani', 'sans-serif'],
                        mono: ['Orbitron', 'monospace'],
                    }
                }
            }
        }
    </script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body {
            background-color: #070a13;
            color: #e2e8f0;
            overflow-x: hidden;
        }
        /* Animated Background Particles */
        .cyber-bg {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: -1;
            background: radial-gradient(circle at 15% 50%, rgba(0, 243, 255, 0.05) 0%, transparent 50%),
                        radial-gradient(circle at 85% 30%, rgba(188, 19, 254, 0.05) 0%, transparent 50%);
        }
        .cyber-bg::before {
            content: ""; position: absolute; width: 200%; height: 200%; top: -50%; left: -50%;
            background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            transform: perspective(500px) rotateX(60deg) translateY(-100px) translateZ(200px);
            animation: gridMove 20s linear infinite;
        }
        @keyframes gridMove { 0% { transform: perspective(500px) rotateX(60deg) translateY(0) translateZ(200px); } 100% { transform: perspective(500px) rotateX(60deg) translateY(40px) translateZ(200px); } }
        
        /* Glassmorphism */
        .glass { background: rgba(16, 23, 42, 0.7); backdrop-filter: blur(16px); border: 1px solid rgba(0, 243, 255, 0.1); }
        .glass-card { background: linear-gradient(145deg, rgba(30, 41, 59, 0.8), rgba(15, 23, 42, 0.9)); border: 1px solid rgba(255,255,255,0.05); box-shadow: 0 8px 32px rgba(0,0,0,0.5); }
        
        /* Inputs & Buttons */
        .cyber-input { background: rgba(0,0,0,0.4); border: 1px solid rgba(0, 243, 255, 0.3); color: #00f3ff; transition: all 0.3s; }
        .cyber-input:focus { outline: none; border-color: #bc13fe; box-shadow: 0 0 10px rgba(188, 19, 254, 0.4); }
        .btn-neon { position: relative; overflow: hidden; transition: 0.3s; z-index: 1; }
        .btn-neon::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent); transition: 0.5s; z-index: -1; }
        .btn-neon:hover::before { left: 100%; }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #070a13; }
        ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 4px; border: 1px solid #00f3ff; }
        ::-webkit-scrollbar-thumb:hover { background: #bc13fe; }

        /* Animations */
        .fade-in { animation: fadeIn 0.4s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="font-sans text-slate-300 min-h-screen flex selection:bg-neon-purple selection:text-white">

    <div class="cyber-bg"></div>

    <?php
    // SWEETALERT TRIGGER
    if (isset($_SESSION['alert'])) {
        $a = $_SESSION['alert'];
        echo "<script>
            document.addEventListener('DOMContentLoaded', () => {
                Swal.fire({
                    icon: '{$a['type']}', title: '{$a['title']}', text: '{$a['text']}',
                    background: '#0f172a', color: '#00f3ff', confirmButtonColor: '#bc13fe',
                    customClass: { popup: 'border border-neon-blue shadow-[0_0_20px_rgba(0,243,255,0.3)]' }
                });
            });
        </script>";
        unset($_SESSION['alert']);
    }
    ?>

    <?php if (in_array($page, $public_pages)): ?>
        <!-- ========================================================================================= -->
        <!-- PUBLIC PAGES (LOGIN) -->
        <!-- ========================================================================================= -->
        <div class="w-full flex items-center justify-center p-4">
            <div class="glass-card p-10 rounded-2xl w-full max-w-md border-t-4 border-t-neon-blue relative fade-in">
                <div class="text-center mb-10">
                    <div class="inline-block p-4 rounded-full bg-slate-900 border border-neon-purple shadow-[0_0_15px_rgba(188,19,254,0.5)] mb-4">
                        <i class="fa-solid fa-gamepad text-4xl text-neon-blue drop-shadow-[0_0_8px_#00f3ff]"></i>
                    </div>
                    <h1 class="text-3xl font-mono font-bold text-white tracking-widest">NEXUS<span class="text-neon-blue">OS</span></h1>
                    <p class="text-slate-400 text-sm tracking-widest uppercase mt-2">Access Terminal v2.5</p>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="login">
                    <div class="space-y-6">
                        <div class="relative">
                            <i class="fa-solid fa-user-astronaut absolute left-4 top-3.5 text-neon-blue"></i>
                            <input type="text" name="username" placeholder="Operator ID" required class="cyber-input w-full py-3 pl-12 pr-4 rounded-lg font-bold">
                        </div>
                        <div class="relative">
                            <i class="fa-solid fa-fingerprint absolute left-4 top-3.5 text-neon-blue"></i>
                            <input type="password" name="password" placeholder="Passcode" required class="cyber-input w-full py-3 pl-12 pr-4 rounded-lg font-bold">
                        </div>
                        <button type="submit" class="btn-neon w-full bg-gradient-to-r from-cyan-600 to-blue-700 hover:from-purple-600 hover:to-pink-600 text-white font-mono font-bold py-3 rounded-lg uppercase tracking-widest shadow-[0_0_15px_rgba(0,243,255,0.4)]">
                            Initialize Link
                        </button>
                    </div>
                </form>
                <div class="mt-6 text-center text-xs text-slate-500 font-mono">
                    System Secured by TiDB Cloud SSL
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ========================================================================================= -->
        <!-- PRIVATE PAGES (DASHBOARD & APP) -->
        <!-- ========================================================================================= -->
        
        <!-- SIDEBAR -->
        <aside class="w-64 glass border-r border-slate-800 flex flex-col h-screen sticky top-0 z-40 shrink-0 hidden md:flex">
            <div class="h-20 flex items-center justify-center border-b border-slate-800 shrink-0">
                <i class="fa-solid fa-vr-cardboard text-2xl text-neon-blue mr-3 animate-pulse"></i>
                <h1 class="text-xl font-mono font-bold text-white tracking-wider">NEXUS<span class="text-neon-purple">OS</span></h1>
            </div>
            
            <div class="p-4 border-b border-slate-800 flex items-center gap-3 shrink-0">
                <div class="w-10 h-10 rounded-full bg-slate-800 border border-neon-blue flex items-center justify-center text-neon-blue">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
                <div>
                    <p class="text-sm font-bold text-white truncate w-40"><?= $_SESSION['nama_lengkap'] ?></p>
                    <p class="text-xs text-neon-green uppercase font-mono"><i class="fa-solid fa-circle text-[8px] mr-1"></i> <?= $_SESSION['role'] ?></p>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto py-4 space-y-1 px-3 custom-scrollbar">
                <p class="text-xs text-slate-500 font-bold uppercase tracking-wider mb-2 px-3 mt-4">Main Menu</p>
                
                <a href="?page=dashboard" class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all <?= $page=='dashboard' ? 'bg-blue-900/40 text-neon-blue border border-blue-500/30' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i class="fa-solid fa-chart-line w-5 text-center"></i> <span class="font-bold">Dashboard</span>
                </a>
                
                <a href="?page=pos" class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all <?= $page=='pos' ? 'bg-blue-900/40 text-neon-blue border border-blue-500/30' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i class="fa-solid fa-cash-register w-5 text-center"></i> <span class="font-bold">Point of Sale</span>
                </a>
                
                <a href="?page=transaksi" class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all <?= $page=='transaksi' || $page=='invoice' ? 'bg-blue-900/40 text-neon-blue border border-blue-500/30' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i class="fa-solid fa-file-invoice-dollar w-5 text-center"></i> <span class="font-bold">Data Transaksi</span>
                </a>

                <p class="text-xs text-slate-500 font-bold uppercase tracking-wider mb-2 px-3 mt-6">Inventory</p>

                <a href="?page=produk" class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all <?= $page=='produk' ? 'bg-purple-900/40 text-neon-purple border border-purple-500/30' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i class="fa-solid fa-boxes-stacked w-5 text-center"></i> <span class="font-bold">Kelola Produk</span>
                </a>
                
                <a href="?page=kategori" class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all <?= $page=='kategori' ? 'bg-purple-900/40 text-neon-purple border border-purple-500/30' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i class="fa-solid fa-tags w-5 text-center"></i> <span class="font-bold">Kategori</span>
                </a>

                <?php if($_SESSION['role'] == 'admin'): ?>
                <p class="text-xs text-slate-500 font-bold uppercase tracking-wider mb-2 px-3 mt-6">System</p>
                <a href="?page=pengguna" class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all <?= $page=='pengguna' ? 'bg-red-900/40 text-neon-red border border-red-500/30' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                    <i class="fa-solid fa-users-gear w-5 text-center"></i> <span class="font-bold">Pengguna & Log</span>
                </a>
                <?php endif; ?>
            </nav>

            <div class="p-4 border-t border-slate-800 shrink-0">
                <form method="POST">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" onclick="return confirm('Disconnect dari sistem?')" class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-slate-800 hover:bg-red-900/50 text-slate-300 hover:text-neon-red border border-slate-700 hover:border-neon-red rounded-lg transition-colors font-bold">
                        <i class="fa-solid fa-power-off"></i> Logout
                    </button>
                </form>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="flex-1 flex flex-col min-h-screen overflow-hidden">
            <!-- TOPBAR -->
            <header class="h-20 glass border-b border-slate-800 flex items-center justify-between px-6 sticky top-0 z-30">
                <div class="flex items-center gap-4">
                    <button class="md:hidden text-neon-blue text-2xl"><i class="fa-solid fa-bars"></i></button>
                    <h2 class="text-xl font-mono font-bold text-white capitalize hidden sm:block"><i class="fa-solid fa-angle-right text-neon-purple mr-2"></i> <?= str_replace('_', ' ', $page) ?></h2>
                </div>
                <div class="flex items-center gap-6">
                    <div class="text-right hidden sm:block">
                        <p id="clock" class="text-neon-blue font-mono font-bold text-lg leading-none tracking-widest"></p>
                        <p class="text-xs text-slate-400"><?= date('l, d M Y') ?></p>
                    </div>
                    <div class="h-10 w-px bg-slate-700 hidden sm:block"></div>
                    <a href="?page=pos" class="relative group">
                        <i class="fa-solid fa-cart-shopping text-xl text-slate-300 group-hover:text-neon-blue transition-colors"></i>
                        <?php $cart_count = array_sum(array_column($_SESSION['cart'], 'qty')); if($cart_count > 0): ?>
                            <span class="absolute -top-2 -right-2 bg-neon-red text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full shadow-[0_0_10px_#ff003c]"><?= $cart_count ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </header>

            <!-- PAGE CONTENT -->
            <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 custom-scrollbar">

                <?php if ($page == 'dashboard'): 
                    // Analytics Queries
                    $t_rev = $conn->query("SELECT SUM(total_akhir) as t FROM pesanan")->fetch_assoc()['t'] ?? 0;
                    $t_ord = $conn->query("SELECT COUNT(id) as c FROM pesanan")->fetch_assoc()['c'];
                    $t_prd = $conn->query("SELECT COUNT(id) as c FROM produk")->fetch_assoc()['c'];
                    $t_stk = $conn->query("SELECT SUM(stok) as s FROM produk")->fetch_assoc()['s'] ?? 0;
                    
                    // Chart Data (7 Days Revenue)
                    $chart_data = []; $chart_labels = [];
                    for ($i=6; $i>=0; $i--) {
                        $d = date('Y-m-d', strtotime("-$i days"));
                        $chart_labels[] = date('d M', strtotime($d));
                        $rev = $conn->query("SELECT SUM(total_akhir) as t FROM pesanan WHERE DATE(tanggal) = '$d'")->fetch_assoc()['t'] ?? 0;
                        $chart_data[] = $rev;
                    }
                ?>
                <!-- DASHBOARD -->
                <div class="fade-in">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                        <div class="glass-card p-6 rounded-xl border-t-2 border-t-neon-blue hover:-translate-y-1 transition-transform">
                            <div class="flex justify-between items-start">
                                <div><p class="text-slate-400 text-sm font-bold uppercase">Total Pendapatan</p><h3 class="text-2xl font-mono text-white mt-2"><?= formatRupiah($t_rev) ?></h3></div>
                                <div class="p-3 rounded-lg bg-blue-900/30 text-neon-blue"><i class="fa-solid fa-wallet text-xl"></i></div>
                            </div>
                        </div>
                        <div class="glass-card p-6 rounded-xl border-t-2 border-t-neon-purple hover:-translate-y-1 transition-transform">
                            <div class="flex justify-between items-start">
                                <div><p class="text-slate-400 text-sm font-bold uppercase">Total Transaksi</p><h3 class="text-3xl font-mono text-white mt-2"><?= number_format($t_ord) ?></h3></div>
                                <div class="p-3 rounded-lg bg-purple-900/30 text-neon-purple"><i class="fa-solid fa-receipt text-xl"></i></div>
                            </div>
                        </div>
                        <div class="glass-card p-6 rounded-xl border-t-2 border-t-neon-green hover:-translate-y-1 transition-transform">
                            <div class="flex justify-between items-start">
                                <div><p class="text-slate-400 text-sm font-bold uppercase">Item Terdaftar</p><h3 class="text-3xl font-mono text-white mt-2"><?= number_format($t_prd) ?></h3></div>
                                <div class="p-3 rounded-lg bg-green-900/30 text-neon-green"><i class="fa-solid fa-box-open text-xl"></i></div>
                            </div>
                        </div>
                        <div class="glass-card p-6 rounded-xl border-t-2 border-t-yellow-400 hover:-translate-y-1 transition-transform">
                            <div class="flex justify-between items-start">
                                <div><p class="text-slate-400 text-sm font-bold uppercase">Total Stok Unit</p><h3 class="text-3xl font-mono text-white mt-2"><?= number_format($t_stk) ?></h3></div>
                                <div class="p-3 rounded-lg bg-yellow-900/30 text-yellow-400"><i class="fa-solid fa-cubes text-xl"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-2 glass-card p-6 rounded-xl">
                            <h3 class="text-lg font-bold text-white mb-4 border-b border-slate-700 pb-2"><i class="fa-solid fa-chart-area text-neon-blue mr-2"></i> Grafik Pendapatan (7 Hari)</h3>
                            <canvas id="revChart" height="100"></canvas>
                        </div>
                        <div class="glass-card p-6 rounded-xl overflow-hidden flex flex-col">
                            <h3 class="text-lg font-bold text-white mb-4 border-b border-slate-700 pb-2"><i class="fa-solid fa-bolt text-neon-purple mr-2"></i> Log Aktivitas Terbaru</h3>
                            <div class="flex-1 overflow-y-auto pr-2 space-y-4 custom-scrollbar">
                                <?php 
                                $logs = $conn->query("SELECT l.*, p.username FROM log_aktivitas l JOIN pengguna p ON l.id_pengguna = p.id ORDER BY l.id DESC LIMIT 10");
                                while($l = $logs->fetch_assoc()): 
                                ?>
                                <div class="flex gap-3 text-sm">
                                    <div class="w-2 h-2 rounded-full bg-neon-blue mt-1.5 shrink-0 shadow-[0_0_5px_#00f3ff]"></div>
                                    <div>
                                        <p class="text-white font-bold"><?= $l['username'] ?> <span class="text-slate-400 font-normal"><?= htmlspecialchars($l['aksi']) ?></span></p>
                                        <p class="text-xs text-slate-500 font-mono mt-0.5"><?= date('d/m/Y H:i', strtotime($l['tanggal'])) ?></p>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    const ctx = document.getElementById('revChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: <?= json_encode($chart_labels) ?>,
                            datasets: [{
                                label: 'Pendapatan (Rp)',
                                data: <?= json_encode($chart_data) ?>,
                                borderColor: '#00f3ff',
                                backgroundColor: 'rgba(0, 243, 255, 0.1)',
                                borderWidth: 2, tension: 0.4, fill: true,
                                pointBackgroundColor: '#bc13fe', pointBorderColor: '#fff', pointRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } },
                                x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } }
                            }
                        }
                    });
                </script>

                <?php elseif ($page == 'produk'): ?>
                <!-- KELOLA PRODUK -->
                <div class="fade-in">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                        <h2 class="text-2xl font-mono text-white"><i class="fa-solid fa-database text-neon-purple mr-2"></i> Database Produk</h2>
                        <button onclick="toggleModal('modalProd')" class="btn-neon bg-neon-blue text-slate-900 font-bold px-5 py-2.5 rounded-lg shadow-[0_0_15px_rgba(0,243,255,0.4)]">
                            <i class="fa-solid fa-plus mr-1"></i> Tambah Item
                        </button>
                    </div>

                    <div class="glass-card rounded-xl overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left whitespace-nowrap">
                                <thead class="bg-slate-800/50 border-b border-slate-700 text-neon-blue font-mono text-sm uppercase">
                                    <tr>
                                        <th class="p-4">SKU</th>
                                        <th class="p-4">Item & Kategori</th>
                                        <th class="p-4">Harga</th>
                                        <th class="p-4 text-center">Stok</th>
                                        <th class="p-4 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/50">
                                    <?php 
                                    $prods = $conn->query("SELECT p.*, k.nama_kategori, k.ikon FROM produk p LEFT JOIN kategori k ON p.id_kategori = k.id ORDER BY p.id DESC");
                                    if($prods->num_rows > 0): while($p = $prods->fetch_assoc()): 
                                    ?>
                                    <tr class="hover:bg-slate-800/30 transition-colors">
                                        <td class="p-4 font-mono text-slate-400"><?= $p['kode_sku'] ?></td>
                                        <td class="p-4">
                                            <p class="font-bold text-white"><?= htmlspecialchars($p['nama_produk']) ?></p>
                                            <p class="text-xs text-slate-500 mt-1"><i class="fa-solid <?= $p['ikon'] ?: 'fa-box' ?> mr-1"></i> <?= $p['nama_kategori'] ?: 'Uncategorized' ?></p>
                                        </td>
                                        <td class="p-4 text-neon-green font-mono font-bold"><?= formatRupiah($p['harga']) ?></td>
                                        <td class="p-4 text-center">
                                            <?php if($p['stok'] > 5): ?>
                                                <span class="px-2.5 py-1 rounded-full bg-blue-900/30 text-neon-blue text-xs font-bold border border-blue-500/30"><?= $p['stok'] ?> Unit</span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-1 rounded-full bg-red-900/30 text-neon-red text-xs font-bold border border-red-500/30 animate-pulse"><?= $p['stok'] ?> Limit</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-4 text-center">
                                            <button onclick='editProd(<?= json_encode($p) ?>)' class="w-8 h-8 rounded bg-yellow-500/20 text-yellow-400 hover:bg-yellow-500 hover:text-white transition-colors mr-2"><i class="fa-solid fa-pen"></i></button>
                                            <a href="?action=del_produk&id=<?= $p['id'] ?>" onclick="return confirm('Hapus item ini dari database?')" class="inline-flex items-center justify-center w-8 h-8 rounded bg-red-500/20 text-neon-red hover:bg-neon-red hover:text-white transition-colors"><i class="fa-solid fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="5" class="p-8 text-center text-slate-500">Database kosong.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Modal Produk -->
                <div id="modalProd" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black/80 backdrop-blur-sm p-4">
                    <div class="glass-card p-6 md:p-8 rounded-2xl w-full max-w-2xl border-t-4 border-t-neon-blue fade-in max-h-[90vh] overflow-y-auto custom-scrollbar">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-xl font-mono text-white font-bold" id="mProdTitle">Tambah Produk Baru</h3>
                            <button onclick="toggleModal('modalProd')" class="text-slate-400 hover:text-neon-red text-2xl"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_produk">
                            <input type="hidden" name="id" id="mProdId">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                                <div>
                                    <label class="block text-xs font-bold text-neon-blue uppercase mb-1">Kode SKU</label>
                                    <input type="text" name="kode_sku" id="mProdSku" required class="cyber-input w-full p-2.5 rounded">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-neon-blue uppercase mb-1">Kategori</label>
                                    <select name="id_kategori" id="mProdKat" required class="cyber-input w-full p-2.5 rounded">
                                        <option value="">-- Pilih --</option>
                                        <?php $kats = $conn->query("SELECT * FROM kategori"); while($k = $kats->fetch_assoc()): ?>
                                            <option value="<?= $k['id'] ?>"><?= $k['nama_kategori'] ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-bold text-neon-blue uppercase mb-1">Nama Produk</label>
                                    <input type="text" name="nama_produk" id="mProdNama" required class="cyber-input w-full p-2.5 rounded">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-neon-blue uppercase mb-1">Harga (Rp)</label>
                                    <input type="number" name="harga" id="mProdHarga" required min="0" class="cyber-input w-full p-2.5 rounded">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-neon-blue uppercase mb-1">Stok Awal</label>
                                    <input type="number" name="stok" id="mProdStok" required min="0" class="cyber-input w-full p-2.5 rounded">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-bold text-neon-blue uppercase mb-1">Deskripsi Singkat</label>
                                    <textarea name="deskripsi" id="mProdDesc" rows="3" class="cyber-input w-full p-2.5 rounded"></textarea>
                                </div>
                            </div>
                            <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
                                <button type="button" onclick="toggleModal('modalProd')" class="px-5 py-2.5 rounded bg-slate-800 text-slate-300 hover:bg-slate-700 font-bold">Batal</button>
                                <button type="submit" class="px-5 py-2.5 rounded bg-neon-blue text-slate-900 font-bold hover:bg-cyan-400 shadow-[0_0_15px_rgba(0,243,255,0.4)]">Simpan Data</button>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                    function editProd(data) {
                        document.getElementById('mProdTitle').innerText = 'Edit Data Produk';
                        document.getElementById('mProdId').value = data.id;
                        document.getElementById('mProdSku').value = data.kode_sku;
                        document.getElementById('mProdKat').value = data.id_kategori;
                        document.getElementById('mProdNama').value = data.nama_produk;
                        document.getElementById('mProdHarga').value = data.harga;
                        document.getElementById('mProdStok').value = data.stok;
                        document.getElementById('mProdDesc').value = data.deskripsi;
                        toggleModal('modalProd');
                    }
                </script>

                <?php elseif ($page == 'kategori'): ?>
                <!-- KATEGORI -->
                <div class="fade-in max-w-4xl mx-auto">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-mono text-white"><i class="fa-solid fa-tags text-neon-green mr-2"></i> Kategori Produk</h2>
                        <button onclick="editKat({id:'', nama_kategori:'', ikon:'fa-box'})" class="btn-neon bg-neon-green text-slate-900 font-bold px-5 py-2 rounded-lg shadow-[0_0_15px_rgba(0,255,102,0.4)]">
                            <i class="fa-solid fa-plus"></i> Baru
                        </button>
                    </div>
                    <div class="glass-card rounded-xl overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-slate-800/50 border-b border-slate-700 text-neon-green font-mono text-sm uppercase">
                                <tr><th class="p-4 w-16 text-center">ID</th><th class="p-4">Ikon & Kategori</th><th class="p-4 text-center">Jml Produk</th><th class="p-4 text-center">Aksi</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/50">
                                <?php 
                                $kats = $conn->query("SELECT k.*, COUNT(p.id) as jml FROM kategori k LEFT JOIN produk p ON k.id = p.id_kategori GROUP BY k.id");
                                while($k = $kats->fetch_assoc()): 
                                ?>
                                <tr class="hover:bg-slate-800/30">
                                    <td class="p-4 text-center text-slate-500 font-mono"><?= $k['id'] ?></td>
                                    <td class="p-4 font-bold text-white"><i class="fa-solid <?= $k['ikon'] ?> text-neon-green w-6 text-center mr-2"></i> <?= htmlspecialchars($k['nama_kategori']) ?></td>
                                    <td class="p-4 text-center"><span class="px-2 py-1 bg-slate-800 rounded text-xs"><?= $k['jml'] ?> item</span></td>
                                    <td class="p-4 text-center">
                                        <button onclick='editKat(<?= json_encode($k) ?>)' class="w-8 h-8 rounded bg-yellow-500/20 text-yellow-400 hover:bg-yellow-500 hover:text-white mr-2"><i class="fa-solid fa-pen"></i></button>
                                        <a href="?action=del_kategori&id=<?= $k['id'] ?>" onclick="return confirm('Hapus kategori?')" class="inline-flex items-center justify-center w-8 h-8 rounded bg-red-500/20 text-neon-red hover:bg-neon-red hover:text-white"><i class="fa-solid fa-trash"></i></a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="modalKat" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black/80 p-4">
                    <div class="glass-card p-6 rounded-2xl w-full max-w-md border-t-4 border-t-neon-green fade-in">
                        <h3 class="text-xl font-mono text-white mb-4" id="mKatTitle">Kategori</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_kategori">
                            <input type="hidden" name="id" id="mKatId">
                            <div class="mb-4">
                                <label class="block text-xs font-bold text-neon-green uppercase mb-1">Nama Kategori</label>
                                <input type="text" name="nama_kategori" id="mKatNama" required class="cyber-input w-full p-2.5 rounded">
                            </div>
                            <div class="mb-6">
                                <label class="block text-xs font-bold text-neon-green uppercase mb-1">Class Ikon (FontAwesome)</label>
                                <input type="text" name="ikon" id="mKatIkon" placeholder="fa-box" required class="cyber-input w-full p-2.5 rounded">
                                <p class="text-xs text-slate-500 mt-1">Contoh: fa-mouse, fa-keyboard, dll.</p>
                            </div>
                            <div class="flex justify-end gap-3">
                                <button type="button" onclick="toggleModal('modalKat')" class="px-4 py-2 rounded bg-slate-800 text-white">Batal</button>
                                <button type="submit" class="px-4 py-2 rounded bg-neon-green text-slate-900 font-bold shadow-[0_0_15px_rgba(0,255,102,0.4)]">Simpan</button>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                    function editKat(data) {
                        document.getElementById('mKatTitle').innerText = data.id ? 'Edit Kategori' : 'Tambah Kategori';
                        document.getElementById('mKatId').value = data.id;
                        document.getElementById('mKatNama').value = data.nama_kategori;
                        document.getElementById('mKatIkon').value = data.ikon;
                        toggleModal('modalKat');
                    }
                </script>

                <?php elseif ($page == 'pos'): 
                    $kat_id = $_GET['cat'] ?? '';
                    $search = $conn->real_escape_string($_GET['q'] ?? '');
                    
                    $q = "SELECT p.*, k.ikon FROM produk p LEFT JOIN kategori k ON p.id_kategori=k.id WHERE p.stok > 0";
                    if($kat_id) $q .= " AND p.id_kategori=".(int)$kat_id;
                    if($search) $q .= " AND p.nama_produk LIKE '%$search%'";
                    $prods = $conn->query($q);
                ?>
                <!-- POINT OF SALE (POS) -->
                <div class="flex flex-col lg:flex-row gap-6 fade-in h-full">
                    <!-- Katalog -->
                    <div class="flex-1 flex flex-col min-h-[50vh]">
                        <div class="glass-card p-4 rounded-xl mb-4 flex gap-3">
                            <form method="GET" class="flex-1 relative">
                                <input type="hidden" name="page" value="pos">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400"></i>
                                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari item..." class="cyber-input w-full py-2 pl-10 pr-4 rounded-lg">
                            </form>
                            <a href="?page=pos" class="px-4 py-2 rounded-lg bg-slate-800 text-white hover:bg-slate-700 flex items-center"><i class="fa-solid fa-rotate-right"></i></a>
                        </div>
                        
                        <!-- Kategori Filter -->
                        <div class="flex gap-2 overflow-x-auto pb-2 mb-4 custom-scrollbar shrink-0">
                            <a href="?page=pos" class="px-4 py-2 rounded-full whitespace-nowrap text-sm font-bold border <?= !$kat_id ? 'bg-neon-blue text-slate-900 border-neon-blue shadow-[0_0_10px_rgba(0,243,255,0.4)]' : 'bg-slate-800 text-slate-300 border-slate-700 hover:border-neon-blue' ?>">Semua</a>
                            <?php $kats = $conn->query("SELECT * FROM kategori"); while($k = $kats->fetch_assoc()): ?>
                            <a href="?page=pos&cat=<?= $k['id'] ?>" class="px-4 py-2 rounded-full whitespace-nowrap text-sm font-bold border <?= $kat_id==$k['id'] ? 'bg-neon-blue text-slate-900 border-neon-blue shadow-[0_0_10px_rgba(0,243,255,0.4)]' : 'bg-slate-800 text-slate-300 border-slate-700 hover:border-neon-blue' ?>"><i class="fa-solid <?= $k['ikon'] ?> mr-1"></i> <?= $k['nama_kategori'] ?></a>
                            <?php endwhile; ?>
                        </div>

                        <!-- Grid Item -->
                        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-4 overflow-y-auto pr-2 pb-4 custom-scrollbar">
                            <?php if($prods->num_rows > 0): while($p = $prods->fetch_assoc()): ?>
                            <div class="glass-card rounded-xl p-4 flex flex-col hover:border-neon-blue transition-colors group">
                                <div class="text-center mb-3 h-20 flex items-center justify-center text-4xl text-slate-600 group-hover:text-neon-blue transition-colors drop-shadow-[0_0_8px_rgba(0,243,255,0)] group-hover:drop-shadow-[0_0_8px_rgba(0,243,255,0.5)]">
                                    <i class="fa-solid <?= $p['ikon'] ?: 'fa-box' ?>"></i>
                                </div>
                                <h4 class="font-bold text-white text-sm line-clamp-2 flex-1"><?= htmlspecialchars($p['nama_produk']) ?></h4>
                                <div class="mt-2 flex justify-between items-end">
                                    <div>
                                        <p class="text-xs text-slate-400 font-mono">Stok: <?= $p['stok'] ?></p>
                                        <p class="text-neon-green font-mono font-bold text-sm"><?= formatRupiah($p['harga']) ?></p>
                                    </div>
                                </div>
                                <form method="POST" class="mt-3 flex gap-2">
                                    <input type="hidden" name="action" value="add_to_cart">
                                    <input type="hidden" name="id_produk" value="<?= $p['id'] ?>">
                                    <input type="number" name="qty" value="1" min="1" max="<?= $p['stok'] ?>" class="w-16 cyber-input rounded text-center text-sm px-1 py-1.5">
                                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-neon-blue hover:text-slate-900 text-white rounded text-sm font-bold transition-all"><i class="fa-solid fa-cart-plus"></i> Add</button>
                                </form>
                            </div>
                            <?php endwhile; else: ?>
                                <div class="col-span-full text-center py-10 text-slate-500">Item tidak ditemukan atau stok habis.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Cart / Checkout Panel -->
                    <div class="w-full lg:w-96 glass-card rounded-xl flex flex-col h-[calc(100vh-8rem)] sticky top-24 shrink-0 border-t-4 border-t-neon-purple">
                        <div class="p-4 border-b border-slate-800 flex justify-between items-center bg-slate-900/50 rounded-t-xl">
                            <h3 class="font-mono font-bold text-white text-lg"><i class="fa-solid fa-receipt text-neon-purple mr-2"></i> Current Cart</h3>
                            <?php if(!empty($_SESSION['cart'])): ?>
                                <form method="POST" class="m-0"><input type="hidden" name="action" value="clear_cart"><button type="submit" onclick="return confirm('Kosongkan keranjang?')" class="text-xs text-red-400 hover:text-neon-red"><i class="fa-solid fa-trash-can"></i> Clear</button></form>
                            <?php endif; ?>
                        </div>

                        <div class="flex-1 overflow-y-auto p-2 custom-scrollbar">
                            <?php if(empty($_SESSION['cart'])): ?>
                                <div class="h-full flex flex-col items-center justify-center text-slate-600 opacity-50">
                                    <i class="fa-solid fa-cart-arrow-down text-5xl mb-3"></i>
                                    <p>Keranjang kosong</p>
                                </div>
                            <?php else: ?>
                                <form method="POST" id="cartForm">
                                    <input type="hidden" name="action" value="update_cart">
                                    <ul class="space-y-2">
                                        <?php $subtotal = 0; foreach($_SESSION['cart'] as $id => $item): $sub_item = $item['harga'] * $item['qty']; $subtotal += $sub_item; ?>
                                        <li class="p-3 bg-slate-800/50 border border-slate-700 rounded-lg relative group">
                                            <a href="?action=remove_cart&id=<?= $id ?>" class="absolute top-2 right-2 text-slate-500 hover:text-neon-red"><i class="fa-solid fa-xmark"></i></a>
                                            <p class="text-sm font-bold text-white pr-6 line-clamp-1"><?= $item['nama'] ?></p>
                                            <p class="text-xs text-slate-400 font-mono mb-2"><?= $item['sku'] ?> | <?= formatRupiah($item['harga']) ?></p>
                                            <div class="flex justify-between items-center">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs text-slate-400">Qty:</span>
                                                    <input type="number" name="qty[<?= $id ?>]" value="<?= $item['qty'] ?>" min="0" class="w-16 cyber-input rounded text-center text-sm py-1" onchange="document.getElementById('cartForm').submit()">
                                                </div>
                                                <p class="font-bold text-neon-green font-mono text-sm"><?= formatRupiah($sub_item) ?></p>
                                            </div>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php if(!empty($_SESSION['cart'])): 
                            $pajak = $subtotal * 0.11; 
                            $total = $subtotal + $pajak;
                        ?>
                        <div class="p-4 bg-slate-900 border-t border-slate-800 rounded-b-xl shrink-0">
                            <div class="flex justify-between text-sm text-slate-400 mb-1"><span>Subtotal</span> <span class="font-mono"><?= formatRupiah($subtotal) ?></span></div>
                            <div class="flex justify-between text-sm text-slate-400 mb-3"><span>PPN (11%)</span> <span class="font-mono"><?= formatRupiah($pajak) ?></span></div>
                            <div class="flex justify-between items-end border-t border-slate-700 pt-3 mb-4">
                                <span class="font-bold text-white uppercase tracking-widest text-sm">Total</span>
                                <span class="text-2xl font-bold font-mono text-neon-purple drop-shadow-[0_0_5px_rgba(188,19,254,0.5)]"><?= formatRupiah($total) ?></span>
                            </div>
                            
                            <form method="POST">
                                <input type="hidden" name="action" value="checkout">
                                <div class="mb-3">
                                    <input type="text" name="nama_pelanggan" placeholder="Nama Pelanggan (Opsional)" class="cyber-input w-full px-3 py-2 rounded text-sm mb-2">
                                    <div class="relative">
                                        <span class="absolute left-3 top-2 text-slate-400 font-bold">Rp</span>
                                        <input type="number" name="bayar" required min="<?= $total ?>" placeholder="Nominal Bayar" class="cyber-input w-full pl-10 pr-3 py-2 rounded font-mono font-bold text-neon-green text-lg">
                                    </div>
                                </div>
                                <button type="submit" class="btn-neon w-full bg-neon-purple text-white font-bold py-3 rounded-lg uppercase tracking-widest shadow-[0_0_15px_rgba(188,19,254,0.4)]">
                                    <i class="fa-solid fa-check-double mr-1"></i> Proses Transaksi
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php elseif ($page == 'invoice' && isset($_GET['id'])): 
                    $id_ord = (int)$_GET['id'];
                    $ord = $conn->query("SELECT p.*, u.nama_lengkap as kasir FROM pesanan p JOIN pengguna u ON p.id_pengguna=u.id WHERE p.id=$id_ord")->fetch_assoc();
                    $details = $conn->query("SELECT d.*, pr.nama_produk, pr.kode_sku FROM detail_pesanan d JOIN produk pr ON d.id_produk=pr.id WHERE d.id_pesanan=$id_ord");
                    if(!$ord) die("Invoice tidak ditemukan.");
                ?>
                <!-- INVOICE -->
                <div class="max-w-3xl mx-auto fade-in">
                    <div class="glass-card bg-white text-slate-900 p-8 rounded-xl shadow-2xl relative overflow-hidden" id="printArea">
                        <div class="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-neon-blue to-neon-purple"></div>
                        
                        <div class="flex justify-between items-start mb-8 border-b pb-6">
                            <div>
                                <h1 class="text-3xl font-mono font-bold text-slate-900 tracking-widest">NEXUS<span class="text-neon-purple">OS</span></h1>
                                <p class="text-slate-500 text-sm mt-1">E-Sports Gear & Equipment Store</p>
                                <p class="text-slate-500 text-xs mt-1">Jl. Cybernetica No. 77, Neo-Jakarta</p>
                            </div>
                            <div class="text-right">
                                <h2 class="text-2xl font-bold text-slate-300 uppercase tracking-widest mb-1">Invoice</h2>
                                <p class="font-mono font-bold text-slate-700"><?= $ord['no_invoice'] ?></p>
                                <p class="text-sm text-slate-500 mt-1"><?= date('d M Y, H:i', strtotime($ord['tanggal'])) ?></p>
                            </div>
                        </div>

                        <div class="flex justify-between mb-8 text-sm">
                            <div>
                                <p class="text-slate-500 uppercase font-bold text-xs mb-1">Ditagihkan Kepada:</p>
                                <p class="font-bold text-lg text-slate-800"><?= htmlspecialchars($ord['nama_pelanggan']) ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-slate-500 uppercase font-bold text-xs mb-1">Operator/Kasir:</p>
                                <p class="font-bold text-slate-800"><?= $ord['kasir'] ?></p>
                            </div>
                        </div>

                        <table class="w-full text-left mb-8">
                            <thead class="border-b-2 border-slate-800 font-bold uppercase text-xs text-slate-600">
                                <tr><th class="pb-2">SKU & Item</th><th class="pb-2 text-center">Qty</th><th class="pb-2 text-right">Harga Satuan</th><th class="pb-2 text-right">Subtotal</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <?php while($d = $details->fetch_assoc()): ?>
                                <tr>
                                    <td class="py-3"><p class="font-bold text-slate-800"><?= htmlspecialchars($d['nama_produk']) ?></p><p class="text-xs text-slate-500 font-mono"><?= $d['kode_sku'] ?></p></td>
                                    <td class="py-3 text-center font-bold"><?= $d['kuantitas'] ?></td>
                                    <td class="py-3 text-right font-mono"><?= formatRupiah($d['harga_satuan']) ?></td>
                                    <td class="py-3 text-right font-mono font-bold"><?= formatRupiah($d['subtotal']) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>

                        <div class="w-full flex justify-end">
                            <div class="w-64 space-y-2 text-sm">
                                <div class="flex justify-between text-slate-600"><span>Subtotal:</span> <span class="font-mono"><?= formatRupiah($ord['subtotal']) ?></span></div>
                                <div class="flex justify-between text-slate-600"><span>PPN (11%):</span> <span class="font-mono"><?= formatRupiah($ord['pajak']) ?></span></div>
                                <?php if($ord['diskon'] > 0): ?><div class="flex justify-between text-red-500"><span>Diskon:</span> <span class="font-mono">-<?= formatRupiah($ord['diskon']) ?></span></div><?php endif; ?>
                                <div class="flex justify-between font-bold text-lg border-t-2 border-slate-800 pt-2 text-slate-900 uppercase"><span>Total:</span> <span class="font-mono text-neon-purple"><?= formatRupiah($ord['total_akhir']) ?></span></div>
                                <div class="flex justify-between text-slate-600 mt-4"><span>Tunai:</span> <span class="font-mono"><?= formatRupiah($ord['bayar']) ?></span></div>
                                <div class="flex justify-between text-slate-600 font-bold"><span>Kembali:</span> <span class="font-mono"><?= formatRupiah($ord['kembalian']) ?></span></div>
                            </div>
                        </div>
                        
                        <div class="mt-12 text-center text-slate-400 text-xs border-t pt-4">
                            <p>Terima kasih atas pembelian Anda. Barang yang sudah dibeli tidak dapat ditukar/dikembalikan.</p>
                            <p class="font-mono mt-1">POWERED BY NEXUS ENGINE</p>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-center gap-4">
                        <a href="?page=pos" class="px-6 py-2.5 bg-slate-800 text-white rounded-lg hover:bg-slate-700 font-bold"><i class="fa-solid fa-arrow-left"></i> Kembali ke POS</a>
                        <button onclick="window.print()" class="px-6 py-2.5 bg-neon-blue text-slate-900 rounded-lg font-bold shadow-[0_0_15px_rgba(0,243,255,0.4)]"><i class="fa-solid fa-print"></i> Cetak Struk</button>
                    </div>
                </div>
                <style>@media print { body * { visibility: hidden; } #printArea, #printArea * { visibility: visible; } #printArea { position: absolute; left: 0; top: 0; width: 100%; margin: 0; box-shadow: none; border: none; } body { background: white; } }</style>

                <?php elseif ($page == 'transaksi'): ?>
                <!-- DATA TRANSAKSI -->
                <div class="fade-in">
                    <h2 class="text-2xl font-mono text-white mb-6"><i class="fa-solid fa-file-invoice-dollar text-neon-blue mr-2"></i> Histori Transaksi</h2>
                    <div class="glass-card rounded-xl overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left whitespace-nowrap">
                                <thead class="bg-slate-800/50 border-b border-slate-700 text-neon-blue font-mono text-sm uppercase">
                                    <tr><th class="p-4">Invoice & Tanggal</th><th class="p-4">Pelanggan</th><th class="p-4">Kasir</th><th class="p-4 text-right">Total Transaksi</th><th class="p-4 text-center">Detail</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/50">
                                    <?php 
                                    $ords = $conn->query("SELECT p.*, u.nama_lengkap FROM pesanan p JOIN pengguna u ON p.id_pengguna=u.id ORDER BY p.id DESC");
                                    if($ords->num_rows > 0): while($o = $ords->fetch_assoc()): 
                                    ?>
                                    <tr class="hover:bg-slate-800/30 transition-colors">
                                        <td class="p-4">
                                            <p class="font-mono font-bold text-white"><?= $o['no_invoice'] ?></p>
                                            <p class="text-xs text-slate-500 mt-1"><i class="fa-solid fa-calendar-day mr-1"></i> <?= date('d M Y, H:i', strtotime($o['tanggal'])) ?></p>
                                        </td>
                                        <td class="p-4 font-bold text-slate-300"><?= htmlspecialchars($o['nama_pelanggan']) ?></td>
                                        <td class="p-4 text-sm text-slate-400"><i class="fa-solid fa-user-tie text-neon-purple mr-1"></i> <?= $o['nama_lengkap'] ?></td>
                                        <td class="p-4 text-right text-neon-green font-mono font-bold"><?= formatRupiah($o['total_akhir']) ?></td>
                                        <td class="p-4 text-center">
                                            <a href="?page=invoice&id=<?= $o['id'] ?>" class="inline-flex items-center justify-center w-8 h-8 rounded bg-blue-500/20 text-neon-blue hover:bg-neon-blue hover:text-slate-900 transition-colors"><i class="fa-solid fa-eye"></i></a>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="5" class="p-8 text-center text-slate-500">Belum ada transaksi tercatat.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php elseif ($page == 'pengguna' && $_SESSION['role'] == 'admin'): ?>
                <!-- PENGGUNA (ADMIN) -->
                <div class="fade-in max-w-5xl mx-auto">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-mono text-white"><i class="fa-solid fa-users-gear text-neon-red mr-2"></i> Manajemen Sistem</h2>
                        <button onclick="toggleModal('modalUser')" class="btn-neon bg-neon-red text-white font-bold px-5 py-2 rounded-lg shadow-[0_0_15px_rgba(255,0,60,0.4)]">
                            <i class="fa-solid fa-user-plus"></i> Admin/Kasir Baru
                        </button>
                    </div>
                    <div class="glass-card rounded-xl overflow-hidden mb-8">
                        <table class="w-full text-left">
                            <thead class="bg-slate-800/50 border-b border-slate-700 text-neon-red font-mono text-sm uppercase">
                                <tr><th class="p-4">User Info</th><th class="p-4">Username</th><th class="p-4">Role</th><th class="p-4 text-center">Aksi</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/50">
                                <?php $users = $conn->query("SELECT * FROM pengguna ORDER BY id ASC"); while($u = $users->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-800/30">
                                    <td class="p-4 font-bold text-white"><i class="fa-solid fa-user-shield text-slate-500 mr-2"></i> <?= htmlspecialchars($u['nama_lengkap']) ?></td>
                                    <td class="p-4 text-slate-400 font-mono">@<?= $u['username'] ?></td>
                                    <td class="p-4"><span class="px-2 py-1 bg-slate-800 rounded text-xs uppercase font-bold <?= $u['role']=='admin' ? 'text-neon-red border border-red-500/30' : 'text-neon-blue border border-blue-500/30' ?>"><?= $u['role'] ?></span></td>
                                    <td class="p-4 text-center">
                                        <?php if($u['id'] != $_SESSION['user_id']): ?>
                                        <a href="?action=del_pengguna&id=<?= $u['id'] ?>" onclick="return confirm('Hapus akses pengguna ini?')" class="inline-flex items-center justify-center w-8 h-8 rounded bg-red-500/20 text-neon-red hover:bg-neon-red hover:text-white"><i class="fa-solid fa-trash"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="modalUser" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black/80 p-4">
                    <div class="glass-card p-6 rounded-2xl w-full max-w-md border-t-4 border-t-neon-red fade-in">
                        <h3 class="text-xl font-mono text-white mb-4">Daftar Akun Baru</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_pengguna">
                            <div class="mb-4">
                                <label class="block text-xs font-bold text-neon-red uppercase mb-1">Nama Lengkap</label>
                                <input type="text" name="nama_lengkap" required class="cyber-input w-full p-2.5 rounded">
                            </div>
                            <div class="mb-4">
                                <label class="block text-xs font-bold text-neon-red uppercase mb-1">Username (Login ID)</label>
                                <input type="text" name="username" required class="cyber-input w-full p-2.5 rounded font-mono">
                            </div>
                            <div class="mb-4">
                                <label class="block text-xs font-bold text-neon-red uppercase mb-1">Password Awal</label>
                                <input type="password" name="password" required class="cyber-input w-full p-2.5 rounded">
                            </div>
                            <div class="mb-6">
                                <label class="block text-xs font-bold text-neon-red uppercase mb-1">Role / Jabatan</label>
                                <select name="role" required class="cyber-input w-full p-2.5 rounded uppercase font-bold">
                                    <option value="kasir">Operator Kasir</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>
                            <div class="flex justify-end gap-3">
                                <button type="button" onclick="toggleModal('modalUser')" class="px-4 py-2 rounded bg-slate-800 text-white">Batal</button>
                                <button type="submit" class="px-4 py-2 rounded bg-neon-red text-white font-bold shadow-[0_0_15px_rgba(255,0,60,0.4)]">Daftarkan Akun</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </main>
    <?php endif; ?>

    <script>
        function toggleModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('hidden');
        }
        
        // Live Clock
        function updateClock() {
            const clk = document.getElementById('clock');
            if(clk) {
                const d = new Date();
                clk.innerText = d.getHours().toString().padStart(2, '0') + ':' + 
                                d.getMinutes().toString().padStart(2, '0') + ':' + 
                                d.getSeconds().toString().padStart(2, '0');
            }
        }
        setInterval(updateClock, 1000); updateClock();
    </script>
</body>
</html>
