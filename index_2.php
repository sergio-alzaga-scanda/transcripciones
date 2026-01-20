<?php
$servername = "localhost";
$port = 3307;
$username = "root";
$password = "";
$database = "voiceFlow";

$conn = new mysqli($servername, $username, $password, $database, $port);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Obtener filtros de la URL
$filter_user_number = $_GET['user_number'] ?? '';
$filter_projectID = $_GET['projectID'] ?? '';
$filter_sessionID = $_GET['sessionID'] ?? '';
$filter_fecha = $_GET['fecha'] ?? '';
$filter_id_caso = $_GET['id_caso'] ?? '';

// Construir condiciones de la consulta WHERE
$where_conditions = [];
if (!empty($filter_user_number)) {
    $safe_user_number = $conn->real_escape_string($filter_user_number);
    $where_conditions[] = "user_number = '$safe_user_number'";
}
if (!empty($filter_projectID)) {
    $safe_projectID = $conn->real_escape_string($filter_projectID);
    $where_conditions[] = "projectID LIKE '%$safe_projectID%'";
}
if (!empty($filter_sessionID)) {
    $safe_sessionID = $conn->real_escape_string($filter_sessionID);
    $where_conditions[] = "sessionID LIKE '%$safe_sessionID%'";
}
if (!empty($filter_id_caso)) {
    $safe_id_caso = $conn->real_escape_string($filter_id_caso);
    $where_conditions[] = "id_caso LIKE '%$safe_id_caso%'";
}

$hasFilters = !empty($filter_user_number) || !empty($filter_projectID) || !empty($filter_sessionID) || !empty($filter_fecha) || !empty($filter_id_caso);
$where_sql = $where_conditions ? 'WHERE '. implode(' AND ', $where_conditions) : '';

$resumen_result = null;
if ($hasFilters) {
    $resumen_sql = "SELECT user_number, projectID, sessionID, status, id_caso
                      FROM conversaciones_html 
                      $where_sql 
                      ORDER BY fecha_creacion DESC";
    $resumen_result = $conn->query($resumen_sql);
}

// Lógica para mostrar el detalle de una conversación seleccionada
$selected_user_number = $_GET['selected_user_number'] ?? null;
$selected_date = $_GET['selected_date'] ?? null;
$html_output = '';
$hora_inicio = $hora_fin = null;

