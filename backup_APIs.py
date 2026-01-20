from flask import Flask, jsonify, request, Response
import logging
from logging.handlers import RotatingFileHandler
import pyodbc
import subprocess
from unidecode import unidecode  # ✅ Importación correcta
import unicodedata
import re
import logging
from flask import Flask, request, jsonify
import requests
import unidecode
import re
from requests.auth import HTTPBasicAuth
from functools import wraps
from datetime import datetime  # Importar datetime para manejar fechas correctamente
from flask import Flask, request, jsonify
from email_validator import validate_email, EmailNotValidError
from dotenv import load_dotenv
import os
import asyncio
from aiosmtplib import SMTP
from email.message import EmailMessage
from fuzzywuzzy import fuzz


#import pyodbc
#import re
from unidecode import unidecode

app = Flask(__name__)

# Configuración del logging
handler = RotatingFileHandler('app.log', maxBytes=10000, backupCount=1)
handler.setLevel(logging.INFO)
handler.setFormatter(logging.Formatter('%(asctime)s - %(levelname)s - %(message)s'))

app.logger.addHandler(handler)
app.logger.setLevel(logging.INFO)

# Decorador para requerir autenticación básica
def check_auth(username, password):
    return username == 'Fanafesa2024' and password == 's4c4nd4_2024'

def authenticate():
    return Response(
        'No se pudo verificar su nivel de acceso para esa URL.\n'
        'Tienes que iniciar sesión con las credenciales adecuadas.', 401,
        {'WWW-Authenticate': 'Basic realm="Login Required"'})

def requires_auth(f):
    @wraps(f)
    def decorated(*args, **kwargs):
        auth = request.authorization
        if not auth or not check_auth(auth.username, auth.password):
            return authenticate()
        return f(*args, **kwargs)
    return decorated

@app.route('/reimpresion', methods=['GET'])
def index():
    return jsonify({'message': 'El servidor está funcionando correctamente!'})

# Validar localidad
@app.route('/reimpresion/validar_localidad', methods=['POST'])
@requires_auth
def validar_localidad():
    # Obtener los datos enviados en formato x-www-form-urlencoded
    localidad = request.form.get('localidad')

    if not localidad:
        return jsonify({'respuesta': 'Falta el parámetro "localidad"'}), 400

    # Convertir la localidad a mayúsculas y quitar acentos y puntos finales
    localidad = normalize_string(localidad)

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        # Consultar si la localidad existe en la base de datos
        query = "SELECT COUNT(*) FROM rpa_farmacias WHERE localidad = ?"
        cursor.execute(query, (localidad,))
        row = cursor.fetchone()

        # Devolver true si existe, false si no existe
        if row[0] > 0:
            return jsonify({'respuesta': 1}), 200
        else:
            return jsonify({'respuesta': 0}), 200

    except pyodbc.Error as e:
        app.logger.error(f"Database error: {e}")
        return jsonify({'error': str(e)}), 500
    except Exception as e:
        app.logger.error(f"Error desconocido: {e}")
        return jsonify({'error': 'Error interno del servidor'}), 500
    finally:
        conn.close()


@app.route('/reimpresion/validar_fecha', methods=['POST'])
def validar_fecha():
    fecha = request.form.get('fecha')

    if not fecha:
        return jsonify({'respuesta': 'Falta el parámetro "fecha"'}), 400

    try:
        # Convertir la fecha recibida a un objeto datetime con el formato 'dd/mm/yy'
        fecha_proporcionada = datetime.strptime(fecha, '%d/%m/%y')
        
        # Obtener la fecha actual (solo la parte de la fecha, sin hora)
        fecha_actual = datetime.today().replace(hour=0, minute=0, second=0, microsecond=0)

        # Validar si la fecha proporcionada es mayor que la fecha actual
        if fecha_proporcionada > fecha_actual:
            return jsonify({'respuesta': 2}), 200
        
        # Si la fecha es válida, retornamos un mensaje positivo
        return jsonify({'respuesta': 1}), 200

    except ValueError:
        # Si no se puede convertir la fecha a datetime, retornar un error
        return jsonify({'respuesta': 'Formato de fecha incorrecto. Usa el formato dd/mm/yy.'}), 400
    except Exception as e:
        app.logger.error(f"Error desconocido: {e}")
        return jsonify({'error': 'Error interno del servidor'}), 500


@app.route('/reimpresion/buscar_localidad', methods=['GET'])
@requires_auth
def buscar_localidad():
    nombre_localidad = request.args.get('localidad')
    cantidad = request.args.get('cantidad')
    fecha = request.args.get('fecha')
    referencia = request.args.get('referencia', None)  # Campo opcional
    tipo_compra = request.args.get('tipo_compra', 1)

    if not nombre_localidad:
        return jsonify({'respuesta': 'Falta el parámetro "localidad"'}), 400
    if not cantidad:
        return jsonify({'respuesta': 'Falta el parámetro "cantidad"'}), 400
    if not fecha:
        return jsonify({'respuesta': 'Falta el parámetro "fecha"'}), 400

    if not re.match(r'^\d{2}/\d{2}/\d{2}$', fecha):
        return jsonify({'respuesta': 'La fecha debe tener el formato dd/mm/aa.'}), 400
    
    try:
        tipo_compra = int(tipo_compra)
    except ValueError:
        return jsonify({'respuesta': 'El parámetro "tipo_compra" debe ser un número entero válido.'}), 400

    nombre_localidad = normalize_string(nombre_localidad)

    try:
        cantidad_float = float(cantidad.replace(",", ""))
        formatted_cantidad = format_cantidad(cantidad_float)
    except ValueError:
        return jsonify({'respuesta': f'El parámetro "cantidad" debe ser un número válido. Recibido: {cantidad}'}), 400

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        if tipo_compra == 1:
            query = "SELECT * FROM rpa_farmacias WHERE localidad = ?"
            cursor.execute(query, (nombre_localidad,))
        elif tipo_compra in [2, 3]:
            id_value = 100 if tipo_compra == 2 else 99
            query = """
                SELECT r1.usuario, r1.password, r2.correo 
                FROM rpa_farmacias r1 
                CROSS JOIN (SELECT correo FROM rpa_farmacias WHERE localidad = ?) r2 
                WHERE r1.id = ?
            """
            cursor.execute(query, (nombre_localidad, id_value))
        else:
            return jsonify({'respuesta': 'El parámetro "tipo_compra" debe ser 1, 2 o 3.'}), 400

        row = cursor.fetchone()

        if row:
            usuario, password = row.usuario, row.password
            correo = row.correo
            
            response_data_user = {
                'respuesta': 'El proceso se está ejecutando'
            }

            insert_query = """
            INSERT INTO rpa_info_consulta (usuario, password, correo, cantidad, fecha, tipo_compra, referencia)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            """
            cursor.execute(insert_query, (usuario, password, correo, formatted_cantidad, fecha, tipo_compra, referencia))
            genera_cierra_tickets(usuario, password, correo, formatted_cantidad, fecha, tipo_compra, referencia)
            conn.commit()
            return jsonify(response_data_user)
        else:
            return jsonify({'respuesta': 'Localidad no encontrada o configuración inválida para el tipo de compra.'}), 404

    except pyodbc.Error as e:
        app.logger.error(f"Database error: {e}")
        return jsonify({'error': str(e)}), 500
    except Exception as e:
        app.logger.error(f"Error desconocido: {e}")
        return jsonify({'error': 'Error interno del servidor'}), 500
    finally:
        conn.close()

#________________________________________________________________________________________________________________
#___________________________________ENCODE_______________________________________________________________________

@app.route('/reimpresion/buscar_localidad_encode', methods=['POST'])
@requires_auth
def buscar_localidad_encode():
    nombre_localidad = request.form.get('localidad')
    cantidad = request.form.get('cantidad')
    fecha = request.form.get('fecha')
    referencia = request.form.get('referencia', None)  # Campo opcional
    tipo_compra = request.form.get('tipo_compra', 1)

    if not nombre_localidad:
        return jsonify({'respuesta': 'Falta el parámetro "localidad"'}), 400
    if not cantidad:
        return jsonify({'respuesta': 'Falta el parámetro "cantidad"'}), 400
    if not fecha:
        return jsonify({'respuesta': 'Falta el parámetro "fecha"'}), 400

    if not re.match(r'^\d{2}/\d{2}/\d{2}$', fecha):
        return jsonify({'respuesta': 'La fecha debe tener el formato dd/mm/aa.'}), 400
    
    try:
        tipo_compra = int(tipo_compra)
    except ValueError:
        return jsonify({'respuesta': 'El parámetro "tipo_compra" debe ser un número entero válido.'}), 400

    nombre_localidad = normalize_string(nombre_localidad)

    try:
        cantidad_float = float(cantidad.replace(",", ""))
        formatted_cantidad = format_cantidad(cantidad_float)
    except ValueError:
        return jsonify({'respuesta': f'El parámetro "cantidad" debe ser un número válido. Recibido: {cantidad}'}), 400

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        if tipo_compra == 1:
            query = "SELECT * FROM rpa_farmacias WHERE localidad = ?"
            cursor.execute(query, (nombre_localidad,))
        elif tipo_compra in [2, 3]:
            id_value = 100 if tipo_compra == 2 else 99
            query = """
                SELECT r1.usuario, r1.password, r2.correo 
                FROM rpa_farmacias r1 
                CROSS JOIN (SELECT correo FROM rpa_farmacias WHERE localidad = ?) r2 
                WHERE r1.id = ?
            """
            cursor.execute(query, (nombre_localidad, id_value))
        else:
            return jsonify({'respuesta': 'El parámetro "tipo_compra" debe ser 1, 2 o 3.'}), 400

        row = cursor.fetchone()

        if row:
            usuario, password = row.usuario, row.password
            correo = row.correo
            
            response_data_user = {
                'respuesta': 'El proceso se está ejecutando'
            }

            insert_query = """
            INSERT INTO rpa_info_consulta (usuario, password, correo, cantidad, fecha, tipo_compra, referencia)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            """
            cursor.execute(insert_query, (usuario, password, correo, formatted_cantidad, fecha, tipo_compra, referencia))

            conn.commit()
            return jsonify(response_data_user)
        else:
            return jsonify({'respuesta': 'Localidad no encontrada o configuración inválida para el tipo de compra.'}), 404

    except pyodbc.Error as e:
        app.logger.error(f"Database error: {e}")
        return jsonify({'error': str(e)}), 500
    except Exception as e:
        app.logger.error(f"Error desconocido: {e}")
        return jsonify({'error': 'Error interno del servidor'}), 500
    finally:
        conn.close()

#________________________________________________________________________________________________________________
#___________________________________FIN ENCODE___________________________________________________________________
def get_db_connection():
    connection_string = (
        "DRIVER={ODBC Driver 17 for SQL Server};"
        "Server=fanafesadbkenos.database.windows.net;"
        "Database=fanafesadb;"
        "UID=admindbkenos;"
        "PWD=K3n0sFanafes4!.*;"
    )
    return pyodbc.connect(connection_string)

def normalize_string(s):
    # Convertir a mayúsculas
    s = s.upper()
    
    # Normalizar la cadena para quitar acentos
    nfkd_form = unicodedata.normalize('NFKD', s)
    s = re.sub(r'[\u0300-\u036f]', '', nfkd_form)
    
    # Eliminar puntos finales
    s = re.sub(r'\.$', '', s)

    return s

def format_cantidad(value):
    """ Formatea el valor numérico para que tenga separador de miles y dos decimales solo si es necesario """
    if value >= 1000:
        return f"$ {value:,.2f}"
    else:
        return f"$ {value:,.2f}"

#________________________________________________________________________________________________________________
#___________________________________Inicio Generar Ticket________________________________________________________



# Configuración del logger
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(message)s')

# URL base
BASE_URL = "https://app.kenos-atom.com/api/V1/Ticket/generateTicket"

# Credenciales de autenticación para la API externa
API_USER = "scanda_kuo"
API_PASS = "4p1#5cAnD4Ku0"

