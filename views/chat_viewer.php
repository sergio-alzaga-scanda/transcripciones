<!DOCTYPE html>
<html lang="es">
<head>
    <title>Conversaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        .chat-layout { height: calc(100vh - 80px); margin-top: 10px; }
        
        /* SIDEBAR */
        .sidebar-container { background: white; border-right: 1px solid #dee2e6; overflow-y: auto; height: 100%; }
        
        .session-item { cursor: pointer; border-left: 4px solid transparent; transition: all 0.2s; position: relative; }
        .session-item:hover { background-color: #f8f9fa; }
        .session-item.active { background-color: #e8f0fe; border-left-color: #0d6efd; }
        .session-item.transfer-active { border-left-color: #fd7e14 !important; }

        /* CHAT AREA */
        .chat-area { background-color: #f4f6f8; height: 100%; display: flex; flex-direction: column; }
        .messages-container { flex: 1; overflow-y: auto; padding: 20px; }

        /* BURBUJAS */
        .message-bubble { 
            max-width: 75%; padding: 12px 16px; border-radius: 12px; 
            margin-bottom: 3px; position: relative; font-size: 0.95rem; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); line-height: 1.5; 
        }
        .msg-user     { background-color: #0d6efd; color: white;   margin-left: auto;  border-bottom-right-radius: 2px; }
        .msg-assistant{ background-color: #ffffff;  color: #212529; margin-right: auto; border-bottom-left-radius: 2px; border: 1px solid #e9ecef; }
        
        /* TRANSFERENCIA */
        .msg-transfer { 
            background-color: #fff3cd !important; color: #856404 !important; 
            border: 1px solid #ffeeba !important;
            margin-right: auto; margin-left: auto; text-align: center;
            max-width: 90%; border-radius: 15px !important;
        }

        .msg-time { font-size: 0.7rem; text-align: right; margin-top: 5px; opacity: 0.7; display: block; }
        .msg-user .msg-time     { color: #e0e0e0; }
        .msg-assistant .msg-time{ color: #6c757d; }
        .msg-transfer .msg-time { color: #856404; }

        /* ETIQUETA DE TIEMPO -- PEGADA AL MENSAJE */
        .response-time-label {
            font-size: 0.65rem; color: #aaa;
            display: block; font-style: italic;
            margin-bottom: 2px;
        }
        .response-time-label.side-user  { text-align: right; }
        .response-time-label.side-bot   { text-align: left;  }
        
        /* BADGE DE DURACIÓN */
        .duration-badge { cursor: help; transition: transform 0.2s; }
        .duration-badge:hover { transform: scale(1.1); }

        /* COMENTARIO */
        .comentario-box {
            background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px;
            padding: 12px 16px; margin: 12px 20px;
        }
        .comentario-box .label-coment { font-size: 0.75rem; font-weight: 700; color: #495057; }

        /* BADGE TRANSFERENCIA ACTIVA EN SIDEBAR */
        .badge-transf-activa {
            background: #fd7e14; color: white;
            font-size: 0.6rem; border-radius: 20px; padding: 2px 7px;
            vertical-align: middle; animation: pulse 1.5s infinite;
        }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.6} }
    </style>
</head>
<body class="bg-light">
    
    <?php include dirname(__DIR__) . '/views/layout/navbar.php'; ?>

    <div class="container-fluid chat-layout">
        <div class="row h-100 g-0 border rounded shadow-sm overflow-hidden">
            
            <!-- ========== SIDEBAR ========== -->
            <div class="col-md-4 col-lg-3 sidebar-container">
                <div class="p-3 border-bottom bg-light sticky-top" id="filterPanel">
                    
                    <form method="GET" action="index.php" id="filterForm">
                        <input type="hidden" name="page" value="chat">
                        
                        <?php if(isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin'): ?>
                            <div class="mb-2">
                                <select name="project_id" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                                    <option value="">Todos los Proyectos</option>
                                    <?php foreach ($projects as $p): ?>
                                        <option value="<?= htmlspecialchars($p['project_id']) ?>" <?= (isset($_GET['project_id']) && $_GET['project_id'] == $p['project_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['nombre_proyecto'] ?? $p['project_id']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="input-group mb-2">
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="Buscar ID..." value="<?= htmlspecialchars($search) ?>">
                            <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="fas fa-search"></i></button>
                        </div>

                        <div class="input-group mb-2">
                            <span class="input-group-text bg-white py-0"><i class="fas fa-calendar-alt text-muted" style="font-size: 0.8rem;"></i></span>
                            <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFilter) ?>" onchange="document.getElementById('filterForm').submit()">
                            <?php if(!empty($dateFilter)): ?>
                                <a href="index.php?page=chat" class="btn btn-outline-danger btn-sm" title="Quitar fecha">×</a>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted fw-bold me-2">Orden:</small>
                            <select name="sort" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                                <option value="DESC"    <?= ($sort == 'DESC')     ? 'selected' : '' ?>>🔄 Más Recientes</option>
                                <option value="ASC"     <?= ($sort == 'ASC')      ? 'selected' : '' ?>>📅 Más Antiguos</option>
                                <option value="MSG_DESC"<?= ($sort == 'MSG_DESC') ? 'selected' : '' ?>>💬 Mayor N° Mensajes</option>
                            </select>
                        </div>

                        <!-- NUEVO FILTRO: Estado (Transferidas/Tickets) -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted fw-bold me-2">Filtro:</small>
                            <select name="estado" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                                <option value="" <?= ($filterType == '') ? 'selected' : '' ?>>Todas</option>
                                <option value="con_ticket" <?= ($filterType == 'con_ticket') ? 'selected' : '' ?>>🎟️ Con Ticket</option>
                                <option value="transferidas_activas" <?= ($filterType == 'transferidas_activas') ? 'selected' : '' ?>>🎧 Transferidas Activas</option>
                                <option value="transferidas_todas" <?= ($filterType == 'transferidas_todas') ? 'selected' : '' ?>>🎧 Todas las Transferidas</option>
                            </select>
                        </div>

                    </form>

                    <!-- Indicador de auto-refresh -->
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted fst-italic"><i class="fas fa-sync-alt fa-spin" id="refreshIcon" style="display:none"></i> 
                            <span id="refreshCountdown">Actualiza en <b id="countdownVal">15</b>s</span>
                        </small>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="refreshSidebar()" title="Actualizar ahora">
                            <i class="fas fa-sync-alt" style="font-size:0.75rem"></i>
                        </button>
                    </div>
                </div>

                <!-- LISTA DE SESIONES (se actualiza con AJAX) -->
                <div class="list-group list-group-flush" id="sessionList">
                    <?php include dirname(__DIR__) . '/views/partials/session_list.php'; ?>
                </div>
            </div>

            <!-- ========== CHAT AREA ========== -->
            <div class="col-md-8 col-lg-9 chat-area">
                <?php if ($currentSession): ?>
                    <?php
                        $startTimeStr = "N/A";
                        $endTimeStr = "N/A";
                        $totalSeconds = 0;
                        $avgUserTimeStr = "N/A";
                        $avgBotTimeStr = "N/A";
                        $transferMessage = null;

                        if (!empty($messages)) {
                            $firstMsg = reset($messages);
                            $lastMsg = end($messages);

                            $tsStart = strtotime($firstMsg['timestamp'] . " -6 hours");
                            $tsEnd = strtotime($lastMsg['timestamp'] . " -6 hours");
                            
                            $totalSeconds = $tsEnd - $tsStart;
                            if ($totalSeconds < 0) $totalSeconds = 0;

                            $startTimeStr = date("d/m H:i:s", $tsStart);
                            $endTimeStr = date("d/m H:i:s", $tsEnd);

                            $totalUserTime = 0; $countUser = 0;
                            $totalBotTime = 0;  $countBot = 0;
                            $prevMsg = null;
                            
                            foreach ($messages as $msg) {
                                if (!empty($msg['canal'])) {
                                    $transferMessage = $msg;
                                }

                                if ($prevMsg) {
                                    $t1 = strtotime($prevMsg['timestamp']);
                                    $t2 = strtotime($msg['timestamp']);
                                    $diff = $t2 - $t1;
                                    
                                    if ($diff < 3600) { 
                                        if ($msg['role'] == 'assistant' && $prevMsg['role'] == 'user') {
                                            $totalBotTime += $diff; $countBot++;
                                        } elseif ($msg['role'] == 'user' && $prevMsg['role'] == 'assistant') {
                                            $totalUserTime += $diff; $countUser++;
                                        }
                                    }
                                }
                                $prevMsg = $msg;
                            }

                            $fnFormat = function($s) {
                                if ($s < 1) return "< 1s";
                                if ($s < 60) return round($s, 1) . "s";
                                $m = floor($s / 60); 
                                $sec = round(fmod($s, 60)); 
                                return "{$m}m {$sec}s";
                            };

                            if ($countUser > 0) $avgUserTimeStr = $fnFormat($totalUserTime / $countUser);
                            if ($countBot > 0)  $avgBotTimeStr  = $fnFormat($totalBotTime / $countBot);
                        }

                        $minTotal = floor($totalSeconds / 60);
                        $secTotal = $totalSeconds % 60;
                        $timeTooltip = "{$minTotal} min {$secTotal} seg";
                        
                        $displayTotal = ($totalSeconds < 60) 
                                        ? $totalSeconds . "s" 
                                        : round($totalSeconds / 60, 1) . " min";
                    ?>
                    
                    <div class="bg-white border-bottom p-3 shadow-sm" style="min-height: 85px;">
                        <div class="d-flex justify-content-between align-items-start">
                            
                            <div class="flex-grow-1">
                                <h5 class="m-0 d-flex align-items-center">
                                    <i class="fas fa-user-circle text-secondary me-2"></i> 
                                    <?= htmlspecialchars($currentSession['session_id']) ?>
                                </h5>
                                
                                <div class="text-muted mt-1" style="font-size: 0.75rem;">
                                    <i class="fas fa-clock text-success"></i> Inicio: <b><?= $startTimeStr ?></b>
                                    <span class="mx-2">|</span>
                                    <i class="fas fa-flag-checkered text-danger"></i> Fin: <b><?= $endTimeStr ?></b>
                                </div>
                                <div class="text-muted mb-2" style="font-size: 0.75rem;">
                                    Proyecto: <b><?= htmlspecialchars($currentSession['project_id']) ?></b>
                                </div>

                                <?php if ($transferMessage): ?>
                                    <div class="card border-warning bg-light shadow-sm mb-2" style="max-width: 95%;">
                                        <div class="card-body p-2">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-headset"></i> Resumen Transferencia
                                                </span>
                                                <small class="text-muted">
                                                    Canal: <span class="badge bg-secondary"><?= htmlspecialchars($transferMessage['canal']) ?></span>
                                                </small>
                                            </div>
                                            <p class="card-text small mb-0 text-dark italic">
                                                <?= nl2br(htmlspecialchars($transferMessage['content'])) ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex flex-column align-items-end gap-1">
                                <span class="badge bg-warning text-dark duration-badge p-2 mb-1" 
                                      data-bs-toggle="tooltip" 
                                      data-bs-html="true"
                                      title="<i class='fas fa-stopwatch'></i> Duración exacta:<br><b><?= $timeTooltip ?></b>">
                                    <i class="fas fa-hourglass-half"></i> Total: <?= $displayTotal ?>
                                </span>

                                <div class="d-flex gap-2">
                                    <span class="badge border text-dark bg-light" title="Tiempo promedio respuesta del BOT">
                                        <i class="fas fa-robot text-primary"></i> Bot Avg: <b><?= $avgBotTimeStr ?></b>
                                    </span>
                                    <span class="badge border text-dark bg-light" title="Tiempo promedio respuesta del USUARIO">
                                        <i class="fas fa-user text-success"></i> User Avg: <b><?= $avgUserTimeStr ?></b>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="messages-container">
                        <?php 
                            $prevTime = null;
                            foreach($messages as $msg): 
                                $currTime = strtotime($msg['timestamp']);
                                $timeDiffStr = '';
                                $isUser = ($msg['role'] === 'user');
                                $isTransfer = !empty($msg['canal']);
                                
                                if ($prevTime !== null) {
                                    $diff = $currTime - $prevTime;
                                    if ($diff < 60) $str = $diff . "s";
                                    else $str = floor($diff / 60) . "m " . ($diff % 60) . "s";

                                    if ($isUser) $timeDiffStr = '<i class="fas fa-user-clock"></i> Usuario respondió en: ' . $str;
                                    elseif (!$isTransfer) $timeDiffStr = '<i class="fas fa-robot"></i> Bot respondió en: ' . $str;
                                }
                                $prevTime = $currTime;

                                // Clase de burbuja
                                if ($isTransfer) {
                                    $bubbleClass = 'msg-transfer';
                                } elseif ($isUser) {
                                    $bubbleClass = 'msg-user';
                                } else {
                                    $bubbleClass = 'msg-assistant';
                                }

                                // Posición del label de tiempo
                                $timeLabelClass = $isUser ? 'side-user' : 'side-bot';
                        ?>
                            <?php if($timeDiffStr && !$isTransfer): ?>
                                <span class="response-time-label <?= $timeLabelClass ?>">
                                    <?= $timeDiffStr ?>
                                </span>
                            <?php endif; ?>

                            <div class="message-bubble <?= $bubbleClass ?>">
                                <?php if($msg['role'] === 'assistant' && !$isTransfer): ?>
                                    <strong class="d-block text-primary mb-1" style="font-size: 0.8rem;">IA Assistant</strong>
                                <?php elseif($isTransfer): ?>
                                    <strong class="d-block mb-1" style="font-size: 0.8rem;"><i class="fas fa-headset"></i> LOG DE TRANSFERENCIA (<?= htmlspecialchars($msg['canal']) ?>)</strong>
                                <?php endif; ?>
                                
                                <?= nl2br(htmlspecialchars($msg['content'])) ?>
                                
                                <span class="msg-time"><?= date("H:i:s", strtotime($msg['timestamp'] . " -6 hours")) ?></span>
                            </div>
                        <?php endforeach; ?>
                        
                        <div id="scroll-target"></div>
                    </div>

                    <!-- ========== COMENTARIO ========== -->
                    <div class="comentario-box">
                        <?php if ($comentario): ?>
                            <div class="label-coment mb-2 text-success fw-bold"><i class="fas fa-comment-dots text-success"></i> Comentario del operador</div>
                            <div class="p-2 rounded bg-success bg-opacity-10 border border-success border-opacity-25 shadow-sm">
                                <p class="mb-0 small text-dark fw-medium"><?= nl2br(htmlspecialchars($comentario['comentario'])) ?></p>
                                <hr class="my-1 border-success border-opacity-25">
                                <small class="text-success text-opacity-75" style="font-size: 0.7rem;"><i class="fas fa-clock"></i> Registrado el <?= date('d/m/Y H:i', strtotime($comentario['created_at'] . " -6 hours")) ?></small>
                            </div>
                        <?php else: ?>
                            <div class="label-coment mb-2"><i class="fas fa-comment-medical text-secondary"></i> Agregar comentario</div>
                            <textarea id="inputComentario" class="form-control form-control-sm mb-2" rows="2" 
                                      placeholder="Escribe un comentario sobre esta conversación..." maxlength="1000"></textarea>
                            <button class="btn btn-sm btn-primary" id="btnGuardarComentario">
                                <i class="fas fa-save"></i> Guardar comentario
                            </button>
                            <span id="comentarioMsg" class="ms-2 small"></span>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="empty-state d-flex flex-column align-items-center justify-content-center h-100 text-muted">
                        <i class="fas fa-comments fa-4x mb-3 opacity-25"></i>
                        <h4>Selecciona una conversación</h4>
                        <p class="text-center px-4">
                            Utiliza los filtros de la izquierda para buscar o selecciona un elemento de la lista.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // --- Scroll al final ---
        document.addEventListener("DOMContentLoaded", function() {
            const target = document.getElementById('scroll-target');
            if(target) target.scrollIntoView({ behavior: "auto" });

            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            tooltipTriggerList.map(function(el){ return new bootstrap.Tooltip(el); });
        });

        // --- Guardar comentario AJAX ---
        <?php if ($currentSession && !$comentario): ?>
        document.getElementById('btnGuardarComentario')?.addEventListener('click', function() {
            const comentario = document.getElementById('inputComentario').value.trim();
            const sessionId  = '<?= htmlspecialchars($currentSession['id']) ?>';
            const msgEl      = document.getElementById('comentarioMsg');

            if (!comentario) { msgEl.textContent = 'El comentario no puede estar vacío.'; msgEl.className = 'ms-2 small text-danger'; return; }

            const fd = new FormData();
            fd.append('session_id', sessionId);
            fd.append('comentario', comentario);

            fetch('api/guardar_comentario.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        msgEl.textContent = '✅ Guardado correctamente';
                        msgEl.className = 'ms-2 small text-success';
                        document.getElementById('btnGuardarComentario').disabled = true;
                        document.getElementById('inputComentario').disabled = true;
                    } else {
                        msgEl.textContent = '⚠ ' + (data.message || 'Error al guardar');
                        msgEl.className = 'ms-2 small text-danger';
                    }
                })
                .catch(() => { msgEl.textContent = 'Error de conexión'; msgEl.className = 'ms-2 small text-danger'; });
        });
        <?php endif; ?>

        // --- Auto-refresh del listado (cada 120 segundos) ---
        let countdown = 15;
        const countdownEl = document.getElementById('countdownVal');
        const refreshIconEl = document.getElementById('refreshIcon');

        function refreshSidebar() {
            refreshIconEl.style.display = 'inline-block';
            const params = new URLSearchParams(window.location.search);
            params.set('_ajax_list', '1');

            fetch('index.php?' + params.toString())
                .then(r => r.text())
                .then(html => {
                    // Extraer solo el #sessionList del HTML devuelto
                    const parser = new DOMParser();
                    const doc    = parser.parseFromString(html, 'text/html');
                    const newList = doc.getElementById('sessionList');
                    if (newList) {
                        document.getElementById('sessionList').innerHTML = newList.innerHTML;
                    }
                    countdown = 15;
                    refreshIconEl.style.display = 'none';
                })
                .catch(() => { refreshIconEl.style.display = 'none'; });
        }

        setInterval(function() {
            countdown--;
            if (countdownEl) countdownEl.textContent = countdown;
            if (countdown <= 0) {
                countdown = 15;
                refreshSidebar();
            }
        }, 1000);
    </script>
</body>

</html>