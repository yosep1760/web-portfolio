/* =========================================
   PENGATURAN URL BACKEND
   ========================================= */
// Jika di-hosting di Vercel, kita gunakan '/api' karena frontend dan backend ada di satu domain.
// CATATAN: Jika Anda sedang menguji coba di komputer lokal (Live Server + Python berjalan), 
// ubah BASE_URL menjadi 'http://127.0.0.1:5000/api'
const BASE_URL = '/api'; 

/* =========================================
   FUNGSI-FUNGSI API (Objek Global)
   ========================================= */
const API = {
    
    // 1. Mengambil daftar semua produk dari database
    getProducts: async () => {
        try {
            // Meminta data dengan metode GET (default fetch)
            const response = await fetch(`${BASE_URL}/products/`);
            
            if (!response.ok) {
                throw new Error('Gagal mengambil data produk dari server');
            }
            
            // Mengubah respon text menjadi JSON (Array objek produk)
            return await response.json();
            
        } catch (error) {
            console.error("Error getProducts:", error);
            // Kembalikan array kosong jika terjadi error agar web tidak crash
            return []; 
        }
    },

    // 2. Mengirim data untuk Login
    loginUser: async (email, password) => {
        try {
            const response = await fetch(`${BASE_URL}/auth/login`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json' // Memberitahu server bahwa kita mengirim format JSON
                },
                // Mengubah objek JavaScript menjadi string JSON
                body: JSON.stringify({ email: email, password: password }) 
            });
            
            const data = await response.json();
            
            // Mengembalikan status kode (misal 200 sukses, 401 gagal) beserta pesannya
            return { status: response.status, data: data };
            
        } catch (error) {
            console.error("Error loginUser:", error);
            return { status: 500, data: { message: "Terjadi kesalahan koneksi ke server." } };
        }
    },

    // 3. Mengirim data untuk Checkout Pesanan
    checkoutPesanan: async (userId, totalPrice) => {
        try {
            const response = await fetch(`${BASE_URL}/cart/checkout`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ 
                    user_id: userId, 
                    total_price: totalPrice 
                })
            });
            
            const data = await response.json();
            return { status: response.status, data: data };
            
        } catch (error) {
            console.error("Error checkoutPesanan:", error);
            return { status: 500, data: { message: "Gagal terhubung ke server untuk proses pembayaran." } };
        }
    }
};