# Ruta para generar el ticket de tipo 1 (incidente)
@app.route('/reimpresion/generaTicketInc', methods=['POST'])
@requires_auth
def genera_ticket_inc():
    try:
        # Intentar obtener el cuerpo de la solicitud como JSON
        data = request.get_json()

        # Imprimir los datos que recibe el servidor para depuración
        print("Datos recibidos:", data)

        # Verificar si los datos son válidos
        if not data:
            return jsonify({"error": "Se esperaba un cuerpo JSON válido."}), 400

        # Normalizar el correo electrónico
        email = data.get("UsuarioRequerimientoEmail", "")
        email = normalize_email(email)
        if not email:
            return jsonify({"error": "El correo electrónico no es válido."}), 400
        
        # Validar campos obligatorios
        usuario_requerimiento_str = data.get("UsuarioRequerimientoStr")
        if not usuario_requerimiento_str:
            return jsonify({"error": "El campo 'UsuarioRequerimientoStr' es obligatorio."}), 400
        
        titulo = data.get("Titulo")
        if not titulo:
            return jsonify({"error": "El campo 'Titulo' es obligatorio."}), 400
        
        descripcion = data.get("Descripcion")
        if not descripcion:
            return jsonify({"error": "El campo 'Descripcion' es obligatorio."}), 400
        
        categoria_tercer_nivel = data.get("CategoriaTercerNivel")
        if not categoria_tercer_nivel:
            return jsonify({"error": "El campo 'CategoriaTercerNivel' es obligatorio."}), 400

        # Datos del ticket
        ticket_data = {
            "Responsable": 8288,  # Fijo
            "Prioridad": 3,  # Valor fijo para prioridad
            "UsuarioRequerimientoStr": usuario_requerimiento_str,
            "UsuarioRequerimientoEmail": email,
            "TipoContacto": data.get("TipoContacto", 7),  # Valor predeterminado si no se pasa
            "GrupoAsignado": data.get("GrupoAsignado", 164),  # Valor predeterminado si no se pasa
            "Localidad": data.get("Localidad", 1),  # Valor predeterminado si no se pasa
            "Titulo": titulo,
            "Descripcion": descripcion,
            "Categoria": data.get("Categoria", "APP DE NEGOCIO"),  # Valor predeterminado si no se pasa
            "SubCategoria": data.get("SubCategoria", "SIMAX"),  # Valor predeterminado si no se pasa
            "CategoriaTercerNivel": categoria_tercer_nivel,
            "Compania": data.get("Compania", 19),  # Valor predeterminado si no se pasa
            "TipoTicket": 1  # Tipo de ticket: Incidente
        }

        # Registrar los datos del ticket en los logs antes de enviarlos
        logging.info("Datos enviados al endpoint: %s", ticket_data)

        # Enviar la solicitud al endpoint correspondiente con autenticación básica
        response = requests.post(BASE_URL, json=ticket_data, auth=HTTPBasicAuth(API_USER, API_PASS))
        
        # Registrar la respuesta y el código de estado en el log
        logging.info("API Respuesta - Status: %d, Response: %s", response.status_code, response.text)
        
        # Verificar la respuesta
        if not response.text.strip():  # Si la respuesta está vacía
            return jsonify({"error": "La respuesta de la API está vacía."}), 500

        try:
            response_data = response.json()
        except ValueError:
            return jsonify({"error": "La respuesta de la API no es un JSON válido."}), 500

        if response.status_code == 200:
                # Acceder al objeto 'data' dentro de la respuesta
                ticket_data = response_data.get("data", {})

                # Verificar si la respuesta contiene un error específico
                if response_data.get("isCorrect") == "false" and response_data.get("isBreakOperation") == "true":
                    return jsonify({"message": response_data.get("message")}), 400  # O el código de estado que prefieras
                
                # Filtrar los datos necesarios y convertir el ID a cadena
                ticket_id = str(ticket_data.get("id"))
                ticket_id_with_spaces = " ".join(ticket_id)
                
                filtered_data = {
                    "id": ticket_id_with_spaces, 
                    "responsibility": ticket_data.get("responsibility"),
                    "assignedGroup": ticket_data.get("assignedGroup"),
                    "srType": ticket_data.get("srType"),
                    "status": ticket_data.get("status")
                }
                return jsonify(filtered_data), 200
        else:
            return jsonify({"error": "Error al crear el ticket", "details": response_data}), response.status_code

    except Exception as e:
        return jsonify({"error": str(e)}), 500


# Ruta para generar el ticket de tipo 2 (requiemiento)
@app.route('/reimpresion/generaTicketReq', methods=['POST'])
@requires_auth
def genera_ticket_req():
    try:
        # Intentar obtener el cuerpo de la solicitud como JSON
        data = request.get_json()

        # Imprimir los datos que recibe el servidor para depuración
        print("Datos recibidos:", data)

        # Verificar si los datos son válidos
        if not data:
            return jsonify({"error": "Se esperaba un cuerpo JSON válido."}), 400

        # Normalizar el correo electrónico
        email = data.get("usuariorequerimientoemail", "")
        email = normalize_email(email)
        if not email:
            return jsonify({"error": "El correo electrónico no es válido."}), 400
        
        # Validar campos obligatorios
        usuario_requerimiento_str = data.get("usuariorequerimientostr")
        if not usuario_requerimiento_str:
            return jsonify({"error": "El campo 'UsuarioRequerimientoStr' es obligatorio."}), 400
        
        titulo = data.get("titulo")
        if not titulo:
            return jsonify({"error": "El campo 'Titulo' es obligatorio."}), 400
        
        descripcion = data.get("descripcion")
        if not descripcion:
            return jsonify({"error": "El campo 'descripcion' es obligatorio."}), 400
        
        categoria_tercer_nivel = data.get("categoriatercernivel")
        if not categoria_tercer_nivel:
            return jsonify({"error": "El campo 'CategoriaTercerNivel' es obligatorio."}), 400

        # Datos del ticket
        ticket_data = {
            "Prioridad": data.get("prioridad", 4),  # Valor predeterminado si no se pasa
            "UsuarioRequerimientoStr": usuario_requerimiento_str,
            "UsuarioRequerimientoEmail": email,
            "TipoContacto": data.get("tipocontacto", 7),  # Valor predeterminado si no se pasa
            "GrupoAsignado": data.get("grupoasignado", 136),  # Valor predeterminado si no se pasa
            "Localidad": data.get("localidad", 12),  # Valor predeterminado si no se pasa
            "Titulo": titulo,
            "Descripcion": descripcion,
            "Categoria": data.get("categoria", "HARDWARE"),  # Valor predeterminado si no se pasa
            "SubCategoria": data.get("subCategoria", "IMPRESORA"),  # Valor predeterminado si no se pasa
            "CategoriaTercerNivel": categoria_tercer_nivel,
            "Compania": data.get("compania", 3),  # Valor predeterminado si no se pasa
            "AdministradorResponsable": data.get("administradorresponsable", 3),  # Valor predeterminado si no se pasa
            "TipoTicket": data.get("tipoticket", 2)  # Valor predeterminado si no se pasa
        }

        # Registrar los datos del ticket en los logs antes de enviarlos
        logging.info("Datos enviados al endpoint: %s", ticket_data)

        # Enviar la solicitud al endpoint correspondiente con autenticación básica
        response = requests.post(BASE_URL, json=ticket_data, auth=HTTPBasicAuth(API_USER, API_PASS))
        
        # Registrar la respuesta y el código de estado en el log
        logging.info("API Respuesta - Status: %d, Response: %s", response.status_code, response.text)
        
        # Verificar la respuesta
        if not response.text.strip():  # Si la respuesta está vacía
            return jsonify({"error": "La respuesta de la API está vacía."}), 500

        try:
            response_data = response.json()
        except ValueError:
            return jsonify({"error": "La respuesta de la API no es un JSON válido."}), 500

        if response.status_code == 200:
                # Acceder al objeto 'data' dentro de la respuesta
                response_data = response.json()  # Suponiendo que la respuesta sea JSON
                ticket_data = response_data.get("data", {})

                # Verificar si la respuesta contiene un error específico
                if response_data.get("isCorrect") == "false" and response_data.get("isBreakOperation") == "true":
                    return jsonify({"message": response_data.get("message")}), 400  # O el código de estado que prefieras
                    # Filtrar los datos necesarios y convertir el ID a cadena
                ticket_id = str(ticket_data.get("id"))
                # Agregar un espacio entre cada caracter del ID
                ticket_id_with_spaces = " ".join(ticket_id)
                # Filtrar los datos necesarios y convertir el ID a cadena
                filtered_data = {
                    "id": ticket_id_with_spaces, 
                    "responsibility": ticket_data.get("responsibility"),
                    "assignedGroup": ticket_data.get("assignedGroup"),
                    "srType": ticket_data.get("srType"),
                    "status": ticket_data.get("status")
                }
                return jsonify(filtered_data), 200
        else:
            return jsonify({"error": "Error al crear el ticket", "details": response_data}), response.status_code

    except Exception as e:
        return jsonify({"error": str(e)}), 500

# Conexión a la base de datos y búsqueda de palabra corregida
def query_palabra_completa(palabra_similar: str) -> str | None:
    conn = pyodbc.connect(
        "DRIVER={ODBC Driver 17 for SQL Server};"
        "SERVER=fanafesadbkenos.database.windows.net;"
        "DATABASE=fanafesadb;"
        "UID=admindbkenos;"
        "PWD=K3n0sFanafes4!.*;"
    )
    cursor = conn.cursor()
    cursor.execute("""
        SELECT palabra_completa 
        FROM dbo.variaciones_usuarios 
        WHERE palabra_similar COLLATE Latin1_General_CI_AI = ?
    """, (palabra_similar,))
    row = cursor.fetchone()
    cursor.close()
    conn.close()

    if row:
        return row.palabra_completa.strip().lower()
    return None

# Función principal para normalizar un email
def normalize_email(text: str) -> str:
    text = unidecode(text.lower().strip())

    # Reemplazos textuales (ej: "arroba" → "@")
    reemplazos = {
        "arroba": "@",
        "punto": ".",
        "guion": "-",
        "guionbajo": "_"
    }

    SEPARADORES = {'@', '.', '-', '_'}

    # Paso 1: Reemplazar palabras clave por sus símbolos
    palabras = re.split(r'\s+', text)
    partes = []
    for palabra in palabras:
        if palabra in reemplazos:
            partes.append(reemplazos[palabra])
        else:
            # Separar cualquier parte del texto con separadores comunes y conservarlos
            subpartes = re.split(r'([@._-])', palabra)
            partes.extend([sp for sp in subpartes if sp])

    # Paso 2: Validar y corregir cada palabra si aplica
    def validar_palabra(palabra: str) -> str:
        if re.fullmatch(r'[a-z0-9]+', palabra):
            correccion = query_palabra_completa(palabra)
            if correccion:
                return correccion
        return palabra

    resultado = []
    for p in partes:
        if p in SEPARADORES:
            resultado.append(p)
        else:
            resultado.append(validar_palabra(p))

    # Paso 3: Reconstruir el email
    email_corregido = ''.join(resultado)

    # Validaciones mínimas para asegurar formato de email
    if email_corregido.count('@') != 1:
        return ""
    dominio = email_corregido.split('@')[1]
    if '.' not in dominio:
        return ""
    if not re.match(r'^[a-z0-9@._-]+$', email_corregido):
        return ""

    return email_corregido

