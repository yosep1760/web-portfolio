import os

# Mendapatkan lokasi folder 'backend' saat ini
BASE_DIR = os.path.abspath(os.path.dirname(__file__))

class Config:
    # SECRET_KEY digunakan oleh Flask untuk menjaga keamanan data (seperti session/login cookie)
    # Gunakan os.environ.get agar aman saat di-hosting (mengambil dari Vercel Environment Variables)
    # Jika tidak ada (saat jalan di komputer lokal), gunakan teks default.
    SECRET_KEY = os.environ.get('SECRET_KEY') or 'kunci-super-rahasia-toko-online-123'
    
    # Pengaturan Database
    # Secara default, kita akan menggunakan SQLite untuk tahap pengembangan lokal karena paling mudah.
    # Nanti saat di Vercel, kita tinggal memasukkan URL database asli (seperti PostgreSQL/MySQL) ke dalam 'DATABASE_URL'
    SQLALCHEMY_DATABASE_URI = os.environ.get('DATABASE_URL') or \
        'sqlite:///' + os.path.join(BASE_DIR, 'database_toko.db')
    
    # Menonaktifkan fitur pelacakan perubahan untuk menghemat memori server
    SQLALCHEMY_TRACK_MODIFICATIONS = False