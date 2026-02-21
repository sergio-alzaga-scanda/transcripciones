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

    public function getSessions($project_id = null, $search = '', $sortParam = 'DESC', $dateFilter = '') {
        
        // El orden ahora es estrictamente por fecha o cantidad de mensajes. 
        // Se elimina por completo la prioridad de "no leídos".
        switch ($sortParam) {
            case 'ASC':
                $orderBy = "s.created_at ASC"; 
                break;
            case 'MSG_DESC':
                $orderBy = "msg_count DESC";
                break;
            case 'DESC':
            default:
                $orderBy = "s.created_at DESC"; 
                break;
        }

        $sql = "SELECT 
                    s.*,
                    (SELECT COUNT(*) FROM messages m WHERE m.session_table_id = s.id) as msg_count
                FROM sessions s 
                WHERE 1=1";
        
        if ($project_id) {
            $sql .= " AND s.project_id = :pid";
        }
        if (!empty($search)) {
            $sql .= " AND (s.session_id LIKE :search OR s.id LIKE :search)";
        }
        if (!empty($dateFilter)) {
            $sql .= " AND DATE(s.created_at) = :filterDate";
        }
        
        // GROUP BY s.id asegura que no existan elementos duplicados en el listado
        $sql .= " GROUP BY s.id ORDER BY $orderBy LIMIT 50";

        $stmt = $this->conn->prepare($sql);
        
        if ($project_id) $stmt->bindParam(':pid', $project_id);
        if (!empty($search)) {
            $term = "%$search%";
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
}
?>