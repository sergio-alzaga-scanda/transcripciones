<?php
require_once 'config/db.php';
require_once 'models/Metrics.php';

class DashboardController {
    public function index() {
        if (!isset($_SESSION['user_id'])) {
            header("Location: index.php?page=login");
            exit;
        }

        $database = new Database();
        $db = $database->getConnection();
        $metricsModel = new Metrics($db);

        // Lógica de filtrado
        $filter_project = null;
        $projects = [];

        if ($_SESSION['role'] === 'admin') {
            $projects = $metricsModel->getProjects();
            // Si el admin selecciona un proyecto del combo
            if (isset($_GET['project_filter']) && !empty($_GET['project_filter'])) {
                $filter_project = $_GET['project_filter'];
            }
        } else {
            // Usuario normal: forzado a su proyecto
            $filter_project = $_SESSION['assigned_project_id'];
        }

        // Obtener datos
        $stats = $metricsModel->getStats($filter_project);
        $sessions = $metricsModel->getSessions($filter_project, 100);

        require 'views/dashboard.php';
    }

    public function getChatDetails() {
        // AJAX endpoint
        $id = $_GET['id'] ?? null;
        if(!$id) echo json_encode([]);
        
        $database = new Database();
        $metricsModel = new Metrics($database->getConnection());
        $msgs = $metricsModel->getMessages($id);
        echo json_encode($msgs);
        exit;
    }
}
?>