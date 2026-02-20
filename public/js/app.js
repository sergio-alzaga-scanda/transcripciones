document.addEventListener("DOMContentLoaded", function () {
  const btnSync = document.getElementById("btnSync");

  // Evento para el botón principal (si existe en la vista de Admin)
  if (btnSync) {
    btnSync.addEventListener("click", function () {
      const takeInput = document.getElementById("syncTake");
      const projectInput = document.getElementById("syncProjectId");
      const apiKeyInput = document.getElementById("syncApiKey");

      const take = takeInput ? takeInput.value : 100;
      const projectId = projectInput ? projectInput.value : "";
      const apiKey = apiKeyInput ? apiKeyInput.value : "";

      ejecutarSincronizacion(projectId, apiKey, take);
    });
  }
});

/**
 * Función centralizada que hace la petición y maneja las alertas con SweetAlert2
 */
function ejecutarSincronizacion(projectId, apiKey, take = 100) {
  // 1. Validaciones
  if (!projectId || !apiKey) {
    Swal.fire({
      icon: "warning",
      title: "Campos incompletos",
      text: "El ID del proyecto y la API Key son obligatorios.",
      confirmButtonColor: "#0d6efd",
    });
    return;
  }

  // 2. Alerta de Carga (Loading)
  Swal.fire({
    title: "Sincronizando...",
    html: `Conectando con el ingestor para el proyecto <br><b>${projectId}</b>.<br><br>Por favor, espera...`,
    allowOutsideClick: false,
    allowEscapeKey: false,
    didOpen: () => {
      Swal.showLoading();
    },
  });

  // 3. Petición Fetch
  const apiUrl = `http://158.23.137.150:8085/api/info_mensaje.php?take=${take}&id_project=${projectId}&api_key=${apiKey}`;

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
      // 4. Caso de Éxito
      if (data.status === "success" || data.status === "completed") {
        const nuevas = data.metrics?.nuevas_sesiones || 0;
        const total = data.metrics?.total_procesadas || 0;

        // Cambiar alerta de carga a éxito
        Swal.fire({
          icon: "success",
          title: "¡Completado!",
          html: `${data.message}<br><br><b>Nuevas sesiones:</b> ${nuevas}<br><b>Total procesadas:</b> ${total}`,
          timer: 3000,
          timerProgressBar: true,
          showConfirmButton: false,
        }).then(() => {
          // Recargamos la página cuando el temporizador termina
          window.location.reload();
        });
      } else {
        // Falló lógicamente desde el servidor
        throw new Error(
          data.error || data.message || "Error desconocido en el proceso",
        );
      }
    })
    .catch((error) => {
      // 5. Caso de Error
      console.error("Error en Sync:", error);

      // Cambiar alerta a Error
      Swal.fire({
        icon: "error",
        title: "Error en la sincronización",
        text: error.message,
        confirmButtonColor: "#dc3545",
      });
    });
}

/**
 * Esta función es la que se llama desde los botones verdes de la tabla.
 */
function syncProjectManual(id, key) {
  // Ya no necesitamos hacer scroll suave hacia arriba ni usar setTimeout.
  // SweetAlert va a aparecer directo en medio de la pantalla tapando todo.

  // Opcional: llenar los inputs de arriba si el usuario es Admin y los inputs existen
  const inputId = document.getElementById("syncProjectId");
  const inputKey = document.getElementById("syncApiKey");

  if (inputId && inputKey) {
    inputId.value = id;
    inputKey.value = key;
  }

  // Lanzar la sincronización directa a la API
  ejecutarSincronizacion(id, key, 100);
}
