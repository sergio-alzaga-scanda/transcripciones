import mysql.connector
from mysql.connector import Error
import logging
from flask import Flask, jsonify, request
import requests
from datetime import datetime
from flask_cors import CORS

# --- CONFIGURACIÓN DE LOGS ---
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] DB_CLIENT: %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
logger = logging.getLogger("DB_Ingestor")

app = Flask(__name__)
CORS(app)

# --- CONFIGURACIÓN DE BD ---
DB_CONFIG = {
    "host": "localhost",
    "port": 3307, 
    "user": "root",
    "password": "",
    "database": "voiceFlow",
    "collation": "utf8mb4_general_ci"
}

# URL DEL SERVIDOR QUE PROCESA LOS TRANSCRIPTS
SERVER_URL = "https://proy020.kenos-atom.com/reimpresion/analyze-project"

def get_db_connection():
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        return conn
    except Error as e:
        logger.error(f"Error conectando a MySQL: {e}")
        raise

def initialize_database():
    logger.info("Iniciando verificación de Base de Datos...")
    try:
        # Crear DB si no existe
        conf_temp = DB_CONFIG.copy()
        del conf_temp['database']
        conn = mysql.connector.connect(**conf_temp)
        cursor = conn.cursor()
        cursor.execute(f"CREATE DATABASE IF NOT EXISTS {DB_CONFIG['database']}")
        conn.close()

        # Conectar a la DB y Crear Tablas
        conn = get_db_connection()
        cursor = conn.cursor()

        # Tabla Sessions
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
        
        # --- MIGRACIONES (Agregar columnas si no existen) ---
        try:
            cursor.execute("SELECT is_read FROM sessions LIMIT 1")
            cursor.fetchall()
        except Error:
            logger.info("Columna 'is_read' no encontrada. Agregándola...")
            cursor.execute("ALTER TABLE sessions ADD COLUMN is_read TINYINT DEFAULT 0")

        try:
            cursor.execute("SELECT new_messages_count FROM sessions LIMIT 1")
            cursor.fetchall()
        except Error:
            logger.info("Columna 'new_messages_count' no encontrada. Agregándola...")
            cursor.execute("ALTER TABLE sessions ADD COLUMN new_messages_count INT DEFAULT 0")

        # Tabla Messages
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
        logger.info("Estructura de tablas verificada correctamente.")
    except Error as e:
        logger.critical(f"Fallo inicializando la DB: {e}", exc_info=True)

# Ejecutar al inicio
initialize_database()

def format_date(iso_str):
    if not iso_str: return None
    try:
        dt = datetime.fromisoformat(iso_str.replace("Z", "+00:00"))
        return dt.strftime('%Y-%m-%d %H:%M:%S')
    except Exception as e:
        logger.warning(f"Error formateando fecha '{iso_str}': {e}")
        return None

