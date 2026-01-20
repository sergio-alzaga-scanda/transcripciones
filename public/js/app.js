document.addEventListener("DOMContentLoaded", function () {
  const btnSync = document.getElementById("btnSync");

  if (btnSync) {
    btnSync.addEventListener("click", function () {
      // 1. Obtener valores del DOM
      const takeInput = document.getElementById("syncTake");
      const projectInput = document.getElementById("syncProjectId");
      const statusLabel = document.getElementById("syncStatus");

      const take = takeInput ? takeInput.value : 25;
      const projectId = projectInput ? projectInput.value : "";

      // 2. Validar
      if (take < 1 || take > 100) {
        alert("El número de registros (Take) debe estar entre 1 y 100");
        return;
      }

      // 3. UI Loading (Bloquear botón)
      btnSync.disabled = true;
      btnSync.innerHTML =
        '<i class="fas fa-spinner fa-spin"></i> Procesando...';
      statusLabel.className = "text-primary";
      statusLabel.innerText = "Conectando con Python API...";

      // 4. Construir URL
      // Asegúrate que este puerto (5001) sea el mismo que usa tu vf_db_client.py
      const apiUrl = `http://127.0.0.1:5001/sync-voiceflow?take=${take}&id_project=${projectId}`;

      // 5. Ejecutar Fetch
      fetch(apiUrl)
        .then((response) => {
          // Si el servidor responde con error HTTP (ej. 500 o 404)
          if (!response.ok) {
            throw new Error(`Error del servidor: ${response.status}`);
          }
          return response.json();
        })
        .then((data) => {
          // LOG DE DEPURACIÓN: Mira esto en la consola del navegador (F12)
          console.log("Respuesta recibida:", data);

          // 6. Verificar el status que devuelve Python ("completed")
          if (data.status === "completed") {
            // --- CORRECCIÓN CLAVE AQUÍ ---
            // Los datos están dentro del objeto "metrics", no en la raíz.
            // Usamos || 0 por seguridad, por si vienen nulos.
            const sesiones = data.metrics?.sessions_upserted || 0;
            const mensajes = data.metrics?.messages_inserted || 0;

            // Mostrar mensaje de éxito
            statusLabel.className = "text-success fw-bold";
            statusLabel.innerHTML = `<i class="fas fa-check-circle"></i> Éxito: ${sesiones} sesiones y ${mensajes} mensajes guardados.`;

            // Recargar la página después de 2 segundos para ver los cambios
            setTimeout(() => {
              window.location.reload();
            }, 2000);
          } else {
            // Si el status no es "completed" (ej. "error")
            throw new Error(
              data.error || data.message || "Error desconocido en la respuesta",
            );
          }
        })
        .catch((error) => {
          console.error("Error en Fetch:", error);

          // Mostrar error en pantalla
          statusLabel.className = "text-danger fw-bold";
          statusLabel.innerText = "Fallo: " + error.message;

          // Restaurar botón para intentar de nuevo
          btnSync.disabled = false;
          btnSync.innerText = "Reintentar";
        });
    });
  }
});
