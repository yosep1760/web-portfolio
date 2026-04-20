import os
import sys

# Tambahkan path folder saat ini agar Python tidak bingung mencari modul
sys.path.append(os.path.join(os.path.dirname(__file__)))

from flask import Flask, jsonify
from flask_cors import CORS
from werkzeug.security import generate_password_hash

# Import konfigurasi dan database
from config import Config
from models import db, Product, User

# Import Blueprints dari folder routes
from routes.auth_routes import auth_bp
from routes.product_routes import product_bp
from routes.cart_routes import cart_bp

app = Flask(__name__)
CORS(app)

# Memuat konfigurasi dari config.py
app.config.from_object(Config)

# --- TRIK KHUSUS VERCEL ---
# Database SQLite disimpan di folder '/tmp/' karena Vercel bersifat Read-Only
if os.environ.get('VERCEL'):
    app.config['SQLALCHEMY_DATABASE_URI'] = 'sqlite:////tmp/database_toko.db'

# Inisialisasi database
db.init_app(app)

# Mendaftarkan rute
app.register_blueprint(auth_bp, url_prefix='/api/auth')
app.register_blueprint(product_bp, url_prefix='/api/products')
app.register_blueprint(cart_bp, url_prefix='/api/cart')

# Setup Database & Data Awal
with app.app_context():
    db.create_all() 
    
    # 1. Cek Produk
    if not Product.query.first():
        dummy_products = [
            Product(
                name="Laptop Gaming ROG", 
                description="Laptop super ngebut untuk gaming.", 
                price=25000000, 
                stock=10, 
                image_url="https://images.unsplash.com/photo-1603302576837-37561b2e2302?w=500"
            ),
            Product(
                name="Keyboard Mechanical RGB", 
                description="Nyaman untuk ngetik seharian.", 
                price=850000, 
                stock=25, 
                image_url="https://images.unsplash.com/photo-1595225476474-87563907a212?w=500"
            )
        ]
        db.session.bulk_save_objects(dummy_products)
        db.session.commit()

    # 2. Tambahkan User Dummy (Penting agar Anda bisa login!)
    if not User.query.filter_by(email='test@gmail.com').first():
        user_test = User(
            username="UserTest",
            email="test@gmail.com",
            password=generate_password_hash("password123")
        )
        db.session.add(user_test)
        db.session.commit()

@app.route('/api/health', methods=['GET'])
def health_check():
    return jsonify({"status": "online", "message": "Backend Toko Online siap!"})

# Perbaikan kurung tutup di bagian ini
if __name__ == '__main__':
    app.run(debug=True, port=5000)