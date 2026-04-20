/* =========================================
   1. FUNGSI UTILITAS (Dipakai di semua halaman)
   ========================================= */

// Memperbarui angka pada ikon keranjang di menu navigasi
function updateCartCount() {
    // Mengambil data keranjang dari memori browser (Local Storage)
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    
    // Menghitung total jumlah barang
    const totalItems = cart.reduce((total, item) => total + item.quantity, 0);
    
    // Menampilkan ke layar
    const cartCountElement = document.getElementById('cart-count');
    if (cartCountElement) {
        cartCountElement.innerText = totalItems;
    }
}

// Mengecek status login dan mengubah tampilan tombol navigasi
function checkAuthStatus() {
    const loginBtn = document.getElementById('login-btn');
    const userData = JSON.parse(localStorage.getItem('user'));

    if (loginBtn) {
        if (userData) {
            // Jika sudah login, ubah tombol menjadi Logout dan sapa pengguna
            loginBtn.innerText = `Halo, ${userData.username} (Logout)`;
            loginBtn.href = "#"; // Mencegah pindah halaman
            
            // Tambahkan aksi untuk logout
            loginBtn.addEventListener('click', (e) => {
                e.preventDefault();
                localStorage.removeItem('user'); // Hapus data sesi
                alert("Anda berhasil logout.");
                window.location.reload(); // Refresh halaman
            });
        } else {
            loginBtn.innerText = "Login";
            loginBtn.href = "login.html";
        }
    }
}


/* =========================================
   2. LOGIKA HALAMAN UTAMA (INDEX.HTML)
   ========================================= */

// Memuat daftar produk dari API dan menampilkannya di halaman
async function loadProducts() {
    const productList = document.getElementById('product-list');
    if (!productList) return; // Jika tidak ada elemen ini (berarti bukan di index.html), hentikan fungsi

    // Tampilkan teks loading sementara menunggu data dari server
    productList.innerHTML = '<p style="text-align: center; grid-column: 1 / -1;">Memuat produk dari server...</p>';

    // Panggil fungsi getProducts dari file api.js
    const products = await API.getProducts();

    // Kosongkan area produk
    productList.innerHTML = '';

    if (products.length === 0) {
        productList.innerHTML = '<p style="text-align: center; grid-column: 1 / -1;">Belum ada produk yang tersedia saat ini.</p>';
        return;
    }

    // Buat elemen HTML untuk setiap produk secara dinamis
    products.forEach(product => {
        // Karena harga di database berbentuk angka biasa, kita format jadi Rupiah
        const hargaRupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(product.price);

        // Membuat kotak produk
        const productCard = document.createElement('div');
        productCard.className = 'product-card';
        
        // Memasukkan struktur HTML ke dalam kotak
        productCard.innerHTML = `
            <img src="${product.image_url || 'https://via.placeholder.com/250x200?text=No+Image'}" alt="${product.name}" class="product-image">
            <h4>${product.name}</h4>
            <p class="product-price">${hargaRupiah}</p>
            <p style="font-size: 14px; margin-bottom: 15px; color: #666;">Stok: ${product.stock}</p>
            <button class="btn-tambah" onclick="addToCart(${product.id}, '${product.name}', ${product.price}, '${product.image_url}')">
                Tambah ke Keranjang
            </button>
        `;

        productList.appendChild(productCard);
    });
}

// Fungsi untuk menambahkan barang ke keranjang (Local Storage)
// Fungsi ini dipanggil dari atribut onclick di tombol "Tambah ke Keranjang"
window.addToCart = function(id, name, price, image) {
    // Ambil keranjang lama, atau buat array kosong jika belum ada
    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    
    // Cek apakah barang sudah ada di keranjang
    const existingItemIndex = cart.findIndex(item => item.id === id);
    
    if (existingItemIndex !== -1) {
        // Jika sudah ada, tambah jumlahnya (quantity)
        cart[existingItemIndex].quantity += 1;
    } else {
        // Jika belum ada, masukkan barang baru
        cart.push({
            id: id,
            name: name,
            price: price,
            image: image,
            quantity: 1
        });
    }
    
    // Simpan kembali ke memori browser
    localStorage.setItem('cart', JSON.stringify(cart));
    
    // Perbarui angka di navbar
    updateCartCount();
    
    alert(`${name} berhasil ditambahkan ke keranjang!`);
};


/* =========================================
   3. LOGIKA HALAMAN LOGIN (LOGIN.HTML)
   ========================================= */

function setupLoginForm() {
    const loginForm = document.getElementById('login-form');
    if (!loginForm) return;

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault(); // Mencegah browser me-refresh halaman bawaan formulir

        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;
        const messageBox = document.getElementById('auth-message');
        const submitBtn = loginForm.querySelector('.btn-submit');

        // Ubah tombol jadi loading
        submitBtn.innerText = "Memproses...";
        submitBtn.disabled = true;

        // Panggil fungsi loginUser dari api.js
        const response = await API.loginUser(email, password);

        // Kembalikan tombol ke kondisi semula
        submitBtn.innerText = "Login";
        submitBtn.disabled = false;

        if (response.status === 200) {
            // Jika berhasil, simpan data user ke Local Storage agar sistem ingat dia sudah login
            localStorage.setItem('user', JSON.stringify(response.data.user));
            messageBox.style.color = "green";
            messageBox.innerText = "Login berhasil! Mengalihkan...";
            
            // Pindahkan halaman ke beranda setelah 1 detik
            setTimeout(() => {
                window.location.href = "index.html";
            }, 1000);
        } else {
            // Jika gagal (email/password salah)
            messageBox.style.color = "red";
            messageBox.innerText = response.data.message || "Terjadi kesalahan saat login.";
        }
    });
}


/* =========================================
   4. INISIALISASI (Dijalankan saat halaman dimuat)
   ========================================= */
document.addEventListener('DOMContentLoaded', () => {
    updateCartCount();
    checkAuthStatus();
    loadProducts();
    setupLoginForm();
});