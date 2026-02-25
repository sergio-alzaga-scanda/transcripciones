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

        /* CHAT AREA */
        .chat-area { background-color: #f4f6f8; height: 100%; display: flex; flex-direction: column; }
        .messages-container { flex: 1; overflow-y: auto; padding: 20px; }

        /* BURBUJAS */
        .message-bubble { 
            max-width: 75%; padding: 12px 16px; border-radius: 12px; 
            margin-bottom: 5px; position: relative; font-size: 0.95rem; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); line-height: 1.5; 
        }
        .msg-user { background-color: #0d6efd; color: white; margin-left: auto; border-bottom-right-radius: 2px; }
        .msg-assistant { background-color: #ffffff; color: #212529; margin-right: auto; border-bottom-left-radius: 2px; border: 1px solid #e9ecef; }
        
        /* ESTILO ESPECIAL PARA TRANSFERENCIA */
        .msg-transfer { 
            background-color: #fff3cd !important; 
            color: #856404 !important; 
            border: 1px solid #ffeeba !important;
            margin-right: auto; 
            margin-left: auto; 
            text-align: center;
            max-width: 90%;
            border-radius: 15px !important;
        }

        .msg-time { font-size: 0.7rem; text-align: right; margin-top: 5px; opacity: 0.7; display: block; }
        .msg-user .msg-time { color: #e0e0e0; }
        .msg-assistant .msg-time { color: #6c757d; }
        .msg-transfer .msg-time { color: #856404; }

        /* ETIQUETAS DE TIEMPO INTERMEDIO */
        .response-time-label { 
            font-size: 0.65rem; color: #888; margin-bottom: 15px; 
            text-align: center; display: block; font-style: italic; 
        }
        
        /* BADGE DE DURACIÓN */
        .duration-badge { cursor: help; transition: transform 0.2s; }
        .duration-badge:hover { transform: scale(1.1); }
    </style>
</head>
<body class="bg-light">
    
    <?php include dirname(__DIR__) . '/views/layout/navbar.php'; ?>

    <div class="container-fluid chat-layout">
        <div class="row h-100 g-0 border rounded shadow-sm overflow-hidden">
            
            <div class="col-md-4 col-lg-3 sidebar-container">
                <div class="p-3 border-bottom bg-light sticky-top">
                    
                    <form method="GET" action="index.php" id="filterForm">
                        <input type="hidden" name="page" value="chat">
                        
                        <?php if(isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin'): ?>
                            <div class="mb-2">
                                <select name="project_id" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                                    <option value="">Todos los Proyectos</option>
                                    <?php foreach ($projects as $p): ?>
                                        <option value="<?= htmlspecialchars($p['project_id']) ?>" <?= (isset($_GET['project_id']) && $_GET['project_id'] == $p['project_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['project_id']) ?>
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

                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted fw-bold me-2">Orden:</small>
                            <select name="sort" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit()">
                                <option value="DESC" <?= ($sort == 'DESC') ? 'selected' : '' ?>>🔄 Más Recientes</option>
                                <option value="ASC" <?= ($sort == 'ASC') ? 'selected' : '' ?>>📅 Más Antiguos</option>
                                <option value="MSG_DESC" <?= ($sort == 'MSG_DESC') ? 'selected' : '' ?>>💬 Mayor N° Mensajes</option>
                            </select>
                        </div>
                    </form>
                </div>

                <div class="list-group list-group-flush">
                    <?php if (empty($sessions)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="fas fa-filter fa-2x mb-2 opacity-50"></i><br>
                            Sin resultados.
                        </div>
                    <?php else: ?>
                        <?php foreach($sessions as $s): ?>
                            <?php 
                                $isActive = ($selectedSessionId == $s['id']) ? 'active' : '';
                                $date = date("d/m H:i", strtotime($s['created_at']));
                                
                                $url = "index.php?page=chat&session_id={$s['id']}&search=" . urlencode($search) . "&sort={$sort}&date={$dateFilter}";
                                if (isset($_GET['project_id'])) {
                                    $url .= "&project_id=" . urlencode($_GET['project_id']);
                                }
                            ?>
                            <a href="<?= $url ?>" class="list-group-item list-group-item-action session-item <?= $isActive ?>">
                                
                                <div class="d-flex w-100 justify-content-between align-items-center">
                                    <h6 class="mb-1 text-truncate fw-bold" style="max-width: 60%;">
                                        <?= htmlspecialchars($s['session_id']) ?>
                                    </h6>
                                    <small class="<?= $isActive ? 'text-primary' : 'text-muted' ?>" style="font-size: 0.75rem;"><?= $date ?></small>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <p class="mb-0 small text-truncate <?= $isActive ? 'text-dark' : 'text-muted' ?>" style="max-width: 70%;">
                                        <i class="fas fa-robot"></i> <?= htmlspecialchars($s['model_used']) ?>
                                    </p>
                                    
                                    <span class="badge <?= $isActive ? 'bg-primary text-white' : 'bg-light text-muted border' ?> rounded-pill" style="font-size: 0.7em;">
                                        <?= $s['msg_count'] ?> <i class="fas fa-comment"></i>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

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

                            $tsStart = strtotime($firstMsg['timestamp']);
                            $tsEnd = strtotime($lastMsg['timestamp']);
                            
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
                                
                                if ($prevTime !== null) {
                                    $diff = $currTime - $prevTime;
                                    if ($diff < 60) $str = $diff . "s";
                                    else $str = floor($diff / 60) . "m " . ($diff % 60) . "s";

                                    if ($msg['role'] === 'user') $timeDiffStr = "Usuario respondió en: $str";
                                    else $timeDiffStr = "Bot respondió en: $str";
                                }
                                $prevTime = $currTime;

                                // LÓGICA DE CLASES PARA LA BURBUJA
                                $bubbleClass = ($msg['role'] === 'user') ? 'msg-user' : 'msg-assistant';
                                
                                // Si tiene 'canal', aplicamos el color especial
                                if (!empty($msg['canal'])) {
                                    $bubbleClass = 'msg-transfer';
                                }
                        ?>
                            <?php if($timeDiffStr): ?>
                                <span class="response-time-label">
                                    <?= ($msg['role'] === 'user') ? '<i class="fas fa-user-clock"></i>' : '<i class="fas fa-robot"></i>' ?> 
                                    <?= $timeDiffStr ?>
                                </span>
                            <?php endif; ?>

                            <div class="message-bubble <?= $bubbleClass ?>">
                                <?php if($msg['role'] === 'assistant' && empty($msg['canal'])): ?>
                                    <strong class="d-block text-primary mb-1" style="font-size: 0.8rem;">IA Assistant</strong>
                                <?php elseif(!empty($msg['canal'])): ?>
                                    <strong class="d-block mb-1" style="font-size: 0.8rem;"><i class="fas fa-headset"></i> LOG DE TRANSFERENCIA (<?= htmlspecialchars($msg['canal']) ?>)</strong>
                                <?php endif; ?>
                                
                                <?= nl2br(htmlspecialchars($msg['content'])) ?>
                                
                                <span class="msg-time"><?= date("H:i:s", $currTime) ?></span>
                            </div>
                        <?php endforeach; ?>
                        
                        <div id="scroll-target"></div>
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
        document.addEventListener("DOMContentLoaded", function() {
            const target = document.getElementById('scroll-target');
            if(target) target.scrollIntoView({ behavior: "auto" });

            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            })
        });
    </script>
</body>

</html>