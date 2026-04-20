from flask import Blueprint, request, jsonify
from werkzeug.security import generate_password_hash, check_password_hash
from models import db, User

# Membuat Blueprint untuk rute autentikasi
auth_bp = Blueprint('auth_bp', __name__)

# 1. Endpoint untuk Register (Mendaftar akun baru)
@auth_bp.route('/register', methods=['POST'])
def register():
    data = request.get_json()
    
    # Memastikan data yang dikirim tidak kosong
    if not data or not data.get('username') or not data.get('email') or not data.get('password'):
        return jsonify({'message': 'Data tidak lengkap!'}), 400

    # Mengecek apakah email atau username sudah pernah terdaftar
    existing_user = User.query.filter((User.email == data['email']) | (User.username == data['username'])).first()
    if existing_user:
        return jsonify({'message': 'Username atau Email sudah terdaftar!'}), 400

    # Mengenkripsi (hashing) password sebelum disimpan ke database
    hashed_password = generate_password_hash(data['password'])

    # Membuat objek user baru dan menyimpannya ke database
    new_user = User(username=data['username'], email=data['email'], password=hashed_password)
    db.session.add(new_user)
    db.session.commit()

    return jsonify({'message': 'Registrasi berhasil!'}), 201


# 2. Endpoint untuk Login
@auth_bp.route('/login', methods=['POST'])
def login():
    data = request.get_json()

    if not data or not data.get('email') or not data.get('password'):
        return jsonify({'message': 'Email dan password harus diisi!'}), 400

    # Mencari user berdasarkan email
    user = User.query.filter_by(email=data['email']).first()

    # Mengecek apakah user ada dan passwordnya cocok dengan yang dienkripsi
    if user and check_password_hash(user.password, data['password']):
        # Jika berhasil login, kita kirimkan data user (tanpa password)
        return jsonify({
            'message': 'Login berhasil!',
            'user': {
                'id': user.id,
                'username': user.username,
                'email': user.email
            }
        }), 200

    # Jika email atau password salah
    return jsonify({'message': 'Email atau password salah!'}), 401