if ($selected_user_number) {
    $user_number_safe = $conn->real_escape_string($selected_user_number);
    $html_sql = "SELECT html, status FROM conversaciones_html WHERE user_number = '$user_number_safe'";
    $html_res = $conn->query($html_sql);

    if ($html_res && $html_res->num_rows) {
        $all_li_items = [];
        while ($row = $html_res->fetch_assoc()) {
            if ($row['status'] == 0) {
                $conn->query("UPDATE conversaciones_html SET status = 1 WHERE user_number = '$user_number_safe'");
            }
            $dom = new DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($row['html'], 'HTML-ENTITIES', 'UTF-8'));
            $items = $dom->getElementsByTagName('li');
            foreach ($items as $item) {
                $smallTags = $item->getElementsByTagName('small');
                if ($smallTags->length > 0) {
                    $fechaHora = $smallTags[0]->nodeValue;
                    $li_html = $dom->saveHTML($item);
                    $agenteIcono = '<img src="data:image/png;base64,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" style="width:24px;height:24px;" />';
                    $usuarioIcono = '<img src="data:image/png;base64,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" style="width:24px;height:24px;" />';


                    $li_html_decoded = str_replace('agenteIconoProd', $agenteIcono, $li_html);
                    $li_html_decoded = str_replace('usuarioIconoProd', $usuarioIcono, $li_html_decoded);
                    $all_li_items[] = ['fechaHora' => $fechaHora, 'li_html' => $li_html_decoded];
                }
            }
        }
        usort($all_li_items, function($a, $b) {
            return strtotime($a['fechaHora']) <=> strtotime($b['fechaHora']);
        });
        $conversacionesPorFecha = [];
        foreach ($all_li_items as $item) {
            $fecha = substr($item['fechaHora'], 0, 10);
            if (!isset($conversacionesPorFecha[$fecha])) {
                $conversacionesPorFecha[$fecha] = ['items' => [], 'horas' => []];
            }
            $conversacionesPorFecha[$fecha]['items'][] = $item['li_html'];
            $conversacionesPorFecha[$fecha]['horas'][] = $item['fechaHora'];
        }
        if ($selected_date && isset($conversacionesPorFecha[$selected_date])) {
            $fechadata = $conversacionesPorFecha[$selected_date];
            $hora_inicio = $fechadata['horas'][0] ?? null;
            $hora_fin = end($fechadata['horas']) ?? null;
            $html_output = "<ul style='list-style:none;'>" . implode('', $fechadata['items']) . "</ul>";
        } else {
            $html_output = "<p>No hay mensajes para esta fecha.</p>";
        }
    } else {
        $html_output = "<p>No se encontró detalle para este usuario.</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>VoiceFlow Conversaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #conversations-list { max-height: 90vh; overflow-y: auto; }
        #conversation-detail { max-height: 90vh; overflow-y: auto; }
        .meta-card { font-size: 0.9rem; background-color: #f8f9fa; padding: 10px; border-radius: 5px; margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row vh-100">
        <div class="col-md-4 border-end d-flex flex-column" id="conversations-list">
            <div class="p-2">
                <h4>Conversaciones</h4>
                <form method="GET" class="mb-3">
                    <input type="text" name="user_number" class="form-control mb-2" placeholder="Buscar por User Number" value="<?= htmlspecialchars($filter_user_number) ?>">
                    <input type="text" name="id_caso" class="form-control mb-2" placeholder="Buscar por ID Caso" value="<?= htmlspecialchars($filter_id_caso) ?>">
                    <input type="date" name="fecha" class="form-control mb-2" value="<?= htmlspecialchars($filter_fecha) ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
                    <a href="index.php" class="btn btn-secondary btn-sm">Limpiar</a>
                </form>
                <?php if (!$hasFilters): ?>
                    <div class="alert alert-warning">Por favor aplica al menos un filtro.</div>
                <?php endif; ?>
            </div>
            <hr>
            <label for="range-select" class="mt-2">Actualizar Conversaciones</label>
            <select id="range-select" class="form-select form-select-sm mb-3">
                <option value="">-- Selecciona un rango --</option>
                <option value="Today">Hoy</option>
                <option value="Yesterday">Ayer</option>
                <option value="Last%207%20Days">Últimos 7 días</option>
                <option value="Last%2030%20days">Últimos 30 días</option>
                <option value="All%20time">Todo</option>
            </select>
            <ul class="list-group flex-grow-1 overflow-auto">
                <?php if ($resumen_result && $resumen_result->num_rows > 0): ?>
                    <?php $seen = []; while ($row = $resumen_result->fetch_assoc()): ?>
                        <?php
                            $html_sql = "SELECT html FROM conversaciones_html WHERE user_number = '" . $conn->real_escape_string($row['user_number']) . "' ORDER BY LENGTH(html) DESC LIMIT 1";
                            $html_result = $conn->query($html_sql);
                            if (!$html_result || !$html_result->num_rows) continue;
                            
                            $conversaciones = [];
                            $dom = new DOMDocument();
                            @$dom->loadHTML(mb_convert_encoding($html_result->fetch_assoc()['html'], 'HTML-ENTITIES', 'UTF-8'));
                            $items = $dom->getElementsByTagName('li');
                            foreach ($items as $item) {
                                $smallTags = $item->getElementsByTagName('small');
                                if ($smallTags->length > 0) {
                                    $fechaHora = $smallTags[0]->nodeValue;
                                    $fecha = substr($fechaHora, 0, 10);
                                    if (!isset($conversaciones[$fecha])) {
                                        $conversaciones[$fecha] = ['horas' => []];
                                    }
                                    $conversaciones[$fecha]['horas'][] = $fechaHora;
                                }
                            }
                        ?>
                        <?php foreach ($conversaciones as $fecha => $datos): ?>
                            <?php if (!empty($filter_fecha) && $fecha !== $filter_fecha) continue;
                                    $key = $row['user_number'] . '|' . $datos['horas'][0];
                                    if (isset($seen[$key])) continue;
                                    $seen[$key] = true;
                                    $h0 = $datos['horas'][0];
                                    $h1 = end($datos['horas']);
                            ?>
                            <li class="list-group-item d-flex flex-column">
                                <div class="d-flex justify-content-between">
                                    <a href="?selected_user_number=<?= urlencode($row['user_number']) ?>&selected_date=<?= urlencode($fecha) ?>&user_number=<?= urlencode($filter_user_number) ?>&projectID=<?= urlencode($filter_projectID) ?>&sessionID=<?= urlencode($filter_sessionID) ?>&fecha=<?= urlencode($filter_fecha) ?>&id_caso=<?= urlencode($filter_id_caso) ?>">
                                        <strong>User #:</strong> <?= htmlspecialchars($row['user_number']) ?>
                                    </a>
                                    <?php if ($row['status'] == 0): ?>
                                        <span class="badge bg-warning text-dark">No abierto</span>
                                    <?php endif; ?>
                                </div>
                                <small><strong>ID Caso:</strong> <?= htmlspecialchars($row['id_caso'] ?: 'N/A') ?></small><br>
                                <small><strong>Inicio:</strong> <?= htmlspecialchars($h0) ?></small><br>
                                <small><strong>Fin:</strong> <?= htmlspecialchars($h1) ?></small><br>
                                <small><strong>ProjectID:</strong> <?= htmlspecialchars($row['projectID']) ?></small><br>
                                <small><strong>SessionID:</strong> <?= htmlspecialchars($row['sessionID']) ?></small>
                            </li>
                        <?php endforeach; ?>
                    <?php endwhile; ?>
                <?php elseif ($hasFilters): ?>
                    <li class="list-group-item">No se encontraron resultados.</li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="col-md-8" id="conversation-detail">
            <h4>Detalle Conversación</h4>
            <?php if ($selected_user_number && $selected_date): ?>
                <div class="meta-card">
                    <strong>User #:</strong> <?= htmlspecialchars($selected_user_number) ?><br>
                    <strong>Inicio:</strong> <?= htmlspecialchars($hora_inicio ?? '-') ?><br>
                    <strong>Fin:</strong> <?= htmlspecialchars($hora_fin ?? '-') ?>
                </div>
                <div id="conversation-messages"><?= $html_output ?></div>
            <?php else: ?>
                <p>Selecciona una conversación para ver el detalle.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<div id="splash-screen" style="display: none; position: fixed; top: 0; left: 0; 
      width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); 
      color: white; font-size: 1.5rem; justify-content: center; 
      align-items: center; z-index: 9999;">
    Procesando, por favor espera...
</div>

<script>
document.getElementById('range-select').addEventListener('change', function () {
    const selected = this.value;
    if (!selected) return;

    const splash = document.getElementById('splash-screen');
    splash.style.display = 'flex';

    fetch(`http://127.0.0.1:5001/sync-voiceflow`)
        .then(response => {
            if (!response.ok) {
                throw new Error('La respuesta de la red no fue exitosa.');
            }
            return response.json();
        })
        .then(data => {
            console.log('Proceso completado:', data);
            alert('Actualización completada. Recargando la página.');
            window.location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ocurrió un error durante la actualización. Revisa la consola para más detalles.');
        })
        .finally(() => {
             splash.style.display = 'none';
        });
});

// --- NUEVO SCRIPT PARA FILTROS EXCLUYENTES ---
document.addEventListener('DOMContentLoaded', function() {
    const userNumberInput = document.querySelector('input[name="user_number"]');
    const idCasoInput = document.querySelector('input[name="id_caso"]');
    const fechaInput = document.querySelector('input[name="fecha"]');
    
    const allFilters = [userNumberInput, idCasoInput, fechaInput];

    function updateFilterStates() {
        // Encuentra el primer filtro que tiene un valor
        const activeFilter = allFilters.find(input => input.value !== '');

        if (activeFilter) {
            // Si hay un filtro activo, deshabilita todos los demás
            allFilters.forEach(input => {
                if (input !== activeFilter) {
                    input.disabled = true;
                }
            });
        } else {
            // Si no hay ningún filtro activo, habilita todos
            allFilters.forEach(input => {
                input.disabled = false;
            });
        }
    }

    // Añade un listener a cada filtro para que la función se ejecute en cada cambio
    allFilters.forEach(input => {
        input.addEventListener('input', updateFilterStates);
    });

    // Ejecuta la función una vez al cargar la página por si viene con un filtro ya aplicado
    updateFilterStates();
});
</script>
</body>
</html>