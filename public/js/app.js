document.addEventListener("DOMContentLoaded", function () {
  const btnSync = document.getElementById("btnSync");

  // Evento para el botón del formulario de Administrador
  if (btnSync) {
    btnSync.addEventListener("click", function () {
      // 1. Obtener valores (con fallback por si no existen)
      const takeInput = document.getElementById("syncTake");
      const projectInput = document.getElementById("syncProjectId");
      const apiKeyInput = document.getElementById("syncApiKey");

      const take = takeInput ? takeInput.value : 100;
      const projectId = projectInput ? projectInput.value : "";
      const apiKey = apiKeyInput ? apiKeyInput.value : "";

      // Ejecutar la sincronización
      ejecutarSincronizacion(projectId, apiKey, take);
    });
  }
});

/**
 * Función centralizada para ejecutar la petición Fetch al Ingestor.
 * Se usa tanto en el formulario admin como en los botones de la tabla.
 */
function ejecutarSincronizacion(projectId, apiKey, take = 100) {
  const statusLabel = document.getElementById("syncStatus");
  const btnSync = document.getElementById("btnSync");

  // 1. Validaciones básicas
  if (!projectId || !apiKey) {
    if (statusLabel) {
      statusLabel.className = "alert alert-danger mt-3";
      statusLabel.innerHTML =
        '<i class="fas fa-exclamation-triangle"></i> Error: El ID del proyecto y la API Key son obligatorios.';
    } else {
      alert("Error: El ID del proyecto y la API Key son obligatorios.");
    }
    return;
  }

  // 2. UI Indicando "Actualizando" (Feedback visual)
  if (btnSync) {
    btnSync.disabled = true;
    btnSync.innerHTML =
      '<i class="fas fa-spinner fa-spin"></i> Actualizando...';
  }

  if (statusLabel) {
    statusLabel.className = "alert alert-primary mt-3";
    statusLabel.innerHTML = `<i class="fas fa-sync fa-spin"></i> Conectando con el ingestor... Sincronizando proyecto <code>${projectId}</code>`;
  }

  // 3. Construir URL hacia tu Ingestor
  const apiUrl = `http://158.23.137.150:8085/api/info_mensaje.php?take=${take}&id_project=${projectId}&api_key=${apiKey}`;

  // 4. Ejecutar Petición
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
      // 5. Caso de Éxito
      if (data.status === "success" || data.status === "completed") {
        const nuevas = data.metrics?.nuevas_sesiones || 0;
        const total = data.metrics?.total_procesadas || 0;

        if (statusLabel) {
          statusLabel.className = "alert alert-success mt-3";
          statusLabel.innerHTML = `<i class="fas fa-check-circle"></i> ¡Listo! ${data.message}. (Nuevas: ${nuevas}, Total: ${total})`;
        }

        if (btnSync) {
          btnSync.innerHTML = '<i class="fas fa-check"></i> Completado';
          btnSync.className = "btn btn-success w-100";
        }

        // Recargar para ver los cambios en la tabla tras 2 segundos
        setTimeout(() => {
          window.location.reload();
        }, 2000);
      } else {
        // Si el status no es success, lanzamos el error del backend
        throw new Error(
          data.error || data.message || "Error desconocido en el proceso",
        );
      }
    })
    .catch((error) => {
      // 6. Caso de Error
      console.error("Error en Sync:", error);

      if (statusLabel) {
        statusLabel.className = "alert alert-danger mt-3";
        statusLabel.innerHTML = `<i class="fas fa-times-circle"></i> Error: ${error.message}`;
      }

      // Restaurar botón para reintentar
      if (btnSync) {
        btnSync.disabled = false;
        btnSync.className = "btn btn-primary w-100";
        btnSync.innerHTML = "Registrar y Sincronizar";
      }
    });
}

/**
 * Esta función es la que se llama desde los botones verdes de la tabla.
 */
function syncProjectManual(id, key) {
  const inputId = document.getElementById("syncProjectId");
  const inputKey = document.getElementById("syncApiKey");
  const btnSync = document.getElementById("btnSync");

  // Si los inputs existen (vista de Administrador), los llenamos visualmente
  if (inputId && inputKey) {
    inputId.value = id;
    inputKey.value = key;

    // Efecto de scroll suave hacia el formulario
    window.scrollTo({ top: 0, behavior: "smooth" });

    // Esperar a que el scroll termine para ejecutar la petición
    setTimeout(() => {
      ejecutarSincronizacion(id, key, 100);
    }, 600);
  } else {
    // Si los inputs NO existen (vista de Usuario normal), ejecutamos la petición directamente
    ejecutarSincronizacion(id, key, 100);
  }
}