@app.route('/reimpresion/validarEmail', methods=['POST'])
def validar_email():
    try:
        # --- PARTE 1: Lógica original (Normalización y Split) ---
        data = request.get_json()
        email = data.get("email", "")

        if not email:
            return jsonify({"error": "El correo electrónico es requerido."}), 400

        email_normalizado = normalize_email(email)

        if not email_normalizado:
            return jsonify({"error": "El correo electrónico no tiene un formato válido."}), 400

        # Obtenemos el usuario "simple" (texto antes del @) como pediste mantener
        usuario_simple = email_normalizado.split('@')[0]


        # --- PARTE 2: Lógica de Base de Datos (Traída de validarEmail2) ---
        datos_usuario_bd = None
        existe = False
        mensaje = "El usuario no existe en la base de datos."

        # Conectar a la base de datos
        conn = pyodbc.connect(
            "DRIVER={ODBC Driver 17 for SQL Server};"
            "SERVER=fanafesadbkenos.database.windows.net;"
            "DATABASE=fanafesadb;"
            "UID=admindbkenos;"
            "PWD=K3n0sFanafes4!.*;"
        )
        cursor = conn.cursor()

        # Buscar el correo en la base de datos
        cursor.execute("""
            SELECT 
                Id_usuario, Nombre, Apellidos, Dominio, Empresa, Departamento,
                Correo_electronico, Telefono_movil, Telefono, Localidad,
                Estado, Deshabilitado, Tipo_de_usuario
            FROM Usuarios_ref
            WHERE Correo_electronico = ?
        """, email_normalizado)

        row = cursor.fetchone()

        cursor.close()
        conn.close()

        # Si encontramos el registro, lo procesamos
        if row:
            existe = True
            mensaje = "Usuario encontrado."
            columnas = [
                "Id_usuario", "Nombre", "Apellidos", "Dominio", "Empresa",
                "Departamento", "Correo_electronico", "Telefono_movil", "Telefono",
                "Localidad", "Estado", "Deshabilitado", "Tipo_de_usuario"
            ]
            datos_usuario_bd = {columnas[i]: row[i] for i in range(len(columnas))}


        # --- PARTE 3: Respuesta Combinada ---
        # Devolvemos el usuario del split ("usuario") Y los datos de la BD ("datos_usuario")
        response_data = {
            "email_normalizado": email_normalizado,
            "usuario": usuario_simple,      # Tu requerimiento original
            "existe": existe,               # Para saber si se halló en BD
            "datos_usuario": datos_usuario_bd, # Toda la info de la BD (null si no existe)
            "mensaje": mensaje
        }

        # Puedes decidir si devolver 404 si no existe en BD, o siempre 200 ya que 
        # el 'usuario' simple sí se generó. Aquí devuelvo 200 por defecto.
        return jsonify(response_data), 200

    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/reimpresion/validarEmail2', methods=['POST'])
def validar_email2():
    try:
        data = request.get_json()
        email = data.get("email", "")

        if not email:
            return jsonify({"error": "El correo electrónico es requerido."}), 400

        # Normalizar correo
        email_normalizado = normalize_email(email)

        if not email_normalizado:
            return jsonify({"error": "El correo electrónico no tiene un formato válido."}), 400

        # Conectar a la base de datos
        conn = pyodbc.connect(
            "DRIVER={ODBC Driver 17 for SQL Server};"
            "SERVER=fanafesadbkenos.database.windows.net;"
            "DATABASE=fanafesadb;"
            "UID=admindbkenos;"
            "PWD=K3n0sFanafes4!.*;"  # <-- agrega tu contraseña manualmente
        )
        cursor = conn.cursor()

        # Buscar el correo en la base de datos
        cursor.execute("""
            SELECT 
                Id_usuario, Nombre, Apellidos, Dominio, Empresa, Departamento,
                Correo_electronico, Telefono_movil, Telefono, Localidad,
                Estado, Deshabilitado, Tipo_de_usuario
            FROM Usuarios_ref
            WHERE Correo_electronico = ?
        """, email_normalizado)

        row = cursor.fetchone()

        cursor.close()
        conn.close()

        # Si NO existe el correo
        if not row:
            return jsonify({
                "email_normalizado": email_normalizado,
                "existe": False,
                "mensaje": "El usuario no existe en la base de datos."
            }), 404

        # Convertir el registro en diccionario
        columnas = [
            "Id_usuario", "Nombre", "Apellidos", "Dominio", "Empresa",
            "Departamento", "Correo_electronico", "Telefono_movil", "Telefono",
            "Localidad", "Estado", "Deshabilitado", "Tipo_de_usuario"
        ]

        usuario_bd = {columnas[i]: row[i] for i in range(len(columnas))}

        # Respuesta correcta
        return jsonify({
            "email_normalizado": email_normalizado,
            "existe": True,
            "usuario": usuario_bd
        }), 200

    except Exception as e:
        return jsonify({"error": str(e)}), 500



@app.route('/reimpresion/normalizar-localidad_form', methods=['POST'])
def normalizar_form():
    localidad_input = (request.form.get('localidad') or "").strip().lower()

    if not localidad_input:
        return jsonify({"error": "La localidad es requerida"}), 400

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        # Consulta SQL para buscar por nombre_clave (normalizado)
        query = """
            SELECT localidad, id_empresa, id_torre, id_responsable
            FROM localidades_rpa
            WHERE LOWER(nombre_clave) = ?
        """
        cursor.execute(query, (localidad_input,))
        row = cursor.fetchone()

        if row:
            localidad, id_empresa, id_torre, id_responsable = row
            result = {
                "localidad": localidad,
                "id_localidad": id_empresa,
                "GrupoAsignado": id_torre,
                "Responsable": id_responsable
            }
        else:
            result = {
                "localidad": request.form.get('localidad'),
                "id_localidad": localidad_input,
                "GrupoAsignado": "N/A",
                "Responsable": "N/A"
            }

        cursor.close()
        conn.close()
        return jsonify(result)

    except Exception as e:
        return jsonify({"error": f"Error al consultar la base de datos: {str(e)}"}), 500




def normalizar_localidad(localidad):
    if not localidad:
        raise ValueError("El parámetro 'localidad' es requerido y no puede ser vacío.")

    localidad = unidecode(localidad.lower().strip())
    return localidad




#---------------------------------------------------------------------------------------------------------------------------
#----------------------------------------RUTAS URL FORM---------------------------------------------------------------------
#---------------------------------------------------------------------------------------------------------------------------




@app.route('/reimpresion/generaTicketInc_form', methods=['POST'])
@requires_auth
def genera_ticket_inc_form():
    try:
        # Intentar obtener el cuerpo de la solicitud como JSON o formulario
        if request.is_json:
            data = request.get_json()
        else:
            data = request.form.to_dict()

        print("Datos recibidos:", data)

        if not data:
            return jsonify({"error": "Se esperaba un cuerpo JSON o formulario válido."}), 400

        # Normalizar y validar email
        email = normalize_email(data.get("UsuarioRequerimientoEmail", ""))
        if not email:
            return jsonify({"error": "El correo electrónico no es válido."}), 400

        # Validaciones básicas
        usuario_requerimiento_str = data.get("UsuarioRequerimientoStr")
        titulo = data.get("Titulo")
        descripcion = data.get("Descripcion")
        categoria_tercer_nivel = data.get("CategoriaTercerNivel")

        for campo, valor in {
            "UsuarioRequerimientoStr": usuario_requerimiento_str,
            "Titulo": titulo,
            "Descripcion": descripcion,
            "CategoriaTercerNivel": categoria_tercer_nivel
        }.items():
            if not valor:
                return jsonify({"error": f"El campo '{campo}' es obligatorio."}), 400

        # Limpieza de HTML en descripción
        descripcion = re.sub(r'<br\s*/?>', '', descripcion)
        descripcion = re.sub(r'\s*(?=\b(Q:|A:))', r'\n', descripcion).strip()

        # Construcción del JSON a enviar
        ticket_data = {
            "responsable": int(data.get("Responsable", 8288)),
            "prioridad": int(data.get("Prioridad", 3)),
            "usuariorequerimientostr": usuario_requerimiento_str,
            "usuariorequerimientoemail": email,
            "tipocontacto": int(data.get("TipoContacto", 6)),
            "grupoasignado": int(data.get("GrupoAsignado", 164)),
            "localidad": int(data.get("Localidad", 1)),
            "titulo": titulo,
            "descripcion": descripcion,
            "categoria": data.get("Categoria", "APP DE NEGOCIO"),
            "subcategoria": data.get("SubCategoria", "SIMAX"),
            "categoriatercerNivel": categoria_tercer_nivel,
            "compania": int(data.get("Compania", 16)),
            "tipoticket": int(data.get("TipoTicket", 1))
        }

        # Log antes de hacer la petición
        logging.info("Datos enviados al endpoint: %s", ticket_data)

        # Envío a la API
        response = requests.post(
            BASE_URL,
            json=ticket_data,
            auth=HTTPBasicAuth(API_USER, API_PASS)
        )

        logging.info("API Respuesta - Status: %d, Response: %s", response.status_code, response.text)

        if not response.text.strip():
            return jsonify({"error": "La respuesta de la API está vacía."}), 500

        try:
            response_data = response.json()
        except ValueError:
            return jsonify({"error": "La respuesta de la API no es un JSON válido."}), 500

        if response.status_code == 200:
            ticket_info = response_data.get("data", {})

            if response_data.get("isCorrect") == "false" and response_data.get("isBreakOperation") == "true":
                return jsonify({"message": response_data.get("message")}), 400

            ticket_id = str(ticket_info.get("id"))
            ticket_id_with_spaces = " ".join(ticket_id)

            filtered_data = {
                "id": ticket_id_with_spaces,
                "responsibility": ticket_info.get("responsibility"),
                "assignedGroup": ticket_info.get("assignedGroup"),
                "srType": ticket_info.get("srType"),
                "status": ticket_info.get("status")
            }
            return jsonify(filtered_data), 200
        else:
            return jsonify({"error": "Error al crear el ticket", "details": response_data}), response.status_code

    except Exception as e:
        logging.exception("Error al procesar el ticket")
        return jsonify({"error": str(e)}), 500

@app.route('/reimpresion/generaTicketReq_form', methods=['POST'])
@requires_auth
def genera_ticket_req_form():
    try:
        # Obtener datos desde JSON o desde formulario (www-form-urlencoded)
        if request.is_json:
            data = request.get_json()
        else:
            data = request.form.to_dict()

        logging.info("Datos recibidos: %s", data)

        if not data:
            return jsonify({"error": "Se esperaba un cuerpo JSON o formulario válido."}), 400

        # Normalizar y validar email
        email = normalize_email(data.get("usuariorequermientoemail", ""))
        if not email:
            return jsonify({"error": "El correo electrónico no es válido.", "correo: ": data.get("usuariorequermientoemail")}), 400

        # Validaciones básicas de campos obligatorios
        usuario_requerimiento_str = data.get("usuario_requerimiento_str")
        titulo = data.get("titulo")
        descripcion = data.get("descripcion", "")
        categoria_tercer_nivel = data.get("categoriatercernivel")

        # Limpieza de descripción (remover HTML innecesario)
        descripcion = re.sub(r'<br\s*/?>', '', descripcion)
        descripcion = re.sub(r'\s*(?=\b(Q:|A:))', r'\n', descripcion).strip()

        # Armar payload para la API externa
        ticket_data = {
            "Responsable": int(data.get("responsable", 0)),
            "Prioridad": int(data.get("prioridad", 0)),
            "UsuarioRequerimientoStr": usuario_requerimiento_str,
            "UsuarioRequerimientoEmail": email,
            "TipoContacto": int(data.get("tipocontacto", 0)),
            "GrupoAsignado": int(data.get("grupoasignado", 0)),
            "Localidad": int(data.get("localidad", 0)),
            "Titulo": titulo,
            "Descripcion": descripcion,
            "Categoria": data.get("categoria"),
            "SubCategoria": data.get("subcategoria"),
            "CategoriaTercerNivel": categoria_tercer_nivel,
            "Compania": int(data.get("compania", 0)),
            "TipoTicket": int(data.get("tipoticket", 0))
        }

        logging.info("Datos enviados a la API: %s", ticket_data)

        # Enviar solicitud a la API externa
        response = requests.post(
            BASE_URL,
            json=ticket_data,
            auth=HTTPBasicAuth(API_USER, API_PASS)
        )

        logging.info("Respuesta API - Código: %d, Cuerpo: %s", response.status_code, response.text)

        if not response.text.strip():
            return jsonify({"error": "La respuesta de la API está vacía."}), 500

        try:
            response_data = response.json()
        except ValueError:
            return jsonify({"error": "La respuesta de la API no es un JSON válido."}), 500

        if response.status_code == 200:
            ticket_info = response_data.get("data", {})

            if response_data.get("isCorrect") == "false" and response_data.get("isBreakOperation") == "true":
                return jsonify({"message": response_data.get("message")}), 400

            ticket_id = str(ticket_info.get("id", ""))
            ticket_id_with_spaces = " ".join(ticket_id)

            filtered_data = {
                "ticket": ticket_id_with_spaces,
                "responsable": ticket_info.get("responsibility"),
                "Gru_Asig": ticket_info.get("assignedGroup"),
                "tipo": ticket_info.get("srType"),
                "status": ticket_info.get("status")
            }
            return jsonify(filtered_data), 200
        else:
            return jsonify({"error": "Error al crear el ticket", "details": response_data}), response.status_code

    except Exception as e:
        logging.exception("Error al procesar el ticket")
        return jsonify({"error": str(e)}), 500







