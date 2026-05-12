<?php
session_start();

// ==========================================
// 1. KONFIGURASI & AUTO-DATABASE GENERATION
// ==========================================
$host = 'localhost';
$user = 'root';
$pass = ''; // Default XAMPP/Laragon biasanya kosong
$db   = 'nexus_gaming';

// Koneksi awal tanpa database untuk membuatnya otomatis
$conn_init = new mysqli($host, $user, $pass);
if ($conn_init->connect_error) {
    die("Koneksi Server Gagal: " . $conn_init->connect_error);
}

// Buat Database jika belum ada
$conn_init->query("CREATE DATABASE IF NOT EXISTS $db");
$conn_init->close();

// Koneksi utama dengan database
$conn = new mysqli($host, $user, $pass, $db);

// Buat Tabel Pengguna
$conn->query("CREATE TABLE IF NOT EXISTS pengguna (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    username VARCHAR(50) NOT NULL, 
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'admin'
)");

// Buat Tabel Produk
$conn->query("CREATE TABLE IF NOT EXISTS produk (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    nama_produk VARCHAR(100) NOT NULL, 
    kategori VARCHAR(50) NOT NULL, 
    harga DECIMAL(12,2) NOT NULL, 
    stok INT NOT NULL,
    gambar_url VARCHAR(255) DEFAULT 'default.png'
)");

// Buat Tabel Pesanan
$conn->query("CREATE TABLE IF NOT EXISTS pesanan (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    id_pengguna INT NOT NULL, 
    total_harga DECIMAL(12,2) NOT NULL, 
    tanggal DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Seeding (Isi Data Awal) jika tabel kosong
$cek_user = $conn->query("SELECT * FROM pengguna");
if ($cek_user->num_rows == 0) {
    // Password default: admin123
    $conn->query("INSERT INTO pengguna (username, password) VALUES ('admin', 'admin123')");
}

$cek_produk = $conn->query("SELECT * FROM produk");
if ($cek_produk->num_rows == 0) {
    $conn->query("INSERT INTO produk (nama_produk, kategori, harga, stok) VALUES ('Razer DeathAdder V2', 'Mouse', 1200000, 15)");
    $conn->query("INSERT INTO produk (nama_produk, kategori, harga, stok) VALUES ('HyperX Cloud II', 'Headset', 1500000, 10)");
    $conn->query("INSERT INTO produk (nama_produk, kategori, harga, stok) VALUES ('Corsair K70 RGB', 'Keyboard', 2500000, 8)");
    $conn->query("INSERT INTO produk (nama_produk, kategori, harga, stok) VALUES ('Secretlab Titan Evo', 'Kursi', 7500000, 5)");
}

// ==========================================
// 2. SISTEM ROUTING & LOGIKA (CONTROLLER)
// ==========================================
$page = $_GET['page'] ?? 'home';

// Proteksi Halaman (Harus Login)
$public_pages = ['login'];
if (!isset($_SESSION['user_id']) && !in_array($page, $public_pages)) {
    $_SESSION['alert'] = ['type' => 'warning', 'title' => 'Akses Ditolak!', 'text' => 'Anda harus login terlebih dahulu.'];
    header("Location: ?page=login");
    exit;
}

// Logika Logout
if ($page == 'logout') {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Logout Berhasil', 'text' => 'Sampai jumpa kembali, Commander.'];
    header("Location: ?page=login");
    exit;
}

// Logika POST (Form Submissions)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    // --- PROSES LOGIN ---
    if ($action == 'login') {
        $username = $conn->real_escape_string($_POST['username']);
        $password = $_POST['password']; // Di dunia nyata gunakan password_verify()

        $result = $conn->query("SELECT * FROM pengguna WHERE username='$username' AND password='$password'");
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Login Berhasil', 'text' => 'Selamat datang di Nexus System.'];
            header("Location: ?page=home");
            exit;
        } else {
            $_SESSION['alert'] = ['type' => 'error', 'title' => 'Akses Gagal', 'text' => 'Kredensial tidak teridentifikasi.'];
            header("Location: ?page=login");
            exit;
        }
    }

    // --- PROSES TAMBAH PRODUK ---
    if ($action == 'tambah_produk') {
        $nama = $conn->real_escape_string($_POST['nama']);
        $kategori = $conn->real_escape_string($_POST['kategori']);
        $harga = $_POST['harga'];
        $stok = $_POST['stok'];

        $conn->query("INSERT INTO produk (nama_produk, kategori, harga, stok) VALUES ('$nama', '$kategori', $harga, $stok)");
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Arsenal Diperbarui!', 'text' => 'Item baru berhasil ditambahkan ke inventaris.'];
        header("Location: ?page=produk");
        exit;
    }

    // --- PROSES EDIT PRODUK ---
    if ($action == 'edit_produk') {
        $id = $_POST['id'];
        $nama = $conn->real_escape_string($_POST['nama']);
        $kategori = $conn->real_escape_string($_POST['kategori']);
        $harga = $_POST['harga'];
        $stok = $_POST['stok'];

        $conn->query("UPDATE produk SET nama_produk='$nama', kategori='$kategori', harga=$harga, stok=$stok WHERE id=$id");
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Data Teredit!', 'text' => 'Spesifikasi item telah diubah.'];
        header("Location: ?page=produk");
        exit;
    }

    // --- PROSES TRANSAKSI (PEMBELIAN) ---
    if ($action == 'checkout') {
        $id_produk = $_POST['id_produk'];
        $jumlah = $_POST['jumlah'];
        $id_user = $_SESSION['user_id'];

        // Cek Stok & Harga
        $res = $conn->query("SELECT harga, stok FROM produk WHERE id=$id_produk");
        if ($res->num_rows > 0) {
            $prod = $res->fetch_assoc();
            if ($prod['stok'] >= $jumlah) {
                $total_harga = $prod['harga'] * $jumlah;
                // Kurangi Stok
                $conn->query("UPDATE produk SET stok = stok - $jumlah WHERE id=$id_produk");
                // Catat Pesanan
                $conn->query("INSERT INTO pesanan (id_pengguna, total_harga) VALUES ($id_user, $total_harga)");
                
                $_SESSION['alert'] = ['type' => 'success', 'title' => 'Transaksi Sukses!', 'text' => 'Item sedang dipersiapkan untuk dikirim.'];
            } else {
                $_SESSION['alert'] = ['type' => 'error', 'title' => 'Stok Menipis', 'text' => 'Kuantitas melebihi stok yang tersedia.'];
            }
        }
        header("Location: ?page=transaksi");
        exit;
    }
}

