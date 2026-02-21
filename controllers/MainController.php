<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/Conversation.php';
require_once dirname(__DIR__) . '/models/Metrics.php';

class MainController {
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
        
        $stats = $this->metricsModel->getStats($projectId);
        
        $chartDataRaw = $this->metricsModel->getDailyTrend($projectId);
        $chartLabels = [];
        $chartValues = [];
        foreach($chartDataRaw as $day) {
            $chartLabels[] = date('d/m', strtotime($day['fecha']));
            $chartValues[] = $day['total'];
        }

        $topDays = $this->metricsModel->getTopDays($projectId);
        $topConversations = $this->metricsModel->getTopLongestSessions($projectId);

        $projects = $this->metricsModel->getProjects(); 
        
        $triggerAutoSync = false;
        if (isset($_SESSION['trigger_sync']) && $_SESSION['trigger_sync'] === true) {
            $triggerAutoSync = true;
            unset($_SESSION['trigger_sync']); 
        }

        include dirname(__DIR__) . '/views/dashboard.php';
    }

    public function ajaxChartData() {
        $projectId = $this->getFilterProject(); 
        if ($_SESSION['user']['role'] === 'admin' && isset($_GET['project_id_ajax'])) {
            $projectId = $_GET['project_id_ajax'];
        }

        $start = $_GET['start'] ?? null;
        $end = $_GET['end'] ?? null;

        $data = $this->metricsModel->getDailyTrend($projectId, $start, $end);
        
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
        $dateFilter = $_GET['date'] ?? ''; 
        
        // 1. Obtener sesiones
        $sessions = $this->convModel->getSessions($projectId, $search, $sort, $dateFilter);
        
        // 2. Obtener lista de proyectos
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
                // Usar == en lugar de === para prevenir fallos por tipos de datos (string vs int)
                if($s['id'] == $selectedSessionId) {
                    $s['is_read'] = 1; 
                    $s['new_messages_count'] = '0';
                    $currentSession = $s;
                    break;
                }
            }
            // ¡CLAVE! Destruir la referencia para evitar duplicados en la vista
            unset($s); 
        }

        include dirname(__DIR__) . '/views/chat_viewer.php'; 
    }
}
?>