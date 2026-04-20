from flask import Blueprint, request, jsonify
from models import db, Product

# Membuat Blueprint untuk rute produk
product_bp = Blueprint('product_bp', __name__)

# 1. Endpoint untuk mengambil SEMUA daftar produk (katalog)
@product_bp.route('/', methods=['GET'])
def get_all_products():
    # Mengambil semua baris data dari tabel Product
    products = Product.query.all()
    
    # Mengubah format data database (objek Python) menjadi format JSON (list of dictionaries)
    product_list = []
    for p in products:
        product_list.append({
            'id': p.id,
            'name': p.name,
            'description': p.description,
            'price': p.price,
            'stock': p.stock,
            'image_url': p.image_url
        })
        
    return jsonify(product_list), 200


# 2. Endpoint untuk mengambil DETAIL SATU produk berdasarkan ID
@product_bp.route('/<int:product_id>', methods=['GET'])
def get_product_detail(product_id):
    # Mencari produk yang ID-nya cocok
    product = Product.query.get(product_id)
    
    # Jika ID tidak ditemukan di database
    if not product:
        return jsonify({'message': 'Produk tidak ditemukan!'}), 404
        
    # Jika ditemukan, kirimkan detail lengkapnya
    return jsonify({
        'id': product.id,
        'name': product.name,
        'description': product.description,
        'price': product.price,
        'stock': product.stock,
        'image_url': product.image_url
    }), 200


# 3. Endpoint untuk MENAMBAHKAN produk baru (Biasanya dipakai oleh Admin toko)
@product_bp.route('/', methods=['POST'])
def add_product():
    data = request.get_json()
    
    # Validasi: Nama dan harga wajib diisi
    if not data or not data.get('name') or not data.get('price'):
        return jsonify({'message': 'Nama dan harga produk wajib diisi!'}), 400
        
    # Membuat objek produk baru (deskripsi, stok, dan gambar bersifat opsional)
    new_product = Product(
        name=data['name'],
        description=data.get('description', ''),
        price=data['price'],
        stock=data.get('stock', 0), # Jika tidak diisi, stok awal adalah 0
        image_url=data.get('image_url', '')
    )
    
    try:
        # Menyimpan ke database
        db.session.add(new_product)
        db.session.commit()
        return jsonify({
            'message': 'Produk berhasil ditambahkan ke katalog!',
            'product_id': new_product.id
        }), 201
        
    except Exception as e:
        db.session.rollback()
        return jsonify({'message': 'Gagal menambahkan produk.', 'error': str(e)}), 500