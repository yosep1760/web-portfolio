from flask_sqlalchemy import SQLAlchemy
from datetime import datetime

# Inisialisasi objek database
db = SQLAlchemy()

# 1. Tabel User (Pengguna/Pembeli)
class User(db.Model):
    __tablename__ = 'users'
    
    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(50), nullable=False, unique=True)
    email = db.Column(db.String(120), nullable=False, unique=True)
    password = db.Column(db.String(200), nullable=False) # Nantinya ini akan menyimpan password yang sudah di-hash/enkripsi
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    # Relasi: Satu user bisa memiliki banyak pesanan (One-to-Many)
    orders = db.relationship('Order', backref='customer', lazy=True)

# 2. Tabel Product (Produk/Barang Jualan)
class Product(db.Model):
    __tablename__ = 'products'
    
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(100), nullable=False)
    description = db.Column(db.Text, nullable=True)
    price = db.Column(db.Integer, nullable=False) # Menggunakan Integer karena harga Rupiah tidak pakai desimal
    stock = db.Column(db.Integer, nullable=False, default=0)
    image_url = db.Column(db.String(255), nullable=True) # Link gambar produk
    created_at = db.Column(db.DateTime, default=datetime.utcnow)

# 3. Tabel Order (Pesanan/Transaksi)
class Order(db.Model):
    __tablename__ = 'orders'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False) # Mengambil ID dari tabel users
    total_price = db.Column(db.Integer, nullable=False)
    status = db.Column(db.String(50), default='Pending') # Status: Pending, Dibayar, Dikirim, Selesai
    created_at = db.Column(db.DateTime, default=datetime.utcnow)