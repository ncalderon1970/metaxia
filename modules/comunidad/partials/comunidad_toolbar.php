<?php
declare(strict_types=1);
?>
<form method="get" class="metis-toolbar comunidad-toolbar">
    <div class="metis-field">
        <label class="metis-label" for="tipo">Tipo</label>
        <select class="metis-select" name="tipo" id="tipo">
            <option value="alumnos" <?= ($tipo ?? '') === 'alumnos' ? 'selected' : '' ?>>Alumnos</option>
            <option value="apoderados" <?= ($tipo ?? '') === 'apoderados' ? 'selected' : '' ?>>Apoderados</option>
            <option value="docentes" <?= ($tipo ?? '') === 'docentes' ? 'selected' : '' ?>>Docentes</option>
            <option value="asistentes" <?= ($tipo ?? '') === 'asistentes' ? 'selected' : '' ?>>Asistentes</option>
        </select>
    </div>
    <div class="metis-field">
        <label class="metis-label" for="anio_escolar">Año escolar</label>
        <input class="metis-input" type="number" name="anio_escolar" id="anio_escolar" min="2020" max="2100" value="<?= htmlspecialchars((string)($anioEscolar ?? date('Y'))) ?>">
    </div>
    <div class="metis-field">
        <label class="metis-label" for="vigencia">Vigencia</label>
        <select class="metis-select" name="vigencia" id="vigencia">
            <option value="vigentes" <?= ($vigencia ?? '') === 'vigentes' ? 'selected' : '' ?>>Vigentes</option>
            <option value="historicos" <?= ($vigencia ?? '') === 'historicos' ? 'selected' : '' ?>>Históricos</option>
            <option value="todos" <?= ($vigencia ?? '') === 'todos' ? 'selected' : '' ?>>Todos</option>
        </select>
    </div>
    <div class="metis-field metis-field--grow">
        <label class="metis-label" for="q">Buscar</label>
        <input class="metis-input" type="search" name="q" id="q" value="<?= htmlspecialchars((string)($q ?? '')) ?>" placeholder="Buscar por RUN, nombre o nombre social">
    </div>
    <button class="metis-btn metis-btn--primary" type="submit">Filtrar</button>
</form>
