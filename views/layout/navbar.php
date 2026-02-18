<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">Centro de transcripciones</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="index.php?page=dashboard">Métricas</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?page=chat">Conversaciones</a></li>
      </ul>
      <span class="navbar-text me-3">
        <?= $_SESSION['user']['username'] ?> (<?= $_SESSION['user']['role'] ?>)
      </span>
      <a href="index.php?page=logout" class="btn btn-outline-light btn-sm">Salir</a>
    </div>
  </div>
</nav>