# Ruta para validar y normalizar el correo electrónico
@app.route('/reimpresion/validarEmail_form', methods=['POST'])
#@requires_auth
def validar_email_form():
    try:
        # Obtener el correo electrónico del cuerpo de la solicitud (form-url-encoded)
        email = request.form.get("email", "")

        # Verificar si el correo es válido
        if not email:
            return jsonify({"error": "El correo electrónico es requerido."}), 400

        # Normalizar el correo (si es necesario)
        email_normalizado = normalize_email(email)

        # Verificar si el correo es válido después de la normalización
        if not email_normalizado:
            return jsonify({"error": "El correo electrónico no tiene un formato válido."}), 400

        # Extraer el nombre de usuario (parte antes del @)
        usuario = email_normalizado.split('@')[0]

        # Retornar el correo normalizado y el nombre de usuario
        return jsonify({
            "email_normalizado": email_normalizado,
            "usuario": usuario
        }), 200

    except Exception as e:
        return jsonify({"error": str(e)}), 500

from flask import request, jsonify
import time
from sqlalchemy.exc import SQLAlchemyError
# Conexión a la base de datos
conn_str = (
    "DRIVER={ODBC Driver 17 for SQL Server};"
    "SERVER=fanafesadbkenos.database.windows.net;"
    "DATABASE=fanafesadb;"
    "UID=admindbkenos;"
    "PWD=K3n0sFanafes4!.*;"
)


@app.route('/reimpresion/buscarArticulo', methods=['POST'])
def registrar():
    # Obtener datos del formulario urlencoded
    numero_registros = 10
    palabras_clave = request.form.get('palabrasClave')
    link_default = 'Consultando'

   
    if palabras_clave is None or palabras_clave.strip() == '':
        return jsonify({"error": 'Falta el parámetro "palabrasClave"'}), 400

    # Convertir numero_registros a entero y validar
    try:
        numero_registros = int(numero_registros)
    except ValueError:
        return jsonify({"error": 'El parámetro "numeroRegistros" debe ser un número válido'}), 400

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        # Insertar nuevo registro con status = 0
        insert_query = """
            INSERT INTO RpaSap (numero_registros, palabras_clave, links,status )
            OUTPUT INSERTED.id
            VALUES (?, ?, ?, 0)
        """
        cursor.execute(insert_query, (numero_registros, palabras_clave, link_default ))
        nuevo_id = cursor.fetchone()[0]
        conn.commit()



        # Consultar el registro insertado
        select_query = "SELECT links FROM RpaSap WHERE id = ?"
        cursor.execute(select_query, (nuevo_id,))
        row = cursor.fetchone()

        if row:
            links_result = row.links

            return jsonify({"id": nuevo_id, "mensaje" : "Estamos revisando tu petición"})
        else:
            return jsonify({"error": "Registro no encontrado"}), 404

    except pyodbc.Error as e:
        return jsonify({"error": f"Error de base de datos: {str(e)}"}), 500
    except Exception as e:
        return jsonify({"error": f"Error inesperado: {str(e)}"}), 500
    finally:
        if 'conn' in locals():
            conn.close()
@app.route('/reimpresion/buscarArticulo_respuesta', methods=['POST'])
def mostrar_busqueda():
    # Obtener datos del formulario urlencoded
    id_busqueda = request.form.get('id')

    try:
        # Intentar convertir el id a entero
        id_busqueda = int(id_busqueda)
    except (ValueError, TypeError):
        return jsonify({"error": "El id debe ser un entero válido"}), 400

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        # Consultar el registro insertado
        select_query = "SELECT links FROM RpaSap WHERE id = ?"
        cursor.execute(select_query, (id_busqueda,))
        row = cursor.fetchone()

        if row:
            links_result = row.links
            return jsonify({"links": links_result})
        else:
            return jsonify({"error": "Registro no encontrado"}), 404

    except pyodbc.Error as e:
        return jsonify({"error": f"Error de base de datos: {str(e)}"}), 500
    except Exception as e:
        return jsonify({"error": f"Error inesperado: {str(e)}"}), 500
    finally:
        if 'conn' in locals():
            conn.close()
# Cadena de conexión a SQL Server
connection_string = (
    "DRIVER={ODBC Driver 17 for SQL Server};"
    "Server=fanafesadbkenos.database.windows.net;"
    "Database=fanafesadb;"
    "UID=admindbkenos;"
    "PWD=K3n0sFanafes4!.*;"
)
@app.route('/reimpresion/Valida_existecia_correo', methods=['POST'])

def buscar_articulo_respuesta():
    correo = request.form.get('correo_electronico')

    if not correo:
        return jsonify({"error": "Falta el parámetro correo_electronico"}), 400

    try:
        conn = pyodbc.connect(connection_string)
        cursor = conn.cursor()

        query = "SELECT COUNT(*) FROM Usuarios_ref WHERE Correo_electronico = ?"
        cursor.execute(query, (correo,))
        resultado = cursor.fetchone()[0]

        cursor.close()
        conn.close()

        return jsonify({
            "correo_electronico": correo,
            "existe": resultado > 0
        })

    except Exception as e:
        return jsonify({"error": str(e)}), 500
# Cadena de conexión
conn_str = (
    "DRIVER={ODBC Driver 17 for SQL Server};"
    "SERVER=fanafesadbkenos.database.windows.net;"
    "DATABASE=fanafesadb;"
    "UID=admindbkenos;"
    "PWD=K3n0sFanafes4!.*;"
)

@app.route('/reimpresion/insertarVariacion', methods=['POST'])
# @requires_auth  # Descomentá si vas a usar autenticación
def insertar_variacion():
    data = request.json
    palabra_similar = data.get('palabra_similar')
    palabra_completa = data.get('palabra_completa')

    if not palabra_similar or not palabra_completa:
        return jsonify({'error': 'Faltan datos requeridos'}), 400

    try:
        conn = pyodbc.connect(conn_str)
        cursor = conn.cursor()
        cursor.execute("""
            INSERT INTO variaciones_usuarios (palabra_similar, palabra_completa)
            VALUES (?, ?)
        """, (palabra_similar, palabra_completa))
        conn.commit()
        cursor.close()
        conn.close()

        return jsonify({'message': 'Datos insertados correctamente'}), 200
    except pyodbc.IntegrityError:
        return jsonify({'error': 'La palabra_similar ya existe'}), 409
    except Exception as e:
        return jsonify({'error': str(e)}), 500

EMAIL_OUTLOOK = "sergio.alzaga@scanda.com.mx"
EMAIL_PASSWORD = "SER132gio."

@app.route("/reimpresion/enviarCorreo", methods=["POST"])
def enviar_correo():
    data = request.json
    mensaje = data.get("mensaje")
    correo = data.get("correo")

    if not mensaje or not correo:
        return jsonify({"error": "Faltan campos requeridos"}), 400

    try:
        validate_email(correo)
    except EmailNotValidError as e:
        return jsonify({"error": str(e)}), 400

    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    result = loop.run_until_complete(enviar_email_async(mensaje, correo))
    return jsonify(result)

async def enviar_email_async(mensaje_texto, correo_destino):
    try:
        mensaje = EmailMessage()
        mensaje["From"] = EMAIL_OUTLOOK
        mensaje["To"] = correo_destino
        mensaje["Subject"] = "Solicitud de ticket"
        mensaje.set_content(mensaje_texto)

        smtp = SMTP(hostname="smtp.office365.com", port=587, start_tls=True)
        await smtp.connect()
        # await smtp.starttls()  <-- ❌ Quitar esta línea
        await smtp.login(EMAIL_OUTLOOK, EMAIL_PASSWORD)
        await smtp.send_message(mensaje)
        await smtp.quit()

        return {"detalle": "Correo enviado correctamente"}
    except Exception as e:
        return {"error": str(e)}

#______________________________________________________________________________________________________________________________
#____________________________________________Voice Flow________________________________________________________________________
#______________________________________________________________________________________________________________________________

# Logging
handler = RotatingFileHandler('voiceflow_api.log', maxBytes=10000, backupCount=1)
handler.setLevel(logging.INFO)
handler.setFormatter(logging.Formatter('%(asctime)s - %(levelname)s - %(message)s'))
app.logger.addHandler(handler)
app.logger.setLevel(logging.INFO)

# Voiceflow Auth
VF_API_KEY = "VF.DM.6966925738c8fb5bdf6bcd81.v2Jtuu2nYTUzbK69"
VF_BASE_URL = "https://api.voiceflow.com/v2"



# === Función auxiliar: Buscar transcriptID por sessionID ===
def find_id_by_session_id(session_id, array):
    item = next((item for item in array if item['sessionID'] == session_id), None)
    return item['_id'] if item else None

