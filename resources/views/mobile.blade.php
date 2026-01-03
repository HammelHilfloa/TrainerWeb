<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainerabrechnung & Trainingsplanung</title>
    <link rel="stylesheet" href="/assets/mobile.css">
</head>
<body class="bg-surface">
<header class="app-bar">
    <div class="brand">Vereinsplanung</div>
    <div class="user-menu">@auth {{ auth()->user()->name }} @endauth</div>
</header>

<main class="container">
    <section class="card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Halbjahr</p>
                <h2>@yield('title', 'H1 / H2 Übersicht')</h2>
            </div>
            <div class="tags">
                <span class="tag tag-success">frei</span>
                <span class="tag tag-warning">Ausfall</span>
                <span class="tag">Turnier</span>
            </div>
        </div>
        <div class="card-body">
            @yield('content')
        </div>
    </section>

    @hasSection('plan')
        <section class="card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Trainingsdetails</p>
                    <h3>@yield('plan_title', 'Aktueller Trainingsplan')</h3>
                </div>
                <div class="tags">
                    <span class="tag tag-success">sichtbar für Trainer:innen</span>
                    <span class="tag tag-warning">Pflege nur Admin</span>
                </div>
            </div>
            <div class="card-body">
                @yield('plan')
            </div>
        </section>
    @endhasSection

    @php
        $sessionDetails = $sessionDetails ?? [
            ['label' => 'Datum', 'value' => '12. Juni 2024 · 18:00 - 19:30'],
            ['label' => 'Ort', 'value' => 'Sporthalle Nord'],
            ['label' => 'Gruppe', 'value' => 'U13 Leistungs'],
            ['label' => 'Trainer:innen', 'value' => 'Mara (Lead), Felix'],
            ['label' => 'Status', 'value' => 'Bestätigt'],
        ];

        $planColumns = $planColumns ?? [
            [
                'title' => 'Warm-up',
                'subtitle' => 'Aktivierung & Mobilität',
                'items' => [
                    'Lauf-ABC & Mobilisation',
                    'Koordinationsleiter (2x10m)',
                    'Pass-Rondo 4vs1',
                ],
            ],
            [
                'title' => 'Technik',
                'subtitle' => 'Ballführung & Passspiel',
                'items' => [
                    'Dribbling Parcours (Hütchen-Slalom)',
                    'Pass-Dreieck mit Direktspiel',
                    '1vs1 Abschluss mit Torabschluss',
                ],
            ],
            [
                'title' => 'Spiel',
                'subtitle' => 'Anwenden & Intensität',
                'items' => [
                    '4vs4 mit Zonen',
                    'Umschalt-Spielform 5vs5',
                    'Cooldown & Feedbackrunde',
                ],
            ],
        ];
    @endphp

    <section class="card plan-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Trainingsdetails</p>
                <h3>Aktueller Fahrplan</h3>
            </div>
            <div class="tags">
                <span class="tag tag-success">Drag & Drop</span>
                <span class="tag">Touch-optimiert</span>
            </div>
        </div>

        <div class="card-body training-details">
            <div class="details-grid">
                @foreach($sessionDetails as $detail)
                    <div class="detail-chip">
                        <p class="eyebrow">{{ $detail['label'] }}</p>
                        <div class="detail-value">{{ $detail['value'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="plan-board">
                @foreach($planColumns as $columnIndex => $column)
                    <div class="plan-column" data-column="{{ $columnIndex }}">
                        <div class="plan-column-head">
                            <div>
                                <p class="eyebrow">{{ $column['subtitle'] }}</p>
                                <h4>{{ $column['title'] }}</h4>
                            </div>
                            <span class="pill">{{ count($column['items']) }} Blöcke</span>
                        </div>
                        <div class="droppable" data-column="{{ $columnIndex }}">
                            @foreach($column['items'] as $itemIndex => $item)
                                <article class="plan-item" draggable="true" data-id="{{ $columnIndex }}-{{ $itemIndex }}">
                                    <div class="plan-item-handle" aria-label="Verschieben">☰</div>
                                    <div class="plan-item-body">
                                        <p class="plan-item-title">Block {{ $itemIndex + 1 }}</p>
                                        <p class="plan-item-text">{{ $item }}</p>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="plan-note">
                <p class="eyebrow">Hinweis</p>
                <p>Blöcke lassen sich per Drag & Drop zwischen den Phasen verschieben. Lange auf den Griff tippen und den Baustein in die gewünschte Spalte ziehen.</p>
            </div>
        </div>
    </section>
</main>

<nav class="bottom-nav">
    <a href="/dashboard" class="nav-item">Dashboard</a>
    <a href="/trainings" class="nav-item">Trainings</a>
    <a href="/turniere" class="nav-item">Turniere</a>
    <a href="/abrechnung" class="nav-item">Abrechnung</a>
</nav>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const droppables = Array.from(document.querySelectorAll('.droppable'));
        let draggedItem = null;

        const refreshEmptyStates = () => {
            droppables.forEach(zone => {
                const hasItems = zone.querySelector('.plan-item');
                zone.classList.toggle('empty', !hasItems);
                renumber(zone);
            });
        };

        const renumber = (zone) => {
            Array.from(zone.querySelectorAll('.plan-item')).forEach((item, index) => {
                const label = item.querySelector('.plan-item-title');
                if (label) label.textContent = `Block ${index + 1}`;
            });
        };

        document.querySelectorAll('.plan-item').forEach(item => {
            item.addEventListener('dragstart', (event) => {
                draggedItem = item;
                item.classList.add('dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', item.dataset.id);
            });

            item.addEventListener('dragend', () => {
                item.classList.remove('dragging');
                droppables.forEach(zone => zone.classList.remove('active'));
                refreshEmptyStates();
            });
        });

        droppables.forEach(zone => {
            zone.addEventListener('dragover', (event) => {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                zone.classList.add('active');
            });

            zone.addEventListener('dragleave', () => zone.classList.remove('active'));

            zone.addEventListener('drop', (event) => {
                event.preventDefault();
                zone.classList.remove('active');
                if (draggedItem) {
                    const afterElement = getDragAfterElement(zone, event.clientY);
                    if (afterElement == null) {
                        zone.appendChild(draggedItem);
                    } else {
                        zone.insertBefore(draggedItem, afterElement);
                    }
                    refreshEmptyStates();
                }
            });
        });

        const getDragAfterElement = (container, y) => {
            const draggableElements = [...container.querySelectorAll('.plan-item:not(.dragging)')];
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        };

        refreshEmptyStates();
    });
</script>
</body>
</html>
