document.addEventListener("DOMContentLoaded", function () {
  const btnSync = document.getElementById("btnSync");

  if (btnSync) {
    btnSync.addEventListener("click", function () {
      // 1. Obtener valores
      const takeInput = document.getElementById("syncTake");
      const projectInput = document.getElementById("syncProjectId");
      const apiKeyInput = document.getElementById("syncApiKey");
      const statusLabel = document.getElementById("syncStatus");

      const take = takeInput ? takeInput.value : 1000;
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

      // 4. Construir URL hacia tu Ingestor (PHP en puerto 8081)
      const apiUrl = `http://158.23.137.150:8085/api/info_mensaje.php?take=${take}&id_project=${projectId}&api_key=${apiKey}`;
      // const apiUrl = `http://localhost:8081/transcripciones/api/info_mensaje.php?take=${take}&id_project=${projectId}&api_key=${apiKey}`;

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
          // 6. Caso de Éxito (Corregido para detectar "success")
          if (data.status === "success" || data.status === "completed") {
            // Usamos las métricas que vienen en tu log (español)
            const nuevas = data.metrics?.nuevas_sesiones || 0;
            const total = data.metrics?.total_procesadas || 0;

            statusLabel.className = "text-success fw-bold";
            statusLabel.innerHTML = `<i class="fas fa-check-circle"></i> ¡Listo! ${data.message}. (Nuevas: ${nuevas}, Total: ${total})`;

            btnSync.innerHTML = '<i class="fas fa-check"></i> Completado';
            btnSync.className = "btn btn-success w-100";

            // Recargar para ver los cambios en la tabla tras 2 segundos
            setTimeout(() => {
              window.location.reload();
            }, 2000);
          } else {
            // Si el status no es success, lanzamos el error del backend o uno genérico
            throw new Error(
              data.error || data.message || "Error desconocido en el proceso",
            );
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

    // Esperar a que el scroll termine para ejecutar el click
    setTimeout(() => {
      if (btnSync) btnSync.click();
    }, 600);
  }
}