def generate_html_conversation(details, all_conversations=False):
    import datetime
    import re
    from collections import defaultdict

    conversation = details.get("conversation", [])
    date = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    id_caso_value = None  # Inicializar id_caso

    if all_conversations:
        relevant_traces = conversation
    else:
        begins = [
            e for e in conversation
            if e.get("type") == "trace" and e.get("payload", {}).get("payload", {}).get("name") == "Begin Convo"
        ]
        ends = [
            e for e in conversation
            if e.get("type") == "trace" and e.get("payload", {}).get("payload", {}).get("name") == "End Convo"
        ]

        if begins:
            last_begin = max(begins, key=lambda e: e["startTime"])
            last_end = next((e for e in ends if e["startTime"] > last_begin["startTime"]), None)
            if last_end:
                relevant_traces = [e for e in conversation if last_begin["startTime"] <= e["startTime"] <= last_end["startTime"]]
            else:
                relevant_traces = [e for e in conversation if e["startTime"] >= last_begin["startTime"]]
        else:
            relevant_traces = conversation  # fallback si no hay begin/end

    # Agrupar por turnID
    turns = defaultdict(list)
    for idx, trace in enumerate(relevant_traces):
        turn_id = trace.get("turnID") or f"no-turn-{idx}"
        turns[turn_id].append(trace)

    # Íconos (puedes reemplazar con tus propios base64 si lo deseas)
    agent_icon = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAwCAYAAABXAvmHAAAKiklEQVR4Ae1ZBVRbyxa9z93dX93dPTgprnWc4HUvEhx+3d3d3d2ou7sLboWQQLL/nAv3v3t/Slax57PWXjOMnn1k5tzA/R3La78PqqC0UCjecnFxeYM1X69qyGTKNwmVJjwv+O9e6NxVFT9XMKll98Gy1h17zmlQr+vahg2t1zRsYL2mAQ+rtQ0kbenf+tBfQ7UYHUw9xtv2HlqnwsoTFpvYBg5q26kPnLwjoQidDd+RM3/DqFJqvj2LQH0CSl8ngnWPYWjdsYfOwinElM6vkDvZ9RrWqFX77uiXsBzLzrxQrbygUq84r1KvLA0XhJrm5auXn8uT9ovnEURjKwgXVJqFSan57oMmgp37SKmc/67YE8pcOpl7DTG374cFSansoAIsPpmFpadzsPRUthjUx/Bbewmrl599gVUX1cJ8Sa2/R3Hf4hOZWHOlCBM3X9G0bOMKS+f+Mv4SaaF4q1wEWrfvrnT0jMCiE5mapadzSUg6SA9LGDEBTAhmrVzM2v8IiStPUZ90nGoxERFojFkC03beKepk6gUjC4WVcAuWj0DH7hH27mFYdDxDs4xp9GUHUx9zF6y6pOY1ztwBG24C0YsO45cvOtEc0iobK6BxHstL2YsIkKWn7rhd1NHEE50tfbpWGgEWA1IXIJQIP2P3PcQtTUL8suN8PXr1GQTHLEIHE3dELTiIxBUn2dgxHgmsveBoKpafyycSLyUwrYSAkaVfxQk4EIETRECqtSX8YSqM33AJzVs6oEH9rmjWyhmNGtugfh1LtJX1gZF1AJq2dET9upZo3MSWH6/+dUc4eIZh/pEUwRKlE7D2qxoLCAFL/uo1dCJkjoMRvu4uRq2+hbB19xCx8SHfHrnqBkLX3GZ/P6KaHxuy4Ayqfy/jXWz1JQ0JrU+AYsDMG13kvvLKIyC2QEm9grmB+4DRcOw7GXG7MxG9PQUjVlxD/1lJfDtqWzKUm58gZPohhDGCMbvSodz0CK1l7lDO3ftSAhQrU7bfLGzbsTdMbYLsKtOF9GKALOAxcCxsA8YiettzxOxMQ68R89CikTmvdSIxbOll/MhxCJ6yD7G7MnhLtGjfE5Hz9okJCG5JMaWbfeAx5N0GqerXlzcrfsxkb1aFBYgAs8AY2AWOQ8yOVF7jA+eehE/CBl7zhNC1d+ERtRLDl19FNJtDbkQWiJq/X0xADPXmu8DiU1kJJMN+gBe+YhYQE9CzwBjYEgGmfdI6kYjbk8UIpZEFWH86Ypl7EZnIrc94V6rLgjx81i6svlzICGTqEdh0G2SNeJJBOX8/vcYsrSErlAJmIUp9yuBCYguICGx6zAs6ZNEFDJhzAoPmn+brESuvFxPY8pTNeQSHgNEYu+48H7BLJBbIpbpg6z1g3TWEVzhzlljAcAwwjafwBMgKZs4D0fin1mjbzgl1uB/QJ2wRWYMfC9v4GGP3pLILQN+i9Dd7MLVEJCh64XmOaz+tRqvu87kv5HO5183mcW9bzOPeEuFdi7kcZza/Vqvuo3t7DmnOW0ypfL1ct1D8niym4WfkQnSFsuC9hOHLrvB1+IYHiNqezAd6+OanGL3rOUs3JG4jvYW23dQ2aGgLz8BYBA0ag+FhkxEaOQ2jlFMRyjBKhPDo6ejhGYrvmnSD3CnEV8hgDROQvAOTJO8AIXz9fRKaRwRDWMlY6JpbGLHmHsbtZY8Yy5eWSAkIjyO9A6jRxKFo5foj6sMn76sfPspVv3gBdXa2Tp3DkC1CTo5OnZamyZ80dTm+b+IKr4DQOoYtID7svP5L3LiZPRo2sEKDenLq4+tGjazRol03VPuiA6zcIrDgOJ/w6bvQ/x6y2/ipjjUYARw8fg/3H2QjNxfIzNQiK0uKjIxCqFTAkye5BcY2weho4TOojLmQiuVC94VcSMh5qC1BwvLjiF18FBM2XibhxfvoE9hxC7/Us8GKtYdxIOku7t7PRHa2FunphUxgPTBiRUhLK9B4+kfi56auSkk67eAZQQQK2aE6OoC0JgafAp+jbFRDmaYYJIwY1EeEad1LQak47TWduVC1BnZYvvYQDhy7i3v3s8BcRRBWAupjroTU1AKNm284qrd0jeCE0sVSMcDYOhBzDz9T051NWqIEbJkUJUQyQQQJQltqrd+CnyxAFhVqgmCVtVe1GLf+Aj75XIa1m08wC9x5ZQLuighUby4iYOMTWo192mn9wmazFDhFxQQoZMJqmHCFBGozYbWCUIKQy+g+Z38Lc5aeytIxwBCIMK2dtfcBujgNRC/P4Uz7d7D74HXcf5iNnOwyEhCuIjP7wB6MBCycBqB7YAK6+ccxxPN1j6BE2LmPgs+I6dr/07jOd+QMrYNHGHoG/wcWPYeho0N/XWcmWGe2jwTU51hcy5wH4c2fLCG39ceG7Wdw+MQ97DpwjYK47ATEL5uTR2itDiYeYc1aOU1k36rjW7RxmdCitfOklm1dE5s2dzxp4dQP85NStWQB9vWlU87bp6v5ozE6mrgv/KWWfPSIiKnPxk1egYSxC7WJ4xahFNA4ZszbjD2HruPIyfvYd+QmdrP2k2d5FMRlJ/AqmWCz5g5rPYdMpgBVM+G1Y9acze9k6gNz++Clwpwrt7J2nL2aSVei+uDx+1QbAiNwAzuZ5nfsv4qLV58jm65LQWhDQUwExEEstoSLi/JtysvpFwIbG8X7fIz0GW7cpKk94pYdV1MOM2jsWnSQeaKLpc/uVasg5CW/HDl6+fbte9k4f+lp4cUrz3CBgWoBF0Q1gYS+xHCXBW9WFglqGEQgJUWl8fBT4meygKEi5Boym4C27Ispx8yhL/8DVlfXwWjYyFpnbBM4WrLgU8to78BoeogKhJskq+TgLH3w/SQ0zSW/F4RMT9ewu14tBvUxzatpnP4u7Ok1CnVb9zRMQPg6klkpxpvYBkHWVfGwSXP74+wDPs5JoawrzJPLQ96h2to5pFf11m44feaWqqgI2vx8aFUqg9CxtIGEk/h8Xh5QUAAdg1YM2k+nQ9HxE9fUP7foDbvu/Z0NEhDHRgczr+9l9h6fSvuVb4p/KgfwevMu7qcdew3Hhk0Hcez4VRxNusxwBQKSSmoaO3joPK5cfaTNyeFJlFhEi/MX7uHQ4QtIOnaFXy+A1qzfeADWzANaGXsmceUtJDjhZfl5D8WgL9uYeKznPpHncd9YFXHfWjNYaRmoTdDy/TXtCjnOHNVa9mBXZ4aWLEFWSE7Oh5ltADiuUxFXza6oeA+G76yL25/J89qZea/q2XP4Z2X5Ufg1igcC0/Nrr/KRMXRo4o++QWENAgOj6/mFKOuL4RsU1cDDL7TWiLDRbb5q5JwRGTuD4kZNrpPCbhhFcAzamXoui4mZ/oNPwMjGtIb2of0GjBr3Q5X+O4BIlmVj195D7V+v64L40XPx7HleAYCCuQs2gtn5uiFF8cqswvIauRhdAoYgBH83j8HdP6zrqLXvMQS79pzCnn2n8+u1d4MF+/wTLglhDe+6f6YiCBQUpKzdvLP7Ko4zVhtZB8HKZQDkLkNhZOUfJZ73pywK0Y9ZoaGTq7Ux9ujTwdw7zsjKb4lM7uds2N//VJYoxa+p/y9SXiNNExkBVa75f8u/5d/yb/m3/BfStUKBYYaMvgAAAABJRU5ErkJggg=="
    user_icon = "data:image/png;base64,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"


    html = f"<h3>Historial de conversación con Kairos | Voice</h3><p>{date}</p><ul style='list-style: none;'>"
    history = []

    for turn_id, traces in turns.items():
        user_msg = kairos_msg = intent_name = confidence = block_id = None
        timestamp = ""

        for trace in traces:
            msg_time = trace.get('startTime')
            if msg_time:
                try:
                    dt = datetime.datetime.fromisoformat(msg_time.replace("Z", "+00:00"))
                    dt -= datetime.timedelta(hours=6)
                    timestamp = dt.strftime("%Y-%m-%d %H:%M:%S")
                except Exception:
                    timestamp = msg_time

            if trace["type"] == "request" and trace.get("format") == "request":
                user_msg = trace["payload"]["payload"].get("query", "").replace('\n', '<br>')

            elif trace["type"] in ["text", "speak", "output"] and trace.get("format") == "trace":
                kairos_msg = trace["payload"]["payload"].get("message") or trace["payload"]["payload"].get("text", "")
                kairos_msg = kairos_msg.replace('\n', '<br>') if kairos_msg else ""

            elif trace["type"] == "debug" and trace["payload"]["payload"].get("context") == "Intent prediction":
                intent_payload = trace["payload"]["payload"]
                intent_name = intent_payload.get("metadata", {}).get("intent")
                confidence = intent_payload.get("metadata", {}).get("confidence")

            elif trace["type"] == "block":
                block_id = trace["payload"]["payload"].get("blockID")

            elif trace["type"] == "IDCaso Convo":
                payload = trace.get("payload", {}).get("payload", "")
                if isinstance(payload, str) and "id_caso" in payload:
                    try:
                        match = re.search(r'"?id_caso"?\s*:\s*(\d+)', payload)
                        if match:
                            id_caso_value = match.group(1)
                    except Exception:
                        pass

        if user_msg:
            html += (
                f"<li style='margin-bottom: 10px;'>"
                f"<img src='{user_icon}' width='40' height='40'> "
                f"<strong>Usuario :</strong> {user_msg}<br>"
                f"<small style='color:gray'>{timestamp}</small></li>"
            )
            history.append({
                "turnID": turn_id,
                "hora": timestamp,
                "msg": user_msg,
                "user": "user",
                "intent": intent_name,
                "confidence": confidence,
                "blockID": block_id
            })

        if kairos_msg:
            html += (
                f"<li style='margin-bottom: 10px;'>"
                f"<img src='{agent_icon}' width='40' height='40'> "
                f"<strong>Kairos :</strong> {kairos_msg}<br>"
                f"<small style='color:gray'>{timestamp}</small></li>"
            )
            history.append({
                "turnID": turn_id,
                "hora": timestamp,
                "msg": kairos_msg,
                "user": "Kairos",
                "intent": intent_name,
                "confidence": confidence,
                "blockID": block_id
            })

    html += "</ul>"
    return html, history, {"id_caso": id_caso_value}





# === Ruta de prueba ===
@app.route('/reimpresion/voiceflow/ping', methods=['GET'])
def ping():
    return jsonify({'message': 'Voiceflow API funcionando correctamente'}), 200



import json
import requests
from flask import request, jsonify

@app.route('/reimpresion/voiceflow/conversacion_html', methods=['GET'])
def get_convo_html():
    session_id = request.args.get('sessionID')
    project_id = request.args.get('projectID')
    api_key = request.args.get('VFAPIKey')
    to_email = request.args.get('to')
    range_param = request.args.get('range')
    all_param = request.args.get('all', 'false').lower()
    all_conversations = all_param == 'true'

    app.logger.info("Iniciando endpoint /voiceflow/conversacion_html")

    if not session_id or not project_id or not api_key:
        app.logger.warning("Parámetros faltantes en la solicitud")
        return jsonify({'error': 'Faltan parámetros: sessionID, projectID y VFAPIKey son requeridos.'}), 400

    headers = {"Authorization": api_key}
    if range_param:
        headers["Range"] = "All time"

    try:
        response = requests.get(f"https://analytics-api.voiceflow.com/v1/transcript/project/{project_id}", headers=headers)
        response.raise_for_status()
        transcripts = response.json()

        transcript_id = find_id_by_session_id(session_id, transcripts)
        if not transcript_id:
            return jsonify({'error': 'No se encontró ningún transcript con ese sessionID.'}), 404

        detail_resp = requests.get(f"https://analytics-api.voiceflow.com/v1/transcript/{transcript_id}", headers=headers)
        detail_resp.raise_for_status()
        details = detail_resp.json()

        html_convo, history_json, extras = generate_html_conversation({"conversation": details}, all_conversations=all_conversations)
        id_caso = extras.get("id_caso")


        email_status = None

        if to_email:
            app.logger.info(f"Intentando enviar correo a: {to_email}")
            email_payload = {
                "from": "Kairos <kairos@resend.dev>",
                "to": to_email,
                "subject": "Your last conversation with Kairos",
                "html": html_convo,
                "mensajes": details
            }

            email_headers = {
                "Authorization": "re_MGLhPdwA_JvoX4Fkikh3S3gP2Yw5coGwh",
                "Content-Type": "application/json"
            }

            max_attempts = 10
            attempt = 0
            success = False
            while attempt < max_attempts and not success:
                attempt += 1
                email_response = requests.post(
                    "https://api.resend.com/emails",
                    headers=email_headers,
                    data=json.dumps(email_payload)
                )
                if email_response.status_code < 400:
                    app.logger.info(f"Correo enviado exitosamente en el intento #{attempt}")
                    success = True
                    email_status = "Correo enviado exitosamente"
                else:
                    app.logger.warning(f"Intento #{attempt} fallido al enviar correo: {email_response.text}")
                    if attempt == max_attempts:
                        email_status = f"Fallo al enviar el correo después de {max_attempts} intentos"
        else:
            email_status = "No se envió correo (parámetro 'to' no recibido)"

        return jsonify({
            "_ID": transcript_id,
            "id_caso": id_caso,
            "sessionID": session_id,
            "projectID": project_id,
            "html": html_convo,
            "historial_json": history_json,
            
            "email_status": email_status
        })


    except requests.exceptions.RequestException as e:
        app.logger.error(f"Error de conexión con Voiceflow o Resend: {str(e)}")
        return jsonify({'error': 'Error de conexión externa', 'details': str(e)}), 502
    except Exception as e:
        app.logger.error(f"Error inesperado: {str(e)}")
        return jsonify({'error': 'Error inesperado', 'details': str(e)}), 500
