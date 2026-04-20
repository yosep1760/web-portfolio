from flask import Flask, jsonify
from flask_cors import CORS

# Inisialisasi aplikasi Flask
app = Flask(__name__)

# Mengaktifkan CORS agar frontend bisa mengakses API ini
# Ini sangat penting agar tidak terjadi error 'Block by CORS' saat hosting
CORS(app)

# Konfigurasi aplikasi
app.config['JSON_SORT_KEYS'] = False # Menjaga urutan data JSON agar tidak berantakan

# --- ROUTE / ENDPOINT ---

# 1. Endpoint untuk mengecek apakah server sudah online
@app.route('/api/health', methods=['GET'])
def health_check():
    return jsonify({
        "status": "online",
        "message": "Backend Toko Online siap digunakan!",
        "version": "1.0.0"
    })

# 2. Contoh Endpoint Produk (Data sementara sebelum pakai database)
@app.route('/api/products', methods=['GET'])
def get_products():
    # Data dummy untuk testing awal
    products = [
        {"id": 1, "name": "Laptop Gaming", "price": 15000000, "image": "laptop.jpg"},
        {"id": 2, "name": "Mouse Wireless", "price": 250000, "image": "mouse.jpg"},
        {"id": 3, "name": "Keyboard Mechanical", "price": 750000, "image": "keyboard.jpg"}
    ]
    return jsonify(products)

# Bagian ini sangat penting untuk testing lokal
if __name__ == '__main__':
    app.run(debug=True, port=5000)