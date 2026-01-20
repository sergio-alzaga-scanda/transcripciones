<?php
class Metrics {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getProjects() {
        $stmt = $this->conn->prepare("SELECT DISTINCT project_id FROM sessions");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- MODIFICADO: Métricas completas ---
    public function getStats($project_id = null) {
        // 1. Métricas Generales (Sesiones y Latencia)
        $sqlGeneral = "SELECT 
                        COUNT(*) as total_sessions,
                        COUNT(DISTINCT session_id) as total_users,
                        SUM(cost_credits) as total_cost,
                        AVG(latency_ms) as avg_latency
                       FROM sessions WHERE 1=1";
        
        // 2. Desglose de Mensajes (Usuario vs Agente)
        $sqlMessages = "SELECT 
                            COUNT(*) as total_messages,
                            SUM(CASE WHEN m.role = 'user' THEN 1 ELSE 0 END) as msg_user,
                            SUM(CASE WHEN m.role = 'assistant' THEN 1 ELSE 0 END) as msg_agent
                        FROM messages m 
                        INNER JOIN sessions s ON m.session_table_id = s.id 
                        WHERE 1=1";

        if ($project_id) {
            $sqlGeneral .= " AND project_id = :pid";
            $sqlMessages .= " AND s.project_id = :pid";
        }

        // Ejecutar General
        $stmt = $this->conn->prepare($sqlGeneral);
        if ($project_id) $stmt->bindParam(':pid', $project_id);
        $stmt->execute();
        $general = $stmt->fetch(PDO::FETCH_ASSOC);

        // Ejecutar Mensajes
        $stmtMsg = $this->conn->prepare($sqlMessages);
        if ($project_id) $stmtMsg->bindParam(':pid', $project_id);
        $stmtMsg->execute();
        $msgs = $stmtMsg->fetch(PDO::FETCH_ASSOC);

        return array_merge($general, $msgs);
    }

    // --- NUEVO: Top 3 Días con más actividad ---
    public function getTopDays($project_id = null) {
        $sql = "SELECT 
                    DATE(timestamp) as fecha, 
                    COUNT(*) as total 
                FROM messages m
                INNER JOIN sessions s ON m.session_table_id = s.id
                WHERE 1=1";
        
        if ($project_id) $sql .= " AND s.project_id = :pid";
        
        $sql .= " GROUP BY DATE(timestamp) ORDER BY total DESC LIMIT 3";

        $stmt = $this->conn->prepare($sql);
        if ($project_id) $stmt->bindParam(':pid', $project_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- NUEVO: Top 3 Conversaciones más largas ---
    public function getTopLongestSessions($project_id = null) {
        $sql = "SELECT 
                    s.id, 
                    s.session_id, 
                    s.created_at,
                    COUNT(m.id) as msg_count 
                FROM sessions s
                LEFT JOIN messages m ON s.id = m.session_table_id
                WHERE 1=1";

        if ($project_id) $sql .= " AND s.project_id = :pid";

        $sql .= " GROUP BY s.id ORDER BY msg_count DESC LIMIT 3";

        $stmt = $this->conn->prepare($sql);
        if ($project_id) $stmt->bindParam(':pid', $project_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Gráfico Lineal (Mantener anterior)
    public function getDailyTrend($project_id = null, $start = null, $end = null) {
        $sql = "SELECT DATE(created_at) as fecha, COUNT(*) as total FROM sessions WHERE 1=1";
        if ($start && $end) $sql .= " AND DATE(created_at) BETWEEN :start AND :end";
        else $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        if ($project_id) $sql .= " AND project_id = :pid";
        $sql .= " GROUP BY DATE(created_at) ORDER BY fecha ASC";
        $stmt = $this->conn->prepare($sql);
        if ($project_id) $stmt->bindParam(':pid', $project_id);
        if ($start && $end) { $stmt->bindParam(':start', $start); $stmt->bindParam(':end', $end); }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // (Otros métodos necesarios para el modal de usuarios, etc. mantenlos igual)
    public function getUsersList($project_id = null) {
        $sql = "SELECT id as session_db_id, session_id, MAX(created_at) as last_seen, platform, SUM(tokens_total) as total_tokens FROM sessions WHERE 1=1";
        if ($project_id) $sql .= " AND project_id = :pid";
        $sql .= " GROUP BY session_id ORDER BY last_seen DESC LIMIT 200";
        $stmt = $this->conn->prepare($sql);
        if ($project_id) $stmt->bindParam(':pid', $project_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>