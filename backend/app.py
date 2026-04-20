import os
from flask import Flask, jsonify
from flask_cors import CORS

# Import konfigurasi dan database
from config import Config
from models import db, Product

# Import Blueprints dari folder routes
from routes.auth_routes import auth_bp
from routes.product_routes import product_bp
from routes.cart_routes import cart_bp

app = Flask(__name__)
CORS(app)

# Memuat konfigurasi dari config.py
app.config.from_object(Config)

# --- TRIK KHUSUS VERCEL ---
# Vercel tidak bisa menyimpan file database di folder biasa (Read-Only).
# Jadi kita akali dengan menyimpannya di folder sementara '/tmp/' milik server Vercel.
if os.environ.get('VERCEL'):
    app.config['SQLALCHEMY_DATABASE_URI'] = 'sqlite:////tmp/database_toko.db'

# Inisialisasi database dengan aplikasi Flask
db.init_app(app)

# Mendaftarkan rute (Blueprints) yang sudah kita buat
app.register_blueprint(auth_bp, url_prefix='/api/auth')
app.register_blueprint(product_bp, url_prefix='/api/products')
app.register_blueprint(cart_bp, url_prefix='/api/cart')

# Membuat tabel database dan memasukkan produk otomatis jika masih kosong
with app.app_context():
    db.create_all() # Membuat tabel User, Product, dan Order
    
    # Cek apakah tabel Product masih kosong
    if not Product.query.first():
        # Suntikkan 3 produk awal (Data Dummy)
        dummy_products = [
            Product(
                name="Laptop Gaming ROG", 
                description="Laptop super ngebut untuk gaming dan coding.", 
                price=25000000, 
                stock=10, 
                image_url="https://images.unsplash.com/photo-1603302576837-37561b2e2302?auto=format&fit=crop&w=500&q=60"
            ),
            Product(
                name="Keyboard Mechanical RGB", 
                description="Nyaman untuk ngetik seharian.", 
                price=850000, 
                stock=25, 
                image_url="https://images.unsplash.com/photo-1595225476474-87563907a212?auto=format&fit=crop&w=500&q=60"
            ),
            Product(
                name="Mouse Wireless Logitech", 
                description="Tanpa kabel, bebas ribet.", 
                price=450000, 
                stock=30, 
                image_url="https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?auto=format&fit=crop&w=500&q=60"
            )
        ]
        db.session.bulk_save_objects(dummy_products)
        db.session.commit()

# Route untuk cek status server
@app.route('/api/health', methods=['GET'])
def health_check():
    return jsonify({"status": "online", "message": "Backend Toko Online siap!"})

if __name__ == '__main__':
    app.run(debug=True, port=5000)