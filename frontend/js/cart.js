// File: frontend/js/cart.js

document.addEventListener('DOMContentLoaded', () => {
    tampilkanKeranjang();
    setupCheckout();
});

function tampilkanKeranjang() {
    const cartItemsContainer = document.getElementById('cart-items');
    const cartTotalElement = document.getElementById('cart-total');
    if (!cartItemsContainer) return; // Hentikan jika bukan di halaman cart.html

    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    cartItemsContainer.innerHTML = ''; // Kosongkan kontainer

    if (cart.length === 0) {
        cartItemsContainer.innerHTML = '<p class="empty-cart-msg">Keranjang Anda masih kosong. Yuk belanja!</p>';
        cartTotalElement.innerText = 'Rp 0';
        return;
    }

    let totalHarga = 0;

    cart.forEach((item, index) => {
        // Hitung subtotal (harga x jumlah)
        const subtotal = item.price * item.quantity;
        totalHarga += subtotal;

        const hargaRupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(item.price);
        const subtotalRupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(subtotal);

        const cartItem = document.createElement('div');
        cartItem.className = 'cart-item';
        cartItem.innerHTML = `
            <div style="display: flex; align-items: center; gap: 15px;">
                <img src="${item.image || 'https://via.placeholder.com/80?text=No+Image'}" alt="${item.name}" style="width: 80px; height: 80px; object-fit: cover; border-radius: 5px;">
                <div class="item-details">
                    <h4>${item.name}</h4>
                    <p style="color: #666;">${hargaRupiah} x ${item.quantity}</p>
                    <p style="font-weight: bold; color: var(--primary-color); margin-top: 5px;">Subtotal: ${subtotalRupiah}</p>
                </div>
            </div>
            <button class="btn-hapus" onclick="hapusDariKeranjang(${index})">Hapus</button>
        `;
        cartItemsContainer.appendChild(cartItem);
    });

    cartTotalElement.innerText = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(totalHarga);
}

// Menjadikan fungsi hapus bersifat global
window.hapusDariKeranjang = function(index) {
    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    cart.splice(index, 1); // Hapus 1 barang dari array berdasarkan posisinya
    localStorage.setItem('cart', JSON.stringify(cart));
    
    tampilkanKeranjang(); // Render ulang tampilan keranjang
    if (typeof updateCartCount === "function") updateCartCount(); // Update angka di navbar (dari main.js)
};

function setupCheckout() {
    const btnCheckout = document.getElementById('btn-checkout');
    if (!btnCheckout) return;

    btnCheckout.addEventListener('click', async () => {
        const cart = JSON.parse(localStorage.getItem('cart')) || [];
        const user = JSON.parse(localStorage.getItem('user'));
        const messageBox = document.getElementById('checkout-message');

        if (cart.length === 0) {
            messageBox.style.color = "red";
            messageBox.innerText = "Keranjang masih kosong!";
            return;
        }

        if (!user) {
            messageBox.style.color = "red";
            messageBox.innerText = "Silakan login terlebih dahulu untuk checkout.";
            setTimeout(() => { window.location.href = "login.html"; }, 1500);
            return;
        }

        btnCheckout.innerText = "Memproses...";
        btnCheckout.disabled = true;

        // Hitung total harga murni (angka)
        const totalHarga = cart.reduce((total, item) => total + (item.price * item.quantity), 0);

        // Panggil API Checkout (Fungsi ini ada di api.js)
        const response = await API.checkoutPesanan(user.id, totalHarga);

        btnCheckout.innerText = "Checkout Sekarang";
        btnCheckout.disabled = false;

        if (response.status === 201) {
            messageBox.style.color = "green";
            messageBox.innerText = "Pesanan berhasil dibuat! Terima kasih.";
            
            // Kosongkan keranjang setelah berhasil bayar
            localStorage.removeItem('cart');
            
            // Perbarui tampilan setelah 2 detik
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            messageBox.style.color = "red";
            messageBox.innerText = response.data.message || "Gagal melakukan checkout.";
        }
    });
}