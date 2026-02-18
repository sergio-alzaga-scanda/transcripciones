document.addEventListener("DOMContentLoaded", function () {
  const btnSync = document.getElementById("btnSync");

  if (btnSync) {
    btnSync.addEventListener("click", function () {
      // 1. Obtener valores
      const takeInput = document.getElementById("syncTake");
      const projectInput = document.getElementById("syncProjectId");
      const apiKeyInput = document.getElementById("syncApiKey");
      const statusLabel = document.getElementById("syncStatus");

      const take = takeInput ? takeInput.value : 100;
      const projectId = projectInput ? projectInput.value : "";
      const apiKey = apiKeyInput ? apiKeyInput.value : "";

      // 2. Validaciones básicas
      if (!projectId || !apiKey) {
        statusLabel.className = "text-danger fw-bold";
        statusLabel.innerHTML =
          '<i class="fas fa-exclamation-triangle"></i> Error: El ID y la API Key son obligatorios.';
        return;
      }

      // 3. UI Indicando "Actualizando" (Feedback visual)
      btnSync.disabled = true;
      btnSync.innerHTML =
        '<i class="fas fa-spinner fa-spin"></i> Actualizando...';

      statusLabel.className = "text-primary fw-bold";
      statusLabel.innerHTML = `<i class="fas fa-sync fa-spin"></i> Conectando con el ingestor... Sincronizando proyecto <code>${projectId}</code>`;

      // 4. Construir URL hacia tu Ingestor Python (Puerto 5001)
      const apiUrl = `http://127.0.0.1:5001/sync-voiceflow?take=${take}&id_project=${projectId}&api_key=${apiKey}`;

      // 5. Ejecutar Petición
      fetch(apiUrl)
        .then((response) => {
          if (!response.ok) {
            throw new Error(
              `Error del servidor: ${response.status} ${response.statusText}`,
            );
          }
          return response.json();
        })
        .then((data) => {
          // 6. Caso de Éxito
          if (data.status === "completed") {
            const total = data.metrics?.sessions_upserted || 0;

            statusLabel.className = "text-success fw-bold";
            statusLabel.innerHTML = `<i class="fas fa-check-circle"></i> ¡Listo! Se actualizaron ${total} sesiones correctamente.`;

            btnSync.innerHTML = '<i class="fas fa-check"></i> Completado';
            btnSync.className = "btn btn-success w-100";

            // Recargar para ver los cambios en la tabla de abajo tras 2 segundos
            setTimeout(() => {
              window.location.reload();
            }, 2000);
          } else {
            throw new Error(data.error || "Error desconocido en el proceso");
          }
        })
        .catch((error) => {
          // 7. Caso de Error
          console.error("Error en Sync:", error);

          statusLabel.className = "text-danger fw-bold";
          statusLabel.innerHTML = `<i class="fas fa-times-circle"></i> Error: ${error.message}`;

          // Restaurar botón para reintentar
          btnSync.disabled = false;
          btnSync.className = "btn btn-primary w-100";
          btnSync.innerHTML = "Reintentar Sincronización";
        });
    });
  }
});

/**
 * Esta función es la que se llama desde la tabla de abajo
 */
function syncProjectManual(id, key) {
  const inputId = document.getElementById("syncProjectId");
  const inputKey = document.getElementById("syncApiKey");
  const btnSync = document.getElementById("btnSync");

  if (inputId && inputKey) {
    inputId.value = id;
    inputKey.value = key;

    // Efecto de scroll suave hacia el formulario
    window.scrollTo({ top: 0, behavior: "smooth" });

    // Esperar a que el scroll termine para dar feedback visual
    setTimeout(() => {
      btnSync.click();
    }, 600);
  }
}