@app.route('/sync-voiceflow', methods=['GET'])
def sync_voiceflow_data():
    take_arg = request.args.get('take', 100)
    project_arg = request.args.get('projectID')
    
    try:
        take_arg = int(take_arg)
        if take_arg < 1: take_arg = 1
        if take_arg > 100: take_arg = 100
    except ValueError:
        return jsonify({"error": "El parámetro 'take' debe ser un número entero"}), 400

    params = {'take': take_arg}
    if project_arg: 
        params['projectID'] = project_arg

    try:
        logger.info(f"Iniciando sincronización. Solicitando {take_arg} registros...")
        
        try:
            response = requests.get(SERVER_URL, params=params)
            response.raise_for_status()
        except requests.exceptions.RequestException as req_err:
            logger.error(f"Error contactando al servidor de métricas: {req_err}")
            return jsonify({"error": "No se pudo conectar con App 1", "details": str(req_err)}), 503

        data_json = response.json()
        if data_json.get('status') != 'success':
            msg = data_json.get('message', 'Error desconocido')
            return jsonify({"error": "App 1 falló", "msg": msg}), 500

        sessions_list = data_json.get('data', [])
        logger.info(f"Datos recibidos: {len(sessions_list)} sesiones. Analizando duplicados por session_id...")

        conn = get_db_connection()
        cursor = conn.cursor()
        
        count_inserts = 0
        count_updates = 0
        count_skipped = 0

        for s in sessions_list:
            try:
                meta = s.get('meta', {})
                metrics = s.get('metrics', {})
                history = s.get('history', [])
                
                incoming_session_id = meta.get('sessionID') 
                incoming_pk_id = meta.get('id')             
                incoming_msg_count = len(history)

                if not incoming_session_id:
                    continue

                # 1. BUSCAR EN BD
                cursor.execute("""
                    SELECT s.id, COUNT(m.id) as msg_count 
                    FROM sessions s 
                    LEFT JOIN messages m ON s.id = m.session_table_id 
                    WHERE s.session_id = %s 
                    GROUP BY s.id
                """, (incoming_session_id,))
                
                existing_record = cursor.fetchone()
                
                should_process = False
                operation_type = "SKIP"
                target_pk_id = incoming_pk_id 
                diff_messages = 0 # Variable para almacenar cuantos mensajes nuevos hay

                if existing_record is None:
                    # CASO A: NUEVO
                    should_process = True
                    operation_type = "INSERT"
                    diff_messages = incoming_msg_count # Si es nuevo, todos son "nuevos"
                else:
                    # CASO B: EXISTE
                    existing_pk_id = existing_record[0]
                    existing_msg_count = existing_record[1]
                    
                    if incoming_msg_count > existing_msg_count:
                        should_process = True
                        operation_type = "UPDATE"
                        target_pk_id = existing_pk_id
                        # Calculamos la diferencia
                        diff_messages = incoming_msg_count - existing_msg_count
                        logger.info(f"Session {incoming_session_id}: Actualizando. (+{diff_messages} msgs nuevos)")
                    else:
                        should_process = False
                        operation_type = "SKIP"
                        count_skipped += 1

                if should_process:
                    
                    if operation_type == "INSERT":
                        sql_insert = """
                        INSERT INTO sessions (
                            id, session_id, project_id, created_at, platform, duration_sec, cost_credits,
                            model_used, tokens_input, tokens_output, tokens_total, latency_ms, 
                            is_read, new_messages_count
                        ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 0, %s)
                        """
                        val_insert = (
                            target_pk_id, incoming_session_id, meta.get('projectID'), format_date(meta.get('createdAt')),
                            meta.get('platform'), meta.get('duration_seconds', 0), meta.get('total_cost_credits', 0),
                            metrics.get('model_used', 'Unknown'), metrics.get('input_tokens', 0), metrics.get('output_tokens', 0), 
                            metrics.get('total_tokens', 0), metrics.get('total_latency_ms', 0),
                            diff_messages # new_messages_count
                        )
                        cursor.execute(sql_insert, val_insert)
                        count_inserts += 1

                    elif operation_type == "UPDATE":
                        # Actualizamos métricas, forzamos is_read = 0 y guardamos la diferencia de mensajes
                        sql_update = """
                        UPDATE sessions SET 
                            duration_sec=%s, cost_credits=%s, model_used=%s, 
                            tokens_input=%s, tokens_output=%s, tokens_total=%s, 
                            latency_ms=%s, is_read=0, new_messages_count=%s
                        WHERE id=%s
                        """
                        update_params = (
                            meta.get('duration_seconds', 0), meta.get('total_cost_credits', 0),
                            metrics.get('model_used', 'Unknown'), metrics.get('input_tokens', 0), 
                            metrics.get('output_tokens', 0), metrics.get('total_tokens', 0), 
                            metrics.get('total_latency_ms', 0),
                            diff_messages, # new_messages_count
                            target_pk_id 
                        )
                        cursor.execute(sql_update, update_params)
                        
                        # Borrar mensajes viejos para insertar la versión completa nueva
                        cursor.execute("DELETE FROM messages WHERE session_table_id = %s", (target_pk_id,))
                        count_updates += 1

                    # INSERTAR MENSAJES
                    if history:
                        sql_msg = """
                        INSERT INTO messages (session_table_id, role, content, timestamp)
                        VALUES (%s, %s, %s, %s)
                        """
                        msg_values = []
                        for msg in history:
                            msg_values.append((
                                target_pk_id,
                                msg.get('role'),
                                msg.get('content'),
                                format_date(msg.get('time'))
                            ))
                        cursor.executemany(sql_msg, msg_values)
            
            except Error as db_err:
                logger.error(f"Error SQL procesando session_id {s.get('meta', {}).get('sessionID')}: {db_err}")
                continue

        conn.commit()
        conn.close()
        
        logger.info(f"Fin proceso. Nuevos: {count_inserts}, Actualizados: {count_updates}, Ignorados: {count_skipped}")

        return jsonify({
            "status": "completed",
            "metrics": {
                "new_sessions": count_inserts,
                "updated_sessions": count_updates,
                "skipped_sessions": count_skipped
            }
        })

    except Exception as e:
        logger.critical(f"Error no controlado en Ingestor: {e}", exc_info=True)
        return jsonify({"error": "Error interno", "details": str(e)}), 500

if __name__ == '__main__':
    print("Iniciando DB Client (New Messages Count)...")
    app.run(port=5001, debug=True)