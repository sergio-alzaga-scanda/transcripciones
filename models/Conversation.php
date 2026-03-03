<?php
class Conversation {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function markAsRead($sessionId) {
        $sql = "UPDATE sessions 
                SET is_read = 1, new_messages_count = '0' 
                WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $sessionId);
        $stmt->execute();
    }

    public function getSessions($project_id = null, $search = '', $sortParam = 'DESC', $dateFilter = '', $filterType = '') {

        switch ($sortParam) {
            case 'ASC':
                $orderBy = "is_transferencia_activa DESC, s.created_at ASC";
                break;
            case 'MSG_DESC':
                $orderBy = "is_transferencia_activa DESC, msg_count DESC";
                break;
            case 'DESC':
            default:
                $orderBy = "is_transferencia_activa DESC, s.created_at DESC";
                break;
        }

        $sql = "SELECT 
                    s.*,
                    (SELECT COUNT(*) FROM messages m WHERE m.session_table_id = s.id) as msg_count,
                    (SELECT COUNT(*) FROM messages m2 
                     WHERE m2.session_table_id = s.id 
                       AND m2.role = 'tranferencia'
                       AND m2.timestamp >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                    ) as is_transferencia_activa,
                    (SELECT COUNT(*) FROM messages m3
                     WHERE m3.session_table_id = s.id
                       AND m3.role = 'tranferencia'
                    ) as tiene_transferencia,
                    (SELECT COUNT(*) FROM tickets t
                     WHERE t.id_sesion COLLATE utf8mb4_general_ci = s.session_id COLLATE utf8mb4_general_ci
                    ) as tiene_ticket
                FROM sessions s 
                WHERE 1=1";

        if ($project_id) {
            $sql .= " AND s.project_id = :pid";
        }
        if (!empty($search)) {
            $term = "%$search%";
            $sql .= " AND (s.session_id LIKE :search OR s.id LIKE :search)";
        }
        if (!empty($dateFilter)) {
            $sql .= " AND DATE(s.created_at) = :filterDate";
        }
        $sql .= " GROUP BY s.id";

        if ($filterType === 'transferidas_activas') {
            $sql .= " HAVING is_transferencia_activa > 0";
        } elseif ($filterType === 'transferidas_todas') {
            $sql .= " HAVING tiene_transferencia > 0";
        } elseif ($filterType === 'con_ticket') {
            $sql .= " HAVING tiene_ticket > 0";
        }

        $sql .= " ORDER BY $orderBy LIMIT 50";

        $stmt = $this->conn->prepare($sql);

        if ($project_id) $stmt->bindParam(':pid', $project_id);
        if (!empty($search)) {
            $stmt->bindParam(':search', $term);
        }
        if (!empty($dateFilter)) {
            $stmt->bindParam(':filterDate', $dateFilter);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMessages($session_id) {
        $sql = "SELECT * FROM messages WHERE session_table_id = :sid ORDER BY timestamp ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':sid', $session_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getComentario($session_id) {
        $sql = "SELECT comentario, created_at FROM comentarios_conversacion WHERE session_table_id = :sid LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':sid', $session_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

?>