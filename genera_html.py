from flask import Flask, jsonify, request, Response
import logging
from logging.handlers import RotatingFileHandler
import pyodbc
import subprocess
from unidecode import unidecode  
import unicodedata
import re
import logging
from flask import Flask, request, jsonify
import requests
import unidecode
import re
from requests.auth import HTTPBasicAuth
from functools import wraps
from datetime import datetime  
from flask import Flask, request, jsonify
from email_validator import validate_email, EmailNotValidError
from dotenv import load_dotenv
import os
import asyncio
from aiosmtplib import SMTP
from email.message import EmailMessage
from fuzzywuzzy import fuzz


from unidecode import unidecode

app = Flask(__name__)

handler = RotatingFileHandler('voiceflow_api.log', maxBytes=10000, backupCount=1)
handler.setLevel(logging.INFO)
handler.setFormatter(logging.Formatter('%(asctime)s - %(levelname)s - %(message)s'))
app.logger.addHandler(handler)
app.logger.setLevel(logging.INFO)


VF_API_KEY = "VF.DM.67e4eb7b5801b7cdd8948b94.nOQAYjlNcsN5U87h"
VF_BASE_URL = "https://api.voiceflow.com/v2"




def find_id_by_session_id(session_id, array):
    item = next((item for item in array if item['sessionID'] == session_id), None)
    return item['_id'] if item else None

def generate_html_conversation(details, all_conversations=False):
    import datetime
    import re
    from collections import defaultdict

    conversation = details.get("conversation", [])
    date = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    id_caso_value = None 

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
            relevant_traces = conversation 

    
    turns = defaultdict(list)
    for idx, trace in enumerate(relevant_traces):
        turn_id = trace.get("turnID") or f"no-turn-{idx}"
        turns[turn_id].append(trace)

    
    agent_icon = "data:image/png;base64,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"
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
        response = requests.get(f"{VF_BASE_URL}/transcripts/{project_id}", headers=headers)
        response.raise_for_status()
        transcripts = response.json()

        transcript_id = find_id_by_session_id(session_id, transcripts)
        if not transcript_id:
            return jsonify({'error': 'No se encontró ningún transcript con ese sessionID.'}), 404

        detail_resp = requests.get(f"{VF_BASE_URL}/transcripts/{project_id}/{transcript_id}", headers=headers)
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

def normalize_phone(num):
    return ''.join(filter(str.isdigit, num))  


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
        
        response = requests.get(f"{VF_BASE_URL}/transcripts/{project_id}", headers=headers)
        response.raise_for_status()
        transcripts = response.json()

        
        transcript_id = find_id_by_phone(phone, transcripts)
        if not transcript_id:
            app.logger.warning(f"No se encontró transcript para el número: {phone}")
            return jsonify({'error': 'No se encontró ningún transcript con ese número telefónico.'}), 404

        
        detail_resp = requests.get(f"{VF_BASE_URL}/transcripts/{project_id}/{transcript_id}", headers=headers)
        detail_resp.raise_for_status()
        details = detail_resp.json()

        
        try:
            html_convo, history_json = generate_html_conversation(
                {"conversation": details},
                all_conversations=all_conversations
            )
        except Exception as e:
            app.logger.error(f"Error generando HTML: {str(e)}")
            return jsonify({'error': 'Error generando conversación HTML', 'details': str(e)}), 500

        
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
                "Authorization": "re_MGLhPdwA_JvoX4Fkikh3S3gP2Yw5coGwh",  
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
    

if __name__ == "__main__":
    app.run(debug=True)