// Logika GET (Hapus Data)
if (isset($_GET['action']) && $_GET['action'] == 'hapus_produk' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $conn->query("DELETE FROM produk WHERE id=$id");
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Item Dihapus', 'text' => 'Item berhasil dihilangkan dari database.'];
    header("Location: ?page=produk");
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEXUS | E-Sports Gear</title>
    <!-- Tailwind CSS (CDN) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- SweetAlert2 (CDN) -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- ========================================== -->
    <!-- 3. CUSTOM CSS: ANIMASI & GLASSMORPHISM     -->
    <!-- ========================================== -->
    <style>
        :root {
            --neon-blue: #00f3ff;
            --neon-purple: #bc13fe;
            --bg-dark: #070a13;
            --glass-bg: rgba(16, 23, 42, 0.6);
            --glass-border: rgba(0, 243, 255, 0.2);
        }

        body {
            font-family: 'Rajdhani', sans-serif;
            background-color: var(--bg-dark);
            color: #e2e8f0;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(0, 243, 255, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 85% 30%, rgba(188, 19, 254, 0.08) 0%, transparent 50%);
            background-attachment: fixed;
        }

        h1, h2, h3, .font-cyber {
            font-family: 'Orbitron', sans-serif;
        }

        /* Particles Animasi Murni CSS */
        .particles {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: -1;
            background: transparent;
        }
        .particles::after {
            content: ""; position: absolute; top: 0; left: 0; width: 200%; height: 200%;
            background-image: radial-gradient(rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: moveBackground 30s linear infinite;
        }
        @keyframes moveBackground {
            0% { transform: translate(0, 0); }
            100% { transform: translate(-50px, -50px); }
        }

        /* Efek Kaca (Glassmorphism) */
        .glass-panel {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }

        /* Neon Glow Hover */
        .neon-border:hover {
            box-shadow: 0 0 15px var(--neon-blue), inset 0 0 10px var(--neon-blue);
            border-color: var(--neon-blue);
        }
        
        .neon-text {
            text-shadow: 0 0 5px var(--neon-blue), 0 0 10px var(--neon-blue);
        }

        /* Animasi Masuk */
        .animate-fade-in-up {
            animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        
        /* Staggered Delay untuk Grid */
        .delay-100 { animation-delay: 100ms; }
        .delay-200 { animation-delay: 200ms; }
        .delay-300 { animation-delay: 300ms; }

        @keyframes fadeInUp {
            to { opacity: 1; transform: translateY(0); }
        }

        /* Input Kustom Cyberpunk */
        .cyber-input {
            background: rgba(0,0,0,0.5);
            border: 1px solid rgba(0, 243, 255, 0.3);
            color: var(--neon-blue);
            transition: all 0.3s ease;
        }
        .cyber-input:focus {
            outline: none;
            border-color: var(--neon-blue);
            box-shadow: 0 0 10px rgba(0, 243, 255, 0.5);
        }

        /* Tabel Kustom */
        table { border-collapse: separate; border-spacing: 0 8px; }
        tr { transition: transform 0.2s; }
        tbody tr:hover { transform: scale(1.01); background: rgba(0, 243, 255, 0.05); }
        td { padding: 16px; }
        td:first-child { border-top-left-radius: 8px; border-bottom-left-radius: 8px; }
        td:last-child { border-top-right-radius: 8px; border-bottom-right-radius: 8px; }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col">
    <!-- Background Particles -->
    <div class="particles"></div>

    <?php
    // ==========================================
    // 4. NOTIFIKASI SYSTEM (SWEETALERT2)
    // ==========================================
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: '{$alert['type']}',
                    title: '{$alert['title']}',
                    text: '{$alert['text']}',
                    background: '#0f172a',
                    color: '#00f3ff',
                    confirmButtonColor: '#bc13fe',
                    customClass: { popup: 'border border-cyan-500 shadow-[0_0_15px_#00f3ff]' }
                });
            });
        </script>";
        unset($_SESSION['alert']);
    }
    ?>

    <!-- ========================================== -->
    <!-- 5. TAMPILAN HALAMAN (VIEWS)                -->
    <!-- ========================================== -->

    <?php if ($page == 'login'): ?>
        <!-- HALAMAN LOGIN -->
        <div class="flex-grow flex items-center justify-center p-4">
            <div class="glass-panel p-10 rounded-2xl w-full max-w-md animate-fade-in-up border-t-4 border-t-cyan-400 relative overflow-hidden">
                <!-- Dekorasi Garis -->
                <div class="absolute top-0 right-0 w-16 h-1 bg-purple-500"></div>
                <div class="absolute bottom-0 left-0 w-16 h-1 bg-cyan-500"></div>

                <div class="text-center mb-8">
                    <i class="fa-solid fa-gamepad text-5xl text-cyan-400 mb-3 drop-shadow-[0_0_10px_#00f3ff]"></i>
                    <h1 class="text-3xl font-bold font-cyber tracking-wider text-white">NEXUS<span class="text-cyan-400">GEAR</span></h1>
                    <p class="text-gray-400 text-sm mt-1 uppercase tracking-widest">Admin Authorization Portal</p>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-5 relative">
                        <i class="fa-solid fa-user absolute left-4 top-3.5 text-cyan-500"></i>
                        <input type="text" name="username" placeholder="Username (ex: admin)" required 
                               class="cyber-input w-full py-3 pl-12 pr-4 rounded-lg font-bold tracking-wide">
                    </div>
                    <div class="mb-8 relative">
                        <i class="fa-solid fa-lock absolute left-4 top-3.5 text-cyan-500"></i>
                        <input type="password" name="password" placeholder="Password (ex: admin123)" required 
                               class="cyber-input w-full py-3 pl-12 pr-4 rounded-lg font-bold tracking-wide">
                    </div>
                    <button type="submit" class="w-full bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-purple-500 text-white font-cyber font-bold py-3 rounded-lg uppercase tracking-widest transition-all duration-300 shadow-[0_0_15px_rgba(0,243,255,0.4)] hover:shadow-[0_0_25px_rgba(188,19,254,0.6)] transform hover:-translate-y-1">
                        INITIALIZE LOGIN <i class="fa-solid fa-arrow-right ml-2"></i>
                    </button>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- NAVIGATION BAR (Jika Sudah Login) -->
        <nav class="glass-panel sticky top-0 z-50 border-b border-cyan-500/30">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-vr-cardboard text-3xl text-cyan-400 animate-pulse"></i>
                        <span class="font-cyber font-bold text-2xl tracking-widest text-white">NEXUS<span class="text-cyan-400">GEAR</span></span>
                    </div>
                    <div class="hidden md:block">
                        <div class="ml-10 flex items-baseline space-x-6">
                            <a href="?page=home" class="<?= $page=='home' ? 'text-cyan-400 border-b-2 border-cyan-400' : 'text-gray-300 hover:text-white' ?> px-3 py-2 font-bold text-lg transition-colors"><i class="fa-solid fa-house mr-1"></i> Command Center</a>
                            <a href="?page=produk" class="<?= $page=='produk' ? 'text-cyan-400 border-b-2 border-cyan-400' : 'text-gray-300 hover:text-white' ?> px-3 py-2 font-bold text-lg transition-colors"><i class="fa-solid fa-box-open mr-1"></i> Arsenal (Produk)</a>
                            <a href="?page=transaksi" class="<?= $page=='transaksi' ? 'text-cyan-400 border-b-2 border-cyan-400' : 'text-gray-300 hover:text-white' ?> px-3 py-2 font-bold text-lg transition-colors"><i class="fa-solid fa-cart-shopping mr-1"></i> Transaksi</a>
                        </div>
                    </div>
                    <div>
                        <a href="?page=logout" onclick="return confirm('Memutuskan koneksi dari sistem. Anda yakin?');" class="bg-red-500/20 text-red-400 hover:bg-red-500 hover:text-white border border-red-500/50 px-4 py-2 rounded-lg font-bold transition-all duration-300 shadow-[0_0_10px_rgba(239,68,68,0.2)]">
                            <i class="fa-solid fa-power-off"></i> Disconnect
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <!-- MAIN CONTENT CONTAINER -->
        <main class="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full">

            <?php if ($page == 'home'): 
                // Hitung Statistik
                $tot_produk = $conn->query("SELECT COUNT(*) as c FROM produk")->fetch_assoc()['c'];
                $tot_stok = $conn->query("SELECT SUM(stok) as c FROM produk")->fetch_assoc()['c'];
                $tot_omzet = $conn->query("SELECT SUM(total_harga) as c FROM pesanan")->fetch_assoc()['c'];
            ?>
                <!-- HALAMAN DASHBOARD -->
                <div class="animate-fade-in-up">
                    <h2 class="font-cyber text-3xl mb-2 text-white">System <span class="text-cyan-400">Overview</span></h2>
                    <p class="text-gray-400 mb-8 font-bold">Selamat bertugas, Operator <?= strtoupper($_SESSION['username']) ?>.</p>

                    <!-- Statistik Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                        <div class="glass-panel p-6 rounded-xl neon-border transform hover:-translate-y-2 transition-all duration-300">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-cyan-400 font-bold uppercase tracking-wider mb-1">Total Item Aktif</p>
                                    <h3 class="text-4xl font-cyber text-white"><?= $tot_produk ?></h3>
                                </div>
                                <i class="fa-solid fa-microchip text-5xl text-purple-500/50"></i>
                            </div>
                        </div>
                        <div class="glass-panel p-6 rounded-xl neon-border delay-100 transform hover:-translate-y-2 transition-all duration-300">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-cyan-400 font-bold uppercase tracking-wider mb-1">Unit Tersedia</p>
                                    <h3 class="text-4xl font-cyber text-white"><?= $tot_stok ?? 0 ?></h3>
                                </div>
                                <i class="fa-solid fa-cubes text-5xl text-blue-500/50"></i>
                            </div>
                        </div>
                        <div class="glass-panel p-6 rounded-xl neon-border delay-200 transform hover:-translate-y-2 transition-all duration-300">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-cyan-400 font-bold uppercase tracking-wider mb-1">Total Pendapatan</p>
                                    <h3 class="text-3xl font-cyber text-white">Rp <?= number_format($tot_omzet ?? 0, 0, ',', '.') ?></h3>
                                </div>
                                <i class="fa-solid fa-wallet text-5xl text-green-500/50"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Welcome Banner -->
                    <div class="glass-panel p-10 rounded-2xl relative overflow-hidden delay-300 animate-fade-in-up border-l-4 border-purple-500">
                        <div class="absolute top-0 right-0 -mt-10 -mr-10 opacity-10">
                            <i class="fa-solid fa-dragon text-9xl"></i>
                        </div>
                        <h2 class="text-2xl font-cyber text-white mb-3">Nexus Engine v2.0 <span class="text-green-400 text-sm align-middle bg-green-400/20 px-2 py-1 rounded">ONLINE</span></h2>
                        <p class="text-gray-300 text-lg leading-relaxed max-w-2xl">Sistem manajemen inventory canggih untuk mengontrol pergerakan perlengkapan E-Sports. Navigasi menuju menu <span class="text-cyan-400 font-bold">Arsenal</span> untuk menambah stok senjata (produk), atau masuk ke <span class="text-purple-400 font-bold">Transaksi</span> untuk menyelesaikan misi penjualan.</p>
                    </div>
                </div>

            <?php elseif ($page == 'produk'): ?>
                <!-- HALAMAN PRODUK -->
                <div class="animate-fade-in-up flex justify-between items-end mb-6">
                    <div>
                        <h2 class="font-cyber text-3xl text-white">Database <span class="text-purple-400">Arsenal</span></h2>
                        <p class="text-gray-400 font-bold">Manajemen Item & Peralatan Tempur</p>
                    </div>
                    <button onclick="document.getElementById('modal-tambah').classList.remove('hidden')" class="bg-cyan-500 hover:bg-cyan-400 text-slate-900 font-bold py-2 px-6 rounded-lg shadow-[0_0_15px_rgba(0,243,255,0.4)] transition-all">
                        <i class="fa-solid fa-plus mr-2"></i> Tambah Item
                    </button>
                </div>

                <div class="glass-panel rounded-xl overflow-hidden animate-fade-in-up delay-100 p-1">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-separate" style="border-spacing: 0 4px;">
                            <thead>
                                <tr class="text-cyan-400 font-cyber tracking-wider uppercase text-sm">
                                    <th class="p-4 border-b border-cyan-500/20">ID</th>
                                    <th class="p-4 border-b border-cyan-500/20">Nama Item</th>
                                    <th class="p-4 border-b border-cyan-500/20">Kategori</th>
                                    <th class="p-4 border-b border-cyan-500/20">Harga</th>
                                    <th class="p-4 border-b border-cyan-500/20">Stok</th>
                                    <th class="p-4 border-b border-cyan-500/20 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-300 font-bold">
                                <?php
                                $produks = $conn->query("SELECT * FROM produk ORDER BY id DESC");
                                while ($row = $produks->fetch_assoc()):
                                ?>
                                <tr class="bg-slate-800/40 hover:bg-slate-700/50 transition-colors">
                                    <td class="p-4 text-cyan-500">#<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT) ?></td>
                                    <td class="p-4 text-white"><?= htmlspecialchars($row['nama_produk']) ?></td>
                                    <td class="p-4"><span class="bg-purple-500/20 text-purple-300 px-3 py-1 rounded-full text-sm border border-purple-500/30"><?= $row['kategori'] ?></span></td>
                                    <td class="p-4 text-green-400">Rp <?= number_format($row['harga'], 0, ',', '.') ?></td>
                                    <td class="p-4">
                                        <?php if($row['stok'] > 5): ?>
                                            <span class="text-blue-400"><i class="fa-solid fa-battery-full mr-1"></i> <?= $row['stok'] ?></span>
                                        <?php else: ?>
                                            <span class="text-red-400 animate-pulse"><i class="fa-solid fa-battery-quarter mr-1"></i> <?= $row['stok'] ?> (Low)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4 text-center">
                                        <button onclick="editProduk(<?= $row['id'] ?>, '<?= addslashes($row['nama_produk']) ?>', '<?= $row['kategori'] ?>', <?= $row['harga'] ?>, <?= $row['stok'] ?>)" class="text-yellow-400 hover:text-yellow-300 bg-yellow-400/10 p-2 rounded mr-2 transition-colors">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <a href="?action=hapus_produk&id=<?= $row['id'] ?>" onclick="return confirm('Data akan dilenyapkan dari database. Lanjutkan?')" class="text-red-400 hover:text-red-300 bg-red-400/10 p-2 rounded transition-colors">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- MODAL TAMBAH PRODUK -->
                <div id="modal-tambah" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/80 backdrop-blur-sm">
                    <div class="glass-panel p-8 rounded-2xl w-full max-w-lg border-t-4 border-t-cyan-500 animate-fade-in-up">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="font-cyber text-2xl text-white">Input <span class="text-cyan-400">Item Baru</span></h3>
                            <button onclick="document.getElementById('modal-tambah').classList.add('hidden')" class="text-gray-400 hover:text-white"><i class="fa-solid fa-xmark text-2xl"></i></button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="tambah_produk">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-cyan-400 text-sm font-bold mb-1 uppercase tracking-wider">Nama Item</label>
                                    <input type="text" name="nama" required class="cyber-input w-full py-2 px-3 rounded">
                                </div>
                                <div>
                                    <label class="block text-cyan-400 text-sm font-bold mb-1 uppercase tracking-wider">Kategori</label>
                                    <select name="kategori" required class="cyber-input w-full py-2 px-3 rounded">
                                        <option value="Mouse">Mouse</option>
                                        <option value="Keyboard">Keyboard</option>
                                        <option value="Headset">Headset</option>
                                        <option value="Monitor">Monitor</option>
                                        <option value="Kursi">Kursi Gaming</option>
                                        <option value="Aksesoris">Aksesoris</option>
                                    </select>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-cyan-400 text-sm font-bold mb-1 uppercase tracking-wider">Harga (Rp)</label>
                                        <input type="number" name="harga" required class="cyber-input w-full py-2 px-3 rounded">
                                    </div>
                                    <div>
                                        <label class="block text-cyan-400 text-sm font-bold mb-1 uppercase tracking-wider">Stok Unit</label>
                                        <input type="number" name="stok" required class="cyber-input w-full py-2 px-3 rounded">
                                    </div>
                                </div>
                                <button type="submit" class="w-full mt-4 bg-cyan-500 hover:bg-cyan-400 text-slate-900 font-bold py-3 rounded uppercase tracking-widest shadow-[0_0_15px_rgba(0,243,255,0.4)] transition-all">Upload Data</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- MODAL EDIT PRODUK -->
                <div id="modal-edit" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/80 backdrop-blur-sm">
                    <div class="glass-panel p-8 rounded-2xl w-full max-w-lg border-t-4 border-t-yellow-400 animate-fade-in-up">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="font-cyber text-2xl text-white">Update <span class="text-yellow-400">Spesifikasi</span></h3>
                            <button onclick="document.getElementById('modal-edit').classList.add('hidden')" class="text-gray-400 hover:text-white"><i class="fa-solid fa-xmark text-2xl"></i></button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="edit_produk">
                            <input type="hidden" name="id" id="edit_id">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-yellow-400 text-sm font-bold mb-1 uppercase tracking-wider">Nama Item</label>
                                    <input type="text" name="nama" id="edit_nama" required class="cyber-input w-full py-2 px-3 rounded">
                                </div>
                                <div>
                                    <label class="block text-yellow-400 text-sm font-bold mb-1 uppercase tracking-wider">Kategori</label>
                                    <select name="kategori" id="edit_kategori" required class="cyber-input w-full py-2 px-3 rounded">
                                        <option value="Mouse">Mouse</option>
                                        <option value="Keyboard">Keyboard</option>
                                        <option value="Headset">Headset</option>
                                        <option value="Monitor">Monitor</option>
                                        <option value="Kursi">Kursi Gaming</option>
                                        <option value="Aksesoris">Aksesoris</option>
                                    </select>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-yellow-400 text-sm font-bold mb-1 uppercase tracking-wider">Harga (Rp)</label>
                                        <input type="number" name="harga" id="edit_harga" required class="cyber-input w-full py-2 px-3 rounded">
                                    </div>
                                    <div>
                                        <label class="block text-yellow-400 text-sm font-bold mb-1 uppercase tracking-wider">Stok Unit</label>
                                        <input type="number" name="stok" id="edit_stok" required class="cyber-input w-full py-2 px-3 rounded">
                                    </div>
                                </div>
                                <button type="submit" class="w-full mt-4 bg-yellow-400 hover:bg-yellow-300 text-slate-900 font-bold py-3 rounded uppercase tracking-widest shadow-[0_0_15px_rgba(250,204,21,0.4)] transition-all">Simpan Kalibrasi</button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    function editProduk(id, nama, kategori, harga, stok) {
                        document.getElementById('edit_id').value = id;
                        document.getElementById('edit_nama').value = nama;
                        document.getElementById('edit_kategori').value = kategori;
                        document.getElementById('edit_harga').value = harga;
                        document.getElementById('edit_stok').value = stok;
                        document.getElementById('modal-edit').classList.remove('hidden');
                    }
                </script>

            <?php elseif ($page == 'transaksi'): ?>
                <!-- HALAMAN TRANSAKSI -->
                <div class="animate-fade-in-up mb-6">
                    <h2 class="font-cyber text-3xl text-white">Checkout <span class="text-green-400">Terminal</span></h2>
                    <p class="text-gray-400 font-bold">Proses pembelian item oleh pelanggan</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Form Beli -->
                    <div class="lg:col-span-1 animate-fade-in-up delay-100">
                        <div class="glass-panel p-6 rounded-xl border-t-4 border-t-green-500 relative">
                            <h3 class="font-cyber text-xl text-white mb-4"><i class="fa-solid fa-barcode text-green-400 mr-2"></i> Scan Item</h3>
                            <form method="POST">
                                <input type="hidden" name="action" value="checkout">
                                <div class="mb-4">
                                    <label class="block text-green-400 text-sm font-bold mb-1 uppercase">Pilih Peralatan</label>
                                    <select name="id_produk" required class="cyber-input w-full py-2 px-3 rounded">
                                        <option value="">-- Pilih Target --</option>
                                        <?php
                                        $prods = $conn->query("SELECT * FROM produk WHERE stok > 0");
                                        while($p = $prods->fetch_assoc()):
                                        ?>
                                            <option value="<?= $p['id'] ?>"><?= $p['nama_produk'] ?> - Rp <?= number_format($p['harga'],0,',','.') ?> (Sisa: <?= $p['stok'] ?>)</option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="mb-6">
                                    <label class="block text-green-400 text-sm font-bold mb-1 uppercase">Kuantitas Deploy</label>
                                    <input type="number" name="jumlah" min="1" value="1" required class="cyber-input w-full py-2 px-3 rounded">
                                </div>
                                <button type="submit" class="w-full bg-green-500 hover:bg-green-400 text-slate-900 font-bold py-3 rounded-lg uppercase tracking-widest shadow-[0_0_15px_rgba(34,197,94,0.4)] transition-all flex justify-center items-center">
                                    <i class="fa-solid fa-satellite-dish mr-2 animate-pulse"></i> Transmit Order
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Log Transaksi Terakhir -->
                    <div class="lg:col-span-2 animate-fade-in-up delay-200">
                        <div class="glass-panel p-6 rounded-xl">
                            <h3 class="font-cyber text-xl text-white mb-4"><i class="fa-solid fa-clock-rotate-left text-cyan-400 mr-2"></i> Log Transaksi Terakhir</h3>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-separate" style="border-spacing: 0 4px;">
                                    <thead>
                                        <tr class="text-cyan-400 font-cyber text-sm">
                                            <th class="p-3 border-b border-cyan-500/20">ID Order</th>
                                            <th class="p-3 border-b border-cyan-500/20">Operator</th>
                                            <th class="p-3 border-b border-cyan-500/20">Total Nilai</th>
                                            <th class="p-3 border-b border-cyan-500/20">Timestamp</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-gray-300 text-sm font-bold">
                                        <?php
                                        $logs = $conn->query("SELECT p.id, u.username, p.total_harga, p.tanggal FROM pesanan p JOIN pengguna u ON p.id_pengguna = u.id ORDER BY p.id DESC LIMIT 5");
                                        if($logs->num_rows > 0):
                                            while($log = $logs->fetch_assoc()):
                                        ?>
                                        <tr class="bg-slate-800/40 hover:bg-slate-700/50">
                                            <td class="p-3 text-cyan-500">#ORD-<?= str_pad($log['id'], 5, '0', STR_PAD_LEFT) ?></td>
                                            <td class="p-3"><i class="fa-solid fa-user-astronaut text-purple-400 mr-1"></i> <?= strtoupper($log['username']) ?></td>
                                            <td class="p-3 text-green-400">Rp <?= number_format($log['total_harga'], 0, ',', '.') ?></td>
                                            <td class="p-3 text-gray-400"><?= date('d M Y, H:i', strtotime($log['tanggal'])) ?></td>
                                        </tr>
                                        <?php 
                                            endwhile; 
                                        else: 
                                        ?>
                                        <tr><td colspan="4" class="text-center p-5 text-gray-500 italic">Belum ada log transaksi.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </main>
    <?php endif; ?>
    
</body>
</html>