# === Función auxiliar: Buscar transcriptID por número telefónico ===
# === Función auxiliar: Normalizar números telefónicos ===
def normalize_phone(num):
    return ''.join(filter(str.isdigit, num))  # Solo conserva dígitos

# === Buscar transcriptID por número telefónico normalizado ===
def find_id_by_phone(phone, array):
    normalized_input = normalize_phone(phone)
    for item in array:
        user_num = item.get("customProperties", {}).get("userNumber")
        if user_num:
            app.logger.debug(f"Comparando '{normalized_input}' con '{normalize_phone(user_num)}'")
            if normalize_phone(user_num) == normalized_input:
                return item["_id"]
    return None

@app.route('/reimpresion/voiceflow/conversacion_html_phone', methods=['GET'])
def get_convo_html_by_phone():
    phone = request.args.get('phone')
    project_id = request.args.get('projectID')
    api_key = request.args.get('VFAPIKey')
    to_email = request.args.get('to')
    range_param = request.args.get('range')
    all_param = request.args.get('all', 'false').lower()
    all_conversations = all_param == 'true'

    app.logger.info("Iniciando endpoint /voiceflow/conversacion_html por número telefónico")

    if not phone or not project_id or not api_key:
        app.logger.warning("Parámetros faltantes en la solicitud")
        return jsonify({'error': 'Faltan parámetros: phone, projectID y VFAPIKey son requeridos.'}), 400

    headers = {"Authorization": api_key}
    if range_param:
        headers["Range"] = "All time"

    try:
        # Obtener la lista de transcripts
        response = requests.get(f"{VF_BASE_URL}/transcripts/{project_id}", headers=headers)
        response.raise_for_status()
        transcripts = response.json()

        # Buscar transcript ID
        transcript_id = find_id_by_phone(phone, transcripts)
        if not transcript_id:
            app.logger.warning(f"No se encontró transcript para el número: {phone}")
            return jsonify({'error': 'No se encontró ningún transcript con ese número telefónico.'}), 404

        # Obtener detalle del transcript
        detail_resp = requests.get(f"{VF_BASE_URL}/transcripts/{project_id}/{transcript_id}", headers=headers)
        detail_resp.raise_for_status()
        details = detail_resp.json()

        # Generar HTML de la conversación
        try:
            html_convo, history_json = generate_html_conversation(
                {"conversation": details},
                all_conversations=all_conversations
            )
        except Exception as e:
            app.logger.error(f"Error generando HTML: {str(e)}")
            return jsonify({'error': 'Error generando conversación HTML', 'details': str(e)}), 500

        # Envío de correo si se solicitó
        email_status = "No se envió correo (parámetro 'to' no recibido)"
        if to_email:
            app.logger.info(f"Intentando enviar correo a: {to_email}")

            email_payload = {
                "from": "Kairos <kairos@resend.dev>",
                "to": to_email,
                "subject": "Your last conversation with Kairos",
                "html": html_convo,
                "mensajes": details
            }

            email_headers = {
                "Authorization": "re_MGLhPdwA_JvoX4Fkikh3S3gP2Yw5coGwh",  # Asegúrate de que esté actualizada y válida
                "Content-Type": "application/json"
            }

            max_attempts = 10
            success = False
            for attempt in range(1, max_attempts + 1):
                email_response = requests.post(
                    "https://api.resend.com/emails",
                    headers=email_headers,
                    data=json.dumps(email_payload)
                )
                if email_response.status_code < 400:
                    app.logger.info(f"Correo enviado exitosamente en el intento #{attempt}")
                    success = True
                    email_status = f"Correo enviado exitosamente (intento #{attempt})"
                    break
                else:
                    app.logger.warning(f"Intento #{attempt} fallido al enviar correo: {email_response.text}")
            if not success:
                email_status = f"Fallo al enviar el correo después de {max_attempts} intentos"

        # Respuesta final
        return jsonify({
            "_ID": transcript_id,
            "userNumber": phone,
            "projectID": project_id,
            "html": html_convo,
            "historial_json": history_json,
            "email_status": email_status
        })

    except requests.exceptions.RequestException as e:
        app.logger.error(f"Error de conexión con Voiceflow o Resend: {str(e)}")
        return jsonify({'error': 'Error de conexión externa', 'details': str(e)}), 502
    except Exception as e:
        app.logger.error(f"Error inesperado: {str(e)}")
        return jsonify({'error': 'Error inesperado', 'details': str(e)}), 500









# ---------------------------
# Cargar correcciones
# ---------------------------
def cargar_variaciones():
    variaciones = {}
    conn = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT palabra_similar, palabra_completa FROM variaciones_usuarios")
        for row in cursor.fetchall():
            if row.palabra_similar and row.palabra_completa:
                variaciones[row.palabra_similar.lower()] = row.palabra_completa.lower()
    except Exception as e:
        print(f"Error al cargar variaciones: {e}")
    finally:
        if conn:
            conn.close()
    return variaciones

# ---------------------------
# Correcciones
# ---------------------------
def aplicar_correcciones(cadena, variaciones):
    for incorrecto, correcto in variaciones.items():
        try:
            if incorrecto and correcto:
                cadena = cadena.replace(incorrecto, correcto)
        except Exception as e:
            print(f"Error al aplicar corrección '{incorrecto}': {e}")
            continue
    return cadena

# ---------------------------
# Normalización
# ---------------------------
def normalizarEmail(cadena, variaciones):
    cadena = cadena.lower()
    cadena = unidecode(cadena)
    cadena = cadena.replace('arroba', '@').replace('punto', '.')
    cadena = re.sub(r'\s+', '', cadena)
    return aplicar_correcciones(cadena, variaciones)

# ---------------------------
# Comparar partes
# ---------------------------
def comparar_partes(email1, email2):
    partes1 = email1.split('@', 1)
    partes2 = email2.split('@', 1)

    local1 = partes1[0]
    dominio1 = partes1[1] if len(partes1) > 1 else ''
    local2 = partes2[0]
    dominio2 = partes2[1] if len(partes2) > 1 else ''

    score_local = fuzz.partial_ratio(local1, local2)
    score_dominio = fuzz.partial_ratio(dominio1, dominio2)

    return int((0.7 * score_local) + (0.3 * score_dominio))

# ---------------------------
# Verbalizar email
# ---------------------------
def verbalizar_email(email):
    if email.endswith(".mx"):
        email_sin_mx = email[:-3]
        return email_sin_mx.replace('.', ' punto ').replace('@', ' arroba ') + " . m x"
    else:
        return email.replace('.', ' punto ').replace('@', ' arroba ')

# ---------------------------
# Endpoint de búsqueda
# ---------------------------
@app.route('/reimpresion/buscar_email_selector', methods=['POST'])
@requires_auth
def buscar_email():
    try:
        data = request.get_json()
        email_input = data.get("email", "")
 
        if email_input.startswith('@') and len(email_input) > 1:
            email_input = email_input[1:]
 
        if not email_input:
            return jsonify({"error": "El campo 'email' es requerido."}), 400
 
        variaciones = cargar_variaciones()
        email_normalizado_input = normalizarEmail(email_input, variaciones)
 
        umbral = 70
        limite = 15
 
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM Usuarios_ref")
        rows = cursor.fetchall()
 
        coincidencias = []
 
        input_parece_email = '@' in email_normalizado_input
 
        for row in rows:
            email_db = row.Correo_electronico
 
            # Validaciones para evitar correos malformateados
            if not email_db or email_db.strip() in ['.', '']:
                continue
            email_db = email_db.strip()
 
            # Saltar si hay más de 1 arroba (probable concatenación)
            if email_db.count('@') != 1:
                continue
 
            # Si es muy largo, probablemente está concatenado
            if len(email_db) > 80:
                continue
 
            # Si no contiene dominio válido básico, ignorar
            if not any(ext in email_db for ext in ['.com', '.mx', '.net']):
                continue
 
            email_normal_db = normalizarEmail(email_db, {})
 
            if input_parece_email:
                score = comparar_partes(email_normalizado_input, email_normal_db)
            else:
                score = fuzz.partial_ratio(email_normalizado_input, email_normal_db)
 
            if score >= umbral:
                coincidencias.append((row.Id_usuario, email_db, email_normal_db, score))
 
        coincidencias.sort(key=lambda x: x[3], reverse=True)
 
        resultados = []
        for Id_usuario, email_db, email_normal_db, score in coincidencias[:limite]:
            usuario = email_db.split('@')[0]
            texto = verbalizar_email(email_db)
            resultados.append({
                "email_normalizado": email_normal_db,
                "usuario": usuario,
                "texto": texto
            })
 
        return jsonify(resultados), 200
 
    except pyodbc.Error as e:
        app.logger.error(f"Database error: {e}")
        return jsonify({"error": f"Error de base de datos: {str(e)}"}), 500
    except Exception as e:
        app.logger.error(f"Error desconocido: {e}")
        return jsonify({"error": f"Error interno del servidor: {str(e)}"}), 500
    finally:
        try:
            conn.close()
        except:
            pass



@app.route('/reimpresion/genera_cierra_tickets', methods=['POST'])
def genera_cierra_tickets(usuario, password, correo, formatted_cantidad, fecha, tipo_compra, referencia):
    try:
        # Paso 1: Login en el primer sistema (kenos-atom.com)
        login_kenos_atom = "https://proy020.kenos-atom.com/api/v1/login"
        login_payload = {
            "Username": "fa_user_01",
            "Password": "Jz5C*1.9F5"
        }
        response = requests.post(login_kenos_atom, json=login_payload)
        if response.status_code != 200:
            return jsonify({"error": "Fallo login kenos-atom", "details": response.text}), 500

        token = response.json().get("token")
        if not token:
            return jsonify({"error": "Token no recibido desde kenos-atom"}), 500

        # Paso 2: Crear ticket
        create_ticket_url = "https://proy020.kenos-atom.com/api/v1/Ticket/generateTicket"
        
        descripcion = (
            f"Solicitud de reimpreción por bot\n"
            f"Usuario: {usuario}\n"
            f"Correo: {correo}\n"
            f"Cantidad: {formatted_cantidad}\n"
            f"Fecha: {fecha}\n"
            f"Tipo de compra: {tipo_compra}\n"
            f"Referencia: {referencia}"
        )
        
        ticket_payload = {
            "UsuarioRequerimientoEmail": correo,
            "CategoriaTercerNivel": "REIMPRESION DE VOUCHER",
            "Descripcion": descripcion
        }
        headers = {"Authorization": f"Bearer {token}"}
        response = requests.post(create_ticket_url, json=ticket_payload, headers=headers)
        if response.status_code != 200:
            return jsonify({"error": "Fallo crear ticket", "details": response.text}), 500

        ticket_data = response.json().get("data", {})
        ticket_id = ticket_data.get("id")
        if not ticket_id:
            return jsonify({"error": "ID del ticket no encontrado"}), 500

        # Paso 3: Login en SysAid usando requests.Session para mantener la cookie
        session = requests.Session()
        login_sysaid_url = "https://kenos.sysaidit.com/api/v1/login"
        login_sysaid_payload = {
            "user_name": "chatbot.seus",
            "password": "Farmacos2024*"
        }
        response = session.post(login_sysaid_url, json=login_sysaid_payload)
        if response.status_code != 200:
            return jsonify({"error": "Fallo login sysaid", "details": response.text}), 500

        # Paso 4: Actualizar ticket en SysAid con sesión activa
        update_ticket_url = f"https://kenos.sysaidit.com/api/v1/sr/{ticket_id}"
        update_payload = {
            "id": str(ticket_id),
            "info": [
                {
                    "key": "update_time",
                    "value": int(time.time() * 1000)
                },
                {
                    "key": "status",
                    "value": "5"
                }
            ]
        }
        response = session.put(update_ticket_url, json=update_payload)
        if response.status_code != 200:
            return jsonify({
                "error": "Fallo al actualizar el ticket",
                "details": response.text
            }), 500

        return jsonify({
            "ticket_id": ticket_id,
            "mensaje": "Ticket creado y actualizado correctamente"
        })

    except Exception as e:
        return jsonify({"error": str(e)}), 500

