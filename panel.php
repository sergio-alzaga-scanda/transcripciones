<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inbox Agente | Sergio Alzaga</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #f0f2f5; height: 100vh; overflow: hidden; font-family: sans-serif; }
        .sidebar { height: 100vh; overflow-y: auto; background: white; border-right: 1px solid #d1d7db; display: flex; flex-direction: column; }
        .chat-area { height: 100vh; display: flex; flex-direction: column; background-color: #e5ddd5; }
        #chat-screen { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; }
        .msg { margin-bottom: 8px; padding: 8px 12px; border-radius: 7px; max-width: 70%; font-size: 14px; box-shadow: 0 1px 1px rgba(0,0,0,0.1); }
        .usuario { background: white; align-self: flex-start; border-bottom-left-radius: 0; }
        .agente, .bot { background: #dcf8c6; align-self: flex-end; border-bottom-right-radius: 0; }
        .sistema { background: #ffeecd; align-self: center; font-size: 12px; border-radius: 10px; margin: 10px 0; padding: 5px 15px; }
        .chat-item { cursor: pointer; border-bottom: 1px solid #f0f2f5; padding: 12px 15px; transition: 0.2s; }
        .chat-item:hover { background-color: #f5f5f5; }
        .active-chat { background-color: #ebebeb !important; border-left: 4px solid #25d366; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <div class="col-md-3 sidebar">
            <div class="p-3 bg-light border-bottom">
                <h5>Mensajería</h5>
                <input type="text" id="buscar-input" class="form-control form-control-sm mt-2" placeholder="Buscar..." onkeyup="refrescarListas()">
                <ul class="nav nav-pills mt-2">
                    <li class="nav-item w-50"><button class="nav-link active w-100" id="btn-activos" onclick="cambiarModo('activos')">Activos</button></li>
                    <li class="nav-item w-50"><button class="nav-link w-100" id="btn-cerrados" onclick="cambiarModo('cerrados')">Historial</button></li>
                </ul>
            </div>
            <div id="lista-chats" class="list-group list-group-flush flex-grow-1"></div>
        </div>
        <div class="col-md-9 chat-area">
            <div class="bg-light px-3 py-2 d-flex justify-content-between align-items-center shadow-sm">
                <div><strong id="chat-nombre">Selecciona un chat</strong><div id="chat-status" class="small text-muted"></div></div>
                <button class="btn btn-danger btn-sm" id="btnFin" style="display:none;" onclick="confirmarFin()">Finalizar Chat</button>
            </div>
            <div id="chat-screen"></div>
            <div class="p-2 bg-light border-top">
                <div id="bloqueo-msg" class="text-center small text-muted mb-1" style="display:none;"></div>
                <form id="chatForm" onsubmit="enviarMsg(event)">
                    <div class="input-group">
                        <input type="text" id="inputMsg" class="form-control" placeholder="Escribe un mensaje..." disabled>
                        <button class="btn btn-primary" id="btnEnviar" disabled>➤</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<audio id="notif-sound" src="https://assets.mixkit.co/active_storage/sfx/2358/2358-preview.mp3" preload="auto"></audio>

<script>
let chatActivo = null;
let modoVista = 'activos';
let lastMsgId = null;
let currentSessionId = null;
let totalUnread = 0;

const notifSound = document.getElementById('notif-sound');

function refrescarListas() {
    const q = document.getElementById('buscar-input').value;
    const url = modoVista === 'activos' ? `api_lista_chats.php?wa_id=${q}` : `api_historial_cerrados.php?is_sesion=${q}`;
    fetch(url).then(r => r.json()).then(data => {
        let unread = data.reduce((acc, c) => acc + (parseInt(c.no_leido) || 0), 0);
        if (unread > totalUnread && modoVista === 'activos') notifSound.play().catch(() => {});
        totalUnread = unread;
        pintarLista(data);
    });
}

// Reemplaza estas funciones en tu panel.php actual

function pintarLista(data) {
    const contenedor = document.getElementById('lista-chats');
    if (data.length === 0) {
        contenedor.innerHTML = '<div class="text-center p-4 text-muted small">Sin resultados</div>';
        return;
    }

    contenedor.innerHTML = data.map(c => {
        const esEste = chatActivo === c.wa_id;
        const nombreDisplay = `${c.nombre_usuario || 'Usuario'} (${c.wa_id})`;
        
        // REGLA: Asegurar que el valor de is_sesion no sea null para evitar 'undefined'
        const sessId = c.is_sesion || ''; 

        return `
            <div onclick="seleccionarChat('${c.wa_id}', '${c.estado}', '${nombreDisplay}', '${sessId}')" 
                 class="chat-item ${esEste ? 'active-chat' : ''}">
                <div class="d-flex justify-content-between align-items-center">
                    <strong class="text-truncate" style="max-width:160px">${nombreDisplay}</strong>
                    ${c.no_leido > 0 ? '<span class="badge bg-success rounded-pill">!</span>' : ''}
                </div>
                <div class="preview text-primary small">ID: ${sessId || 'Sin sesión'}</div>
                <div class="preview">${c.ultimo_mensaje || ''}</div>
            </div>`;
    }).join('');
}

function seleccionarChat(id, estado, nombre, sessionId) {
    chatActivo = id;
    // REGLA: Si sessionId viene como string 'null' o vacío, manejarlo
    currentSessionId = (sessionId === 'null' || sessionId === '') ? null : sessionId;
    lastMsgId = null;

    document.getElementById('chat-nombre').innerText = nombre;
    // Mostrar el ID de sesión en el header o 'Activa' si es nulo
    document.getElementById('chat-status').innerText = `Sesión: ${currentSessionId || 'Activa'}`;
    
    configurarInputs(estado);

    // Marcar como leído
    const fd = new FormData();
    fd.append('wa_id', id);
    fetch('marcar_leido.php', { method: 'POST', body: fd }).then(() => refrescarListas());

    cargarMensajes();
}

function cargarMensajes() {
    if(!chatActivo) return;
    const url = `api_mensajes.php?wa_id=${chatActivo}&is_sesion=${currentSessionId || ''}`;
    fetch(url).then(r => r.json()).then(data => {
        if(data.length > 0) {
            const ultId = data[data.length - 1].id;
            if(ultId !== lastMsgId) {
                const screen = document.getElementById('chat-screen');
                screen.innerHTML = data.map(m => {
                    let esMio = (m.tipo === 'agente' || m.tipo === 'bot');
                    let clase = m.tipo === 'sistema' ? 'sistema' : (esMio ? 'agente' : 'usuario');
                    let align = m.tipo === 'sistema' ? 'justify-content-center' : (esMio ? 'justify-content-end' : 'justify-content-start');
                    return `<div class="d-flex ${align} w-100 mb-2"><div class="msg ${clase}"><small class="d-block fw-bold">${m.nombre}</small>${m.mensaje}</div></div>`;
                }).join('');
                screen.scrollTop = screen.scrollHeight;
                lastMsgId = ultId;
            }
        }
    });
}

function configurarInputs(estado) {
    const input = document.getElementById('inputMsg');
    const btn = document.getElementById('btnEnviar');
    const btnFin = document.getElementById('btnFin');
    const bloqueo = document.getElementById('bloqueo-msg');
    if (estado === 'agente') {
        input.disabled = false; btn.disabled = false; btnFin.style.display = 'block'; bloqueo.style.display = 'none';
    } else {
        input.disabled = true; btn.disabled = true; btnFin.style.display = 'none'; bloqueo.style.display = 'block';
        bloqueo.innerText = estado === 'bot' ? "🤖 Bot en control." : "🔒 Chat cerrado.";
    }
}

function enviarMsg(e) {
    e.preventDefault();
    const input = document.getElementById('inputMsg');
    const fd = new FormData(); fd.append('wa_id', chatActivo); fd.append('mensaje', input.value);
    fetch('enviar_agente.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => {
        if(res.status === 'ok') { input.value = ''; cargarMensajes(); }
    });
}

function cambiarModo(modo) {
    modoVista = modo; chatActivo = null; document.getElementById('chat-screen').innerHTML = '';
    document.getElementById('btn-activos').classList.toggle('active', modo === 'activos');
    document.getElementById('btn-cerrados').classList.toggle('active', modo === 'cerrados');
    refrescarListas();
}

function confirmarFin() {
    Swal.fire({ title: '¿Cerrar sesión?', icon: 'warning', showCancelButton: true }).then(r => {
        if (r.isConfirmed) {
            const fd = new FormData(); fd.append('wa_id', chatActivo);
            fetch('cerrar_chat.php', { method: 'POST', body: fd }).then(() => cambiarModo('activos'));
        }
    });
}

setInterval(() => { if(!document.getElementById('buscar-input').value) refrescarListas(); }, 4000);
setInterval(cargarMensajes, 2000);
refrescarListas();
</script>
</body>
</html>