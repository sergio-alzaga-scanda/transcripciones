<?php
// Inicio de sesión para validar roles y asignaciones
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php'; 

$database = new Database();
$db = $database->getConnection();

// 1. VARIABLES DE CONTROL BASADAS EN TU ESTRUCTURA DE TABLA
$userRole = $_SESSION['user']['role'] ?? 'cliente';
// Usamos assigned_project_id que es el nombre en tu tabla de usuarios
$assignedProjectId = $_SESSION['user']['assigned_project_id'] ?? null; 

$projectsConfig = [];

// Capturar el proyecto seleccionado por el filtro (solo para admin) o el asignado (para usuario)
$currentFilterId = ($userRole === 'admin') ? ($_GET['project_id'] ?? null) : $assignedProjectId;

// Inicializar variables de estadísticas para evitar errores
$stats = ['avg_latency' => 0, 'total_messages' => 0, 'msg_user' => 0, 'msg_agent' => 0, 'total_users' => 0, 'total_sessions' => 0];
$topDays = [];
$topConversations = [];

try {
    // 2. OBTENER CONFIGURACIÓN TÉCNICA DE PROYECTOS
    $query = "SELECT project_id, api_key, last_sync FROM projects_config";
    
    // Si NO es admin, filtramos por el proyecto asignado
    if ($userRole !== 'admin') {
        $query .= " WHERE project_id = :assigned_id";
    }
    $query .= " ORDER BY created_at DESC";
    
    $stmt = $db->prepare($query);
    if ($userRole !== 'admin') {
        $stmt->bindParam(':assigned_id', $assignedProjectId);
    }
    $stmt->execute();
    $projectsConfig = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. LOGICA PARA EL SELECTOR DE ADMIN
    $allProjects = [];
    if ($userRole === 'admin') {
        $stmtAll = $db->prepare("SELECT project_id FROM projects_config ORDER BY project_id ASC");
        $stmtAll->execute();
        $allProjects = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Error al obtener proyectos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Métricas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .clickable-card { cursor: pointer; transition: transform 0.2s; }
        .clickable-card:hover { transform: translateY(-5px); }
        
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
    
    <input type="hidden" id="currentProjectId" value="<?= htmlspecialchars($currentFilterId ?? '') ?>">

    <div class="container mt-4 mb-5">
        
        <?php if ($userRole === 'admin'): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-bold"><i class="fas fa-plus-circle"></i> Registro Técnico de Proyectos</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="small fw-bold">ID Proyecto (Voiceflow)</label>
                        <input type="text" id="syncProjectId" class="form-control" placeholder="ID del proyecto">
                    </div>
                    <div class="col-md-4">
                        <label class="small fw-bold">API Key</label>
                        <input type="password" id="syncApiKey" class="form-control" placeholder="VF.DM.XXXXXXX">
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold">Registros</label>
                        <input type="number" id="syncTake" class="form-control" value="100" min="1">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary w-100" id="btnSync">Registrar y Sincronizar</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 fw-bold text-primary">
                    <i class="fas fa-list"></i> 
                    <?= ($userRole === 'admin') ? 'Proyectos en Sistema' : 'Mi Proyecto Asignado' ?>
                </h6>

                <?php if ($userRole === 'admin'): ?>
                <form method="GET" action="index.php" class="d-flex gap-2">
                    <input type="hidden" name="page" value="dashboard">
                    <select name="project_id" class="form-select form-select-sm" style="width: auto;">
                        <option value="">Todos los proyectos</option>
                        <?php foreach ($allProjects as $p): ?>
                            <option value="<?= $p['project_id'] ?>" <?= ($p['project_id'] == ($currentFilterId ?? '')) ? 'selected' : '' ?>>
                                <?= $p['project_id'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Filtrar</button>
                </form>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID Proyecto</th>
                            <th>Última Sincronización</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($projectsConfig)): ?>
                            <?php foreach($projectsConfig as $pc): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-dark border p-2">
                                        <i class="fas fa-fingerprint text-muted"></i> 
                                        <?= htmlspecialchars($pc['project_id']) ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <i class="far fa-clock"></i> 
                                        <?= $pc['last_sync'] ? date("d/m/Y H:i", strtotime($pc['last_sync'] . " -6 hours")) : 'Sin sincronizar' ?>
                                    </small>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-success px-3" 
                                            onclick="syncProjectManual('<?= $pc['project_id'] ?>', '<?= $pc['api_key'] ?>')">
                                        <i class="fas fa-sync-alt"></i> Actualizar Datos
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">
                                    <i class="fas fa-info-circle fa-2x mb-2"></i><br>
                                    No se encontró configuración técnica para el proyecto: <b><?= htmlspecialchars($assignedProjectId ?? 'No asignado') ?></b>.<br>
                                    <small>Contacta al administrador para registrar las credenciales del proyecto.</small>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2 small">T. Prom. Respuesta</h6>
                        <h3 class="mb-0 fw-bold text-dark"><?= number_format($stats['avg_latency'] ?? 0, 0) ?> <small class="fs-6 text-muted">ms</small></h3>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2 small">Mensajes Totales</h6>
                        <h3 class="mb-0 fw-bold text-dark"><?= number_format($stats['total_messages'] ?? 0) ?></h3>
                        <div class="mt-2 small">
                            <span class="text-success"><i class="fas fa-user"></i> <?= number_format($stats['msg_user'] ?? 0) ?></span> | 
                            <span class="text-primary"><i class="fas fa-robot"></i> <?= number_format($stats['msg_agent'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-success clickable-card" id="btnShowUsers">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2 small">Usuarios Únicos</h6>
                        <h3 class="mb-0 fw-bold text-dark"><?= number_format($stats['total_users'] ?? 0) ?></h3>
                        <small class="text-success">Ver lista <i class="fas fa-arrow-right"></i></small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary clickable-card" onclick="window.location.href='index.php?page=chat'">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2 small">Sesiones Totales</h6>
                        <h3 class="mb-0 fw-bold text-dark"><?= number_format($stats['total_sessions'] ?? 0) ?></h3>
                        <small class="text-primary">Ir al historial <i class="fas fa-arrow-right"></i></small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold"><i class="fas fa-calendar-day text-warning"></i> Top Días Activos</div>
                    <ul class="list-group list-group-flush">
                        <?php if(!empty($topDays)): ?>
                            <?php foreach($topDays as $day): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?= date("d/m/Y", strtotime($day['fecha'])) ?>
                                <span class="badge bg-secondary rounded-pill"><?= $day['total'] ?> msgs</span>
                            </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="list-group-item text-muted">Sin datos de actividad</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-white fw-bold"><i class="fas fa-comments text-primary"></i> Conversaciones más largas</div>
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
                        <h6 class="m-0 fw-bold text-primary">Actividad de Sesiones (Mensajes Diarios)</h6>
                        <div class="d-flex gap-1">
                            <input type="date" id="chartStart" class="form-control form-control-sm w-auto">
                            <input type="date" id="chartEnd" class="form-control form-control-sm w-auto">
                            <button class="btn btn-sm btn-outline-secondary" id="btnUpdateChart"><i class="fas fa-sync"></i></button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div style="height: 350px;">
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
                    <h5 class="modal-title">Usuarios Únicos Registrados</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead class="table-light">
                                <tr><th>ID Sesión</th><th>Última Actividad</th><th>Tokens</th><th>Acción</th></tr>
                            </thead>
                            <tbody id="usersTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script src="public/js/app.js"></script>

    <script>
        // 1. AUTO-SYNC INICIAL
        const shouldSync = <?= (isset($triggerAutoSync) && $triggerAutoSync) ? 'true' : 'false' ?>;
        if (shouldSync) { runAutoSync(); }

        function runAutoSync() {
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.add('active');
            let projectId = "<?= $currentFilterId ?? '' ?>";
            
            const apiUrl = `http://127.0.0.1:5001/sync-voiceflow?take=100&id_project=${projectId}`;
            fetch(apiUrl)
                .then(response => response.json())
                .then(data => {
                    setTimeout(() => { window.location.reload(); }, 500);
                })
                .catch(error => {
                    console.error("Error:", error);
                    overlay.classList.remove('active');
                });
        }

        // 2. CHART.JS LOGIC
        const ctx = document.getElementById('dailyChart').getContext('2d');
        let myChart;
        const initialLabels = <?= json_encode($chartLabels ?? []) ?>;
        const initialData = <?= json_encode($chartValues ?? []) ?>;

        function renderChart(labels, data) {
            if (myChart) myChart.destroy();
            myChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Interacciones',
                        data: data,
                        borderColor: '#4e73df',
                        backgroundColor: 'rgba(78, 115, 223, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        }
        renderChart(initialLabels, initialData);

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

        // 3. AJAX USERS
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
                        html = '<tr><td colspan="4" class="text-center">No hay usuarios</td></tr>';
                    } else {
                        users.forEach(u => {
                            html += `<tr>
                                <td><small class="fw-bold text-muted">${u.session_id}</small></td>
                                <td>${u.last_seen}</td>
                                <td><span class="badge bg-light text-dark border">${u.total_tokens}</span></td>
                                <td><a href="index.php?page=chat&session_id=${u.session_db_id}" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a></td>
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