# ==============================================================
# CONFIGURACIÓN API SAP
# ==============================================================
USERNAME_SAP_API = "TI1BOTAPI"
PASSWORD_SAP_API = "Inicio.2024%"
BASE_URL_SAP_API = "https://104.155.147.108:8443"


# ==============================================================
# FUNCIÓN GENÉRICA PARA CONSUMIR API SAP
# ==============================================================
def call_sap_api_SAP_API(endpoint_SAP_API: str, data_SAP_API: dict):
    """
    Llama al API SAP exactamente igual que lo hace Postman:
    - Todos usan GET con body raw (data=)
    - Autenticación básica
    """
    try:
        # Convertir el payload a texto JSON
        json_body = json.dumps(data_SAP_API)
        url = f"{BASE_URL_SAP_API}{endpoint_SAP_API}"

        print(f"\n📤 Llamando SAP GET {url}")
        print(f"📦 Body enviado: {json_body}")

        response_SAP_API = requests.request(
            method="GET",                     # SAP lo espera así
            url=url,
            data=json_body,                   # ⚠️ data= en lugar de json=
            auth=(USERNAME_SAP_API, PASSWORD_SAP_API),
            headers={"Content-Type": "application/json"},
            verify=False                      # 🔒 Cambiar a True en producción
        )

        response_SAP_API.raise_for_status()
        raw_text_SAP_API = response_SAP_API.text or ""
        print("📥 Respuesta cruda SAP:", repr(raw_text_SAP_API))

        # ==============================================================
        # Limpieza robusta antes del parseo
        # ==============================================================
        raw_text_SAP_API = raw_text_SAP_API.lstrip("\ufeff").strip()

        # Eliminar posibles caracteres basura antes del JSON
        if "{" in raw_text_SAP_API:
            raw_text_SAP_API = raw_text_SAP_API[raw_text_SAP_API.find("{"):]
        raw_text_SAP_API = raw_text_SAP_API.strip()

        # Si SAP devuelve varios JSON seguidos
        if raw_text_SAP_API.startswith("{") and "},{" in raw_text_SAP_API:
            raw_text_SAP_API = f"[{raw_text_SAP_API}]"

        # Intentar parsear JSON limpio
        result_json = json.loads(raw_text_SAP_API)
        print("✅ JSON parseado correctamente:", result_json)
        return result_json

    except requests.RequestException as e_SAP_API:
        print("⚠️ Error conexión SAP:", str(e_SAP_API))
        return {"error_SAP_API": str(e_SAP_API)}

    except ValueError as e_SAP_API:
        print("⚠️ Error parseando JSON SAP:", str(e_SAP_API))
        return {"error_SAP_API": f"Respuesta inválida desde SAP: {str(e_SAP_API)}"}


# ==============================================================
# RUTA 1: Validar usuario SAP
# ==============================================================
@app.route("/reimpresion/usercheck", methods=["POST"])
def user_check_SAP_API():
    payload_SAP_API = request.get_json()

    if not payload_SAP_API or "User" not in payload_SAP_API:
        return jsonify({"error_SAP_API": "Falta parámetro 'User'"}), 400

    result_SAP_API = call_sap_api_SAP_API(
        "/sap/bc/zsrv_user_valid/usercheck",
        payload_SAP_API
    )

    print("🔎 Resultado SAP usercheck:", result_SAP_API)

    if isinstance(result_SAP_API, dict) and "DATA" in result_SAP_API:
        data_SAP_API = result_SAP_API["DATA"]
        return jsonify({
            "existAccount_SAP_API": data_SAP_API.get("existAccount"),
            "activeAccount_SAP_API": data_SAP_API.get("activeAccount"),
            "isGeneric_SAP_API": data_SAP_API.get("isGeneric")
        })

    # Si llega aquí, SAP no devolvió el formato esperado
    return jsonify({
        "valid_SAP_API": False,
        "message_SAP_API": "Usuario no válido o no encontrado",
        "raw_response_SAP_API": result_SAP_API  # 👈 útil para depuración
    })


# ==============================================================
# RUTA 2: Desbloquear usuario SAP
# ==============================================================
@app.route("/reimpresion/userunlock", methods=["POST"])
def user_unlock_SAP_API():
    payload_SAP_API = request.get_json()
    if not payload_SAP_API:
        return jsonify({"error_SAP_API": "Payload vacío"}), 400

    result_SAP_API = call_sap_api_SAP_API(
        "/sap/bc/zsrv_userunlock/userunlock",
        payload_SAP_API
    )

    print("🔓 Resultado SAP userunlock:", result_SAP_API)

    if isinstance(result_SAP_API, list) and len(result_SAP_API) == 2:
        status_data_SAP_API = result_SAP_API[0].get("data")
        message_data_SAP_API = result_SAP_API[1]

        if isinstance(status_data_SAP_API, dict):
            return jsonify({
                "status_SAP_API": status_data_SAP_API.get("status"),
                "statusName_SAP_API": status_data_SAP_API.get("statusName"),
                "processCorrect_SAP_API": status_data_SAP_API.get("processCorrect"),
                "isCorrect_SAP_API": message_data_SAP_API.get("isCorrect"),
                "message_SAP_API": message_data_SAP_API.get("message"),
                "isBreakOperation_SAP_API": message_data_SAP_API.get("isBreakOperation")
            })

    return jsonify({
        "valid_SAP_API": False,
        "message_SAP_API": "No se pudo desbloquear el usuario o los datos son inválidos",
        "raw_response_SAP_API": result_SAP_API
    })


# ==============================================================
# RUTA 3: Resetear contraseña de usuario SAP
# ==============================================================
@app.route("/reimpresion/userchangepwd", methods=["POST"])
def reset_password_SAP_API():
    payload_SAP_API = request.get_json()
    if not payload_SAP_API:
        return jsonify({"error_SAP_API": "Payload vacío"}), 400

    result_SAP_API = call_sap_api_SAP_API(
        "/sap/bc/zsrv_user_c_pwd/userchangepwd",
        payload_SAP_API
    )

    print("🔑 Resultado SAP userchangepwd:", result_SAP_API)

    if isinstance(result_SAP_API, list) and len(result_SAP_API) == 2:
        data_SAP_API = result_SAP_API[0].get("data", {})
        message_data_SAP_API = result_SAP_API[1]
        return jsonify({
            "newPassword_SAP_API": data_SAP_API.get("newPassword"),
            "resetType_SAP_API": data_SAP_API.get("typeResetName"),
            "status_SAP_API": data_SAP_API.get("statusName"),
            "processCorrect_SAP_API": data_SAP_API.get("processCorrect"),
            "isCorrect_SAP_API": message_data_SAP_API.get("isCorrect"),
            "message_SAP_API": message_data_SAP_API.get("message")
        })

    return jsonify({
        "valid_SAP_API": False,
        "message_SAP_API": "No se pudo resetear la contraseña o los datos no son válidos",
        "raw_response_SAP_API": result_SAP_API
    })
# ==========================================================
# CONEXIÓN A SQL SERVER
# ==========================================================
def get_db_connection():
    connection_string = (
        "DRIVER={ODBC Driver 17 for SQL Server};"
        "Server=fanafesadbkenos.database.windows.net;"
        "Database=fanafesadb;"
        "UID=admindbkenos;"
        "PWD=K3n0sFanafes4!.*;"
    )
    return pyodbc.connect(connection_string)

# ==========================================================
# CONFIGURACIONES DE API VOICEFLOW / CONVERSACIÓN
# ==========================================================
BASE_URL_CONVERSACION = "https://api.voiceflow.com/v2"
PROJECT_ID_CONVERSACION = "686d850308d685f4c8312d26"
VF_API_KEY = "VF.DM.686d938d6e41d661276d53fa.5dOgOsv9wYqaoYql"

# ==========================================================
# FUNCIONES DE LOGGING
# ==========================================================
def log_error_conversacion(mensaje, error_obj=None, extra=None):
    try:
        extra_info = f" | Datos: {json.dumps(extra, ensure_ascii=False)}" if extra else ""
        logging.error(f"{mensaje}{extra_info}\n{traceback.format_exc()}")
        print(f"[ERROR CONVERSACION] {mensaje}: {error_obj}")
    except Exception as e:
        print(f"[ERROR LOGGING] No se pudo registrar el error: {e}")

def log_info(mensaje, extra=None):
    try:
        extra_info = f" | Datos: {json.dumps(extra, ensure_ascii=False)}" if extra else ""
        logging.info(f"{mensaje}{extra_info}")
        print(f"[INFO] {mensaje}")
    except Exception as e:
        print(f"[ERROR LOGGING INFO] No se pudo registrar log_info: {e}")

# ==========================================================
# FUNCIONES DE NEGOCIO
# ==========================================================
def insertar_datos(ID, email_status, historial_json, html, projectID, sessionID, name, user_number, id_caso):
    datos = {
        "ID": ID,
        "EmailStatus": email_status,
        "ProjectID": projectID,
        "SessionID": sessionID,
        "Name": name,
        "UserNumber": user_number,
        "IdCaso": id_caso
    }

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        cursor.execute("SELECT COUNT(*) FROM Transcripts WHERE ID = ?", (ID,))
        existe = cursor.fetchone()[0]

        if existe:
            cursor.execute("""
                UPDATE Transcripts
                SET EmailStatus = ?, HistorialJSON = ?, HTMLConversacion = ?, ProjectID = ?, 
                    SessionID = ?, Name = ?, UserNumber = ?, IdCaso = ?, FechaActualizacion = GETDATE()
                WHERE ID = ?
            """, (email_status, historial_json, html, projectID, sessionID, name, user_number, id_caso, ID))
            conn.commit()
            conn.close()
            log_info(f"Registro actualizado correctamente (ID={ID})", extra=datos)
            return "actualizado"
        else:
            cursor.execute("""
                INSERT INTO Transcripts (ID, EmailStatus, HistorialJSON, HTMLConversacion, 
                    ProjectID, SessionID, Name, UserNumber, IdCaso, FechaCreacion)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
            """, (ID, email_status, historial_json, html, projectID, sessionID, name, user_number, id_caso))
            conn.commit()
            conn.close()
            log_info(f"Registro insertado correctamente (ID={ID})", extra=datos)
            return "insertado"

    except Exception as e:
        log_error_conversacion(f"[SQL ERROR] Error al insertar/actualizar ID={ID}", e, extra=datos)
        return "error"

