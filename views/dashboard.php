<!DOCTYPE html>
<html lang="es">
<head>
    <title>Dashboard - Métricas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .clickable-card { cursor: pointer; transition: transform 0.2s; }
        .clickable-card:hover { transform: translateY(-5px); }
        
        /* Loading Overlay */
        #loadingOverlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.85); z-index: 9999;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            color: white; visibility: hidden; opacity: 0; transition: opacity 0.3s;
        }
        #loadingOverlay.active { visibility: visible; opacity: 1; }
        .loader { width: 48px; height: 48px; border: 5px solid #FFF; border-bottom-color: #0d6efd; border-radius: 50%; animation: rotation 1s linear infinite; }
        @keyframes rotation { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="bg-light">
    
    <div id="loadingOverlay">
        <div class="loader mb-3"></div>
        <h4 id="loadingText">Sincronizando datos...</h4>
        <small class="text-white-50">Obteniendo las últimas conversaciones recientes</small>
    </div>

    <?php include dirname(__DIR__) . '/views/layout/navbar.php'; ?>
    
    <input type="hidden" id="currentProjectId" value="<?= $projectId ?? '' ?>">

    <div class="container mt-4 mb-5">
        
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="row align-items-end">
                    
                    <?php if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin'): ?>
                    <div class="col-md-4">
                        <form method="GET" action="index.php">
                            <input type="hidden" name="page" value="dashboard">
                            <label class="form-label fw-bold">Proyecto:</label>
                            <div class="input-group">
                                <select name="project_id" class="form-select">
                                    <option value="">Todos los Proyectos</option>
                                    <?php if(isset($projects)): ?>
                                        <?php foreach ($projects as $p): ?>
                                            <option value="<?= $p['project_id'] ?>" <?= ($p['project_id'] == ($projectId ?? '')) ? 'selected' : '' ?>>
                                                <?= $p['project_id'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <button class="btn btn-outline-primary" type="submit">Filtrar</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-8 ms-auto">
                        <div class="border-start ps-3">
                            <label class="form-label fw-bold text-primary"><i class="fas fa-sync"></i> Sincronización Manual</label>
                            <div class="input-group">
                                <span class="input-group-text">Registros</span>
                                <input type="number" id="syncTake" class="form-control" min="1" max="100" value="25">
                                
                                
                                    <input type="text" id="syncProjectId" class="form-control" placeholder="ID Proyecto">
                                
                                    <input type="hidden" id="syncProjectId" value="<?= $_SESSION['user']['assigned_project_id'] ?? '' ?>">
                                
                                
                                <button class="btn btn-primary" id="btnSync" type="button">Ejecutar Sync</button>
                            </div>
                            <small class="text-muted" id="syncStatus"></small>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">T. Prom. Respuesta (Gen)</h6>
                        <h3 class="mb-0 fw-bold text-dark"><?= number_format($stats['avg_latency'] ?? 0, 0) ?> <small class="fs-6">ms</small></h3>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Mensajes Totales</h6>
                        <h3 class="mb-0 fw-bold text-dark"><?= number_format($stats['total_messages'] ?? 0) ?></h3>
                        <div class="mt-2 small">
                            <span class="text-success"><i class="fas fa-user"></i> <?= number_format($stats['msg_user'] ?? 0) ?> Usuario</span> | 
                            <span class="text-primary"><i class="fas fa-robot"></i> <?= number_format($stats['msg_agent'] ?? 0) ?> Agente</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-success clickable-card" id="btnShowUsers">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Usuarios Únicos</h6>
                        <h3 class="mb-0 fw-bold text-dark"><?= number_format($stats['total_users'] ?? 0) ?></h3>
                        <small class="text-success">Ver lista <i class="fas fa-arrow-right"></i></small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary clickable-card" onclick="window.location.href='index.php?page=chat'">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Sesiones Totales</h6>
                        <h3 class="mb-0 fw-bold text-dark"><?= number_format($stats['total_sessions'] ?? 0) ?></h3>
                        <small class="text-primary">Ir al chat <i class="fas fa-arrow-right"></i></small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold"><i class="fas fa-calendar-day text-warning"></i> Top 3 Días con más Mensajes</div>
                    <ul class="list-group list-group-flush">
                        <?php if(!empty($topDays)): ?>
                            <?php foreach($topDays as $day): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?= date("d/m/Y", strtotime($day['fecha'])) ?>
                                <span class="badge bg-secondary rounded-pill"><?= $day['total'] ?> msgs</span>
                            </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="list-group-item text-muted">Sin datos suficientes</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-white fw-bold"><i class="fas fa-comments text-primary"></i> Top 3 Conversaciones Largas</div>
                    <div class="list-group list-group-flush">
                        <?php if(!empty($topConversations)): ?>
                            <?php foreach($topConversations as $convo): ?>
                            <a href="index.php?page=chat&session_id=<?= $convo['id'] ?>" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <small class="fw-bold text-truncate" style="max-width: 150px;"><?= $convo['session_id'] ?></small>
                                    <span class="badge bg-primary rounded-pill"><?= $convo['msg_count'] ?></span>
                                </div>
                                <small class="text-muted"><?= date("d/m H:i", strtotime($convo['created_at'])) ?></small>
                            </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-3 text-muted">Sin datos suficientes</div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <div class="col-lg-8">
                <div class="card shadow border-0 h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-primary">Actividad (Últimos 30 días)</h6>
                        <div>
                            <input type="date" id="chartStart" class="form-control form-control-sm d-inline-block w-auto">
                            <input type="date" id="chartEnd" class="form-control form-control-sm d-inline-block w-auto">
                            <button class="btn btn-sm btn-outline-secondary" id="btnUpdateChart"><i class="fas fa-sync"></i></button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div style="height: 300px;">
                            <canvas id="dailyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="usersModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Usuarios Únicos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead><tr><th>ID</th><th>Última Vez</th><th>Tokens</th><th>Acción</th></tr></thead>
                            <tbody id="usersTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="public/js/app.js"></script> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ==========================================
        // 1. LÓGICA DE AUTO-SYNC AL INICIAR SESIÓN
        // ==========================================
        // PHP inyecta true si se acaba de hacer login
        const shouldSync = <?= (isset($triggerAutoSync) && $triggerAutoSync) ? 'true' : 'false' ?>;
        
        if (shouldSync) {
            runAutoSync();
        }

        function runAutoSync() {
            // Mostrar Overlay
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.add('active');

            // Determinar proyecto
            let projectId = $('#syncProjectId').val() || "<?= $projectId ?? '' ?>";
            
            // URL API (Take 100 fijo para auto-sync)
            const apiUrl = `http://127.0.0.1:5001/sync-voiceflow?take=100&id_project=${projectId}`;

            console.log("Iniciando Auto-Sync...");

            fetch(apiUrl)
                .then(response => response.json())
                .then(data => {
                    console.log("Auto-Sync completado:", data);
                    // Ocultar Overlay y Recargar para quitar la bandera
                    setTimeout(() => {
                        window.location.href = 'index.php?page=dashboard'; 
                    }, 500);
                })
                .catch(error => {
                    console.error("Error en Auto-Sync:", error);
                    alert("Hubo un error sincronizando los datos automáticamente.");
                    overlay.classList.remove('active');
                });
        }

        // ==========================================
        // 2. CONFIGURACIÓN DEL GRÁFICO
        // ==========================================
        const ctx = document.getElementById('dailyChart').getContext('2d');
        let myChart;
        
        // Datos inyectados desde PHP
        const initialLabels = <?= json_encode($chartLabels ?? []) ?>;
        const initialData = <?= json_encode($chartValues ?? []) ?>;

        function renderChart(labels, data) {
            if (myChart) myChart.destroy();
            myChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Sesiones',
                        data: data,
                        borderColor: '#4e73df',
                        backgroundColor: 'rgba(78, 115, 223, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        }
        
        // Render inicial
        renderChart(initialLabels, initialData);

        // Actualizar gráfico vía AJAX (Filtro Fechas)
        $('#btnUpdateChart').click(function() {
            let start = $('#chartStart').val();
            let end = $('#chartEnd').val();
            let projectId = $('#currentProjectId').val();
            
            $.ajax({
                url: 'index.php?page=ajax_chart',
                method: 'GET',
                data: { start: start, end: end, project_id_ajax: projectId },
                dataType: 'json',
                success: function(response) { renderChart(response.labels, response.values); }
            });
        });

        // ==========================================
        // 3. LÓGICA DEL MODAL DE USUARIOS
        // ==========================================
        $('#btnShowUsers').click(function() {
            let projectId = $('#currentProjectId').val();
            $('#usersModal').modal('show');
            $('#usersTableBody').html('<tr><td colspan="4" class="text-center">Cargando...</td></tr>');
            
            $.ajax({
                url: 'index.php?page=ajax_users',
                method: 'GET',
                data: { project_id_ajax: projectId },
                dataType: 'json',
                success: function(users) {
                    let html = '';
                    if (users.length === 0) {
                        html = '<tr><td colspan="4" class="text-center text-muted">No se encontraron usuarios.</td></tr>';
                    } else {
                        users.forEach(u => {
                            html += `<tr>
                                <td><small class="fw-bold">${u.session_id}</small></td>
                                <td>${u.last_seen}</td>
                                <td>${u.total_tokens}</td>
                                <td><a href="index.php?page=chat&session_id=${u.session_db_id}" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i></a></td>
                            </tr>`;
                        });
                    }
                    $('#usersTableBody').html(html);
                }
            });
        });
    </script>
</body>
</html>