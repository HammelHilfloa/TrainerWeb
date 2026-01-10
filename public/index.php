<?php
$appVersion = getenv('APP_VERSION') ?: '2026-01-04-01';
$appGlobals = [
  'version' => $appVersion,
  'apiBase' => getenv('APP_API_BASE') ?: '/api',
];
?>
<!DOCTYPE html>
<html lang="de" data-auth="out">
  <head>
    <script>document.documentElement.dataset.auth = "out";</script>
    <script>
      function showFatal(message) {
        const target = document.getElementById("fatalError");
        if (target) {
          target.textContent = message || "Unbekannter Fehler.";
          target.hidden = false;
          return;
        }

        const fallback = document.createElement("div");
        fallback.id = "fatalError";
        fallback.className = "fatal";
        fallback.role = "alert";
        fallback.textContent = message || "Unbekannter Fehler.";
        fallback.hidden = false;
        (document.body || document.documentElement).appendChild(fallback);
      }
      window.addEventListener("error", (e) => showFatal(e.message));
      window.addEventListener("unhandledrejection", (e) => showFatal(String(e.reason || e)));
    </script>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <title>TrainerTool</title>
    <base target="_top" />
    <?php include __DIR__ . '/style.html'; ?>
    <script>
      window.APP_CONFIG = <?php echo json_encode($appGlobals, JSON_UNESCAPED_UNICODE); ?>;
      const APP_VERSION = window.APP_CONFIG?.version || "2026-01-04-01";
      console.info(`TrainerTool Version: ${APP_VERSION}`);
      document.documentElement.dataset.version = APP_VERSION;
    </script>
  </head>
  <body class="logged-out">
    <div id="screen-login">
      <main>
        <section id="loginView" class="card login-card">
          <div class="login-brand">
            <div class="logo" aria-hidden="true">TT</div>
            <h1>TrainerTool</h1>
            <p>Das Trainertool des KSV Homberg</p>
          </div>
          <h2>Login</h2>
          <p class="muted">Bitte gib deinen Namen und PIN ein.</p>
          <form id="loginForm" novalidate>
            <div>
              <label for="trainerName">Name</label>
              <input
                id="trainerName"
                name="trainerName"
                type="text"
                autocomplete="name"
                placeholder="Vorname Nachname"
                required
              />
            </div>
            <div>
              <label for="pin">PIN</label>
              <input id="pin" name="pin" type="password" minlength="4" maxlength="8" pattern="[0-9]{4,8}" inputmode="numeric" autocomplete="current-password" required />
            </div>
            <button class="btn" type="button" id="loginBtn">Einloggen</button>
          </form>
          <div class="login-actions">
            <button class="link-btn" type="button">Passwort vergessen?</button>
          </div>
          <div id="loginError" class="login-error" hidden></div>
          <pre id="loginDebug" class="login-debug" hidden></pre>
          <pre id="debugBox" style="display:none; white-space:pre-wrap; font-size:12px;"></pre>
          <div id="loginCandidates" class="candidate-picker" hidden>
            <p class="muted">Bitte wähle deinen Namen:</p>
            <div class="candidate-list" id="candidateList"></div>
          </div>
          <div id="fatalError" class="fatal" role="alert" hidden></div>
        </section>
      </main>
    </div>

    <div id="screen-app" class="app-shell" hidden>
      <?php include __DIR__ . '/ui_components.html'; ?>
      <?php include __DIR__ . '/ui_app.html'; ?>
    </div>

    <footer class="app-footer" id="appVersionFooter" aria-label="Version"></footer>
    <script>
      const versionFooter = document.getElementById("appVersionFooter");
      if (versionFooter) versionFooter.textContent = `Version ${APP_VERSION}`;
    </script>
    <div class="toast" id="toast" role="alert" aria-live="polite"></div>
    <div class="spinner-overlay" id="spinner">
      <div class="spinner" role="status" aria-label="Laden"></div>
    </div>
  </body>
</html>
