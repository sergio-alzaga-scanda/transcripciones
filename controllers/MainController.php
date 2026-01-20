<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/Conversation.php';
require_once dirname(__DIR__) . '/models/Metrics.php';

class MainController {
    // ... (Constructor y getFilterProject igual que antes) ...
    private $metricsModel;
    private $convModel;

    public function __construct() {
        if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
            header("Location: index.php?page=logout");
            exit;
        }
        $database = new Database();
        $this->metricsModel = new Metrics($database->getConnection());
        $this->convModel = new Conversation($database->getConnection()); 
    }

    private function getFilterProject() {
        $user = $_SESSION['user'];
        if ($user['role'] === 'admin') {
            return $_GET['project_id'] ?? null;
        }
        return $user['assigned_project_id'];
    }

    public function dashboard() {
        $projectId = $this->getFilterProject();
        
        // 1. Obtener Métricas Generales
        $stats = $this->metricsModel->getStats($projectId);
        
        // 2. Obtener Datos para el Gráfico
        $chartDataRaw = $this->metricsModel->getDailyTrend($projectId);
        $chartLabels = [];
        $chartValues = [];
        foreach($chartDataRaw as $day) {
            $chartLabels[] = date('d/m', strtotime($day['fecha']));
            $chartValues[] = $day['total'];
        }

        // ======================================================
        // 3. AGREGAR ESTO (Es lo que te falta)
        // ======================================================
        $topDays = $this->metricsModel->getTopDays($projectId);
        $topConversations = $this->metricsModel->getTopLongestSessions($projectId);
        // ======================================================

        // 4. Lista de proyectos
        $projects = $this->metricsModel->getProjects(); 
        
        // 5. Auto-Sync
        $triggerAutoSync = false;
        if (isset($_SESSION['trigger_sync']) && $_SESSION['trigger_sync'] === true) {
            $triggerAutoSync = true;
            unset($_SESSION['trigger_sync']); 
        }

        include dirname(__DIR__) . '/views/dashboard.php';
    }

    // --- NUEVO: Endpoint AJAX para actualizar el gráfico ---
    public function ajaxChartData() {
        $projectId = $this->getFilterProject(); // Respetar permisos
        // Si el admin mandó un project_id específico por AJAX, úsalo (pero valídalo)
        if ($_SESSION['user']['role'] === 'admin' && isset($_GET['project_id_ajax'])) {
            $projectId = $_GET['project_id_ajax'];
        }

        $start = $_GET['start'] ?? null;
        $end = $_GET['end'] ?? null;

        $data = $this->metricsModel->getDailyTrend($projectId, $start, $end);
        
        // Formatear para JS
        $response = [
            'labels' => [],
            'values' => []
        ];
        foreach($data as $d) {
            $response['labels'][] = date('d/m/Y', strtotime($d['fecha']));
            $response['values'][] = $d['total'];
        }
        echo json_encode($response);
        exit;
    }

    // --- NUEVO: Endpoint AJAX para lista de usuarios ---
    public function ajaxUsersList() {
        $projectId = $this->getFilterProject();
        if ($_SESSION['user']['role'] === 'admin' && isset($_GET['project_id_ajax'])) {
            $projectId = $_GET['project_id_ajax'];
        }

        $users = $this->metricsModel->getUsersList($projectId);
        echo json_encode($users);
        exit;
    }
public function chatViewer() {
        $projectId = $this->getFilterProject();
        
        // Filtros
        $search = $_GET['search'] ?? '';
        $sort = $_GET['sort'] ?? 'DESC'; 
        $dateFilter = $_GET['date'] ?? ''; // NUEVO: Capturar fecha
        
        // 1. Obtener sesiones (Pasamos fecha al modelo)
        $sessions = $this->convModel->getSessions($projectId, $search, $sort, $dateFilter);
        
        // 2. Obtener lista de proyectos (Solo Admin)
        $projects = [];
        if ($_SESSION['user']['role'] === 'admin') {
            $projects = $this->metricsModel->getProjects();
        }

        // 3. Lógica de sesión seleccionada
        $selectedSessionId = $_GET['session_id'] ?? null;
        $messages = [];
        $currentSession = null;

        if ($selectedSessionId) {
            $this->convModel->markAsRead($selectedSessionId);
            $messages = $this->convModel->getMessages($selectedSessionId);
            
            foreach($sessions as &$s) {
                if($s['id'] === $selectedSessionId) {
                    $s['is_read'] = 1; 
                    $currentSession = $s;
                    break;
                }
            }
        }

        include dirname(__DIR__) . '/views/chat_viewer.php';
    }
}
?>