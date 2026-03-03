<?php
class Metrics {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Obtiene la lista de proyectos para el combo del Admin
    public function getProjects() {
        $stmt = $this->conn->prepare("SELECT project_id, COALESCE(nombre_proyecto, project_id) as nombre_proyecto FROM projects_config ORDER BY nombre_proyecto ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Métricas principales
    public function getStats($project_id = null) {
        $params = [];
        $where = "";
        if ($project_id) {
            $where = " AND project_id = :pid";
            $params[':pid'] = $project_id;
        }

        $sqlGeneral = "SELECT 
                        COUNT(*) as total_sessions,
                        COUNT(DISTINCT session_id) as total_users,
                        IFNULL(SUM(cost_credits), 0) as total_cost,
                        IFNULL(AVG(latency_ms), 0) as avg_latency
                       FROM sessions WHERE 1=1 $where";

        $sqlMessages = "SELECT 
                            COUNT(m.id) as total_messages,
                            SUM(CASE WHEN m.role = 'user' THEN 1 ELSE 0 END) as msg_user,
                            SUM(CASE WHEN m.role = 'assistant' THEN 1 ELSE 0 END) as msg_agent
                        FROM messages m 
                        INNER JOIN sessions s ON m.session_table_id COLLATE utf8mb4_general_ci = s.id COLLATE utf8mb4_general_ci 
                        WHERE 1=1 $where";

        $stmt = $this->conn->prepare($sqlGeneral);
        $stmt->execute($params);
        $general = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmtMsg = $this->conn->prepare($sqlMessages);
        $stmtMsg->execute($params);
        $msgs = $stmtMsg->fetch(PDO::FETCH_ASSOC);

        // Transferencias
        $whereTransf = $project_id ? " WHERE t.project_id = :pid" : "";
        $stmtTf = $this->conn->prepare("SELECT COUNT(*) as total_transferencias FROM tranferencias t $whereTransf");
        if ($project_id) {
            $stmtTf->execute([':pid' => $project_id]);
        } else {
            $stmtTf->execute();
        }
        $tf = $stmtTf->fetch(PDO::FETCH_ASSOC);

        // Tickets
        $whereTk = $project_id ? " WHERE tk.proyecto = :pid" : "";
        $stmtTk = $this->conn->prepare("SELECT COUNT(*) as total_tickets FROM tickets tk $whereTk");
        if ($project_id) {
            $stmtTk->execute([':pid' => $project_id]);
        } else {
            $stmtTk->execute();
        }
        $tk = $stmtTk->fetch(PDO::FETCH_ASSOC);

        return array_merge($general ?: [], $msgs ?: [], $tf ?: [], $tk ?: []);
    }

    public function getTopDays($project_id = null) {
        $where = $project_id ? " AND s.project_id = :pid " : "";
        $sql = "SELECT DATE(m.timestamp) as fecha, COUNT(m.id) as total 
                FROM messages m
                INNER JOIN sessions s ON m.session_table_id COLLATE utf8mb4_general_ci = s.id COLLATE utf8mb4_general_ci
                WHERE 1=1 $where
                GROUP BY DATE(m.timestamp) ORDER BY total DESC LIMIT 3";
        $stmt = $this->conn->prepare($sql);
        if ($project_id) $stmt->bindParam(':pid', $project_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTopLongestSessions($project_id = null) {
        $where = $project_id ? " AND s.project_id = :pid " : "";
        $sql = "SELECT s.id, s.session_id, s.created_at, COUNT(m.id) as msg_count 
                FROM sessions s
                LEFT JOIN messages m ON s.id COLLATE utf8mb4_general_ci = m.session_table_id COLLATE utf8mb4_general_ci
                WHERE 1=1 $where
                GROUP BY s.id ORDER BY msg_count DESC LIMIT 3";
        $stmt = $this->conn->prepare($sql);
        if ($project_id) $stmt->bindParam(':pid', $project_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDailyTrend($project_id = null) {
        $where = $project_id ? " AND project_id = :pid " : "";
        $sql = "SELECT DATE(created_at) as fecha, COUNT(*) as total 
                FROM sessions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) $where
                GROUP BY DATE(created_at) ORDER BY fecha ASC";
        $stmt = $this->conn->prepare($sql);
        if ($project_id) $stmt->bindParam(':pid', $project_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUsersList($project_id = null) {
        $where = $project_id ? " AND project_id = :pid " : "";
        $sql = "SELECT id as session_db_id, session_id, created_at as last_seen, platform, tokens_total 
                FROM sessions WHERE 1=1 $where ORDER BY created_at DESC LIMIT 200";
        $stmt = $this->conn->prepare($sql);
        if ($project_id) $stmt->bindParam(':pid', $project_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---- Transferencias ----
    public function getTransferenciasList($project_id = null) {
        $where = $project_id ? " WHERE t.project_id = :pid " : "";
        $sql = "SELECT t.*, s.session_id, COALESCE(pc.nombre_proyecto, t.project_id) as nombre_proyecto 
                FROM tranferencias t
                LEFT JOIN sessions s ON t.session_table_id COLLATE utf8mb4_general_ci = s.id COLLATE utf8mb4_general_ci
                LEFT JOIN projects_config pc ON t.project_id COLLATE utf8mb4_general_ci = pc.project_id COLLATE utf8mb4_general_ci
                $where
                ORDER BY t.created_at DESC LIMIT 200";
        $stmt = $this->conn->prepare($sql);
        if ($project_id) {
            $stmt->execute([':pid' => $project_id]);
        } else {
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---- Tickets ----
    public function getTicketsList($project_id = null) {
        $where = $project_id ? " WHERE tk.proyecto = :pid " : "";
        $sql = "SELECT tk.*, COALESCE(pc.nombre_proyecto, tk.proyecto) as nombre_proyecto 
                FROM tickets tk
                LEFT JOIN projects_config pc ON tk.proyecto COLLATE utf8mb4_general_ci = pc.project_id COLLATE utf8mb4_general_ci
                $where
                ORDER BY tk.created_at DESC LIMIT 200";
        $stmt = $this->conn->prepare($sql);
        if ($project_id) {
            $stmt->execute([':pid' => $project_id]);
        } else {
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}