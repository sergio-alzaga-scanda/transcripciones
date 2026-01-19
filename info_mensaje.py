from flask import Flask, request, jsonify
import requests
from requests.auth import HTTPBasicAuth
import mysql.connector
from mysql.connector import Error
import json
import logging
from flask_cors import CORS

# ---------------- CONFIGURACIÓN DE LOGGING ----------------
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.FileHandler("app.log", encoding="utf-8"),
        logging.StreamHandler()
    ]
)

app = Flask(__name__)
CORS(app)  # Permitir CORS

# Configuración Voiceflow
AUTH_TOKEN = "VF.DM.6966925738c8fb5bdf6bcd81.v2Jtuu2nYTUzbK69"
DEFAULT_PROJECT_ID = "693afada764078aca74405a7"
BASE_URL = "https://api.voiceflow.com/v2"

# Basic Auth para segunda API
BASIC_AUTH_USERNAME = "Fanafesa2024"
BASIC_AUTH_PASSWORD = "s4c4nd4_2024"

# Config DB
DB_CONFIG = {
    "host": "localhost",
    "port": 3307,
    "user": "root",
    "password": "",
    "database": "voiceFlow",
    "collation": "utf8mb4_general_ci"
}

headers = {"Authorization": AUTH_TOKEN}

# -----------------------------------------------------------

def limpiar_html(html):
    # Puedes mantener o ajustar esta función si quieres reemplazar iconos
    return html

def reemplazar_iconos(html):
    return limpiar_html(html)

def obtener_transcripts(filtro="Today", project_id=DEFAULT_PROJECT_ID):
    """
    Obtiene los transcripts usando paginación para asegurar que se traigan TODOS los registros.
    """
    transcripts_totales = []
    page = 1
    limit = 100  # Pedimos bloques de 100 para ser más eficientes
    
    logging.info(f"[Voiceflow API] Iniciando recolección de transcripts. Filtro: {filtro}")

    while True:
        # Añadimos page y limit a la URL
        url = f"{BASE_URL}/transcripts/{project_id}?range={filtro}&page={page}&limit={limit}"
        
        try:
            response = requests.get(url, headers=headers)
            
            if response.status_code != 200:
                logging.error(f"[Voiceflow API] Error {response.status_code} en página {page}: {response.text}")
                break  # Detenemos el loop si hay error en la API
            
            data = response.json()
            
            # Si la lista está vacía, ya no hay más datos
            if not data:
                logging.info(f"[Voiceflow API] Página {page} vacía. Fin de recolección.")
                break
                
            count = len(data)
            logging.info(f"[Voiceflow API] Página {page}: Recibidos {count} registros")
            transcripts_totales.extend(data)
            
            # Si recibimos menos del límite, es la última página
            if count < limit:
                break
                
            page += 1
            
        except Exception as e:
            logging.error(f"[Voiceflow API] Excepción al obtener página {page}: {e}")
            break

    logging.info(f"[Voiceflow API] Total acumulado final: {len(transcripts_totales)} registros")
    return transcripts_totales

def obtener_mensajes_produccion(session_id, project_id=DEFAULT_PROJECT_ID):
    """
    Obtiene historial_json y HTML directamente desde la API de producción.
    """
    url = "https://proy020.kenos-atom.com/reimpresion/voiceflow/conversacion_html"
    params = {"sessionID": session_id, "projectID": project_id, "VFAPIKey": AUTH_TOKEN}
    
    try:
        response = requests.get(
            url,
            params=params,
            auth=HTTPBasicAuth(BASIC_AUTH_USERNAME, BASIC_AUTH_PASSWORD)
        )
        logging.info(f"[HTML API] SessionID: {session_id} | Status: {response.status_code}")
        response.raise_for_status()
    except requests.HTTPError as e:
        logging.error(f"[HTML API] HTTPError para sessionID {session_id}: {e}")
        return None, None
    except Exception as e:
        logging.error(f"[HTML API] Error inesperado para sessionID {session_id}: {e}")
        return None, None

    try:
        contenido_json = response.json()
        if "error" in contenido_json:
            logging.warning(f"[HTML API] Error en respuesta JSON para sessionID {session_id}: {contenido_json['error']}")
            return None, None

        historial_json = contenido_json.get("historial_json")
        html = contenido_json.get("html")

        if historial_json is None or html is None:
            logging.warning(f"[HTML API] Faltan llaves en la respuesta JSON para sessionID {session_id}")
            return None, None

    except ValueError:
        logging.error(f"[HTML API] La respuesta no es JSON para sessionID {session_id}")
        return None, None

    return historial_json, html

