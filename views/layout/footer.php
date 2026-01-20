</div> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
$(document).ready(function() {
    
    // 1. Lógica del Chat Modal
    $('.view-chat').click(function() {
        let sessionId = $(this).data('id');
        $('#chatContent').html('<div class="text-center"><div class="spinner-border text-primary"></div> Cargando...</div>');
        $('#chatModal').modal('show');

        $.ajax({
            url: 'index.php?page=ajax_chat',
            method: 'GET',
            data: { id: sessionId },
            dataType: 'json',
            success: function(data) {
                let html = '';
                if(data.length === 0) {
                    html = '<p class="text-center text-muted">No hay mensajes registrados.</p>';
                } else {
                    data.forEach(msg => {
                        let roleClass = (msg.role === 'user') ? 'chat-user' : 'chat-assistant';
                        let icon = (msg.role === 'user') ? '<i class="fas fa-user"></i>' : '<i class="fas fa-robot"></i>';
                        html += `
                            <div class="d-flex ${msg.role === 'user' ? 'justify-content-end' : 'justify-content-start'}">
                                <div class="chat-bubble ${roleClass}">
                                    <strong>${icon} ${msg.role}</strong><br>
                                    ${msg.content}
                                    <span class="chat-time">${msg.timestamp}</span>
                                </div>
                            </div>
                        `;
                    });
                }
                $('#chatContent').html(html);
            },
            error: function() {
                $('#chatContent').html('<p class="text-danger">Error cargando el chat.</p>');
            }
        });
    });

    // 2. Lógica de Sincronización (Llamada al API Python)
    $('#btnSync').click(function() {
        let take = $('#syncTake').val();
        
        // Validación 1-100
        if (take < 1 || take > 100) {
            alert("Por favor ingresa un número entre 1 y 100");
            return;
        }

        let btn = $(this);
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Procesando...');
        $('#syncStatus').text('Conectando con servidor de métricas...');

        // NOTA: Ajusta esta URL a donde corre tu vf_db_client.py
        // Si tu PHP y Python están en localhost, esto funciona.
        // Si no, necesitamos un proxy en PHP.
        let apiUrl = 'http://localhost:5001/sync-voiceflow'; 
        
        // Obtenemos el ID de proyecto actual (si no es admin se envía, si es admin y filtra se envía)
        let urlParams = new URLSearchParams(window.location.search);
        let currentProject = urlParams.get('project_filter'); // Del filtro admin
        
        // Si no hay filtro y es admin, quizás quiera sync todo, o el default.
        // Aquí asumimos que le pasamos el filtro activo si existe.
        
        $.ajax({
            url: apiUrl, 
            method: 'GET',
            data: { 
                take: take,
                id_project: currentProject // Si es null, el Python usa default
            },
            success: function(response) {
                console.log(response);
                $('#syncStatus').removeClass('text-danger').addClass('text-success').text('Sincronización Completada: ' + response.metrics.sessions_upserted + ' sesiones.');
                setTimeout(() => location.reload(), 2000); // Recargar para ver datos nuevos
            },
            error: function(xhr) {
                console.error(xhr);
                $('#syncStatus').addClass('text-danger').text('Error en sync. Revisa la consola.');
                btn.prop('disabled', false).text('Ejecutar');
            }
        });
    });
});
</script>
</body>
</html>