<?php
// Partial: listado de sesiones en el sidebar
// Variables requeridas: $sessions (array), $selectedSessionId, $search, $sort, $dateFilter
?>
<?php if (empty($sessions)): ?>
    <div class="p-4 text-center text-muted">
        <i class="fas fa-filter fa-2x mb-2 opacity-50"></i><br>
        Sin resultados.
    </div>
<?php else: ?>
    <?php foreach($sessions as $s): ?>
        <?php 
            $isActive    = ($selectedSessionId == $s['id']) ? 'active' : '';
            $isTransfAct = ($s['is_transferencia_activa'] ?? 0) > 0;
            $tieneTransf = ($s['tiene_transferencia']     ?? 0) > 0;
            $date = date("d/m H:i", strtotime($s['created_at']));
            
            $url = "index.php?page=chat&session_id={$s['id']}&search=" . urlencode($search ?? '') . "&sort={$sort}&date={$dateFilter}";
            if (isset($_GET['project_id'])) {
                $url .= "&project_id=" . urlencode($_GET['project_id']);
            }
            if (isset($_GET['estado']) && !empty($_GET['estado'])) {
                $url .= "&estado=" . urlencode($_GET['estado']);
            }

            $extraClass = $isTransfAct ? 'transfer-active' : '';
        ?>
        <a href="<?= $url ?>" class="list-group-item list-group-item-action session-item <?= $isActive ?> <?= $extraClass ?>">
            
            <div class="d-flex w-100 justify-content-between align-items-center">
                <h6 class="mb-1 text-truncate fw-bold" style="max-width: 60%;">
                    <?= htmlspecialchars($s['session_id']) ?>
                    <?php if ($isTransfAct): ?>
                        <span class="badge-transf-activa ms-1"><i class="fas fa-headset"></i> TRANSFER.</span>
                    <?php elseif ($tieneTransf): ?>
                        <span class="badge bg-warning text-dark ms-1" style="font-size:0.55rem; vertical-align:middle;"><i class="fas fa-headset"></i></span>
                    <?php endif; ?>
                </h6>
                <small class="<?= $isActive ? 'text-primary' : 'text-muted' ?>" style="font-size: 0.75rem;"><?= $date ?></small>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mt-1">
                <p class="mb-0 small text-truncate <?= $isActive ? 'text-dark' : 'text-muted' ?>" style="max-width: 70%;">
                    <i class="fas fa-robot"></i> <?= htmlspecialchars($s['model_used'] ?? '') ?>
                </p>
                
                <span class="badge <?= $isActive ? 'bg-primary text-white' : 'bg-light text-muted border' ?> rounded-pill" style="font-size: 0.7em;">
                    <?= $s['msg_count'] ?> <i class="fas fa-comment"></i>
                </span>
            </div>
        </a>
    <?php endforeach; ?>
<?php endif; ?>
