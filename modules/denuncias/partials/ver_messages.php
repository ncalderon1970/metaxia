<?php if ($error !== ''): ?>
    <div class="exp-msg error">
        <i class="bi bi-exclamation-triangle"></i>
        <?= e($error) ?>
    </div>
<?php endif; ?>
