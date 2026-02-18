import mysql.connector
from mysql.connector import Error
import logging
from flask import Flask, jsonify, request
import requests
from datetime import datetime, timedelta
from flask_cors import CORS

logging.basicConfig(level=logging.INFO, format='%(asctime)s [%(levelname)s] DB_CLIENT: %(message)s')
logger = logging.getLogger("DB_Ingestor")

app = Flask(__name__)
CORS(app)

# Modifica tu DB_CONFIG en info_mensaje.py
DB_CONFIG = {
    "host": "localhost",
    "port": 3307, 
    "user": "root",
    "password": "",
    "database": "voiceFlow",
    # Cambia utf8mb4_0900_ai_ci por esta que es compatible con versiones 5.7+
    "collation": "utf8mb4_general_ci" 
}
SERVER_URL = "https://proy020.kenos-atom.com/reimpresion/analyze-project"

def get_db_connection():
    return mysql.connector.connect(**DB_CONFIG)

def initialize_database():
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        
        # TABLA DE CONFIGURACIÓN DE PROYECTOS
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS projects_config (
            project_id VARCHAR(100) PRIMARY KEY,
            api_key VARCHAR(150) NOT NULL,
            last_sync DATETIME
        )
        """)

        # TABLA SESSIONS
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(50) PRIMARY KEY,
            session_id VARCHAR(100),
            project_id VARCHAR(50),
            created_at DATETIME,
            platform VARCHAR(50),
            duration_sec INT,
            cost_credits DECIMAL(15, 2),
            model_used VARCHAR(100),
            tokens_input INT DEFAULT 0,
            tokens_output INT DEFAULT 0,
            tokens_total INT DEFAULT 0,
            latency_ms INT DEFAULT 0,
            is_read TINYINT DEFAULT 0,
            new_messages_count INT DEFAULT 0,
            INDEX idx_session_id (session_id)
        )
        """)

        # TABLA MESSAGES
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_table_id VARCHAR(50),
            role VARCHAR(20),
            content TEXT,
            timestamp DATETIME,
            FOREIGN KEY (session_table_id) REFERENCES sessions(id) ON DELETE CASCADE
        )
        """)
        conn.commit()
        conn.close()
    except Error as e:
        logger.critical(f"Error DB: {e}")

initialize_database()

def format_date_with_offset(iso_str):
    """Convierte fecha ISO y RESTA 6 HORAS."""
    if not iso_str: return None
    try:
        dt = datetime.fromisoformat(iso_str.replace("Z", "+00:00"))
        # RESTA DE 6 HORAS (Ajuste GTM-6)
        dt_final = dt - timedelta(hours=6)
        return dt_final.strftime('%Y-%m-%d %H:%M:%S')
    except Exception as e:
        return None

@app.route('/sync-voiceflow', methods=['GET'])
def sync_voiceflow_data():
    take = request.args.get('take', 25)
    project_id = request.args.get('id_project')
    api_key = request.args.get('api_key')
    
    if not project_id or not api_key:
        return jsonify({"error": "Faltan parámetros"}), 400

    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)

        # 1. GESTIONAR PROYECTO (Guardar si no existe)
        cursor.execute("SELECT project_id FROM projects_config WHERE project_id = %s", (project_id,))
        if not cursor.fetchone():
            cursor.execute("INSERT INTO projects_config (project_id, api_key) VALUES (%s, %s)", (project_id, api_key))
            logger.info(f"Proyecto {project_id} registrado en DB.")

        # 2. SOLICITAR DATOS AL SERVIDOR DE ANÁLISIS
        params = {'take': take, 'id_project': project_id, 'api_key': api_key}
        response = requests.get(SERVER_URL, params=params)
        data_json = response.json()

        if data_json.get('status') != 'success':
            return jsonify({"error": "App 1 falló"}), 500

        sessions_list = data_json.get('data', [])
        c_ins, c_upd = 0, 0

        for s in sessions_list:
            meta = s.get('meta', {})
            metrics = s.get('metrics', {})
            history = s.get('history', [])
            
            # Buscar duplicados
            cursor.execute("SELECT id FROM sessions WHERE session_id = %s", (meta.get('sessionID'),))
            exists = cursor.fetchone()

            if not exists:
                # INSERTAR SESIÓN (Usando fecha -6h)
                sql = "INSERT INTO sessions (id, session_id, project_id, created_at, platform, duration_sec, cost_credits, model_used, tokens_total, is_read, new_messages_count) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, 0, %s)"
                cursor.execute(sql, (meta.get('id'), meta.get('sessionID'), project_id, format_date_with_offset(meta.get('createdAt')), meta.get('platform'), meta.get('duration_seconds'), meta.get('total_cost_credits'), metrics.get('model_used'), metrics.get('total_tokens'), len(history)))
                c_ins += 1
            else:
                # UPDATE (Omitido por brevedad, similar al original)
                c_upd += 1

            # INSERTAR MENSAJES (Usando fecha -6h)
            for m in history:
                cursor.execute("INSERT INTO messages (session_table_id, role, content, timestamp) VALUES (%s, %s, %s, %s)", 
                              (meta.get('id'), m.get('role'), m.get('content'), format_date_with_offset(m.get('time'))))

        # Actualizar fecha de sincronización
        cursor.execute("UPDATE projects_config SET last_sync = NOW() WHERE project_id = %s", (project_id,))
        
        conn.commit()
        conn.close()
        return jsonify({"status": "completed", "metrics": {"sessions_upserted": c_ins + c_upd}})

    except Exception as e:
        return jsonify({"error": str(e)}), 500

if __name__ == '__main__':
    app.run(port=5001, debug=True)