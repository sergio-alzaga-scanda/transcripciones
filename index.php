<?php
session_start();

require_once 'controllers/AuthController.php';
require_once 'controllers/MainController.php';

$page = $_GET['page'] ?? 'login';

// Middleware simple de autenticación
if ($page !== 'login' && !isset($_SESSION['user'])) {
    header("Location: index.php?page=login");
    exit;
}

switch($page) {
    case 'login':
        (new AuthController())->login();
        break;
        
    case 'logout':
        (new AuthController())->logout();
        break;
        
    case 'dashboard':
        (new MainController())->dashboard();
        break;

    case 'chat':
        (new MainController())->chatViewer();
        break;
    case 'ajax_chart':
        (new MainController())->ajaxChartData();
        break;
    case 'ajax_users':
        (new MainController())->ajaxUsersList();
        break;    
    default:
        header("Location: index.php?page=login");
        break;
}
?>