def obtener_mensajes_produccion_conversacion(session_id_conversacion):
    try:
        url = f"https://proy020.kenos-atom.com/reimpresion/voiceflow/conversacion_html"
        params = {
            "sessionID": session_id_conversacion,
            "projectID": PROJECT_ID_CONVERSACION,
            "VFAPIKey": VF_API_KEY
        }
        response = requests.get(url, params=params, timeout=30)
        response.raise_for_status()
        return response.text

    except requests.exceptions.RequestException as e:
        log_error_conversacion(
            f"[HTML API] Error HTTP {getattr(e.response, 'status_code', 'N/A')} para sessionID {session_id_conversacion}",
            e
        )
        return None

def obtener_transcripts_conversacion(filtro):
    try:
        url = f"{BASE_URL_CONVERSACION}/transcripts/{PROJECT_ID_CONVERSACION}?range={filtro}"
        headers = {"Authorization": VF_API_KEY}
        response = requests.get(url, headers=headers, timeout=30)
        response.raise_for_status()

        data = response.json().get("data", [])
        log_info(f"Transcripts recuperados exitosamente (filtro={filtro})", extra={"cantidad": len(data)})
        return data
    except Exception as e:
        log_error_conversacion("[TRANSCRIPTS API] Error obteniendo transcripts", e)
        return []

# ==========================================================
# ENDPOINT PRINCIPAL
# ==========================================================
@app.route("/reimpresion/transcripts", methods=["GET"])
def procesar_transcripts_conversacion():
    filtro_conversacion = request.args.get("range", "Today")
    try:
        log_info(f"Iniciando procesamiento de transcripts (filtro={filtro_conversacion})")
        transcripts_conversacion = obtener_transcripts_conversacion(filtro_conversacion)

        insertados, actualizados, errores = [], [], []

        for transcript in transcripts_conversacion:
            session_id_conversacion = transcript.get("sessionID")
            ID = transcript.get("_id")
            projectID = transcript.get("projectID")
            name = transcript.get("name", "")
            custom = transcript.get("customProperties", {})
            user_number = custom.get("userNumber", "")
            id_caso = custom.get("id_caso", "")
            email_status = "pendiente"
            historial_json = json.dumps(transcript, ensure_ascii=False)

            if not (session_id_conversacion and ID and projectID):
                log_info(f"[SKIP] Transcript incompleto", extra={"ID": ID})
                continue

            html_conversacion = obtener_mensajes_produccion_conversacion(session_id_conversacion)
            if not html_conversacion:
                log_info(f"[WARN] No se obtuvo HTML para SessionID {session_id_conversacion}")
                continue

            resultado = insertar_datos(
                ID, email_status, historial_json, html_conversacion,
                projectID, session_id_conversacion, name, user_number, id_caso
            )

            if resultado == "insertado":
                insertados.append(ID)
            elif resultado == "actualizado":
                actualizados.append(ID)
            else:
                errores.append(ID)

        log_info(f"[RESUMEN FINAL] Insertados={len(insertados)}, Actualizados={len(actualizados)}, Errores={len(errores)}")
        return jsonify({
            "success": True,
            "insertados": insertados,
            "actualizados": actualizados,
            "errores": errores
        }), 200

    except Exception as e:
        log_error_conversacion("[ERROR 500] Error crítico en /reimpresion/transcripts", e)
        return jsonify({"success": False, "error": str(e)}), 500


from flask import Flask, request, jsonify
import requests
from requests.auth import HTTPBasicAuth
import pyodbc
import json
from bs4 import BeautifulSoup
import traceback
import logging
from datetime import datetime
from flask_cors import CORS  # 👈 Importa CORS

# ---------------- CONFIGURACIÓN DE LOGGING ----------------
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.FileHandler("app.log", encoding="utf-8"),
        logging.StreamHandler()
    ]
)


CORS(app)  # Permitir CORS si es necesario

# Configuración Voiceflow
AUTH_TOKEN = "VF.DM.686d938d6e41d661276d53fa.5dOgOsv9wYqaoYql"
DEFAULT_PROJECT_ID = "686d850308d685f4c8312d26"
BASE_URL_VF_2 = "https://api.voiceflow.com/v2"

# Basic Auth para segunda API
BASIC_AUTH_USERNAME = "Fanafesa2024"
BASIC_AUTH_PASSWORD = "s4c4nd4_2024"

# -----------------------------------------------------------
# Conexión SQL Server
def get_db_connection():
    connection_string = (
        "DRIVER={ODBC Driver 17 for SQL Server};"
        "Server=fanafesadbkenos.database.windows.net;"
        "Database=fanafesadb;"
        "UID=admindbkenos;"
        "PWD=K3n0sFanafes4!.*;"
    )
    return pyodbc.connect(connection_string)

headers = {"Authorization": AUTH_TOKEN}

# ---------------- FUNCIONES AUXILIARES ----------------
def limpiar_html(html):
    soup = BeautifulSoup(html, "html.parser")
    for img in soup.find_all("img"):
        src = img.get("src", "")
        if src.startswith("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADErkJggg=="):
            img.replace_with("agenteIconoProd")
        if src.startswith("data:image/png;base64,iVBORw0KGgoAAAgAAAABJRU5ErkJggg=="):
            img.replace_with("usuarioIconoProd")
    return str(soup)

def reemplazar_iconos(html):
    return limpiar_html(html)

def obtener_transcripts(filtro="Today", project_id=DEFAULT_PROJECT_ID):
    url = f"{BASE_URL_VF_2}/transcripts/{project_id}?range={filtro}"
    response = requests.get(url, headers=headers)
    logging.info(f"[Voiceflow API] URL: {url}")
    logging.info(f"[Voiceflow API] Status: {response.status_code}")

    if response.status_code != 200:
        logging.error(f"[Voiceflow API] Error {response.status_code}: {response.text[:500]}")

    response.raise_for_status()
    data = response.json()
    logging.info(f"[Voiceflow API] Transcripts obtenidos: {len(data)} registros")
    return data

def obtener_mensajes_produccion(session_id, project_id=DEFAULT_PROJECT_ID):
    url = "https://proy020.kenos-atom.com/reimpresion/voiceflow/conversacion_html"
    params = {"sessionID": session_id, "projectID": project_id, "VFAPIKey": AUTH_TOKEN}
    try:
        response = requests.get(url, params=params, auth=HTTPBasicAuth(BASIC_AUTH_USERNAME, BASIC_AUTH_PASSWORD))
        logging.info(f"[HTML API] SessionID: {session_id} | Status: {response.status_code}")
        logging.info(f"[HTML API] URL: {response.url}")

        if response.status_code != 200:
            logging.warning(f"[HTML API] Respuesta no exitosa: {response.text[:300]}")

        response.raise_for_status()
    except requests.HTTPError as e:
        logging.error(f"[HTML API] HTTPError para sessionID {session_id}: {e}")
        return None

    try:
        contenido_json = response.json()
        if "error" in contenido_json:
            logging.warning(f"[HTML API] Error en respuesta JSON para sessionID {session_id}: {contenido_json['error']}")
            return None
    except ValueError:
        pass  # no es JSON

    soup = BeautifulSoup(response.text, "html.parser")
    h3_tag = soup.find("h3")
    if h3_tag:
        contenido = "".join(str(elem) for elem in h3_tag.find_all_next())
        raw_html = str(h3_tag) + contenido
    else:
        raw_html = response.text

    logging.info(f"[HTML API] HTML obtenido (fragmento): {raw_html[:200]}...")
    return raw_html

# ---------------- FUNCION INSERTAR DATOS ----------------
def insertar_datos(ID, email_status, historial_json, html, projectID, sessionID, name, user_number, id_caso):
    conexion = None
    cursor = None
    try:
        conexion = get_db_connection()
        cursor = conexion.cursor()
        html_modificado = reemplazar_iconos(html)

        cursor.execute("SELECT html FROM conversaciones_html WHERE ID = ?", ID)
        registro = cursor.fetchone()

        if registro:
            html_actual = registro[0]
            diferencia = abs(len(html_modificado) - len(html_actual))
            if diferencia >= 25:
                consulta_update = """
                    UPDATE conversaciones_html
                    SET html=?, email_status=?, status=?, historial_json=?,
                        projectID=?, sessionID=?, name=?, user_number=?, id_caso=?
                    WHERE ID=?
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
                logging.info(f"[DB] Sin cambios para ID={ID}")
                return "sin_cambios"
        else:
            consulta_insert = """
                INSERT INTO conversaciones_html (
                    ID, email_status, historial_json, html,
                    projectID, sessionID, name, user_number, id_caso
                ) VALUES (?,?,?,?,?,?,?,?,?)
            """
            valores_insert = (
                ID, email_status, historial_json, html_modificado,
                projectID, sessionID, name, user_number, id_caso
            )
            cursor.execute(consulta_insert, valores_insert)
            conexion.commit()
            logging.info(f"[DB] Insertado ID={ID}, longitud HTML={len(html_modificado)}, usuario={name}")
            return "insertado"

    except pyodbc.Error as e:
        logging.error(f"[ERROR DB] {e}")
        logging.error(traceback.format_exc())
        return "error"
    finally:
        if cursor:
            cursor.close()
        if conexion:
            conexion.close()

# ---------------- RUTAS FLASK ----------------
@app.route('/reimpresion/procesar_transcripts', methods=['GET'])
def procesar_transcripts():
    filtro = request.args.get('range', 'Today')
    project_id = request.args.get('project_id', DEFAULT_PROJECT_ID)
    logging.info(f"==> Iniciando procesamiento con filtro='{filtro}', project_id='{project_id}'")

    try:
        transcripts = obtener_transcripts(filtro, project_id)
        insertados, actualizados = [], []

        for transcript in transcripts:
            session_id = transcript.get("sessionID")
            ID = transcript.get("_id")
            name = transcript.get("name", "")
            custom = transcript.get("customProperties", {})
            user_number = custom.get("userNumber", "")
            id_caso = custom.get("id_caso", "")
            email_status = "pendiente"
            historial_json = json.dumps(transcript, ensure_ascii=False)

            if session_id and ID:
                logging.info(f"[Procesando] ID={ID} | SessionID={session_id} | Nombre={name}")
                html = obtener_mensajes_produccion(session_id, project_id)
                if html is None:
                    logging.warning(f"[Procesar] No se obtuvo HTML válido para sessionID {session_id}")
                    continue

                resultado = insertar_datos(
                    ID, email_status, historial_json, html,
                    project_id, session_id, name, user_number, id_caso
                )

                if resultado == "insertado":
                    insertados.append(ID)
                elif resultado == "actualizado":
                    actualizados.append(ID)

        logging.info(f"==> Proceso completado. Insertados: {len(insertados)} | Actualizados: {len(actualizados)}")

        return jsonify({
            "success": True,
            "project_id": project_id,
            "insertados": insertados,
            "actualizados": actualizados
        }), 200

    except requests.HTTPError as e:
        logging.error(f"[HTTPError] {e}")
        logging.error(traceback.format_exc())
        return jsonify({"error": f"HTTP error: {str(e)}"}), 500
    except Exception as e:
        logging.error(f"[Exception] {e}")
        logging.error(traceback.format_exc())
        return jsonify({"error": f"Unexpected error: {str(e)}"}), 500
    


    # ==========================================================
# ENDPOINTS FLASK
# ==========================================================
# @app.route("/reimpresion/genera_cierra_tickets", methods=["POST"])
# def genera_cierra_tickets():
#     try:
#         data = request.get_json(force=True)
#         usuario = data.get("usuario")
#         password = data.get("password")
#         correo = data.get("correo")
#         formatted_cantidad = data.get("formatted_cantidad")
#         fecha = data.get("fecha")
#         tipo_compra = data.get("tipo_compra")
#         referencia = data.get("referencia")

#         # Aquí iría tu lógica de negocio
#         log_info(f"Generando cierre de ticket para {usuario} - {correo} - ref {referencia}")
#         return jsonify({"success": True, "mensaje": "Ticket generado correctamente"}), 200

#     except Exception as e:
#         log_error_conversacion("[ERROR] En genera_cierra_tickets", e)
#         return jsonify({"success": False, "error": str(e)}), 500
# ==============================================================
#  MAIN (solo si se ejecuta directamente)
# ==============================================================

if __name__ == "__main__":
    app.run(debug=True)

