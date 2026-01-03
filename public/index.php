<?php
// Lightweight Landing Page als Platzhalter bis Laravel-Routing aktiv ist.
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainer-Abrechnung & Einsatzplanung</title>
    <link rel="stylesheet" href="/assets/mobile.css">
</head>
<body>
    <header class="app-bar">
        <div class="brand">Trainerabrechnung</div>
        <a href="/login" class="nav-item" style="padding:10px 12px;">Login</a>
    </header>

    <section class="hero">
        <h1>Alles im Griff – mobil.</h1>
        <p>Trainings erstellen, Einteilungen buchen, Abwesenheiten melden und Halbjahresabrechnungen generieren.</p>
    </section>

    <section class="container">
        <div class="login-card">
            <h3>Direkt einloggen</h3>
            <form method="POST" action="/login">
                <input type="email" name="email" placeholder="E-Mail" required>
                <input type="password" name="password" placeholder="Passwort" required>
                <button type="submit">Jetzt einloggen</button>
            </form>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Funktionsüberblick</p>
                    <h2>Training, Turniere, Abrechnung</h2>
                </div>
                <div class="tags">
                    <span class="tag">Laravel</span>
                    <span class="tag">MySQL</span>
                    <span class="tag tag-success">Mobil</span>
                </div>
            </div>
            <div class="card-body">
                <ul>
                    <li>Admin: Trainings & Benutzer verwalten</li>
                    <li>Trainer: Selbst einteilen und Abwesenheiten hinterlegen</li>
                    <li>Turniere & Fahrten: Teams, Coach-Zuweisungen und Reiseplanung</li>
                    <li>Abrechnung: Halbjahresreports inkl. Lohnsätze je Status</li>
                </ul>
            </div>
        </div>
    </section>

    <nav class="bottom-nav">
        <a href="#" class="nav-item">Mobile</a>
        <a href="#" class="nav-item">Sicher</a>
        <a href="#" class="nav-item">Schnellstart</a>
        <a href="#" class="nav-item">Support</a>
    </nav>
</body>
</html>