def insertar_datos(ID, email_status, historial_json, html, projectID, sessionID, name, user_number, id_caso):
    conexion = None
    cursor = None
    try:
        conexion = mysql.connector.connect(**DB_CONFIG)
        cursor = conexion.cursor(dictionary=True)
        html_modificado = reemplazar_iconos(html)

        cursor.execute("SELECT html FROM conversaciones_html WHERE ID = %s", (ID,))
        registro = cursor.fetchone()

        if registro:
            html_actual = registro['html']
            diferencia = abs(len(html_modificado) - len(html_actual))
            
            # Nota: Si la diferencia es menor a 25 caracteres, se considera "sin cambios"
            if diferencia >= 25:
                consulta_update = """
                    UPDATE conversaciones_html
                    SET html=%s, email_status=%s, status=%s, historial_json=%s,
                        projectID=%s, sessionID=%s, name=%s, user_number=%s, id_caso=%s
                    WHERE ID=%s
                """
                valores_update = (
                    html_modificado, "pendiente", 0, historial_json,
                    projectID, sessionID, name, user_number, id_caso, ID
                )
                cursor.execute(consulta_update, valores_update)
                conexion.commit()
                logging.info(f"[DB] Actualizado registro ID={ID}, longitud HTML={len(html_modificado)}")
                return "actualizado"
            else:
                logging.info(f"[DB] Sin cambios significativos (diff<25) para ID={ID}")
                return "sin_cambios"
        else:
            consulta_insert = """
                INSERT INTO conversaciones_html (
                    ID, email_status, historial_json, html,
                    projectID, sessionID, name, user_number, id_caso
                ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
            """
            valores_insert = (
                ID, email_status, historial_json, html_modificado,
                projectID, sessionID, name, user_number, id_caso
            )
            cursor.execute(consulta_insert, valores_insert)
            conexion.commit()
            logging.info(f"[DB] Insertado ID={ID}, longitud HTML={len(html_modificado)}, usuario={name}")
            return "insertado"

    except Error as e:
        logging.error(f"[ERROR DB] {e}")
        return "error"
    finally:
        if cursor:
            cursor.close()
        if conexion:
            conexion.close()

@app.route('/procesar_transcripts', methods=['GET'])
def procesar_transcripts():
    filtro = request.args.get('range', 'Today')
    project_id = request.args.get('project_id', DEFAULT_PROJECT_ID)
    logging.info(f"==> Iniciando procesamiento con filtro='{filtro}', project_id='{project_id}'")

    try:
        # Llamamos a la nueva función con paginación
        transcripts = obtener_transcripts(filtro, project_id)
        
        insertados = []
        actualizados = []
        fallidos = [] # Lista para rastrear los que fallan en la segunda API
        sin_cambios = []

        for transcript in transcripts:
            session_id = transcript.get("sessionID")
            ID = transcript.get("_id")
            name = transcript.get("name", "")
            custom = transcript.get("customProperties", {})
            user_number = custom.get("userNumber", "")
            id_caso = custom.get("id_caso", "")
            email_status = "pendiente"

            if session_id and ID:
                logging.info(f"[Procesando] ID={ID} | SessionID={session_id} | Nombre={name}")
                
                # Obtener detalles de la segunda API
                historial, html = obtener_mensajes_produccion(session_id, project_id)
                
                if historial is None or html is None:
                    logging.warning(f"[Procesar] No se obtuvo historial o HTML válido para sessionID {session_id}")
                    fallidos.append({"ID": ID, "sessionID": session_id, "razon": "Fallo API Secundaria"})
                    continue

                historial_json = json.dumps(historial, ensure_ascii=False)

                resultado = insertar_datos(
                    ID, email_status, historial_json, html,
                    project_id, session_id, name, user_number, id_caso
                )

                if resultado == "insertado":
                    insertados.append(ID)
                elif resultado == "actualizado":
                    actualizados.append(ID)
                elif resultado == "sin_cambios":
                    sin_cambios.append(ID)
                elif resultado == "error":
                    fallidos.append({"ID": ID, "sessionID": session_id, "razon": "Error Base de Datos"})

        logging.info(f"==> Proceso completado. Ins: {len(insertados)} | Upd: {len(actualizados)} | Fail: {len(fallidos)}")

        return jsonify({
            "success": True,
            "project_id": project_id,
            "total_procesados": len(transcripts),
            "resumen": {
                "insertados": len(insertados),
                "actualizados": len(actualizados),
                "sin_cambios": len(sin_cambios),
                "fallidos": len(fallidos)
            },
            "detalles": {
                "lista_insertados": insertados,
                "lista_actualizados": actualizados,
                "lista_fallidos": fallidos
            }
        }), 200

    except requests.HTTPError as e:
        logging.error(f"[HTTPError] {e}")
        return jsonify({"error": f"HTTP error: {str(e)}"}), 500
    except Exception as e:
        logging.error(f"[Exception] {e}")
        return jsonify({"error": f"Unexpected error: {str(e)}"}), 500

if __name__ == "__main__":
    logging.info("=== Iniciando aplicación Flask Voiceflow con Paginación ===")
    app.run(host="0.0.0.0", port=5000, debug=True)