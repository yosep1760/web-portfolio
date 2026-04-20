from flask import Blueprint, request, jsonify
from models import db, Order, User

# Membuat Blueprint untuk rute keranjang/pesanan
cart_bp = Blueprint('cart_bp', __name__)

# Endpoint untuk memproses Checkout (membuat pesanan baru)
@cart_bp.route('/checkout', methods=['POST'])
def checkout():
    data = request.get_json()

    # Memastikan data yang dikirim dari frontend lengkap
    # Minimal kita butuh ID pengguna yang beli dan total harganya
    if not data or not data.get('user_id') or not data.get('total_price'):
        return jsonify({'message': 'Data checkout tidak lengkap!'}), 400

    # Mengecek apakah user_id valid (pengguna benar-benar ada di database)
    user = User.query.get(data['user_id'])
    if not user:
        return jsonify({'message': 'Pengguna tidak ditemukan!'}), 404

    # Membuat catatan pesanan baru di tabel Order
    new_order = Order(
        user_id=data['user_id'],
        total_price=data['total_price'],
        status='Pending' # Status awal selalu 'Pending' (Menunggu Pembayaran)
    )

    try:
        # Menyimpan pesanan ke database
        db.session.add(new_order)
        db.session.commit()

        return jsonify({
            'message': 'Checkout berhasil! Pesanan sedang diproses.',
            'order_id': new_order.id,
            'status': new_order.status
        }), 201

    except Exception as e:
        # Jika terjadi kesalahan pada database
        db.session.rollback()
        return jsonify({'message': 'Terjadi kesalahan saat memproses pesanan.', 'error': str(e)}), 500


# Endpoint untuk melihat riwayat pesanan milik satu pengguna
@cart_bp.route('/history/<int:user_id>', methods=['GET'])
def order_history(user_id):
    # Mengambil semua pesanan yang dimiliki oleh user_id tersebut
    orders = Order.query.filter_by(user_id=user_id).all()
    
    if not orders:
        return jsonify({'message': 'Belum ada riwayat pesanan.'}), 404

    # Mengubah format data pesanan menjadi list of dictionaries agar bisa dikirim sebagai JSON
    orders_data = []
    for order in orders:
        orders_data.append({
            'order_id': order.id,
            'total_price': order.total_price,
            'status': order.status,
            'created_at': order.created_at.strftime("%Y-%m-%d %H:%M:%S") # Format tanggal agar rapi
        })

    return jsonify({
        'user_id': user_id,
        'order_history': orders_data
    }), 200